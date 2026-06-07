#!/usr/bin/env bash
# ============================================================================
# Experiment-4 — Vault-PKI fault-recovery runner (feat/vault-pki).
#
# Same downstream-fault scenario matrix as fault-recovery-{vault,spire,linkerd}.sh,
# run against the Vault-PKI stack; writes <OUT>/recovery_vault-pki.csv.
#
# The identity/transport layer here is Vault-as-CA mTLS: each service gets a
# Vault-issued X.509 with a SPIFFE URI SAN (spiffe://zt.local/<svc>), rendered
# by a per-host vault-agent sidecar to /vault/out/{tls.crt,tls.key,ca.crt};
# RoadRunner serves :8443 with client_auth_type: require_and_verify_client_cert.
# Fault injection targets the SAME downstream SERVICE containers as the other
# stacks (apples-to-apples) — only STACK and the container/host wiring differ.
#
# Prereq:
#   - Full gateway stack up with PERF_METRIC_ENABLED=1 so OrderSaga emits the
#     [perf-saga-step1] / [perf-saga-complete] markers (Sagas/OrderSaga.php).
#   - The 3 service hosts up + mTLS verified (EXPERIMENTS.md §1.4.4).
#   - A smoke order reaching '✅ Saga Step 4' (EXPERIMENTS.md §1.3).
#
# Usage (drive from the gateway host = 10.1.1.209):
#   PERF_METRIC_ENABLED=1 \
#   OUT=artifacts/fault-recovery-vault-pki-$(date +%Y%m%d-%H%M%S) \
#     bash scripts/experiments/fault-recovery-vault-pki.sh
#
# ⚠️ SPLIT-REPO TOPOLOGY: the 3 services live on separate hosts in their own
#    repos and Compose auto-names the containers (no container_name set), so the
#    real names are '<svc>-<svc>-1'. The defaults below match the verified
#    2026-06-07 deploy; override the *_HOST / *_SVC_CONTAINER envs if 'docker ps'
#    on each host disagrees. inject aborts if a target container is not running.
#
# ANALYZER: analyze-fault-recovery.py now accepts any number of stacks — feed
#    this CSV via --vault-pki <path> or the generic --csv vault-pki=<path>
#    (see EXPERIMENTS.md §6.4). The old --spire/--linkerd-only limitation is gone.
# ============================================================================
set -euo pipefail

export STACK="vault-pki"
export COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
OUT="${OUT:-artifacts/fault-recovery-vault-pki-$(date +%Y%m%d-%H%M%S)}"
export OUT

# ── Split-repo topology (verified 2026-06-07; override if docker ps differs) ──
# Worker runs on the gateway host (drive locally); downstream services are
# remote, reached via ssh alias + Compose auto-generated container names.
export WORKER_HOST="${WORKER_HOST:-}"                 # "" = local (gateway)
export WORKER_CONTAINER="${WORKER_CONTAINER:-zt-php-worker}"
export ORDER_HOST="${ORDER_HOST:-zt-order}"
export PROD_HOST="${PROD_HOST:-zt-prod}"
export USER_HOST="${USER_HOST:-zt-user}"
export ORDER_SVC_CONTAINER="${ORDER_SVC_CONTAINER:-order-service-order-service-1}"
export PRODUCTION_SVC_CONTAINER="${PRODUCTION_SVC_CONTAINER:-production-service-production-service-1}"
export USER_SVC_CONTAINER="${USER_SVC_CONTAINER:-user-service-user-service-1}"

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=scripts/experiments/fault-recovery-common.sh
source "$HERE/fault-recovery-common.sh"

record_csv_init "$STACK"

log "STACK=$STACK OUT=$OUT FAULT_DURATIONS='$FAULT_DURATIONS'"
log "worker=$WORKER_CONTAINER (host=${WORKER_HOST:-local})"
log "order=$ORDER_SVC_CONTAINER (host=$ORDER_HOST)"
log "prod=$PRODUCTION_SVC_CONTAINER (host=$PROD_HOST)"
log "user=$USER_SVC_CONTAINER (host=$USER_HOST)"
log "preflight: confirm the above names match 'docker ps' on each host (override *_SVC_CONTAINER / *_HOST if not)"
if [[ "${PERF_METRIC_ENABLED:-0}" != "1" ]]; then
    warn "PERF_METRIC_ENABLED != 1 — OrderSaga will NOT emit [perf-saga-*] markers; recovery_sec will be empty"
fi
wait_health || { err "gateway not healthy — start the full gateway stack first (COMPOSE_PROFILES=zt docker compose up -d)"; exit 1; }

# Scenario matrix (identical to the other stacks), each swept over FAULT_DURATIONS.
#   1 step1-transient     : pause production → Step1 abort (商品資訊查詢失敗), no rollback
#   2 comp-after-recovery : pause user → Step3 fail → RollbackSaga Step 2 (補償)
#   3 full-rollback       : pause order AFTER Step3 → Step4 confirm fail → full rollback
#   4 kill-restart        : kill+start production → vault-agent re-auth + cert re-render on restart
for dur in $FAULT_DURATIONS; do
    measure_recovery "step1-transient"     pause "$PRODUCTION_SVC_CONTAINER" "$dur" pre ""           0
    measure_recovery "comp-after-recovery" pause "$USER_SVC_CONTAINER"       "$dur" pre ""           1
    measure_recovery "full-rollback"       pause "$ORDER_SVC_CONTAINER"      "$dur" mid "Saga Step 3" 1
    measure_recovery "kill-restart"        kill  "$PRODUCTION_SVC_CONTAINER" "$dur" pre ""           0
done

# Business-completeness gate: prove a clean order still completes at the end.
log "final completeness check: one clean order must reach ✅ Saga Step 4"
since_final="$(date -u +%Y-%m-%dT%H:%M:%S)"
final_trace="$(new_trace_id 'final')"
post_order "$final_trace" >/dev/null
if wait_for_worker_log "$since_final" "✅ Saga Step 4" "$PROBE_TIMEOUT"; then
    log "PASS: clean order completed after fault matrix"
else
    err "FAIL: clean order did NOT complete after fault matrix (trace=$final_trace)"
    exit 1
fi

log "done → $CSV_PATH"
log "analyze: python3 scripts/experiments/analyze-fault-recovery.py --vault-pki $CSV_PATH --out <out-dir>"
