#!/usr/bin/env bash
# ============================================================================
# 一次性整合 wrapper：固定數據負載實驗（5000 / 10000 / 20000）
#
# 流程：
#   §A.1 四台 host 分支同步檢查（feat/spiffe-keycloak）
#   §A.2 stack health probe（:8080/:8082/:8083/:8084）
#   §A.3 smoke 訂單 — 必須走完 Saga Step 4
#   §A.4 蒐集 metadata.json（驗證 GATEWAY_WORKERS / numprocs 是正整數；
#        若 EXPECTED_GW_WORKERS / EXPECTED_NUMPROCS 明確設值才做 strict pin）
#   §B   呼叫 run-dualmode-distributed.sh 跑 warm/cold × 5000/10000/20000
#   §B.3 呼叫 analyze-dualmode-experiment.py 產 4 組 xlsx/png + summary.xlsx
#
# Usage:
#   bash scripts/experiments/run-experimental-full.sh
#   EXPECTED_BRANCH=feat/spiffe-keycloak \
#     SCALES="5000 10000 20000" ROUNDS="warm cold" \
#     bash scripts/experiments/run-experimental-full.sh
#
# Env:
#   EXPECTED_BRANCH       default: feat/spiffe-keycloak
#   SCALES                default: "5000 10000 20000"
#   ROUNDS                default: "warm cold"
#   EXPECTED_GW_WORKERS   default: ""  empty → 只 sanity check (>0)
#                                       設值 → strict pin（不符 fail）
#   EXPECTED_NUMPROCS     default: ""  同上 — 從 1 改成多 process 後不再固定
#   SKIP_BRANCH_SYNC=1    跳過 git fetch/pull（debug only）
#   SKIP_SMOKE=1          跳過 smoke 訂單（debug only）
#   OUT_OVERRIDE          自訂輸出目錄，否則自動產生 artifacts/<stamp>_Experimental
# ============================================================================
set -euo pipefail

EXPECTED_BRANCH="${EXPECTED_BRANCH:-feat/spiffe-keycloak}"
SCALES="${SCALES:-5000 10000 20000}"
ROUNDS="${ROUNDS:-warm cold}"
EXPECTED_GW_WORKERS="${EXPECTED_GW_WORKERS:-}"
EXPECTED_NUMPROCS="${EXPECTED_NUMPROCS:-}"

PROJECT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="${OUT_OVERRIDE:-${PROJECT_DIR}/artifacts/${STAMP}_Experimental}"
mkdir -p "$OUT/raw"

# WAN aliases per CLAUDE.md (對應的 LAN -lan 別名由 distributed runner 自己用)
HOSTS=(zt-gateway zt-order zt-prod zt-user)
health_port_for() {
    case "$1" in
        zt-gateway) echo 8080 ;;
        zt-order)   echo 8082 ;;
        zt-prod)    echo 8083 ;;
        zt-user)    echo 8084 ;;
        *) return 1 ;;
    esac
}

BOLD='\033[1m'; GREEN='\033[32m'; RED='\033[31m'
YELLOW='\033[33m'; CYAN='\033[36m'; RESET='\033[0m'
log()     { printf "${BOLD}[exp]${RESET} %s %s\n" "$(date +%T)" "$*"; }
ok()      { printf "${GREEN}[ok ]${RESET} %s\n" "$*"; }
warn()    { printf "${YELLOW}[warn]${RESET} %s\n" "$*"; }
fail()    { printf "${RED}[fail]${RESET} %s\n" "$*" >&2; exit 1; }
section() { printf "\n${CYAN}${BOLD}══ %s ══${RESET}\n" "$*"; }

START_TS=$(date +%s)
log "STAMP=$STAMP  OUT=$OUT"
log "branch=$EXPECTED_BRANCH  scales='$SCALES'  rounds='$ROUNDS'"

# ── §A.1 Branch sync check ──────────────────────────────────────────────────
section "A.1 branch sync check (target=$EXPECTED_BRANCH)"
for h in "${HOSTS[@]}"; do
    if [[ "${SKIP_BRANCH_SYNC:-0}" != "1" ]]; then
        ssh "$h" "cd ~/zt-event-gateway && git fetch --all --prune \
            && git checkout '$EXPECTED_BRANCH' && git pull --ff-only" \
            >/dev/null || fail "$h: git sync failed"
    fi
    actual=$(ssh "$h" 'cd ~/zt-event-gateway && git rev-parse --abbrev-ref HEAD')
    [[ "$actual" == "$EXPECTED_BRANCH" ]] || \
        fail "$h on '$actual', expected '$EXPECTED_BRANCH'"
    ok "$h on $actual"
done

# ── §A.2 Stack health probe ────────────────────────────────────────────────
section "A.2 stack health probe"
for h in "${HOSTS[@]}"; do
    port="$(health_port_for "$h")"
    # Gateway expose /api/health (200); downstream services do not — accept any
    # 2xx–4xx as "HTTP server responsive" (matches docker healthcheck convention).
    code=$(ssh "$h" "curl -s -o /dev/null -w '%{http_code}' -m 5 http://127.0.0.1:${port}/api/health" || echo 000)
    [[ "$code" =~ ^[234] ]] || fail "$h:${port} not healthy (HTTP $code)"
    ok "$h:${port} responsive (HTTP $code)"
done

# SHM watcher state on gateway host (best-effort, non-fatal)
shm_x509=$(ssh zt-gateway '
    docker exec zt-spiffe-watcher cat /tmp/spiffe-shared/meta.json 2>/dev/null \
      | python3 -c "import json,sys;print(json.load(sys.stdin).get(\"x509_state\",\"unknown\"))" 2>/dev/null
' || echo "unavailable")
ok "spiffe-watcher x509_state=$shm_x509"

shm_token=$(ssh zt-gateway '
    docker exec zt-keycloak-watcher cat /tmp/keycloak-shared/meta.json 2>/dev/null \
      | python3 -c "import json,sys;print(json.load(sys.stdin).get(\"token_state\",\"unknown\"))" 2>/dev/null
' || echo "unavailable")
ok "keycloak-watcher token_state=$shm_token"

# ── §A.3 Smoke order — must complete Saga Step 4 ───────────────────────────
section "A.3 smoke order (must complete Saga Step 4)"
if [[ "${SKIP_SMOKE:-0}" != "1" ]]; then
    TRACE="smoke-${STAMP}"
    # Gateway ingress requires user-level Keycloak JWT. Mint via ROPC against
    # the docker-internal URL (http://keycloak:8080) so iss matches gateway's
    # KEYCLOAK_ISSUER. We exec curl inside zt-gateway (on anser_project_network).
    SMOKE_TOKEN=$(ssh zt-gateway "docker exec zt-gateway curl -fsS -m 5 -X POST \
        -H 'Content-Type: application/x-www-form-urlencoded' \
        --data-urlencode 'grant_type=password' \
        --data-urlencode 'client_id=client-app' \
        --data-urlencode 'client_secret=client-app-dev-secret' \
        --data-urlencode 'username=testuser' \
        --data-urlencode 'password=testpass' \
        http://keycloak:8080/realms/zt/protocol/openid-connect/token" \
        | python3 -c "import json,sys;print(json.load(sys.stdin).get('access_token',''))")
    [[ -n "$SMOKE_TOKEN" ]] || fail "smoke: failed to mint Keycloak token"
    ok "smoke: minted Keycloak token (len=${#SMOKE_TOKEN})"

    code=$(ssh zt-gateway "curl -sS -o /dev/null -w '%{http_code}' \
        -X POST http://127.0.0.1:8080/api/orders \
        -H 'Content-Type: application/json' \
        -H 'Authorization: Bearer ${SMOKE_TOKEN}' \
        -H 'X-Correlation-Id: ${TRACE}' \
        -d '{\"userKey\":\"1\",\"productList\":[{\"p_key\":1,\"amount\":1}],\"total\":100}'")
    [[ "$code" == "202" ]] || fail "smoke order HTTP $code (expected 202)"
    sleep 5
    # The "Saga Step 4" line carries only orderId (no traceId), so we first
    # resolve our trace → orderId via the [perf-saga-step1] line, then collect
    # all worker log lines mentioning that orderId.
    ORDER_ID=$(ssh zt-gateway "docker logs --tail 500 zt-php-worker 2>&1 \
        | grep -E '\[perf-saga-step1\].*traceId=${TRACE}'" \
        | sed -E 's/.*orderId=([a-f0-9-]+).*/\1/' | head -1 || true)
    if [[ -n "$ORDER_ID" ]]; then
        ssh zt-gateway "docker logs --tail 500 zt-php-worker 2>&1 \
            | grep -E '${ORDER_ID}|${TRACE}'" > "$OUT/smoke.log" 2>/dev/null || true
    else
        ssh zt-gateway "docker logs --tail 500 zt-php-worker 2>&1 \
            | grep '${TRACE}'" > "$OUT/smoke.log" 2>/dev/null || true
    fi
    if grep -qE 'RollbackSaga|perf-saga-rolled-back' "$OUT/smoke.log" 2>/dev/null; then
        fail "smoke order rolled back; see $OUT/smoke.log"
    fi
    grep -qE 'Saga Step 4|perf-saga-complete' "$OUT/smoke.log" 2>/dev/null \
        || fail "smoke order did not reach Saga Step 4 within 5s; see $OUT/smoke.log"
    ok "smoke order complete (trace=$TRACE orderId=$ORDER_ID)"
else
    warn "SKIP_SMOKE=1, skipping smoke order"
fi

# ── §A.4 Metadata 落檔（記錄實際抓到的值；可選 strict pin） ───────────────
section "A.4 collecting metadata.json"

gw_envs=$(ssh zt-gateway 'docker inspect zt-gateway --format "{{range .Config.Env}}{{println .}}{{end}}"')
extract_env() { awk -F= -v k="$1" '$1==k{print $2; exit}' <<< "$gw_envs"; }

gw_workers=$(extract_env GATEWAY_WORKERS); gw_workers="${gw_workers:-32}"
if ! [[ "$gw_workers" =~ ^[0-9]+$ ]] || (( gw_workers < 1 )); then
    fail "GATEWAY_WORKERS resolved to '$gw_workers'; expected positive integer."
fi
if [[ -n "$EXPECTED_GW_WORKERS" && "$gw_workers" != "$EXPECTED_GW_WORKERS" ]]; then
    fail "GATEWAY_WORKERS=$gw_workers, EXPECTED_GW_WORKERS=$EXPECTED_GW_WORKERS pin failed.
   Fix: 'export GATEWAY_WORKERS=$EXPECTED_GW_WORKERS' (或寫進 .env)
        然後 'docker compose up -d --force-recreate gateway'"
fi
ok "GATEWAY_WORKERS=$gw_workers"

numprocs=$(ssh zt-gateway 'docker exec zt-php-worker pgrep -fc "php bin/worker.php" 2>/dev/null' || echo "0")
if ! [[ "$numprocs" =~ ^[0-9]+$ ]] || (( numprocs < 1 )); then
    fail "php-worker numprocs=$numprocs; no 'php bin/worker.php' process found in zt-php-worker."
fi
if [[ -n "$EXPECTED_NUMPROCS" && "$numprocs" != "$EXPECTED_NUMPROCS" ]]; then
    fail "php-worker numprocs=$numprocs, EXPECTED_NUMPROCS=$EXPECTED_NUMPROCS pin failed.
   Fix: 'export WORKER_PROCESSES=$EXPECTED_NUMPROCS' (寫進 .env)
        然後 'docker compose up -d --force-recreate php-worker'"
fi
ok "numprocs=$numprocs"

spiffe_enabled=$(extract_env SPIFFE_ENABLED);     spiffe_enabled="${spiffe_enabled:-0}"
lsvid_required=$(extract_env LSVID_REQUIRED);     lsvid_required="${lsvid_required:-0}"
mtls_enabled=$(extract_env SPIFFE_MTLS_ENABLED);  mtls_enabled="${mtls_enabled:-0}"
keycloak_enabled=$(extract_env KEYCLOAK_ENABLED); keycloak_enabled="${keycloak_enabled:-0}"

SHA_GW=$(ssh zt-gateway 'cd ~/zt-event-gateway && git rev-parse HEAD')
SHA_ORD=$(ssh zt-order   'cd ~/zt-event-gateway && git rev-parse HEAD')
SHA_PROD=$(ssh zt-prod   'cd ~/zt-event-gateway && git rev-parse HEAD')
SHA_USER=$(ssh zt-user   'cd ~/zt-event-gateway && git rev-parse HEAD')

EXP_BRANCH="$EXPECTED_BRANCH" \
EXP_STAMP="$STAMP" \
EXP_SCALES="$SCALES" \
EXP_ROUNDS="$ROUNDS" \
EXP_OUT="$OUT/metadata.json" \
EXP_GW_SHA="$SHA_GW" \
EXP_ORD_SHA="$SHA_ORD" \
EXP_PROD_SHA="$SHA_PROD" \
EXP_USER_SHA="$SHA_USER" \
EXP_GW_WORKERS="$gw_workers" \
EXP_NUMPROCS="$numprocs" \
EXP_SPIFFE="$spiffe_enabled" \
EXP_LSVID="$lsvid_required" \
EXP_MTLS="$mtls_enabled" \
EXP_KEYCLOAK="$keycloak_enabled" \
python3 - <<'PYEOF'
import datetime, json, os, sys

def i(name, default=0):
    v = os.environ.get(name, "")
    return int(v) if v.strip() else default

data = {
    "stamp": os.environ["EXP_STAMP"],
    "branch": os.environ["EXP_BRANCH"],
    "commit_sha": {
        "zt-gateway": os.environ["EXP_GW_SHA"],
        "zt-order":   os.environ["EXP_ORD_SHA"],
        "zt-prod":    os.environ["EXP_PROD_SHA"],
        "zt-user":    os.environ["EXP_USER_SHA"],
    },
    "gateway_workers":           i("EXP_GW_WORKERS"),
    "worker_consumer_processes": i("EXP_NUMPROCS"),
    "amqp_prefetch_count":       1,
    "spiffe_enabled":   i("EXP_SPIFFE"),
    "lsvid_required":   i("EXP_LSVID"),
    "mtls_enabled":     i("EXP_MTLS"),
    "keycloak_enabled": i("EXP_KEYCLOAK"),
    "scales": [int(x) for x in os.environ["EXP_SCALES"].split()],
    "rounds": os.environ["EXP_ROUNDS"].split(),
    "started_at": datetime.datetime.now(datetime.timezone.utc)
                   .strftime("%Y-%m-%dT%H:%M:%SZ"),
}
out = os.environ["EXP_OUT"]
with open(out, "w", encoding="utf-8") as f:
    json.dump(data, f, indent=2, ensure_ascii=False)
print(f"[meta] wrote {out}", file=sys.stderr)
PYEOF
ok "metadata.json written → $OUT/metadata.json"

# ── §B Distributed runner ──────────────────────────────────────────────────
section "B running distributed experiment (this can take hours)"
SCALES="$SCALES" ROUNDS="$ROUNDS" \
    bash "${PROJECT_DIR}/scripts/experiments/run-dualmode-distributed.sh" "$OUT"

# ── §B.3 Analyze ───────────────────────────────────────────────────────────
section "B.3 analyzing & rendering charts"
SCALES_CSV="$(echo "$SCALES" | tr ' ' ',')"
python3 "${PROJECT_DIR}/scripts/experiments/analyze-dualmode-experiment.py" \
    --in "$OUT" --scales "$SCALES_CSV"

ELAPSED=$(( $(date +%s) - START_TS ))
section "DONE"
ok "elapsed: ${ELAPSED}s"
ok "artifacts: $OUT"
ls -la "$OUT" | sed 's/^/      /'
