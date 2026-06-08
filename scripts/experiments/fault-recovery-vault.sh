#!/usr/bin/env bash
# ============================================================================
# Experiment-4 — Vault-side fault-recovery runner (feat/vault).
#
# Drives the SAME downstream-fault scenario matrix as fault-recovery-spire.sh
# / fault-recovery-linkerd.sh against the Vault stack and writes
# <OUT>/recovery_vault.csv. analyze-fault-recovery.py compares all stacks.
#
# The only difference from the SPIRE runner is the transport/identity layer
# (Vault Agent + AppRole secrets, SPIFFE stripped) — fault injection targets
# the SAME downstream service containers (apples-to-apples), so this just sets
# STACK=vault and the Vault container names.
#
# Prereq: Vault stack up with PERF_METRIC_ENABLED=1 (markers from feat/vault
#         commits 4837559 + e093742) and a smoke order reaching ✅ Saga Step 4.
#
# Usage:
#   OUT=artifacts/fault-recovery-$(date +%Y%m%d-%H%M%S) \
#     bash scripts/experiments/fault-recovery-vault.sh
#
# ⚠️ Container-name drift (goal.md §7): the Vault downstream service containers
# have NO explicit container_name in Services/*/docker-compose.yml, so Compose
# auto-names them (e.g. order_service-order-service-1). ALWAYS run `docker ps`
# and override the *_SVC_CONTAINER envs below to the real names before running.
# The preflight echoes the names it will use; inject aborts if not running.
# ============================================================================
set -euo pipefail

export STACK="vault"
export COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
OUT="${OUT:-artifacts/fault-recovery-vault-$(date +%Y%m%d-%H%M%S)}"
export OUT

# Vault-stack containers (override after checking `docker ps`).
export WORKER_CONTAINER="${WORKER_CONTAINER:-zt-php-worker}"
export PRODUCTION_SVC_CONTAINER="${PRODUCTION_SVC_CONTAINER:-production-service}"
export ORDER_SVC_CONTAINER="${ORDER_SVC_CONTAINER:-order-service}"
export USER_SVC_CONTAINER="${USER_SVC_CONTAINER:-user-service}"

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=scripts/experiments/fault-recovery-common.sh
source "$HERE/fault-recovery-common.sh"

record_csv_init "$STACK"

log "STACK=$STACK OUT=$OUT FAULT_DURATIONS='$FAULT_DURATIONS'"
log "containers: worker=$WORKER_CONTAINER prod=$PRODUCTION_SVC_CONTAINER order=$ORDER_SVC_CONTAINER user=$USER_SVC_CONTAINER"
log "preflight: confirm the above container names match 'docker ps' (Vault names auto-generated — override *_SVC_CONTAINER if mismatch)"
wait_health || { err "gateway not healthy — start the Vault stack first"; exit 1; }

# Scenario matrix (goal.md §故障場景矩陣), each swept over FAULT_DURATIONS.
#   1 step1-transient : pause production → Step1 abort (商品資訊查詢失敗)
#   2 comp-after-recovery : pause user → Step3 fail → RollbackSaga Step 2
#   3 full-rollback : pause order AFTER Step3 → Step4 confirm fail → rollback
#   4 kill-restart : kill+start production (sidecar/secret re-fetch on restart)
for dur in $FAULT_DURATIONS; do
    measure_recovery "step1-transient"     pause "$PRODUCTION_SVC_CONTAINER" "$dur" pre ""           0
    measure_recovery "comp-after-recovery" pause "$USER_SVC_CONTAINER"       "$dur" pre ""           1
    measure_recovery "full-rollback"       pause "$ORDER_SVC_CONTAINER"      "$dur" mid "Saga Step 3" 1
    measure_recovery "kill-restart"        kill  "$PRODUCTION_SVC_CONTAINER" "$dur" pre ""           0
done

# F9/F10 — Vault identity-layer scenarios (thesis ch4 §身份層容錯).
#   F9  identity-agent-pause   : pause worker-side vault-agent → cached secrets
#                                 must keep saga running (NIST SP 800-207 §7.3)
#   F10 identity-server-restart: kill+start Vault server → agent-side cache must
#                                 survive upstream outage (degraded mode)
VAULT_AGENT_WORKER_CONTAINER="${VAULT_AGENT_WORKER_CONTAINER:-zt-vault-agent-worker}"
VAULT_SERVER_CONTAINER="${VAULT_SERVER_CONTAINER:-zt-vault}"
measure_recovery "identity-agent-pause"    pause "$VAULT_AGENT_WORKER_CONTAINER" 30 pre "" 0
measure_recovery "identity-server-restart" kill  "$VAULT_SERVER_CONTAINER"       30 pre "" 0

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
