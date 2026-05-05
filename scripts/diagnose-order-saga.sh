#!/usr/bin/env bash
# =============================================================================
# Diagnose Order Saga
#
# Send one order through the gateway and pinpoint exactly where the saga
# stalls: every queue's depth is snapshotted before, during, and after the
# test, and the worker log is tailed in parallel. The final report shows
# which event made it through, which didn't, and the most likely culprit.
#
# Prereqs (all must already be running):
#   - docker compose stack up (gateway, php-worker, rabbitmq)
#   - order-service on $ORDER_URL, production-service on $PRODUCTION_URL,
#     user-service on $USER_URL (used by the saga's HTTP calls)
#
# Usage:
#   bash scripts/diagnose-order-saga.sh
#   bash scripts/diagnose-order-saga.sh --watch-seconds 60
#
# Env overrides:
#   GATEWAY_URL         default: http://127.0.0.1:8080/api/orders
#   HEALTH_URL          default: http://127.0.0.1:8080/api/health
#   RABBIT_API          default: http://127.0.0.1:15672/api
#   RABBIT_USER         default: zt
#   RABBIT_PASS         default: ztpass
#   ORDER_URL           default: http://127.0.0.1:8082
#   PRODUCTION_URL      default: http://127.0.0.1:8083
#   USER_URL            default: http://127.0.0.1:8084
#   WORKER_CONTAINER    default: zt-php-worker
#   WATCH_SECONDS       default: 30
# =============================================================================
set -uo pipefail  # intentionally NOT -e: many greps/counts return 1 when
                  # the saga is still in flight; we handle exit codes locally

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

# ── Config ───────────────────────────────────────────────────────────────────
GATEWAY_URL="${GATEWAY_URL:-http://127.0.0.1:8080/api/orders}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1:8080/api/health}"
RABBIT_API="${RABBIT_API:-http://127.0.0.1:15672/api}"
RABBIT_USER="${RABBIT_USER:-zt}"
RABBIT_PASS="${RABBIT_PASS:-ztpass}"
ORDER_URL="${ORDER_URL:-http://127.0.0.1:8082}"
PRODUCTION_URL="${PRODUCTION_URL:-http://127.0.0.1:8083}"
USER_URL="${USER_URL:-http://127.0.0.1:8084}"
WORKER_CONTAINER="${WORKER_CONTAINER:-zt-php-worker}"
WATCH_SECONDS="${WATCH_SECONDS:-30}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --watch-seconds) WATCH_SECONDS="$2"; shift 2 ;;
        -h|--help) grep -E '^# ' "$0" | sed 's/^# //'; exit 0 ;;
        *) echo "Unknown flag: $1" >&2; exit 2 ;;
    esac
done

# Saga queues in execution order (empty slot = stall point)
QUEUES=(
    order_queue
    OrderCreateRequestedEvent
    OrderCreatedEvent
    InventoryDeductedEvent
    PaymentProcessedEvent
    OrderSagaCompletedEvent
)
COMPENSATION_QUEUES=(
    RollbackInventoryEvent
    RollbackOrderEvent
)

# Saga step → log marker pattern to match in worker logs
declare -a STEP_NAMES=(
    "Step 1: OrderCreateRequested handler"
    "Step 2: Order created → deduct inventory"
    "Step 3: Inventory deducted → charge wallet"
    "Step 4: Payment processed → confirm order"
    "Saga completed"
)

# ── Colors ───────────────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
    RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'
    CYAN=$'\033[0;36m'; BOLD=$'\033[1m'; NC=$'\033[0m'
else
    RED=""; GREEN=""; YELLOW=""; CYAN=""; BOLD=""; NC=""
fi

section() { printf "\n${BOLD}${CYAN}── %s ──${NC}\n" "$*"; }
info()    { printf "${CYAN}ℹ${NC}  %s\n" "$*"; }
ok()      { printf "${GREEN}✓${NC}  %s\n" "$*"; }
warn()    { printf "${YELLOW}⚠${NC}  %s\n" "$*"; }
err()     { printf "${RED}✗${NC}  %s\n" "$*" >&2; }

require_cmd() {
    for cmd in "$@"; do
        command -v "$cmd" >/dev/null 2>&1 || { err "missing command: $cmd"; exit 2; }
    done
}

# ── Temp files ───────────────────────────────────────────────────────────────
TMPDIR="$(mktemp -d)"
trap 'rm -rf "$TMPDIR" 2>/dev/null; [[ -n "${LOG_PID:-}" ]] && kill "$LOG_PID" 2>/dev/null || true' EXIT

# ── RabbitMQ helpers ─────────────────────────────────────────────────────────
rabbit_queue_state() {
    # $1 queue name → prints "ready unacked total" (0 0 0 on error)
    local q="$1"
    local json
    json=$(curl -sS -u "${RABBIT_USER}:${RABBIT_PASS}" \
        "${RABBIT_API}/queues/%2F/${q}" 2>/dev/null || echo '{}')
    local ready unacked total
    ready=$(echo "$json" | jq -r '.messages_ready // 0' 2>/dev/null || echo 0)
    unacked=$(echo "$json" | jq -r '.messages_unacknowledged // 0' 2>/dev/null || echo 0)
    total=$(echo "$json" | jq -r '.messages // 0' 2>/dev/null || echo 0)
    printf "%s %s %s" "$ready" "$unacked" "$total"
}

# Print all queue states as a table. First arg: label prefix.
snapshot_queues() {
    local label="$1"
    printf "  ${BOLD}%-30s %6s %8s %6s${NC}\n" "queue" "ready" "unacked" "total"
    for q in "${QUEUES[@]}" "${COMPENSATION_QUEUES[@]}"; do
        read -r ready unacked total <<<"$(rabbit_queue_state "$q")"
        local marker=""
        [[ "$unacked" != "0" ]] && marker="  ${YELLOW}← unacked${NC}"
        [[ "$ready" != "0" && "$unacked" == "0" ]] && marker="  ${YELLOW}← stuck in ready${NC}"
        printf "  %-30s %6s %8s %6s%s\n" "$q" "$ready" "$unacked" "$total" "$marker"
    done
}

# ── Pre-flight ───────────────────────────────────────────────────────────────
require_cmd curl jq docker

section "Pre-flight"

check_http() {
    local label="$1" url="$2" expect_2xx="${3:-0}"
    local code
    code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 3 "$url" 2>/dev/null || echo 000)
    if [[ "$expect_2xx" == "1" ]]; then
        [[ "$code" =~ ^2 ]] && ok "$label → HTTP $code ($url)" || { err "$label → HTTP $code ($url)"; return 1; }
    else
        # Any response (incl. 404) means the service is up
        [[ "$code" != "000" ]] && ok "$label reachable → HTTP $code ($url)" || { err "$label UNREACHABLE ($url)"; return 1; }
    fi
}

PREFLIGHT_OK=true
check_http "Gateway health" "$HEALTH_URL" 1 || PREFLIGHT_OK=false
check_http "RabbitMQ mgmt API" "${RABBIT_API}/overview" || PREFLIGHT_OK=false
check_http "order-service"      "$ORDER_URL"      || PREFLIGHT_OK=false
check_http "production-service" "$PRODUCTION_URL" || PREFLIGHT_OK=false
check_http "user-service"       "$USER_URL"       || PREFLIGHT_OK=false

if docker ps --format '{{.Names}}' | grep -qx "$WORKER_CONTAINER"; then
    ok "Worker container running: $WORKER_CONTAINER"
else
    err "Worker container not running: $WORKER_CONTAINER"
    PREFLIGHT_OK=false
fi

[[ "$PREFLIGHT_OK" == "true" ]] || { err "Pre-flight failed — fix the above before running the saga test."; exit 1; }

# ── Baseline snapshot ────────────────────────────────────────────────────────
section "Baseline queue state"
snapshot_queues "before"

# Start worker log capture from now
info "Capturing worker log to $TMPDIR/worker.log (starting from now)"
docker logs -f --since 0s "$WORKER_CONTAINER" >"$TMPDIR/worker.log" 2>&1 &
LOG_PID=$!
sleep 0.3

# ── Send test order ──────────────────────────────────────────────────────────
section "Sending test order"
TRACE_ID="diag-$(date +%s)-$$"
PAYLOAD=$(cat <<JSON
{
    "user_id": "1",
    "product_list": [
        {"p_key": 1, "amount": 2}
    ]
}
JSON
)

info "Trace ID: $TRACE_ID"
RESP_FILE="$TMPDIR/resp.txt"
HTTP_CODE=$(curl -sS -o "$RESP_FILE" -w '%{http_code}' \
    -X POST "$GATEWAY_URL" \
    -H "Content-Type: application/json" \
    -H "X-Correlation-Id: $TRACE_ID" \
    -d "$PAYLOAD" || echo 000)

if [[ "$HTTP_CODE" =~ ^2 ]]; then
    ok "Gateway accepted: HTTP $HTTP_CODE"
    jq . <"$RESP_FILE" 2>/dev/null || cat "$RESP_FILE"
else
    err "Gateway rejected: HTTP $HTTP_CODE"
    cat "$RESP_FILE"
    exit 1
fi

# ── Watch for progression ────────────────────────────────────────────────────
section "Watching saga progress for ${WATCH_SECONDS}s"

echo ""
# Space-separated lists of events/lines — compatible with bash 3.2 (macOS).
SEEN_HANDLED=""       # space-separated event short-names that were "[event-consumer] handled"
SEEN_PUBLISHED=""     # space-separated event short-names "[request-consumer] published"
HANDLER_ERRORS_COUNT=0
REQUEUE_COUNT=0

START_TS=$(date +%s)
SAGA_DONE=false

# Returns 0 if $1 is present in the space-separated $2
contains() {
    case " $2 " in *" $1 "*) return 0 ;; *) return 1 ;; esac
}

while true; do
    NOW=$(date +%s)
    ELAPSED=$((NOW - START_TS))
    [[ $ELAPSED -ge $WATCH_SECONDS ]] && break

    if [[ -f "$TMPDIR/worker.log" ]]; then
        HANDLED_NOW=$(grep -oE 'handled event=\S+' "$TMPDIR/worker.log" 2>/dev/null \
            | awk -F= '{print $2}' | awk -F'\\\\' '{print $NF}' | sort -u | tr '\n' ' ')
        [[ -n "$HANDLED_NOW" ]] && SEEN_HANDLED="$HANDLED_NOW"

        PUBLISHED_NOW=$(grep -oE 'published event=\S+' "$TMPDIR/worker.log" 2>/dev/null \
            | awk -F= '{print $2}' | awk -F'\\\\' '{print $NF}' | sort -u | tr '\n' ' ')
        [[ -n "$PUBLISHED_NOW" ]] && SEEN_PUBLISHED="$PUBLISHED_NOW"

        HANDLER_ERRORS_COUNT=$(grep -c '\[event-bus\] handler' "$TMPDIR/worker.log" 2>/dev/null | head -1 || true)
        HANDLER_ERRORS_COUNT=${HANDLER_ERRORS_COUNT:-0}
        REQUEUE_COUNT=$(grep -c '\[consumer\] requeue' "$TMPDIR/worker.log" 2>/dev/null | head -1 || true)
        REQUEUE_COUNT=${REQUEUE_COUNT:-0}

        if grep -qE 'Saga 完成|OrderSagaCompletedEvent' "$TMPDIR/worker.log" 2>/dev/null; then
            SAGA_DONE=true
        fi
    fi

    TOTAL=0
    for q in "${QUEUES[@]}" "${COMPENSATION_QUEUES[@]}"; do
        read -r _ _ total <<<"$(rabbit_queue_state "$q")"
        TOTAL=$((TOTAL + total))
    done

    HANDLED_CNT=$(echo $SEEN_HANDLED | wc -w | tr -d ' ')
    PUBLISHED_CNT=$(echo $SEEN_PUBLISHED | wc -w | tr -d ' ')
    printf "  [%02ds] queues_total=%s handled=%s published=%s errors=%s requeue=%s\n" \
        "$ELAPSED" "$TOTAL" "$HANDLED_CNT" "$PUBLISHED_CNT" "$HANDLER_ERRORS_COUNT" "$REQUEUE_COUNT"

    if [[ "$SAGA_DONE" == "true" ]]; then
        ok "Saga reached completion state"
        break
    fi

    sleep 1
done

# ── Final snapshot ───────────────────────────────────────────────────────────
section "Post-test queue state"
snapshot_queues "after"

# ── Diagnosis ────────────────────────────────────────────────────────────────
section "Saga progression"

# The expected chain in order
SAGA_EVENTS=(
    OrderCreateRequestedEvent
    OrderCreatedEvent
    InventoryDeductedEvent
    PaymentProcessedEvent
    OrderSagaCompletedEvent
)

LAST_HANDLED=""
FIRST_MISSED=""
for ev in "${SAGA_EVENTS[@]}"; do
    if contains "$ev" "$SEEN_HANDLED"; then
        ok "$ev handled"
        LAST_HANDLED="$ev"
    else
        [[ -z "$FIRST_MISSED" ]] && FIRST_MISSED="$ev"
        warn "$ev NOT handled"
    fi
done

echo ""
section "Diagnosis"

if [[ "$SAGA_DONE" == "true" ]]; then
    ok "Full saga completed. No stall detected."
    exit 0
fi

# Check rollback side — user may have hit compensation flow
for ev in "${COMPENSATION_QUEUES[@]}"; do
    if contains "$ev" "$SEEN_HANDLED"; then
        warn "Compensation fired: $ev handled → saga rolled back (usually means a downstream call returned non-200)"
    fi
done

if [[ "$HANDLER_ERRORS_COUNT" -gt 0 ]]; then
    err "Saga handlers threw ${HANDLER_ERRORS_COUNT} exception(s) — unique errors:"
    grep '\[event-bus\] handler' "$TMPDIR/worker.log" 2>/dev/null | sort -u | sed 's/^/    /' | tail -10
    echo ""
    info "Likely cause: a downstream HTTP call in the above handler returned an error or threw."
fi

if [[ "$REQUEUE_COUNT" -gt 0 ]]; then
    err "Consumer nack+requeue fired ${REQUEUE_COUNT} time(s) — same message being retried:"
    grep '\[consumer\] requeue' "$TMPDIR/worker.log" 2>/dev/null | sort -u | sed 's/^/    /' | tail -10
fi

# Dropped (unrecoverable) — messages rejected and NOT requeued.
DROPPED_COUNT=$(grep -c '\[consumer\] dropped' "$TMPDIR/worker.log" 2>/dev/null | head -1 || true)
DROPPED_COUNT=${DROPPED_COUNT:-0}
if [[ "$DROPPED_COUNT" -gt 0 ]]; then
    err "Consumer DROPPED ${DROPPED_COUNT} message(s) — envelope was unrecoverable:"
    grep '\[consumer\] dropped' "$TMPDIR/worker.log" 2>/dev/null | sort -u | sed 's/^/    /' | tail -10

fi

# Pinpoint stall
if [[ -n "$FIRST_MISSED" ]]; then
    echo ""
    err "STALL DETECTED: saga stopped before ${FIRST_MISSED}"
    if [[ -z "$LAST_HANDLED" ]]; then
        info "No event was ever handled. Suspects:"
        info "  • Gateway didn't publish → check order_queue (was it 0?)"
        info "  • order_queue bound wrong routing key → check QueueTopology exchange config"
        info "  • Worker not subscribed to order_queue → check bin/worker.php"
    else
        PREV=""
        for ev in "${SAGA_EVENTS[@]}"; do
            if [[ "$ev" == "$FIRST_MISSED" ]]; then
                break
            fi
            PREV="$ev"
        done

        # Is the FIRST_MISSED queue actually filling up?
        read -r ready unacked total <<<"$(rabbit_queue_state "$FIRST_MISSED")"
        if [[ "$total" != "0" ]]; then
            warn "Queue ${FIRST_MISSED} has ${total} message(s) (${ready} ready, ${unacked} unacked)"
            if [[ "$unacked" != "0" ]]; then
                info "Handler is running but can't ack — could be handler deadlock or infinite retry."
                info "Check worker log for [event-bus] errors while handling ${FIRST_MISSED}"
            else
                info "Messages arrived but consumer isn't picking them up."
                info "Likely wiring error: queue ${FIRST_MISSED} missing subscription."
                info "Confirm HandlerScanner discovered ${FIRST_MISSED} and basic_consume subscribed it."
            fi
        else
            info "Queue ${FIRST_MISSED} never received any message."
            info "Previous handler (${PREV:-OrderCreateRequestedEvent}) didn't publish it."
            info "Most likely: that handler's downstream HTTP call failed/threw before publish()."
            info "Relevant downstream URLs to verify manually:"
            case "$PREV" in
                OrderCreateRequestedEvent)
                    info "  • POST ${PRODUCTION_URL}/api/v1/production/{p_key}  (productInfoAction)"
                    info "  • POST ${ORDER_URL}/api/v1/order                    (createOrderAction)"
                    ;;
                OrderCreatedEvent)
                    info "  • POST ${PRODUCTION_URL}/api/v1/production/inventory (reduceInventory, concurrent)"
                    ;;
                InventoryDeductedEvent)
                    info "  • POST ${USER_URL}/api/v1/user/wallet                (walletChargeAction)"
                    ;;
                PaymentProcessedEvent)
                    info "  • PUT ${ORDER_URL}/api/v1/order/{orderId}            (confirmOrderAction)"
                    ;;
            esac
        fi
    fi
fi

# ── Worker log tail ──────────────────────────────────────────────────────────
section "Worker log tail (last 60 lines)"
tail -n 60 "$TMPDIR/worker.log" 2>/dev/null | sed 's/^/  /'

exit 1
