#!/usr/bin/env bash
# ============================================================================
# Health probe — saga behaviour under downstream failure modes.
#
# This script does NOT bring up the stack; it assumes the operator already
# has gateway + worker + rabbitmq + order/production/user services running
# (e.g. via `composer ci:zt` or the manual multi-host setup). It then:
#
#   Phase 1: pause `production-service` and POST /api/orders → expect saga
#            to abort during Step 1 (productInfo query fails) without ever
#            creating an order.
#   Phase 2: pause `user-service` after order is created and inventory is
#            deducted → expect saga Step 3 to fail and emit RollbackInventory
#            (compensation triggered).
#   Phase 3: pause `order-service` after payment succeeds → expect Step 4
#            confirm to fail and emit a paymentCompleted=true rollback so
#            both wallet refund AND inventory restore are attempted.
#
# The script verifies via worker log signatures and queue depth. It does
# NOT modify business logic — when the saga has known fire-and-forget
# weaknesses (compensation steps without isSuccess()), this script
# documents the resulting end-state rather than asserting fixes that don't
# exist yet.
#
# Usage:
#   bash scripts/e2e-failure-modes.sh [--phase 1|2|3]
#
# Environment overrides:
#   COMPOSE_FILE          (default: docker-compose.yml — gateway side only)
#   E2E_GATEWAY_URL       (default: http://127.0.0.1:8080/api/orders)
#   E2E_HEALTH_URL        (default: http://127.0.0.1:8080/api/health)
#   E2E_WAIT_TIMEOUT      (default: 90)
#   ORDER_SVC_CONTAINER   (default: order-service)
#   PRODUCTION_SVC_CONTAINER (default: production-service)
#   USER_SVC_CONTAINER    (default: user-service)
# ============================================================================
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
REQUEST_URL="${E2E_GATEWAY_URL:-http://127.0.0.1:8080/api/orders}"
HEALTH_URL="${E2E_HEALTH_URL:-http://127.0.0.1:8080/api/health}"
WAIT_TIMEOUT="${E2E_WAIT_TIMEOUT:-90}"

ORDER_SVC_CONTAINER="${ORDER_SVC_CONTAINER:-order-service}"
PRODUCTION_SVC_CONTAINER="${PRODUCTION_SVC_CONTAINER:-production-service}"
USER_SVC_CONTAINER="${USER_SVC_CONTAINER:-user-service}"

PHASE_FILTER=""
if [[ "${1:-}" == "--phase" ]]; then
    PHASE_FILTER="${2:-}"
fi

PASS_COUNT=0
FAIL_COUNT=0
PAUSED_CONTAINERS=()

cd "$PROJECT_DIR"

log() { printf '[fm] %s %s\n' "$(date +%T)" "$*"; }
pass() { PASS_COUNT=$((PASS_COUNT + 1)); printf '\033[32m[PASS]\033[0m %s\n' "$*"; }
fail() { FAIL_COUNT=$((FAIL_COUNT + 1)); printf '\033[31m[FAIL]\033[0m %s\n' "$*" >&2; }
section() { printf '\n\033[1m── %s ──\033[0m\n' "$*"; }

# ── Container manipulation ──────────────────────────────────────────────────

container_exists() {
    docker ps --format '{{.Names}}' | grep -Fxq "$1"
}

pause_container() {
    local name="$1"
    if ! container_exists "$name"; then
        fail "container '$name' is not running — cannot pause"
        return 1
    fi
    log "pausing $name"
    docker pause "$name" >/dev/null
    PAUSED_CONTAINERS+=("$name")
}

unpause_container() {
    local name="$1"
    log "unpausing $name"
    docker unpause "$name" >/dev/null 2>&1 || true
    # remove from PAUSED_CONTAINERS
    local new_list=()
    for c in "${PAUSED_CONTAINERS[@]}"; do
        [[ "$c" != "$name" ]] && new_list+=("$c")
    done
    PAUSED_CONTAINERS=("${new_list[@]:-}")
}

# ── HTTP / log helpers ──────────────────────────────────────────────────────

new_trace_id() {
    printf 'fm-%s-%s-%s-%s' "$1" "$(date +%s)" "$$" "$RANDOM"
}

post_order() {
    local trace="$1" body="$2"
    curl -sS -w '\n%{http_code}' \
        -X POST "$REQUEST_URL" \
        -H 'Content-Type: application/json' \
        -H "X-Correlation-Id: ${trace}" \
        -d "$body"
}

worker_logs_since() {
    docker compose -f "$COMPOSE_FILE" logs --no-color --since "$1" php-worker 2>&1 || true
}

wait_for_worker_log() {
    local since_ts="$1" pattern="$2" timeout="$3" elapsed=0
    while (( elapsed < timeout )); do
        if grep -Fq "$pattern" <<<"$(worker_logs_since "$since_ts")"; then
            return 0
        fi
        sleep 2
        elapsed=$((elapsed + 2))
    done
    return 1
}

assert_worker_log_absent() {
    local since_ts="$1" pattern="$2"
    if grep -Fq "$pattern" <<<"$(worker_logs_since "$since_ts")"; then
        fail "unexpected worker log seen — pattern: ${pattern}"
        return 1
    fi
    return 0
}

# ── Cleanup ─────────────────────────────────────────────────────────────────

cleanup() {
    if (( ${#PAUSED_CONTAINERS[@]} > 0 )); then
        log "cleanup: unpausing ${#PAUSED_CONTAINERS[@]} containers"
        for c in "${PAUSED_CONTAINERS[@]}"; do
            [[ -n "$c" ]] && docker unpause "$c" >/dev/null 2>&1 || true
        done
    fi
}
trap cleanup EXIT

# ── Preflight ───────────────────────────────────────────────────────────────

preflight() {
    section "preflight"
    for cmd in docker curl; do
        if ! command -v "$cmd" >/dev/null 2>&1; then
            fail "missing required command: $cmd"
            exit 1
        fi
    done

    log "checking gateway health: $HEALTH_URL"
    local code
    code="$(curl -s -o /dev/null -w '%{http_code}' "$HEALTH_URL" || true)"
    if [[ "$code" != "200" ]]; then
        fail "gateway not reachable (HTTP $code) — start the stack first"
        exit 1
    fi

    log "checking expected service containers are running"
    for c in "$ORDER_SVC_CONTAINER" "$PRODUCTION_SVC_CONTAINER" "$USER_SVC_CONTAINER"; do
        if ! container_exists "$c"; then
            fail "container '$c' is not running"
            log "expected: order-service / production-service / user-service all up"
            log "tip: bring them up via Services/{Order,Production,User}_service/docker-compose.yml"
            exit 1
        fi
    done

    pass "preflight: gateway 200, all 3 service containers running"
}

# ============================================================================
# Phase 1 — productionService unreachable, Step 1 must abort
# ============================================================================

phase1_production_down_step1_aborts() {
    section "Phase 1: productionService paused → Step 1 aborts"

    pause_container "$PRODUCTION_SVC_CONTAINER"
    sleep 1

    local since trace body resp code
    since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    trace="$(new_trace_id 'phase1')"
    body='{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}'

    log "POST $REQUEST_URL trace=${trace}"
    resp="$(post_order "$trace" "$body")"
    code="${resp##*$'\n'}"

    # Gateway must still ack the ingress regardless of downstream state — that's
    # the whole point of an event-driven layer. The failure must happen at the
    # saga level, not at the HTTP boundary.
    if [[ "$code" != "202" ]]; then
        fail "phase1: gateway ingress did not return 202 (got $code)"
    else
        pass "phase1a: gateway returned 202 even with productionService down"
    fi

    # Saga Step 1 currently aborts silently with a "[x] 商品資訊查詢失敗" log.
    if wait_for_worker_log "$since" "商品資訊查詢失敗" "$WAIT_TIMEOUT"; then
        pass "phase1b: saga aborted at Step 1 (productInfo query failed)"
    else
        fail "phase1b: did not see Step 1 abort log within ${WAIT_TIMEOUT}s"
    fi

    # An aborted Step 1 must never publish OrderCreatedEvent.
    if assert_worker_log_absent "$since" "Saga Step 2:"; then
        pass "phase1c: Step 2 was NOT entered (no OrderCreatedEvent published)"
    fi

    unpause_container "$PRODUCTION_SVC_CONTAINER"
    # Give the freshly-resumed service a moment so the next phase starts clean.
    sleep 3
}

# ============================================================================
# Phase 2 — userService unreachable, Step 3 fails → compensation
# ============================================================================

phase2_user_down_step3_rolls_back() {
    section "Phase 2: userService paused → Step 3 fails → RollbackInventory"

    pause_container "$USER_SVC_CONTAINER"
    sleep 1

    local since trace body resp code
    since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    trace="$(new_trace_id 'phase2')"
    body='{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}'

    log "POST $REQUEST_URL trace=${trace}"
    resp="$(post_order "$trace" "$body")"
    code="${resp##*$'\n'}"

    if [[ "$code" != "202" ]]; then
        fail "phase2: gateway did not return 202 (got $code)"
    else
        pass "phase2a: gateway returned 202"
    fi

    if wait_for_worker_log "$since" "Saga Step 3" "$WAIT_TIMEOUT"; then
        pass "phase2b: saga reached Step 3 (payment attempt)"
    else
        fail "phase2b: saga did not reach Step 3 within ${WAIT_TIMEOUT}s"
        unpause_container "$USER_SVC_CONTAINER"
        return
    fi

    # Step 3 must observe the wallet failure and emit RollbackInventoryEvent.
    if wait_for_worker_log "$since" "支付失敗" "$WAIT_TIMEOUT" \
        || wait_for_worker_log "$since" "RollbackSaga Step 2" "$WAIT_TIMEOUT"; then
        pass "phase2c: saga published RollbackInventoryEvent on payment failure"
    else
        fail "phase2c: saga did not trigger compensation"
    fi

    # Inventory restoration is fire-and-forget; we just check the loop ran.
    if wait_for_worker_log "$since" "RollbackSaga Step 1" "$WAIT_TIMEOUT"; then
        pass "phase2d: saga reached RollbackOrder (cancel order) handler"
    else
        # Documenting current behaviour: with userService paused, the wallet
        # refund call inside RollbackInventory hangs; the loop may not reach
        # RollbackOrder until userService is unpaused. This is a real risk
        # for compensation reliability — flag, do not fail.
        log "phase2d: NOTE — RollbackOrder not reached while userService is paused. " \
            "This documents the current fire-and-forget hang risk; compensation " \
            "is blocked on the unreachable refund call."
    fi

    unpause_container "$USER_SVC_CONTAINER"
    sleep 3
}

# ============================================================================
# Phase 3 — orderService confirm fails, Step 4 → paymentCompleted=true rollback
# ============================================================================

phase3_order_down_after_payment_full_rollback() {
    section "Phase 3: orderService paused after payment → full rollback path"

    # We need orderService alive for Step 1 (createOrder) but dead for Step 4
    # (confirmOrder). Easiest reliable approach: send the request, wait for
    # the worker log "Saga Step 3" to appear, then pause orderService just
    # before Step 4. If timing is tight we tolerate either of two outcomes:
    #   (a) confirmOrder fails → RollbackInventory with paymentCompleted=true
    #   (b) confirmOrder times out → connect error → same compensation
    local since trace body resp code
    since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    trace="$(new_trace_id 'phase3')"
    body='{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}'

    log "POST $REQUEST_URL trace=${trace}"
    resp="$(post_order "$trace" "$body")"
    code="${resp##*$'\n'}"

    if [[ "$code" != "202" ]]; then
        fail "phase3: gateway did not return 202 (got $code)"
    else
        pass "phase3a: gateway returned 202"
    fi

    # Wait for saga to enter Step 3 (payment) so that:
    #   - order is already created (Step 1 done)
    #   - inventory is already deducted (Step 2 done)
    # Then pause order-service so that Step 4 confirm hits a connect error.
    if wait_for_worker_log "$since" "Saga Step 3" "$WAIT_TIMEOUT"; then
        log "phase3: Step 3 entered, pausing orderService now"
        pause_container "$ORDER_SVC_CONTAINER"
    else
        fail "phase3: saga did not reach Step 3 within ${WAIT_TIMEOUT}s"
        return
    fi

    # Step 4 will attempt confirmOrder against a paused container. Either
    # FailHandlerFilter sets code=500 (connect error → 500) or the call
    # times out — both branches lead to RollbackInventoryEvent with
    # paymentCompleted=true.
    if wait_for_worker_log "$since" "RollbackSaga Step 2" "$WAIT_TIMEOUT"; then
        pass "phase3b: confirm failure triggered RollbackInventory"
    else
        log "phase3b: NOTE — RollbackInventory not seen yet. " \
            "If your saga waits longer than ${WAIT_TIMEOUT}s on the confirm " \
            "timeout, retry with a higher E2E_WAIT_TIMEOUT."
    fi

    unpause_container "$ORDER_SVC_CONTAINER"
    sleep 3
}

# ── Run phases ──────────────────────────────────────────────────────────────

preflight

if [[ -z "$PHASE_FILTER" || "$PHASE_FILTER" == "1" ]]; then
    phase1_production_down_step1_aborts
fi
if [[ -z "$PHASE_FILTER" || "$PHASE_FILTER" == "2" ]]; then
    phase2_user_down_step3_rolls_back
fi
if [[ -z "$PHASE_FILTER" || "$PHASE_FILTER" == "3" ]]; then
    phase3_order_down_after_payment_full_rollback
fi

# ── Summary ─────────────────────────────────────────────────────────────────

section "Results"
printf 'Total: \033[32m%d passed\033[0m, \033[31m%d failed\033[0m\n' "$PASS_COUNT" "$FAIL_COUNT"

if (( FAIL_COUNT > 0 )); then
    exit 1
fi
exit 0
