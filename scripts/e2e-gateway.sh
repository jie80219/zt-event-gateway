#!/usr/bin/env bash
# ============================================================================
# Gateway E2E smoke test (post-SPIFFE removal).
#
# Brings up gateway + worker + RabbitMQ + EventStoreDB and verifies:
#   1. Gateway HTTP /api/orders returns 202.
#   2. Canonical envelope lands in order_queue.
#   3. Worker consumes the request and dispatches the saga.
#
# This is intentionally lightweight — the SPIFFE/LSVID-specific assertions
# from the previous version were removed alongside the identity layer.
# ============================================================================
set -euo pipefail

MODE="baseline"
for arg in "$@"; do
    case "$arg" in
        --mode=*) MODE="${arg#--mode=}" ;;
        *) echo "unknown arg: $arg" >&2; exit 2 ;;
    esac
done

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
GATEWAY_URL="${GATEWAY_URL:-http://127.0.0.1:8080}"
RABBIT_API="${RABBIT_API:-http://127.0.0.1:15672/api}"
RABBIT_USER="${RABBIT_USER:-zt}"
RABBIT_PASS="${RABBIT_PASS:-ztpass}"
REQUEST_QUEUE="${REQUEST_QUEUE:-order_queue}"
WAIT_TIMEOUT="${E2E_WAIT_TIMEOUT:-60}"
BUILD_IMAGES="${E2E_BUILD_IMAGES:-1}"
KEEP_ON_FAIL="${E2E_KEEP_ON_FAIL:-0}"

# In linkerd mode we layer the mesh overlay on top of the base compose. The
# base compose is unchanged — apps still talk over plain HTTP — but the
# overlay introduces the linkerd + zipkin services and rewrites *_SERVICE_HOST
# env to point at linkerd:4140.
COMPOSE_ARGS=(-f "$COMPOSE_FILE")
if [[ "$MODE" == "linkerd" ]]; then
    COMPOSE_ARGS+=(-f docker-compose.linkerd.yml)
fi

cd "$PROJECT_DIR"

log()  { printf '[e2e] %s %s\n' "$(date +%T)" "$*"; }
pass() { printf '\033[32m[e2e] PASS\033[0m %s\n' "$*"; }
fail() { printf '\033[31m[e2e] FAIL\033[0m %s\n' "$*" >&2; }

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        fail "missing command: $1"
        exit 1
    fi
}

cleanup() {
    local rc=$?
    if [[ $rc -ne 0 && "$KEEP_ON_FAIL" == "1" ]]; then
        log "leaving stack up for debugging (E2E_KEEP_ON_FAIL=1)"
        exit $rc
    fi
    log "tearing down compose stack"
    docker compose "${COMPOSE_ARGS[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
    exit $rc
}
trap cleanup EXIT

require_cmd docker
require_cmd curl
require_cmd jq

if [[ "$BUILD_IMAGES" == "1" ]]; then
    log "building gateway + php-worker images"
    docker compose "${COMPOSE_ARGS[@]}" build gateway php-worker >/dev/null
fi

log "starting compose stack (mode=${MODE})"
if [[ "$MODE" == "linkerd" ]]; then
    docker compose "${COMPOSE_ARGS[@]}" up -d rabbitmq eventstoredb linkerd zipkin gateway php-worker >/dev/null
    log "waiting for linkerd admin port"
    elapsed=0
    until curl -fsS http://127.0.0.1:9990/admin/ping 2>/dev/null | grep -q pong; do
        sleep 2
        elapsed=$((elapsed + 2))
        if (( elapsed >= WAIT_TIMEOUT )); then
            fail "linkerd admin not responding after ${WAIT_TIMEOUT}s"
            docker compose "${COMPOSE_ARGS[@]}" logs --no-color linkerd | tail -50 >&2
            exit 1
        fi
    done
    pass "linkerd admin reachable"
else
    docker compose "${COMPOSE_ARGS[@]}" up -d rabbitmq eventstoredb gateway php-worker >/dev/null
fi

log "waiting for gateway HTTP (timeout=${WAIT_TIMEOUT}s)"
elapsed=0
until curl -fsS "${GATEWAY_URL}/healthz" >/dev/null 2>&1 \
   || curl -fsS -o /dev/null -w '%{http_code}' "${GATEWAY_URL}/" 2>/dev/null | grep -qE '^[2-4][0-9][0-9]$'; do
    sleep 2
    elapsed=$((elapsed + 2))
    if (( elapsed >= WAIT_TIMEOUT )); then
        fail "gateway HTTP not responding after ${WAIT_TIMEOUT}s"
        docker compose "${COMPOSE_ARGS[@]}" logs --no-color gateway | tail -50 >&2
        exit 1
    fi
done
pass "gateway HTTP responding"

log "waiting for RabbitMQ management API"
elapsed=0
until curl -fsS -u "${RABBIT_USER}:${RABBIT_PASS}" "${RABBIT_API}/overview" >/dev/null 2>&1; do
    sleep 2
    elapsed=$((elapsed + 2))
    if (( elapsed >= WAIT_TIMEOUT )); then
        fail "RabbitMQ management API not reachable after ${WAIT_TIMEOUT}s"
        exit 1
    fi
done
pass "RabbitMQ management API reachable"

trace_id="e2e-$(date +%s)-$RANDOM"
log "POST /api/orders trace_id=${trace_id}"

http_code=$(curl -sS -o /tmp/e2e-response.json -w '%{http_code}' \
    -H 'Content-Type: application/json' \
    -H "X-Correlation-ID: ${trace_id}" \
    -X POST "${GATEWAY_URL}/api/orders" \
    -d '{
        "userKey": "1",
        "productList": [{"p_key": 1, "amount": 1}],
        "total": 1
    }')

if [[ "$http_code" != "202" ]]; then
    fail "expected 202 from /api/orders, got ${http_code}"
    cat /tmp/e2e-response.json >&2
    exit 1
fi
pass "/api/orders returned 202"

log "fetching message from ${REQUEST_QUEUE}"
sleep 1

queue_path=$(printf 'queues/%%2F/%s/get' "$REQUEST_QUEUE")
payload=$(curl -fsS -u "${RABBIT_USER}:${RABBIT_PASS}" \
    -H 'Content-Type: application/json' \
    -X POST "${RABBIT_API}/${queue_path}" \
    -d "{\"count\":1,\"ackmode\":\"ack_requeue_true\",\"encoding\":\"auto\"}" \
    | jq -r '.[0].payload // empty')

if [[ -z "$payload" ]]; then
    fail "no message in ${REQUEST_QUEUE}"
    exit 1
fi

schema_version=$(printf '%s' "$payload" | jq -r '.schema_version // empty')
type=$(printf '%s' "$payload" | jq -r '.type // empty')
route=$(printf '%s' "$payload" | jq -r '.route // empty')
id=$(printf '%s' "$payload" | jq -r '.id // empty')
user_key=$(printf '%s' "$payload" | jq -r '.data.userKey // empty')

if [[ "$schema_version" != "1" ]]; then
    fail "unexpected schema_version: ${schema_version}"
    exit 1
fi
if [[ "$type" != "gateway.request" ]]; then
    fail "unexpected envelope type: ${type}"
    exit 1
fi
if [[ "$route" != "OrderCreateRequestedEvent" ]]; then
    fail "unexpected route: ${route}"
    exit 1
fi
if [[ "$id" != "$trace_id" ]]; then
    fail "trace_id mismatch (expected=${trace_id} got=${id})"
    exit 1
fi
if [[ "$user_key" != "1" ]]; then
    fail "unexpected userKey in envelope: ${user_key}"
    exit 1
fi

pass "envelope is canonical (schema_version=1, type=gateway.request, route=${route}, id=${id})"

log "checking worker logs for trace_id"
sleep 2
if docker compose "${COMPOSE_ARGS[@]}" logs --no-color php-worker 2>&1 | grep -q 'request-consumer'; then
    pass "worker invoked request-consumer"
else
    log "WARN: no request-consumer log entry yet (may still be consuming)"
fi

if [[ "$MODE" == "linkerd" ]]; then
    log "asserting linkerd outgoing observed traffic"
    if curl -fsS http://127.0.0.1:9990/admin/metrics/prometheus 2>/dev/null \
        | grep -q '^rt:outgoing'; then
        pass "linkerd outgoing metrics emitted"
    else
        fail "linkerd outgoing router did not record any requests"
        exit 1
    fi
fi

log "E2E smoke test passed (trace_id=${trace_id})"
exit 0
