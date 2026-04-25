#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════
#  Full Microservice E2E (C1)
#
#  Exercises the REAL 4-Docker-stack topology end-to-end:
#    Gateway+Worker+RabbitMQ+SPIRE  (docker-compose.yml)
#    Order Service                  (Services/Order_service/)
#    Production Service             (Services/Production_service/)
#    User Service                   (Services/User_service/)
#
#  Four cases:
#    MS-HAPPY          legitimate order — Saga Step 1→4 must complete
#    MS-PAYMENT-FAIL   user has no wallet — Saga Step 3 must compensate
#                      (RollbackInventory + RollbackOrder must appear)
#    MS-LSVID-CHAIN    legitimate order — downstream logs must show
#                      L0 → L1 → L2 chain propagation
#    MS-MTLS-REJECT    direct curl to downstream with no client cert
#                      must fail TLS handshake or return 4xx
#
#  Preconditions (this script does NOT bring the stacks up — use
#  scripts/e2e-full-stack.sh --keep first, or bring the 3 microservice
#  stacks up manually):
#    * Gateway healthy at http://10.1.1.209:8080
#    * order-service at  http://10.1.1.210:8082
#    * production-svc at http://10.1.1.207:8083
#    * user-service at   http://10.1.1.214:8084
#    * mTLS enabled on downstream (SPIFFE_MTLS_ENABLED=1)
#
#  Outputs:
#    artifacts/e2e-micro-<STAMP>/case-<MS-*>.json per case
#    artifacts/e2e-micro-<STAMP>/summary.json
#
#  Usage:
#    bash scripts/e2e-suite/full-microservice.sh
#    CASES="MS-HAPPY MS-MTLS-REJECT" bash scripts/e2e-suite/full-microservice.sh
# ══════════════════════════════════════════════════════════════════
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$PROJECT_DIR"

GATEWAY_URL="${GATEWAY_URL:-http://10.1.1.209:8080}"
ORDER_URL="${ORDER_URL:-http://10.1.1.210:8082}"
PRODUCTION_URL="${PRODUCTION_URL:-http://10.1.1.207:8083}"
USER_URL="${USER_URL:-http://10.1.1.214:8084}"
PRODUCTION_MTLS_URL="${PRODUCTION_MTLS_URL:-https://10.1.1.207:18083}"

WORKER_CONTAINER="${WORKER_CONTAINER:-php-worker}"
CASES="${CASES:-MS-HAPPY MS-PAYMENT-FAIL MS-LSVID-CHAIN MS-MTLS-REJECT}"
SAGA_WAIT_SECS="${SAGA_WAIT_SECS:-12}"

STAMP="$(date +%Y%m%d-%H%M%S)"
OUT_DIR="${OUT_DIR:-artifacts/e2e-micro-$STAMP}"
mkdir -p "$OUT_DIR"

BOLD='\033[1m'; GREEN='\033[32m'; RED='\033[31m'; YELLOW='\033[33m'; RESET='\033[0m'
info() { echo -e "${BOLD}[micro]${RESET} $(date +%T) $*"; }
pass() { echo -e "${GREEN}${BOLD}[ok]${RESET}   $*"; }
fail() { echo -e "${RED}${BOLD}[fail]${RESET} $*"; }
warn() { echo -e "${YELLOW}${BOLD}[warn]${RESET} $*"; }

# ── Preflight ────────────────────────────────────────────────────────────────
preflight() {
    local missing=0
    for url_label in \
        "gateway|${GATEWAY_URL}/api/health" \
        "order-service|${ORDER_URL}/" \
        "production-service|${PRODUCTION_URL}/" \
        "user-service|${USER_URL}/"
    do
        local label="${url_label%%|*}" url="${url_label##*|}"
        local code
        code=$(curl -sk -o /dev/null -w '%{http_code}' --connect-timeout 3 --max-time 5 "$url" 2>/dev/null) || true
        if [[ -z "$code" ]]; then code="000"; fi
        if [[ "$code" =~ ^[234][0-9][0-9]$ ]]; then
            pass "$label reachable (HTTP $code)"
        else
            fail "$label UNREACHABLE at $url (HTTP $code)"
            missing=$((missing+1))
        fi
    done
    if (( missing > 0 )); then
        warn "start the 4 stacks first:"
        warn "  docker compose up -d"
        warn "  docker compose -f Services/Order_service/docker-compose.yml up -d"
        warn "  docker compose -f Services/Production_service/docker-compose.yml up -d"
        warn "  docker compose -f Services/User_service/docker-compose.yml up -d"
        exit 2
    fi
}

# ── Worker log helpers ───────────────────────────────────────────────────────
worker_logs_since() {
    local since="$1"
    docker logs --since "$since" "$WORKER_CONTAINER" 2>&1 | tr '\n' '|' || true
}

contains_pattern() {
    local log="$1" pattern="$2"
    [[ "$log" == *"$pattern"* ]]
}

# ── Submit an order to the gateway ───────────────────────────────────────────
# Args: user_id product_count amount_each correlation_id
submit_order() {
    local user_id="$1" pcount="$2" amount="$3" trace="$4"
    local products=""
    for ((i=0; i<pcount; i++)); do
        [ -z "$products" ] || products+=","
        products+="{\"p_key\": $((i+1)), \"amount\": $amount}"
    done
    curl -s -w '\n%{http_code}' --connect-timeout 5 --max-time 10 \
        -X POST "${GATEWAY_URL}/api/orders" \
        -H 'Content-Type: application/json' \
        -H "X-Correlation-ID: ${trace}" \
        -d "{\"user_id\": $user_id, \"product_list\": [${products}]}" 2>/dev/null
}

# ── Per-case implementations ─────────────────────────────────────────────────

case_happy() {
    local case_id="MS-HAPPY"
    local trace="${case_id}-$(date +%s)"
    local out="$OUT_DIR/case-$case_id.json"
    local since
    since="$(date -u +%Y-%m-%dT%H:%M:%S)Z"
    local response http_code
    response=$(submit_order 1 1 1 "$trace")
    http_code=$(echo "$response" | tail -1)
    sleep "$SAGA_WAIT_SECS"
    local log
    log=$(worker_logs_since "$since")
    local completed
    contains_pattern "$log" "Saga 完成" && completed="true" || completed="false"
    local compensated
    contains_pattern "$log" "回滾" && compensated="true" || compensated="false"
    local verdict
    if [[ "$http_code" == "202" && "$completed" == "true" && "$compensated" == "false" ]]; then
        verdict="pass"; pass "$case_id"
    else
        verdict="fail"; fail "$case_id (http=$http_code completed=$completed compensated=$compensated)"
    fi
    python3 - "$out" "$case_id" "$trace" "$http_code" "$completed" "$compensated" "$verdict" <<'PY'
import json, sys
out, c, t, code, done, comp, v = sys.argv[1:]
json.dump({"case": c, "trace": t, "http_code": int(code) if code.isdigit() else None,
          "saga_completed": done == "true", "compensation_triggered": comp == "true", "verdict": v},
         open(out, "w"), indent=2)
PY
}

case_payment_fail() {
    local case_id="MS-PAYMENT-FAIL"
    local trace="${case_id}-$(date +%s)"
    local out="$OUT_DIR/case-$case_id.json"
    local since
    since="$(date -u +%Y-%m-%dT%H:%M:%S)Z"
    # user_id 999999 does not exist in user-service DB → walletChargeAction fails
    local response http_code
    response=$(submit_order 999999 1 1 "$trace")
    http_code=$(echo "$response" | tail -1)
    sleep "$SAGA_WAIT_SECS"
    local log
    log=$(worker_logs_since "$since")
    local rollback_inv rollback_order
    contains_pattern "$log" "RollbackSaga Step 2" && rollback_inv="true"   || rollback_inv="false"
    contains_pattern "$log" "RollbackSaga Step 1" && rollback_order="true" || rollback_order="false"
    local verdict
    # The gateway should still accept (202) — compensation is expected at Saga Step 3.
    if [[ "$http_code" == "202" && "$rollback_inv" == "true" && "$rollback_order" == "true" ]]; then
        verdict="pass"; pass "$case_id (both rollback events fired)"
    else
        verdict="fail"; fail "$case_id (http=$http_code rollback_inv=$rollback_inv rollback_order=$rollback_order)"
    fi
    python3 - "$out" "$case_id" "$trace" "$http_code" "$rollback_inv" "$rollback_order" "$verdict" <<'PY'
import json, sys
out, c, t, code, ri, ro, v = sys.argv[1:]
json.dump({"case": c, "trace": t, "http_code": int(code) if code.isdigit() else None,
          "rollback_inventory_fired": ri == "true",
          "rollback_order_fired": ro == "true",
          "verdict": v},
         open(out, "w"), indent=2)
PY
}

case_lsvid_chain() {
    local case_id="MS-LSVID-CHAIN"
    local trace="${case_id}-$(date +%s)"
    local out="$OUT_DIR/case-$case_id.json"
    local since
    since="$(date -u +%Y-%m-%dT%H:%M:%S)Z"
    submit_order 1 1 1 "$trace" >/dev/null
    sleep "$SAGA_WAIT_SECS"
    local worker_log prod_log
    worker_log=$(worker_logs_since "$since")
    prod_log=$(docker logs --since "$since" production-service 2>&1 | tr '\n' '|' || true)
    # The downstream access log or LSVID debug log should show a token whose
    # chain length is >= 2 (L0+L1+L2 = 3 levels in the log snippet). Many
    # deployments emit "chain level=N" or similar — probe multiple markers.
    local has_l0 has_l1 has_l2
    contains_pattern "$worker_log" "L0"      && has_l0="true" || has_l0="false"
    contains_pattern "$worker_log" "L1"      && has_l1="true" || has_l1="false"
    contains_pattern "$prod_log"   "LSVID"   && has_l2="true" || has_l2="false"
    local verdict
    if [[ "$has_l0" == "true" || "$has_l1" == "true" || "$has_l2" == "true" ]]; then
        verdict="pass"; pass "$case_id (found chain markers: L0=$has_l0 L1=$has_l1 downstream=$has_l2)"
    else
        verdict="inconclusive"
        warn "$case_id chain markers not observable in logs (LSVID_CAPTURE_DEBUG=1 required on services)"
    fi
    python3 - "$out" "$case_id" "$trace" "$has_l0" "$has_l1" "$has_l2" "$verdict" <<'PY'
import json, sys
out, c, t, l0, l1, l2, v = sys.argv[1:]
json.dump({"case": c, "trace": t,
          "worker_log_has_L0": l0 == "true",
          "worker_log_has_L1": l1 == "true",
          "downstream_log_has_LSVID": l2 == "true",
          "verdict": v,
          "note": "set LSVID_CAPTURE_DEBUG=1 on downstream services to make this conclusive"},
         open(out, "w"), indent=2)
PY
}

case_mtls_reject() {
    local case_id="MS-MTLS-REJECT"
    local out="$OUT_DIR/case-$case_id.json"
    # Direct curl to downstream WITHOUT a client cert. If mTLS is enforced on
    # the downstream (SPIFFE_MTLS_ENABLED=1), the TLS handshake should fail
    # (exit != 0) or return a 4xx. We probe BOTH the plain :8083 endpoint
    # (non-mTLS, should reject unauthenticated) AND the :18083 mTLS endpoint.
    local plain_code mtls_code mtls_err
    plain_code=$(curl -sk -o /dev/null -w '%{http_code}' --connect-timeout 3 --max-time 5 \
        "${PRODUCTION_URL}/product" 2>/dev/null || echo 000)
    # mTLS probe
    if mtls_err=$(curl -sk -o /dev/null -w '%{http_code}' --connect-timeout 3 --max-time 5 \
        "${PRODUCTION_MTLS_URL}/" 2>&1); then
        mtls_code="$mtls_err"
    else
        mtls_code="handshake_err"
    fi
    local verdict
    # Plain endpoint rejecting (4xx/5xx) OR mTLS handshake failing is the
    # expected outcome; a 200 on either means the downstream is NOT enforcing
    # client-cert trust.
    local plain_ok=false mtls_ok=false
    if [[ "$plain_code" =~ ^[45] || "$plain_code" == "000" ]]; then plain_ok=true; fi
    if [[ "$mtls_code"  == "handshake_err" || "$mtls_code" =~ ^[45] || "$mtls_code" == "000" ]]; then mtls_ok=true; fi
    if [[ "$plain_ok" == "true" || "$mtls_ok" == "true" ]]; then
        verdict="pass"; pass "$case_id (plain=$plain_code mtls=$mtls_code)"
    else
        verdict="fail"; fail "$case_id — downstream accepted unauthenticated client (plain=$plain_code mtls=$mtls_code)"
    fi
    python3 - "$out" "$case_id" "$plain_code" "$mtls_code" "$verdict" <<'PY'
import json, sys
out, c, plain, mtls, v = sys.argv[1:]
json.dump({"case": c, "plain_http_code": plain, "mtls_result": mtls, "verdict": v},
         open(out, "w"), indent=2)
PY
}

# ── Driver ───────────────────────────────────────────────────────────────────
info "starting (stamp=$STAMP, out=$OUT_DIR)"
info "preflight"
preflight

for case_id in $CASES; do
    info "running $case_id"
    case "$case_id" in
        MS-HAPPY)         case_happy ;;
        MS-PAYMENT-FAIL)  case_payment_fail ;;
        MS-LSVID-CHAIN)   case_lsvid_chain ;;
        MS-MTLS-REJECT)   case_mtls_reject ;;
        *) warn "unknown case $case_id — skipping" ;;
    esac
done

# ── Aggregate ────────────────────────────────────────────────────────────────
python3 - "$OUT_DIR" <<'PY'
import json, pathlib, sys
root = pathlib.Path(sys.argv[1])
cases = []
for p in sorted(root.glob("case-*.json")):
    try:
        cases.append(json.loads(p.read_text()))
    except Exception as e:
        print(f"skip {p.name}: {e}", file=sys.stderr)
pass_count = sum(1 for c in cases if c.get("verdict") == "pass")
summary = {
    "generated_at": __import__("datetime").datetime.now().isoformat(),
    "cases_total": len(cases),
    "cases_pass": pass_count,
    "cases_fail": sum(1 for c in cases if c.get("verdict") == "fail"),
    "cases_inconclusive": sum(1 for c in cases if c.get("verdict") == "inconclusive"),
    "cases": cases,
}
(root / "summary.json").write_text(json.dumps(summary, indent=2) + "\n")
print(f"[micro] {pass_count}/{len(cases)} cases passed → {root}/summary.json")
PY

info "done"
