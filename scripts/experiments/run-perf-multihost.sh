#!/usr/bin/env bash
# ============================================================================
# Multi-host perf experiment driver — runs the 5k/10k/20k suite TWICE
# against the deployed topology over SSH.
#
# Topology (set via env if different):
#   GATEWAY_ALIAS  ssh alias for 10.1.1.209 (default: zt-gateway)
#   ORDER_ALIAS    ssh alias for 10.1.1.210 (default: zt-order)
#   PROD_ALIAS     ssh alias for 10.1.1.207 (default: zt-prod)
#   USER_ALIAS     ssh alias for 10.1.1.214 (default: zt-user)
#
# Inter-host (gateway → service hosts) seeds use the LAN IPs:
#   PERF_SEED_USER_HOST  default root@10.1.1.214
#   PERF_SEED_PROD_HOST  default root@10.1.1.207
#
# Outputs land at:
#   ./artifacts/<TS>_Experimental/        (rsynced back from gateway)
# Two distinct timestamped dirs are produced (one per pass).
#
# Usage:
#   bash scripts/experiments/run-perf-multihost.sh
#   PERF_SCALES="100 200" bash scripts/experiments/run-perf-multihost.sh   # smoke
#   PASSES=1 bash scripts/experiments/run-perf-multihost.sh                # single pass
# ============================================================================
set -euo pipefail

GATEWAY_ALIAS="${GATEWAY_ALIAS:-zt-gateway}"
ORDER_ALIAS="${ORDER_ALIAS:-zt-order}"
PROD_ALIAS="${PROD_ALIAS:-zt-prod}"
USER_ALIAS="${USER_ALIAS:-zt-user}"

PASSES="${PASSES:-2}"
PERF_SCALES="${PERF_SCALES:-5000 10000 20000}"
PERF_CONCURRENCY="${PERF_CONCURRENCY:-100}"
PERF_DRAIN_SEC="${PERF_DRAIN_SEC:-600}"
PERF_MTLS_PROBE_COUNT="${PERF_MTLS_PROBE_COUNT:-200}"
GATEWAY_REPO_DIR="${GATEWAY_REPO_DIR:-/root/zt-event-gateway}"
LOCAL_REPO_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

# These are passed to run-perf-experiment.sh's seed_dbs to reach the
# Production / User DB hosts from the gateway VM via inter-host SSH.
PERF_SEED_USER_HOST="${PERF_SEED_USER_HOST:-root@10.1.1.214}"
PERF_SEED_PROD_HOST="${PERF_SEED_PROD_HOST:-root@10.1.1.207}"

log() { printf '[multihost] %s %s\n' "$(date +%T)" "$*" >&2; }
fail() { printf '[multihost][FAIL] %s\n' "$*" >&2; exit 1; }

_preflight_one() {
    local alias="$1"
    local expected="$2"
    local running
    running=$(ssh -o BatchMode=yes -o ConnectTimeout=8 "$alias" \
        'docker ps --format "{{.Names}}"' 2>/dev/null) \
        || fail "ssh $alias unreachable"
    local c
    for c in $expected; do
        if ! grep -qw "$c" <<<"$running"; then
            log "  WARN: $alias missing container $c"
        fi
    done
    log "  ✓ $alias OK"
}

preflight() {
    log "preflight: SSH-reachable + container check on all 4 hosts"
    _preflight_one "$GATEWAY_ALIAS" "zt-gateway zt-php-worker zt-rabbitmq"
    _preflight_one "$ORDER_ALIAS"   "order_service-order-service-1 order_service-order_DB-1"
    _preflight_one "$PROD_ALIAS"    "production_service-production-service-1 production_service-production_DB-1"
    _preflight_one "$USER_ALIAS"    "user_service-user-service-1 user_service-user_DB-1"

    log "preflight: gateway → service hosts inter-host SSH"
    ssh "$GATEWAY_ALIAS" "for h in 10.1.1.210 10.1.1.207 10.1.1.214; do ssh -o BatchMode=yes -o ConnectTimeout=5 root@\$h hostname >/dev/null 2>&1 || { echo \"gateway→\$h SSH FAIL\"; exit 1; }; done && echo OK" \
        || fail "gateway cannot SSH to one of the service hosts"

    log "preflight: gateway /api/health"
    ssh "$GATEWAY_ALIAS" 'curl -fsS http://127.0.0.1:8080/api/health >/dev/null && echo OK' \
        || log "  WARN: gateway /api/health not yet healthy — run-perf-experiment.sh's reset_stack will wait"
}

run_pass() {
    local pass="$1"
    local ts
    ts=$(date +%F_%H%M%S)
    local out_rel="artifacts/${ts}_Experimental"
    log "==== pass ${pass}/${PASSES}  ts=${ts}  scales=[${PERF_SCALES}] ===="

    ssh "$GATEWAY_ALIAS" "cd ${GATEWAY_REPO_DIR} && \
        PERF_METRIC_ENABLED=1 \
        PERF_OUT='${out_rel}' \
        PERF_SCALES='${PERF_SCALES}' \
        PERF_CONCURRENCY='${PERF_CONCURRENCY}' \
        PERF_DRAIN_SEC='${PERF_DRAIN_SEC}' \
        PERF_MTLS_PROBE_COUNT='${PERF_MTLS_PROBE_COUNT}' \
        PERF_SEED_USER_HOST='${PERF_SEED_USER_HOST}' \
        PERF_SEED_PROD_HOST='${PERF_SEED_PROD_HOST}' \
        bash scripts/experiments/run-perf-experiment.sh"

    log "pass ${pass}: rsync artifacts back → ${LOCAL_REPO_DIR}/${out_rel}/"
    mkdir -p "${LOCAL_REPO_DIR}/${out_rel}"
    rsync -avz "${GATEWAY_ALIAS}:${GATEWAY_REPO_DIR}/${out_rel}/" "${LOCAL_REPO_DIR}/${out_rel}/"

    # The remote gateway lacks CJK fonts → its PNGs render Chinese as tofu.
    # Re-run the analyzer locally over the rsynced raw/ data so PNGs use the
    # macOS native CJK fonts (Heiti TC / Arial Unicode MS / etc).
    log "pass ${pass}: re-running analyzer locally (CJK fonts available)"
    cd "${LOCAL_REPO_DIR}"
    python3 scripts/experiments/analyze-perf-experiment.py \
        --in "${out_rel}/raw" \
        --out "${out_rel}" \
        --scales "${PERF_SCALES}"

    log "pass ${pass} done → ${LOCAL_REPO_DIR}/${out_rel}/"
    ls -la "${LOCAL_REPO_DIR}/${out_rel}/" >&2
}

preflight
for ((p = 1; p <= PASSES; p++)); do
    run_pass "$p"
    if (( p < PASSES )); then
        log "cooling down 30s between passes…"
        sleep 30
    fi
done

log "ALL PASSES COMPLETE"
