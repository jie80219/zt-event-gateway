#!/usr/bin/env bash
# ============================================================================
# Experiment-4 — Linkerd 1.x-side fault-recovery runner.
#
# BELONGS ON branch feat/Linkerd1. Built on feat/spiffe-keycloak alongside
# fault-recovery-common.sh, then cherry-picked to feat/Linkerd1 (see
# docs/goal/goal2.md §9). Identical scenario matrix to fault-recovery-spire.sh
# — same downstream containers, same fault types — so the ONLY difference is
# the transport layer (Linkerd sidecar @:4140 vs SPIRE mTLS/LSVID). Writes
# <OUT>/recovery_linkerd.csv.
#
# Prereq: Linkerd stack up with PERF_METRIC_ENABLED=1, e.g.
#   docker compose -f docker-compose.yml -f docker-compose.linkerd.yml up -d
# and the SAME frozen saga markers present (fairness gate —
#   git show feat/Linkerd1:Sagas/OrderSaga.php | grep perf-saga-complete).
#
# Usage:
#   OUT=artifacts/fault-recovery-$(date +%Y%m%d-%H%M%S) \
#     bash scripts/experiments/fault-recovery-linkerd.sh
# ============================================================================
set -euo pipefail

export STACK="linkerd"
# Linkerd overlays the base compose; runner uses `docker logs <container>`
# directly so the multi-file compose string is informational only.
export COMPOSE_FILE="${COMPOSE_FILE:--f docker-compose.yml -f docker-compose.linkerd.yml}"
OUT="${OUT:-artifacts/fault-recovery-linkerd-$(date +%Y%m%d-%H%M%S)}"
export OUT

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=scripts/experiments/fault-recovery-common.sh
source "$HERE/fault-recovery-common.sh"

record_csv_init "$STACK"

log "STACK=$STACK OUT=$OUT FAULT_DURATIONS='$FAULT_DURATIONS'"
log "containers: worker=$WORKER_CONTAINER prod=$PRODUCTION_SVC_CONTAINER order=$ORDER_SVC_CONTAINER user=$USER_SVC_CONTAINER"
log "preflight: confirm the above container names match 'docker ps'"
wait_health || { err "gateway not healthy — start the Linkerd stack first"; exit 1; }

for dur in $FAULT_DURATIONS; do
    measure_recovery "step1-transient"     pause "$PRODUCTION_SVC_CONTAINER" "$dur" pre ""           0
    measure_recovery "comp-after-recovery" pause "$USER_SVC_CONTAINER"       "$dur" pre ""           1
    measure_recovery "full-rollback"       pause "$ORDER_SVC_CONTAINER"      "$dur" mid "Saga Step 3" 1
    measure_recovery "kill-restart"        kill  "$PRODUCTION_SVC_CONTAINER" "$dur" pre ""           0
done

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
