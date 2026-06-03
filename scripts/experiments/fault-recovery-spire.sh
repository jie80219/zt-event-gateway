#!/usr/bin/env bash
# ============================================================================
# Experiment-4 — SPIRE-side fault-recovery runner (feat/spiffe-keycloak).
#
# Drives the downstream-fault scenario matrix against the SPIFFE/SPIRE stack
# and writes <OUT>/recovery_spire.csv. The Linkerd counterpart
# (fault-recovery-linkerd.sh) writes recovery_linkerd.csv with identical
# scenarios; analyze-fault-recovery.py compares them.
#
# Prereq: the stack is up with PERF_METRIC_ENABLED=1 (so saga markers fire)
#         and a smoke order completes (see ✅ Saga Step 4) before running.
#
# Usage:
#   OUT=artifacts/fault-recovery-$(date +%Y%m%d-%H%M%S) \
#     bash scripts/experiments/fault-recovery-spire.sh
#
# Topology: defaults to single-host (all containers local). For the
# distributed rig, export PROD_HOST/ORDER_HOST/USER_HOST ssh aliases and the
# real container names (see fault-recovery-common.sh header). Always confirm
# names with `docker ps` first — preflight echoes them.
# ============================================================================
set -euo pipefail

export STACK="spire"
export COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
OUT="${OUT:-artifacts/fault-recovery-spire-$(date +%Y%m%d-%H%M%S)}"
export OUT

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=scripts/experiments/fault-recovery-common.sh
source "$HERE/fault-recovery-common.sh"

record_csv_init "$STACK"

log "STACK=$STACK OUT=$OUT FAULT_DURATIONS='$FAULT_DURATIONS'"
log "containers: worker=$WORKER_CONTAINER prod=$PRODUCTION_SVC_CONTAINER order=$ORDER_SVC_CONTAINER user=$USER_SVC_CONTAINER"
log "preflight: confirm the above container names match 'docker ps'"
wait_health || { err "gateway not healthy — start the SPIRE stack first"; exit 1; }

# Scenario matrix (goal.md §故障場景矩陣), each swept over FAULT_DURATIONS.
#   1 step1-transient : pause production → Step1 abort (商品資訊查詢失敗)
#   2 comp-after-recovery : pause user → Step3 fail → RollbackSaga Step 2
#   3 full-rollback : pause order AFTER Step3 → Step4 confirm fail → rollback
#   4 kill-restart : kill+start production (sidecar re-resolve vs SVID re-fetch)
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
