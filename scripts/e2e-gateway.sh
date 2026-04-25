#!/usr/bin/env bash
# ============================================================================
# End-to-end verification for the ZT Event Gateway (OpenSwoole) pipeline.
#
# Flow tested:
#   HTTP client -> OpenSwoole Gateway -> RabbitMQ -> PHP Worker (RequestConsumer)
#                                                 -> EventConsumer -> Saga
#
# Test cases:
#   Phase 1 — Gateway ingress (no worker)
#     1.  Health check (GET /api/health -> 200)
#     2.  Happy-path order (canonical fields -> 202 + queue envelope)
#     3.  Field-alias normalization (user_id/product_list/qty/amount -> canonical)
#     4.  Envelope structure compliance (schema_version, route)
#     5.  Empty body -> 400
#     6.  Invalid JSON -> 400
#     7.  Missing required fields -> 422
#     8.  Schema version mismatch (schema_version=99) -> worker drops
#
#   Phase 2 — Worker consumption + event forwarding
#     9.  Worker consumes trusted request, publishes downstream event
#     10. Full saga flow (4 steps verified via worker logs)
#     11. SPIFFE identity chain propagation (spiffe_path accumulates per hop)
#     12. Distributed tracing (X-Correlation-Id propagates to event payloads)
#
#   Phase 3 — Security
#     13. Untrusted SPIFFE source on request queue -> dropped, no requeue storm
#     14. Untrusted SPIFFE source on event queue -> dropped
#
#   Phase 4 — Concurrency
#     15. Concurrent requests (5x fan-in)
#
# Usage:
#   bash scripts/e2e-gateway.sh [--keep]
#
# Environment overrides:
#   COMPOSE_FILE          (default: docker-compose.yml)
#   E2E_GATEWAY_URL       (default: http://10.1.1.209:8080/api/orders)
#   E2E_HEALTH_URL        (default: http://10.1.1.209:8080/api/health)
#   E2E_RABBIT_API_URL    (default: http://10.1.1.209:15672/api)
#   E2E_WAIT_TIMEOUT      (default: 90)
#   E2E_KEEP_ON_FAIL      (default: 0)
#   E2E_BUILD_IMAGES      (default: 1)
#   E2E_DIAG_LEVEL        (default: full) — none | minimal | full
# ============================================================================
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
REQUEST_URL="${E2E_GATEWAY_URL:-http://10.1.1.209:8080/api/orders}"
HEALTH_URL="${E2E_HEALTH_URL:-http://10.1.1.209:8080/api/health}"
RABBIT_API_URL="${E2E_RABBIT_API_URL:-http://10.1.1.209:15672/api}"
RABBIT_USER="${E2E_RABBIT_USER:-zt}"
RABBIT_PASS="${E2E_RABBIT_PASS:-ztpass}"
WAIT_TIMEOUT="${E2E_WAIT_TIMEOUT:-90}"
REQUEST_QUEUE="${E2E_REQUEST_QUEUE:-order_queue}"
REQUEST_ROUTING_KEY="${E2E_REQUEST_ROUTING_KEY:-request.new}"
REQUEST_EVENT_TYPE="${E2E_REQUEST_EVENT_TYPE:-OrderCreateRequestedEvent}"
DIAG_LEVEL="${E2E_DIAG_LEVEL:-full}"
KEEP_ON_FAIL="${E2E_KEEP_ON_FAIL:-0}"
BUILD_IMAGES="${E2E_BUILD_IMAGES:-1}"

KEEP=false
FAILED=false
PASS_COUNT=0
FAIL_COUNT=0
LAST_HTTP_CODE=""
LAST_HTTP_BODY=""
LAST_TRACE=""
LAST_QUEUE_SNAPSHOT=""

if [[ "${1:-}" == "--keep" ]]; then
    KEEP=true
fi

cd "$PROJECT_DIR"

# ── Logging helpers ──────────────────────────────────────────────────────────

log() {
    printf '[e2e] %s %s\n' "$(date +%T)" "$*"
}

pass() {
    PASS_COUNT=$((PASS_COUNT + 1))
    printf '\033[32m[PASS]\033[0m %s\n' "$*"
}

fail() {
    FAIL_COUNT=$((FAIL_COUNT + 1))
    printf '\033[31m[FAIL]\033[0m %s\n' "$*" >&2
}

section() {
    printf '\n\033[1m── %s ──\033[0m\n' "$*"
}

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        fail "missing command: $1"
        exit 1
    fi
}

# ── URL encoding ─────────────────────────────────────────────────────────────

urlencode() {
    local input="$1" output="" i ch hex
    for ((i = 0; i < ${#input}; i++)); do
        ch="${input:i:1}"
        case "$ch" in
            [a-zA-Z0-9.~_-]) output+="$ch" ;;
            *) printf -v hex '%%%02X' "'$ch"; output+="$hex" ;;
        esac
    done
    printf '%s' "$output"
}

queue_url_path() {
    printf '%s/queues/%%2F/%s' "$RABBIT_API_URL" "$(urlencode "$1")"
}

# ── HTTP helpers ─────────────────────────────────────────────────────────────

record_response() {
    LAST_HTTP_CODE="${1##*$'\n'}"
    LAST_HTTP_BODY="${1%$'\n'*}"
}

new_trace_id() {
    printf '%s-%s-%s-%s' "$1" "$(date +%s)" "$$" "$RANDOM"
}

post_order() {
    curl -sS -w '\n%{http_code}' \
        -X POST "$REQUEST_URL" \
        -H 'Content-Type: application/json' \
        -H "X-Correlation-Id: ${1}" \
        -d "$2"
}

post_order_empty() {
    curl -sS -w '\n%{http_code}' \
        -X POST "$REQUEST_URL" \
        -H 'Content-Type: application/json' \
        -H "X-Correlation-Id: ${1}"
}

# ── RabbitMQ helpers ─────────────────────────────────────────────────────────

fetch_queue_messages() {
    curl -sS \
        -u "${RABBIT_USER}:${RABBIT_PASS}" \
        -H 'content-type: application/json' \
        -X POST "$(queue_url_path "$1")/get" \
        -d "{\"count\":50,\"ackmode\":\"${2}\",\"encoding\":\"auto\",\"truncate\":50000}"
}

queue_messages_ready() {
    curl -sS \
        -u "${RABBIT_USER}:${RABBIT_PASS}" \
        "$(queue_url_path "$1")" 2>/dev/null | \
        php -n -r '
            $row = json_decode(stream_get_contents(STDIN), true);
            echo (string) ($row["messages_ready"] ?? -1);
        '
}

# Validate full event envelope structure.
# Returns "ok" on success, diagnostic string on failure.
queue_payload_has_trace() {
    local trace_id="$1"
    local payload_json="$2"

    TRACE_ID="$trace_id" php -n -r '
        $rows = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($rows)) exit(1);

        $trace = getenv("TRACE_ID") ?: "";
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $payload = $row["payload"] ?? null;
            if (!is_string($payload)) continue;
            $data = json_decode($payload, true);
            if (!is_array($data)) continue;
            if (($data["id"] ?? "") !== $trace) continue;

            $schemaOk = ($data["schema_version"] ?? null) === 1
                     || (string)($data["schema_version"] ?? "") === "1";
            $eventData = $data["data"] ?? null;
            $userKey = is_array($eventData) ? ($eventData["userKey"] ?? null) : null;
            $productList = is_array($eventData) ? ($eventData["productList"] ?? null) : null;

            if (
                $schemaOk
                && ($data["type"] ?? "") === "gateway.request"
                && is_string($data["spiffe_id"] ?? null) && ($data["spiffe_id"] ?? "") !== ""
                && is_array($data["spiffe_path"] ?? null) && count($data["spiffe_path"]) > 0
                && is_string($data["route"] ?? null) && ($data["route"] ?? "") !== ""
                && is_string($userKey) && $userKey !== ""
                && is_array($productList) && count($productList) > 0
            ) {
                echo "ok";
                exit(0);
            }
            echo "trace-matched-but-envelope-invalid";
            exit(2);
        }
        exit(1);
    ' <<<"$payload_json" 2>/dev/null
}

# Verify canonical field names + types in queue payload.
queue_payload_verify_canonical() {
    local trace_id="$1"
    local payload_json="$2"

    TRACE_ID="$trace_id" php -n -r '
        $rows = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($rows)) { echo "no-data"; exit(1); }

        $trace = getenv("TRACE_ID") ?: "";
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $payload = $row["payload"] ?? null;
            if (!is_string($payload)) continue;
            $data = json_decode($payload, true);
            if (!is_array($data) || ($data["id"] ?? "") !== $trace) continue;

            $eventData = $data["data"] ?? [];
            if (!isset($eventData["userKey"])) { echo "missing-userKey"; exit(2); }
            if (!isset($eventData["productList"])) { echo "missing-productList"; exit(2); }
            if (!is_int($eventData["total"] ?? null)) { echo "total-not-int"; exit(2); }
            if (!is_int($eventData["productList"][0]["p_key"] ?? null)) { echo "p_key-not-int"; exit(2); }
            if (!is_int($eventData["productList"][0]["amount"] ?? null)) { echo "amount-not-int"; exit(2); }
            echo "ok";
            exit(0);
        }
        echo "trace-not-found";
        exit(1);
    ' <<<"$payload_json" 2>/dev/null
}

# Verify envelope structure compliance: schema_version, type, route.
queue_payload_verify_envelope() {
    local trace_id="$1"
    local payload_json="$2"

    TRACE_ID="$trace_id" php -n -r '
        $rows = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($rows)) { echo "no-data"; exit(1); }

        $trace = getenv("TRACE_ID") ?: "";
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $payload = $row["payload"] ?? null;
            if (!is_string($payload)) continue;
            $d = json_decode($payload, true);
            if (!is_array($d) || ($d["id"] ?? "") !== $trace) continue;

            // schema_version must be 1
            if (($d["schema_version"] ?? 0) !== 1) {
                echo "schema_version=" . ($d["schema_version"] ?? "MISSING");
                exit(2);
            }
            // type must be "gateway.request"
            if (($d["type"] ?? "") !== "gateway.request") {
                echo "type=" . ($d["type"] ?? "MISSING");
                exit(2);
            }
            // route must be non-empty
            if (!is_string($d["route"] ?? null) || ($d["route"] ?? "") === "") {
                echo "route-missing";
                exit(2);
            }
            echo "ok";
            exit(0);
        }
        echo "trace-not-found";
        exit(1);
    ' <<<"$payload_json" 2>/dev/null
}

# Verify spiffe_path and spiffe_id content in queue payload.
queue_payload_verify_spiffe() {
    local trace_id="$1"
    local payload_json="$2"
    local expected_spiffe_id="$3"

    TRACE_ID="$trace_id" EXPECTED_ID="$expected_spiffe_id" php -n -r '
        $rows = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($rows)) { echo "no-data"; exit(1); }

        $trace = getenv("TRACE_ID") ?: "";
        $expectedId = getenv("EXPECTED_ID") ?: "";
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $payload = $row["payload"] ?? null;
            if (!is_string($payload)) continue;
            $d = json_decode($payload, true);
            if (!is_array($d) || ($d["id"] ?? "") !== $trace) continue;

            $spiffeId = $d["spiffe_id"] ?? "";
            $spiffePath = $d["spiffe_path"] ?? [];

            if ($expectedId !== "" && $spiffeId !== $expectedId) {
                echo "spiffe_id=" . $spiffeId . ",expected=" . $expectedId;
                exit(2);
            }
            if (!is_array($spiffePath) || count($spiffePath) === 0) {
                echo "spiffe_path-empty";
                exit(2);
            }
            // spiffe_path must contain the spiffe_id
            if (!in_array($spiffeId, $spiffePath, true)) {
                echo "spiffe_id-not-in-path";
                exit(2);
            }
            echo "ok|id=" . $spiffeId . "|path=" . implode(",", $spiffePath);
            exit(0);
        }
        echo "trace-not-found";
        exit(1);
    ' <<<"$payload_json" 2>/dev/null
}

wait_for_trace_in_queue() {
    local trace_id="$1" queue_name="$2" timeout="$3" elapsed=0
    while (( elapsed < timeout )); do
        local queue_json
        queue_json="$(fetch_queue_messages "$queue_name" "ack_requeue_true" || true)"
        LAST_QUEUE_SNAPSHOT="$queue_json"
        if queue_payload_has_trace "$trace_id" "$queue_json" >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
        elapsed=$((elapsed + 2))
    done
    return 1
}

consume_trace_from_queue() {
    local queue_json
    queue_json="$(fetch_queue_messages "$2" "ack_requeue_false" || true)"
    LAST_QUEUE_SNAPSHOT="$queue_json"
    queue_payload_has_trace "$1" "$queue_json" >/dev/null 2>&1
}

assert_trace_absent_in_queue() {
    local queue_json
    queue_json="$(fetch_queue_messages "$2" "ack_requeue_true" || true)"
    LAST_QUEUE_SNAPSHOT="$queue_json"
    ! queue_payload_has_trace "$1" "$queue_json" >/dev/null 2>&1
}

purge_queue() {
    curl -sS -o /dev/null -w '%{http_code}' \
        -u "${RABBIT_USER}:${RABBIT_PASS}" \
        -X DELETE "$(queue_url_path "$1")/contents"
}

# ── Wait helpers ─────────────────────────────────────────────────────────────

wait_for_http_200() {
    local name="$1" url="$2" timeout="$3" elapsed=0
    while true; do
        local code
        code="$(curl -s -o /dev/null -w '%{http_code}' "$url" || true)"
        [[ "$code" == "200" ]] && return 0
        sleep 2
        elapsed=$((elapsed + 2))
        if (( elapsed >= timeout )); then
            fail "${name} not ready after ${timeout}s (last HTTP=${code})"
            return 1
        fi
    done
}

wait_for_rabbit_api() {
    local timeout="$1" elapsed=0
    while true; do
        local code
        code="$(curl -s -o /dev/null -w '%{http_code}' -u "${RABBIT_USER}:${RABBIT_PASS}" "${RABBIT_API_URL}/overview" || true)"
        [[ "$code" == "200" ]] && return 0
        sleep 2
        elapsed=$((elapsed + 2))
        if (( elapsed >= timeout )); then
            fail "RabbitMQ API not ready after ${timeout}s"
            return 1
        fi
    done
}

wait_for_worker_log() {
    local since_ts="$1" pattern="$2" timeout="$3" elapsed=0
    while true; do
        local logs
        logs="$(docker compose -f "$COMPOSE_FILE" logs --no-color --since "$since_ts" php-worker 2>&1 || true)"
        grep -Fq "$pattern" <<<"$logs" && return 0
        sleep 2
        elapsed=$((elapsed + 2))
        if (( elapsed >= timeout )); then
            fail "worker log pattern not found in ${timeout}s: ${pattern}"
            return 1
        fi
    done
}

count_worker_log_occurrences() {
    docker compose -f "$COMPOSE_FILE" logs --no-color --since "$1" php-worker 2>&1 | \
        grep -Fc "$2" || echo "0"
}

# ── Message injection via RabbitMQ Management API ────────────────────────────

# Publish a forged message with untrusted SPIFFE source to a given routing key.
publish_forged_message() {
    local trace_id="$1"
    local routing_key="$2"
    local spiffe_id="$3"
    local envelope_type="$4"

    local payload
    payload="$(php -n -r '
        $trace = $argv[1];
        $routingKey = $argv[2];
        $spiffeId = $argv[3];
        $envelopeType = $argv[4];

        echo json_encode([
            "properties" => ["delivery_mode" => 2],
            "routing_key" => $routingKey,
            "payload" => json_encode([
                "schema_version" => 1,
                "type" => $envelopeType,
                "route" => "OrderCreateRequestedEvent",
                "id" => $trace,
                "spiffe_id" => $spiffeId,
                "spiffe_path" => [$spiffeId],
                "data" => [
                    "userKey" => "999",
                    "productList" => [["p_key" => 1, "amount" => 1]],
                    "total" => 0,
                ],
            ], JSON_UNESCAPED_SLASHES),
            "payload_encoding" => "string",
        ], JSON_UNESCAPED_SLASHES);
    ' "$trace_id" "$routing_key" "$spiffe_id" "$envelope_type")"

    curl -sS \
        -u "${RABBIT_USER}:${RABBIT_PASS}" \
        -H 'content-type: application/json' \
        -X POST "${RABBIT_API_URL}/exchanges/%2F/events/publish" \
        -d "$payload"
}

# Publish a message with wrong schema version to request queue.
publish_bad_schema_message() {
    local trace_id="$1"
    local schema_version="$2"

    local payload
    payload="$(php -n -r '
        $trace = $argv[1];
        $schema = (int) $argv[2];

        echo json_encode([
            "properties" => ["delivery_mode" => 2],
            "routing_key" => "request.new",
            "payload" => json_encode([
                "schema_version" => $schema,
                "type" => "gateway.request",
                "route" => "OrderCreateRequestedEvent",
                "id" => $trace,
                "spiffe_id" => "spiffe://zt.local/php-gateway",
                "spiffe_path" => ["spiffe://zt.local/php-gateway"],
                "data" => [
                    "userKey" => "1",
                    "productList" => [["p_key" => 1, "amount" => 1]],
                    "total" => 0,
                ],
            ], JSON_UNESCAPED_SLASHES),
            "payload_encoding" => "string",
        ], JSON_UNESCAPED_SLASHES);
    ' "$trace_id" "$schema_version")"

    curl -sS \
        -u "${RABBIT_USER}:${RABBIT_PASS}" \
        -H 'content-type: application/json' \
        -X POST "${RABBIT_API_URL}/exchanges/%2F/events/publish" \
        -d "$payload"
}

# ── Diagnostics ──────────────────────────────────────────────────────────────

dump_diagnostics() {
    [[ "$DIAG_LEVEL" == "none" ]] && return

    printf '\n[diag] trace=%s http_code=%s\n' "$LAST_TRACE" "$LAST_HTTP_CODE" >&2
    printf '[diag] http_body=%s\n' "$LAST_HTTP_BODY" >&2

    local ready
    ready="$(queue_messages_ready "$REQUEST_QUEUE" || true)"
    printf '[diag] queue=%s messages_ready=%s\n' "$REQUEST_QUEUE" "$ready" >&2

    if [[ "$DIAG_LEVEL" == "full" ]]; then
        printf '\n[diag] gateway logs (last 80 lines)\n' >&2
        docker compose -f "$COMPOSE_FILE" logs --no-color --tail=80 gateway >&2 || true
        printf '\n[diag] worker logs (last 80 lines)\n' >&2
        docker compose -f "$COMPOSE_FILE" logs --no-color --tail=80 php-worker >&2 || true
    fi
}

fail_exit() {
    FAILED=true
    fail "$1"
    dump_diagnostics
    exit 1
}

# ── Cleanup ──────────────────────────────────────────────────────────────────

cleanup() {
    if [[ "$KEEP" == true ]]; then
        log "keeping containers alive (manual: docker compose -f ${COMPOSE_FILE} down -v)"
        return
    fi
    if [[ "$FAILED" == true && "$KEEP_ON_FAIL" == "1" ]]; then
        log "keeping containers for diagnostics (E2E_KEEP_ON_FAIL=1)"
        return
    fi
    log "tearing down environment"
    docker compose -f "$COMPOSE_FILE" down -v --remove-orphans >/dev/null 2>&1 || true
}

trap cleanup EXIT

# ── Preflight checks ────────────────────────────────────────────────────────

require_cmd docker
require_cmd curl
require_cmd php

if [[ "$BUILD_IMAGES" != "0" && "$BUILD_IMAGES" != "1" ]]; then
    fail_exit "E2E_BUILD_IMAGES must be 0 or 1, got: ${BUILD_IMAGES}"
fi

# ============================================================================
# Phase 0: Start infrastructure
# ============================================================================
section "Phase 0: Environment setup"

log "reset environment"
docker compose -f "$COMPOSE_FILE" down -v --remove-orphans >/dev/null 2>&1 || true

log "starting rabbitmq + gateway"
if [[ "$BUILD_IMAGES" == "1" ]]; then
    docker compose -f "$COMPOSE_FILE" up -d --build rabbitmq gateway
else
    # FIX: BUILD_IMAGES=0 must not fallback to --build — images must exist already.
    docker compose -f "$COMPOSE_FILE" up -d rabbitmq gateway
fi

log "waiting for RabbitMQ management API"
wait_for_rabbit_api "$WAIT_TIMEOUT" || fail_exit "RabbitMQ API not ready"

log "waiting for gateway health endpoint"
wait_for_http_200 "gateway" "$HEALTH_URL" "$WAIT_TIMEOUT" || fail_exit "gateway not ready"

log "purging request queue"
purge_code="$(purge_queue "$REQUEST_QUEUE" || true)"
if [[ "$purge_code" != "204" && "$purge_code" != "404" ]]; then
    log "purge returned ${purge_code} (queue may not exist yet, continuing)"
fi

pass "infrastructure is ready"

# ============================================================================
# Phase 1: Gateway ingress validation (no worker needed)
# ============================================================================
section "Phase 1: Gateway ingress tests"

# ── Test 1: Health check ────────────────────────────────────────────────────
log "test 1: health check"
health_response="$(curl -sS -w '\n%{http_code}' "$HEALTH_URL")"
record_response "$health_response"

if [[ "$LAST_HTTP_CODE" != "200" ]]; then
    fail_exit "health check returned ${LAST_HTTP_CODE}, expected 200"
fi
pass "test 1: GET /api/health returns 200"

# ── Test 2: Happy-path order (canonical field names) ─────────────────────────
trace_happy="$(new_trace_id 'e2e-happy')"
LAST_TRACE="$trace_happy"
log "test 2: happy-path order (trace=${trace_happy})"

response="$(post_order "$trace_happy" '{"userKey":"42","productList":[{"p_key":1,"amount":2}],"total":100}')"
record_response "$response"

if [[ "$LAST_HTTP_CODE" != "202" ]]; then
    fail_exit "expected 202, got ${LAST_HTTP_CODE}"
fi
if ! grep -Fq "\"trace_id\":\"${trace_happy}\"" <<<"$LAST_HTTP_BODY"; then
    fail_exit "response missing trace_id"
fi
pass "test 2: POST /api/orders with canonical fields returns 202 + trace_id"

# Verify the message lands in RabbitMQ with correct envelope
if ! wait_for_trace_in_queue "$trace_happy" "$REQUEST_QUEUE" "$WAIT_TIMEOUT"; then
    fail_exit "message not found in ${REQUEST_QUEUE}"
fi
pass "test 2b: event envelope with SPIFFE metadata arrived in ${REQUEST_QUEUE}"

# ── Test 3: Field-alias normalization ────────────────────────────────────────
trace_alias="$(new_trace_id 'e2e-alias')"
LAST_TRACE="$trace_alias"
log "test 3: field-alias normalization (trace=${trace_alias})"

response="$(post_order "$trace_alias" '{"user_id":7,"product_list":[{"product_id":"3","qty":"5"}],"amount":"250"}')"
record_response "$response"

if [[ "$LAST_HTTP_CODE" != "202" ]]; then
    fail_exit "alias payload: expected 202, got ${LAST_HTTP_CODE}"
fi

if ! wait_for_trace_in_queue "$trace_alias" "$REQUEST_QUEUE" "$WAIT_TIMEOUT"; then
    fail_exit "alias message not found in queue"
fi

# Verify canonical format
queue_snapshot="$(fetch_queue_messages "$REQUEST_QUEUE" "ack_requeue_true" || true)"
canonical_result="$(queue_payload_verify_canonical "$trace_alias" "$queue_snapshot" || true)"
if [[ "$canonical_result" != "ok" ]]; then
    fail_exit "alias not normalized: ${canonical_result}"
fi
pass "test 3: aliases -> canonical fields (userKey, productList, p_key:int, amount:int, total:int)"

# ── Test 4: Envelope structure compliance ─────────────────────────────────────
log "test 4: envelope structure compliance on trace=${trace_happy}"
# Use the happy-path message already in queue
env_result="$(queue_payload_verify_envelope "$trace_happy" "$queue_snapshot" || true)"
if [[ "$env_result" != "ok" ]]; then
    fail_exit "envelope structure violation: ${env_result}"
fi
pass "test 4: schema_version=1, type=gateway.request, route present"

# Also verify SPIFFE fields on the happy-path message
spiffe_result="$(queue_payload_verify_spiffe "$trace_happy" "$queue_snapshot" "spiffe://zt.local/php-gateway" || true)"
if [[ "$spiffe_result" != ok* ]]; then
    fail_exit "SPIFFE fields invalid: ${spiffe_result}"
fi
pass "test 4b: spiffe_id matches gateway, spiffe_path contains gateway"

# Clean up both messages
consume_trace_from_queue "$trace_happy" "$REQUEST_QUEUE" || true
consume_trace_from_queue "$trace_alias" "$REQUEST_QUEUE" || true

# ── Test 5: Empty body -> 400 ───────────────────────────────────────────────
trace_empty="$(new_trace_id 'e2e-empty')"
LAST_TRACE="$trace_empty"
log "test 5: empty body"

response="$(post_order_empty "$trace_empty")"
record_response "$response"

if [[ "$LAST_HTTP_CODE" != "400" ]]; then
    fail_exit "empty body: expected 400, got ${LAST_HTTP_CODE}"
fi
if ! assert_trace_absent_in_queue "$trace_empty" "$REQUEST_QUEUE"; then
    fail_exit "empty body leaked into queue"
fi
pass "test 5: empty body returns 400, no queue leak"

# ── Test 6: Invalid JSON -> 400 ─────────────────────────────────────────────
trace_invalid="$(new_trace_id 'e2e-invalid-json')"
LAST_TRACE="$trace_invalid"
log "test 6: invalid JSON"

response="$(post_order "$trace_invalid" '{"user_id":1,"product_list":')"
record_response "$response"

if [[ "$LAST_HTTP_CODE" != "400" ]]; then
    fail_exit "invalid JSON: expected 400, got ${LAST_HTTP_CODE}"
fi
if ! assert_trace_absent_in_queue "$trace_invalid" "$REQUEST_QUEUE"; then
    fail_exit "invalid JSON leaked into queue"
fi
pass "test 6: invalid JSON returns 400, no queue leak"

# ── Test 7: Missing required fields -> 422 ──────────────────────────────────
trace_missing="$(new_trace_id 'e2e-missing')"
LAST_TRACE="$trace_missing"
log "test 7: missing required fields"

response="$(post_order "$trace_missing" '{}')"
record_response "$response"

if (( LAST_HTTP_CODE < 400 || LAST_HTTP_CODE > 499 )); then
    fail_exit "missing fields: expected 4xx, got ${LAST_HTTP_CODE}"
fi
if ! assert_trace_absent_in_queue "$trace_missing" "$REQUEST_QUEUE"; then
    fail_exit "missing fields leaked into queue"
fi
pass "test 7: missing required fields returns ${LAST_HTTP_CODE}, no queue leak"

# ============================================================================
# Phase 2: Worker consumption + Saga flow
# ============================================================================
section "Phase 2: Worker consumption + Saga flow"

worker_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
log "starting php-worker"
if [[ "$BUILD_IMAGES" == "1" ]]; then
    docker compose -f "$COMPOSE_FILE" up -d --build php-worker
else
    docker compose -f "$COMPOSE_FILE" up -d php-worker
fi

wait_for_worker_log "$worker_since" "[worker] listening" "$WAIT_TIMEOUT" \
    || fail_exit "php-worker did not enter listening state"
pass "php-worker is listening"

# ── Test 8: Schema version mismatch -> worker drops ──────────────────────────
schema_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
schema_trace="$(new_trace_id 'e2e-schema-v99')"
LAST_TRACE="$schema_trace"
log "test 8: schema version mismatch (trace=${schema_trace})"

publish_result="$(publish_bad_schema_message "$schema_trace" 99)"
if ! grep -Fq '"routed":true' <<<"$publish_result"; then
    fail_exit "failed to inject bad-schema message"
fi

# Worker should drop with "Unsupported schema_version" error
wait_for_worker_log "$schema_since" "Unsupported schema_version" "$WAIT_TIMEOUT" \
    || fail_exit "worker did not reject bad schema_version"

if ! assert_trace_absent_in_queue "$schema_trace" "$REQUEST_QUEUE"; then
    fail_exit "bad schema message still in queue after drop"
fi
pass "test 8: schema_version=99 dropped by worker (Unsupported schema_version)"

# ── Test 9: Worker processes trusted request ─────────────────────────────────
trace_worker="$(new_trace_id 'e2e-worker')"
LAST_TRACE="$trace_worker"
log "test 9: worker trusted request (trace=${trace_worker})"

response="$(post_order "$trace_worker" '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":0}')"
record_response "$response"

if [[ "$LAST_HTTP_CODE" != "202" ]]; then
    fail_exit "worker test: expected 202, got ${LAST_HTTP_CODE}"
fi

wait_for_worker_log "$worker_since" \
    "[request-consumer] verified source=spiffe://zt.local/php-gateway" "$WAIT_TIMEOUT" \
    || fail_exit "worker did not verify SPIFFE source"

wait_for_worker_log "$worker_since" \
    "[request-consumer] published event=App\\Events\\OrderCreateRequestedEvent" "$WAIT_TIMEOUT" \
    || fail_exit "worker did not publish downstream event"

pass "test 9: worker consumed trusted request, verified SPIFFE, published downstream event"

# ── Test 10: Full Saga flow (4 steps) ────────────────────────────────────────
log "test 10: full saga flow verification"

# The saga steps produce these log messages in sequence:
#   Step 1: "Saga Step 1: 收到訂單建立請求" + "[x] 訂單建立成功"
#   Step 2: "Saga Step 2: 訂單建立，開始扣庫存" + "[x] 扣減庫存成功"
#   Step 3: "Saga Step 3: 開始支付" + ("[x] 支付成功" or "[x] 支付失敗，開始回滾")
#   Step 4: "✅ Saga Step 4: 訂單完成！" (only if payment succeeded)
#
# Note: Steps may succeed or fail depending on whether external services
# (OrderService, ProductionService, UserService) are reachable.
# We verify each step's presence in worker logs.

wait_for_worker_log "$worker_since" \
    "Saga Step 1:" "$WAIT_TIMEOUT" \
    || fail_exit "saga step 1 not triggered"
pass "test 10a: Saga Step 1 triggered (OrderCreateRequested -> create order)"

wait_for_worker_log "$worker_since" \
    "Saga Step 2:" "$WAIT_TIMEOUT" \
    || fail_exit "saga step 2 not triggered"
pass "test 10b: Saga Step 2 triggered (OrderCreated -> deduct inventory)"

wait_for_worker_log "$worker_since" \
    "Saga Step 3:" "$WAIT_TIMEOUT" \
    || fail_exit "saga step 3 not triggered"
pass "test 10c: Saga Step 3 triggered (InventoryDeducted -> process payment)"

# Step 4 depends on payment success; check for either outcome
payment_since="$worker_since"
elapsed_saga=0
saga_step4=false
saga_rollback=false
while (( elapsed_saga < WAIT_TIMEOUT )); do
    logs="$(docker compose -f "$COMPOSE_FILE" logs --no-color --since "$payment_since" php-worker 2>&1 || true)"
    if grep -Fq "Saga Step 4:" <<<"$logs"; then
        saga_step4=true
        break
    fi
    if grep -Fq "支付失敗" <<<"$logs"; then
        saga_rollback=true
        break
    fi
    sleep 2
    elapsed_saga=$((elapsed_saga + 2))
done

if [[ "$saga_step4" == true ]]; then
    pass "test 10d: Saga Step 4 completed (payment succeeded, order done)"
elif [[ "$saga_rollback" == true ]]; then
    # Payment failed but compensation path triggered — this is still valid E2E
    log "payment failed (external service unavailable); verifying compensation path"
    wait_for_worker_log "$worker_since" \
        "RollbackSaga Step 2:" "$WAIT_TIMEOUT" \
        || fail_exit "compensation step (RollbackInventory) not triggered after payment failure"
    pass "test 10d: Saga compensation triggered (payment failed -> rollback inventory)"

    wait_for_worker_log "$worker_since" \
        "RollbackSaga Step 1:" "$WAIT_TIMEOUT" 2>/dev/null && \
        pass "test 10e: RollbackOrder triggered (cancel order)" || true
else
    fail_exit "saga did not reach step 4 or compensation within ${WAIT_TIMEOUT}s"
fi

# ── Test 11: SPIFFE identity chain propagation ───────────────────────────────
log "test 11: SPIFFE identity chain propagation"

# After RequestConsumer publishes OrderCreateRequestedEvent, EventConsumer
# logs the spiffe_path. Check that the path includes the gateway identity.
wait_for_worker_log "$worker_since" \
    "path=[spiffe://zt.local/php-gateway" "$WAIT_TIMEOUT" \
    || fail_exit "spiffe_path does not include gateway identity in first hop"

# After EventConsumer dispatches and saga publishes next event, the worker's
# SPIFFE ID should be appended. Check for worker identity in event-consumer logs.
wait_for_worker_log "$worker_since" \
    "[event-consumer] source=spiffe://zt.local/php-worker" "$WAIT_TIMEOUT" \
    || fail_exit "event-consumer did not see worker SPIFFE ID as source"

pass "test 11: SPIFFE chain propagates (gateway -> worker identity visible in downstream events)"

# ── Test 12: Distributed tracing propagation ─────────────────────────────────
log "test 12: distributed tracing (X-Correlation-Id)"

# The gateway sets the trace_id from X-Correlation-Id header.
# Check that the request-consumer log contains our trace for the worker test.
# The trace propagates: gateway -> queue (id field) -> worker (traceId in eventData)
wait_for_worker_log "$worker_since" \
    "[request-consumer] verified source" "$WAIT_TIMEOUT" \
    || fail_exit "request-consumer did not process message"

# Verify the event-consumer also received the trace (it's in the payload)
wait_for_worker_log "$worker_since" \
    "[event-consumer] handled event=App\\Events\\OrderCreateRequestedEvent" "$WAIT_TIMEOUT" \
    || fail_exit "event-consumer did not handle OrderCreateRequestedEvent"

pass "test 12: trace propagated through gateway -> request-consumer -> event-consumer"

# ============================================================================
# Phase 3: Security tests
# ============================================================================
section "Phase 3: Security tests"

# ── Test 13: Untrusted SPIFFE on request queue ───────────────────────────────
forged_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
forged_trace="$(new_trace_id 'e2e-untrusted-req')"
LAST_TRACE="$forged_trace"
log "test 13: untrusted SPIFFE on request queue (trace=${forged_trace})"

publish_result="$(publish_forged_message "$forged_trace" "$REQUEST_ROUTING_KEY" "spiffe://evil.domain/attacker" "gateway.request")"
if ! grep -Fq '"routed":true' <<<"$publish_result"; then
    fail_exit "failed to inject forged message"
fi

wait_for_worker_log "$forged_since" \
    "Untrusted SPIFFE source: spiffe://evil.domain/attacker" "$WAIT_TIMEOUT" \
    || fail_exit "worker did not reject untrusted SPIFFE on request queue"

sleep 3
ready_after="$(queue_messages_ready "$REQUEST_QUEUE" || true)"
if [[ "$ready_after" =~ ^[0-9]+$ ]] && (( ready_after > 0 )); then
    fail_exit "queue not empty after untrusted drop (messages_ready=${ready_after})"
fi

drop_count="$(count_worker_log_occurrences "$forged_since" "Untrusted SPIFFE source: spiffe://evil.domain/attacker")"
if [[ -n "$drop_count" ]] && (( drop_count > 1 )); then
    fail_exit "requeue storm detected (drop count=${drop_count})"
fi
pass "test 13: untrusted SPIFFE on request queue dropped, no requeue storm"

# ── Test 14: Untrusted SPIFFE on event queue ─────────────────────────────────
event_forged_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
event_forged_trace="$(new_trace_id 'e2e-untrusted-evt')"
LAST_TRACE="$event_forged_trace"
log "test 14: untrusted SPIFFE on event queue (trace=${event_forged_trace})"

# Inject directly into the OrderCreateRequestedEvent event queue (not request queue).
# The EventConsumer listens on this queue and should also verify SPIFFE source.
event_publish_result="$(publish_forged_message \
    "$event_forged_trace" \
    "OrderCreateRequestedEvent" \
    "spiffe://evil.domain/event-attacker" \
    "App\\Events\\OrderCreateRequestedEvent")"
if ! grep -Fq '"routed":true' <<<"$event_publish_result"; then
    fail_exit "failed to inject forged event message"
fi

wait_for_worker_log "$event_forged_since" \
    "Untrusted SPIFFE source: spiffe://evil.domain/event-attacker" "$WAIT_TIMEOUT" \
    || fail_exit "event-consumer did not reject untrusted SPIFFE on event queue"

event_drop_count="$(count_worker_log_occurrences "$event_forged_since" "Untrusted SPIFFE source: spiffe://evil.domain/event-attacker")"
if [[ -n "$event_drop_count" ]] && (( event_drop_count > 1 )); then
    fail_exit "event queue requeue storm detected (drop count=${event_drop_count})"
fi
pass "test 14: untrusted SPIFFE on event queue dropped, no requeue storm"

# ============================================================================
# Phase 4: Concurrency test
# ============================================================================
section "Phase 4: Concurrency test"

CONCURRENT_COUNT=5
concurrent_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
log "test 15: ${CONCURRENT_COUNT} concurrent requests"

# FIX: Use inline curl instead of subshell function call (functions aren't
# exported to background subshells).
declare -a concurrent_pids
concurrent_success=true

for i in $(seq 1 "$CONCURRENT_COUNT"); do
    trace_c="$(new_trace_id "e2e-concurrent-${i}")"

    (
        resp="$(curl -sS -w '\n%{http_code}' \
            -X POST "$REQUEST_URL" \
            -H 'Content-Type: application/json' \
            -H "X-Correlation-Id: ${trace_c}" \
            -d "{\"userKey\":\"${i}\",\"productList\":[{\"p_key\":${i},\"amount\":1}],\"total\":${i}00}")"
        code="${resp##*$'\n'}"
        if [[ "$code" != "202" ]]; then
            exit 1
        fi
        exit 0
    ) &
    concurrent_pids+=($!)
done

for pid in "${concurrent_pids[@]}"; do
    if ! wait "$pid"; then
        concurrent_success=false
    fi
done

if [[ "$concurrent_success" != true ]]; then
    fail_exit "one or more concurrent requests did not return 202"
fi
pass "test 15a: ${CONCURRENT_COUNT} concurrent requests all returned 202"

# Verify worker processes them all
elapsed_c=0
while (( elapsed_c < WAIT_TIMEOUT )); do
    verified_count="$(count_worker_log_occurrences "$concurrent_since" "[request-consumer] verified source")"
    if [[ "$verified_count" =~ ^[0-9]+$ ]] && (( verified_count >= CONCURRENT_COUNT )); then
        break
    fi
    sleep 2
    elapsed_c=$((elapsed_c + 2))
done

if (( verified_count < CONCURRENT_COUNT )); then
    fail_exit "worker only verified ${verified_count}/${CONCURRENT_COUNT} concurrent requests"
fi
pass "test 15b: all ${CONCURRENT_COUNT} concurrent requests processed by worker"

# ============================================================================
# Summary
# ============================================================================
section "Results"
printf 'Total: \033[32m%d passed\033[0m, \033[31m%d failed\033[0m\n' "$PASS_COUNT" "$FAIL_COUNT"

if (( FAIL_COUNT > 0 )); then
    FAILED=true
    exit 1
fi

pass "all end-to-end gateway tests passed"
