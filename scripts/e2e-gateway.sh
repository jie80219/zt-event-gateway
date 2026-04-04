#!/usr/bin/env bash
# End-to-end verification for gateway -> RabbitMQ -> worker flow.
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
REQUEST_URL="${E2E_GATEWAY_URL:-http://127.0.0.1:8080/api/orders}"
HEALTH_URL="${E2E_HEALTH_URL:-http://127.0.0.1:8080/api/health}"
RABBIT_API_URL="${E2E_RABBIT_API_URL:-http://127.0.0.1:15672/api}"
RABBIT_USER="${E2E_RABBIT_USER:-zt}"
RABBIT_PASS="${E2E_RABBIT_PASS:-ztpass}"
WAIT_TIMEOUT="${E2E_WAIT_TIMEOUT:-90}"
REQUEST_QUEUE="${E2E_REQUEST_QUEUE:-order_queue}"
REQUEST_ROUTING_KEY="${E2E_REQUEST_ROUTING_KEY:-request.new}"
REQUEST_EVENT_TYPE="${E2E_REQUEST_EVENT_TYPE:-OrderCreateRequestedEvent}"
QUEUE_CHECK_MODE="${E2E_QUEUE_CHECK_MODE:-requeue}"
DIAG_LEVEL="${E2E_DIAG_LEVEL:-full}"
KEEP_ON_FAIL="${E2E_KEEP_ON_FAIL:-0}"
BUILD_IMAGES="${E2E_BUILD_IMAGES:-1}"

KEEP=false
FAILED=false
LAST_HTTP_CODE=""
LAST_HTTP_BODY=""
LAST_TRACE=""
LAST_QUEUE_SNAPSHOT=""

if [[ "${1:-}" == "--keep" ]]; then
    KEEP=true
fi

cd "$PROJECT_DIR"

log() {
    printf '[e2e-gateway] %s %s\n' "$(date +%T)" "$*"
}

pass() {
    printf '[PASS] %s\n' "$*"
}

fail() {
    printf '[FAIL] %s\n' "$*" >&2
}

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        fail "missing command: $1"
        exit 1
    fi
}

urlencode() {
    local input="$1"
    local output=""
    local i
    local ch
    local hex

    for ((i = 0; i < ${#input}; i++)); do
        ch="${input:i:1}"
        case "$ch" in
            [a-zA-Z0-9.~_-])
                output+="$ch"
                ;;
            *)
                printf -v hex '%%%02X' "'$ch"
                output+="$hex"
                ;;
        esac
    done

    printf '%s' "$output"
}

queue_url_path() {
    local queue_name="$1"
    printf '%s/queues/%%2F/%s' "$RABBIT_API_URL" "$(urlencode "$queue_name")"
}

record_response() {
    local response="$1"
    LAST_HTTP_CODE="${response##*$'\n'}"
    LAST_HTTP_BODY="${response%$'\n'*}"
}

new_trace_id() {
    local prefix="$1"
    printf '%s-%s-%s-%s' "$prefix" "$(date +%s)" "$$" "$RANDOM"
}

fetch_queue_messages() {
    local queue_name="$1"
    local ack_mode="$2"

    curl -sS \
        -u "${RABBIT_USER}:${RABBIT_PASS}" \
        -H 'content-type: application/json' \
        -X POST "$(queue_url_path "$queue_name")/get" \
        -d "{\"count\":20,\"ackmode\":\"${ack_mode}\",\"encoding\":\"auto\",\"truncate\":50000}"
}

snapshot_queue() {
    local queue_name="$1"
    LAST_QUEUE_SNAPSHOT="$(fetch_queue_messages "$queue_name" "ack_requeue_true" || true)"
}

queue_messages_ready() {
    local queue_name="$1"

    curl -sS \
        -u "${RABBIT_USER}:${RABBIT_PASS}" \
        "$(queue_url_path "$queue_name")" | \
        php -n -r '
            $raw = stream_get_contents(STDIN);
            $row = json_decode($raw, true);
            if (!is_array($row)) {
                echo "-1";
                exit(0);
            }
            echo (string) ($row["messages_ready"] ?? -1);
        '
}

queue_payload_has_trace() {
    local trace_id="$1"
    local payload_json="$2"

    TRACE_ID="$trace_id" php -n -r '
        $raw = stream_get_contents(STDIN);
        $rows = json_decode($raw, true);
        if (!is_array($rows)) {
            exit(1);
        }

        $trace = getenv("TRACE_ID") ?: "";
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $payload = $row["payload"] ?? null;
            if (!is_string($payload)) {
                continue;
            }

            $data = json_decode($payload, true);
            if (!is_array($data)) {
                continue;
            }

            if (($data["id"] ?? "") !== $trace) {
                continue;
            }

            $schemaVersion = $data["schema_version"] ?? null;
            $spiffeId = $data["spiffe_id"] ?? "";
            $spiffePath = $data["spiffe_path"] ?? null;
            $route = $data["route"] ?? "";
            $eventData = $data["data"] ?? null;
            $userKey = is_array($eventData) ? ($eventData["userKey"] ?? null) : null;
            $productList = is_array($eventData) ? ($eventData["productList"] ?? null) : null;

            $schemaOk = ($schemaVersion === 1) || (is_string($schemaVersion) && $schemaVersion === "1");
            if (
                $schemaOk
                && ($data["type"] ?? "") === "gateway.request"
                && is_string($spiffeId) && $spiffeId !== ""
                && is_array($spiffePath) && count($spiffePath) > 0
                && is_string($route) && $route !== ""
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

wait_for_trace_in_queue() {
    local trace_id="$1"
    local queue_name="$2"
    local timeout="$3"
    local elapsed=0

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

wait_for_trace_and_consume() {
    local trace_id="$1"
    local queue_name="$2"
    local timeout="$3"
    local elapsed=0

    while (( elapsed < timeout )); do
        local queue_json
        queue_json="$(fetch_queue_messages "$queue_name" "ack_requeue_false" || true)"
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
    local trace_id="$1"
    local queue_name="$2"

    local queue_json
    queue_json="$(fetch_queue_messages "$queue_name" "ack_requeue_false" || true)"
    LAST_QUEUE_SNAPSHOT="$queue_json"

    if queue_payload_has_trace "$trace_id" "$queue_json" >/dev/null 2>&1; then
        return 0
    fi

    return 1
}

assert_trace_absent_in_queue() {
    local trace_id="$1"
    local queue_name="$2"

    local queue_json
    queue_json="$(fetch_queue_messages "$queue_name" "ack_requeue_true" || true)"
    LAST_QUEUE_SNAPSHOT="$queue_json"

    if queue_payload_has_trace "$trace_id" "$queue_json" >/dev/null 2>&1; then
        return 1
    fi

    return 0
}

purge_queue() {
    local queue_name="$1"

    curl -sS -o /dev/null -w '%{http_code}' \
        -u "${RABBIT_USER}:${RABBIT_PASS}" \
        -X DELETE "$(queue_url_path "$queue_name")/contents"
}

wait_for_http_200() {
    local name="$1"
    local url="$2"
    local timeout="$3"
    local elapsed=0

    while true; do
        local code
        code="$(curl -s -o /dev/null -w '%{http_code}' "$url" || true)"
        if [[ "$code" == "200" ]]; then
            return 0
        fi

        sleep 2
        elapsed=$((elapsed + 2))
        if (( elapsed >= timeout )); then
            fail "${name} not ready after ${timeout}s (last HTTP=${code})"
            return 1
        fi
    done
}

wait_for_rabbit_api() {
    local timeout="$1"
    local elapsed=0

    while true; do
        local code
        code="$(
            curl -s -o /dev/null -w '%{http_code}' \
                -u "${RABBIT_USER}:${RABBIT_PASS}" \
                "${RABBIT_API_URL}/overview" || true
        )"
        if [[ "$code" == "200" ]]; then
            return 0
        fi

        sleep 2
        elapsed=$((elapsed + 2))
        if (( elapsed >= timeout )); then
            fail "rabbitmq API not ready after ${timeout}s (last HTTP=${code})"
            return 1
        fi
    done
}

post_order() {
    local trace_id="$1"
    local payload="$2"

    curl -sS -w '\n%{http_code}' \
        -X POST "$REQUEST_URL" \
        -H 'Content-Type: application/json' \
        -H "X-Correlation-Id: ${trace_id}" \
        -d "$payload"
}

wait_for_worker_log() {
    local since_ts="$1"
    local pattern="$2"
    local timeout="$3"
    local elapsed=0

    while true; do
        local logs
        logs="$(docker compose -f "$COMPOSE_FILE" logs --no-color --since "$since_ts" php-worker 2>&1 || true)"
        if grep -Fq "$pattern" <<<"$logs"; then
            return 0
        fi

        sleep 2
        elapsed=$((elapsed + 2))
        if (( elapsed >= timeout )); then
            fail "worker log pattern not found in ${timeout}s: ${pattern}"
            return 1
        fi
    done
}

count_worker_log_occurrences() {
    local since_ts="$1"
    local pattern="$2"

    docker compose -f "$COMPOSE_FILE" logs --no-color --since "$since_ts" php-worker 2>&1 | \
        grep -F "$pattern" | wc -l | tr -d ' '
}

publish_forged_untrusted_message() {
    local trace_id="$1"

    local payload
    payload="$(php -n -r '
        $trace = $argv[1];
        $routingKey = $argv[2];
        $eventType = $argv[3];

        $message = [
            "schema_version" => 1,
            "specversion" => "1.0",
            "type" => "gateway.request",
            "route" => $eventType,
            "source" => "/forged/untrusted",
            "id" => $trace,
            "time" => date(DATE_RFC3339),
            "spiffe_id" => "spiffe://evil.domain/attacker",
            "spiffe_path" => ["spiffe://evil.domain/attacker"],
            "data" => [
                "userKey" => "999",
                "productList" => [["p_key" => 1, "amount" => 1]],
                "total" => 0,
            ],
        ];

        echo json_encode([
            "properties" => ["delivery_mode" => 2],
            "routing_key" => $routingKey,
            "payload" => json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            "payload_encoding" => "string",
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ' "$trace_id" "$REQUEST_ROUTING_KEY" "$REQUEST_EVENT_TYPE")"

    curl -sS \
        -u "${RABBIT_USER}:${RABBIT_PASS}" \
        -H 'content-type: application/json' \
        -X POST "${RABBIT_API_URL}/exchanges/%2F/events/publish" \
        -d "$payload"
}

dump_diagnostics() {
    if [[ "$DIAG_LEVEL" == "none" ]]; then
        return
    fi

    printf '\n[diag] trace=%s\n' "$LAST_TRACE" >&2
    printf '[diag] last_http_code=%s\n' "$LAST_HTTP_CODE" >&2
    printf '[diag] last_http_body=%s\n' "$LAST_HTTP_BODY" >&2

    local ready
    ready="$(queue_messages_ready "$REQUEST_QUEUE" || true)"
    printf '[diag] queue=%s messages_ready=%s\n' "$REQUEST_QUEUE" "$ready" >&2

    if [[ "$DIAG_LEVEL" == "full" ]]; then
        snapshot_queue "$REQUEST_QUEUE"
        printf '[diag] queue_snapshot=%s\n' "$LAST_QUEUE_SNAPSHOT" >&2

        printf '\n[diag] gateway logs\n' >&2
        docker compose -f "$COMPOSE_FILE" logs --no-color --tail=120 gateway >&2 || true

        printf '\n[diag] worker logs\n' >&2
        docker compose -f "$COMPOSE_FILE" logs --no-color --tail=120 php-worker >&2 || true
    fi
}

fail_exit() {
    FAILED=true
    fail "$1"
    dump_diagnostics
    exit 1
}

cleanup() {
    if [[ "$KEEP" == true ]]; then
        log "keeping containers alive (manual cleanup: docker compose -f ${COMPOSE_FILE} down -v)"
        return
    fi

    if [[ "$FAILED" == true && "$KEEP_ON_FAIL" == "1" ]]; then
        log "test failed; keeping containers for diagnostics (E2E_KEEP_ON_FAIL=1)"
        return
    fi

    log "tearing down environment"
    docker compose -f "$COMPOSE_FILE" down -v --remove-orphans >/dev/null 2>&1 || true
}

trap cleanup EXIT

require_cmd docker
require_cmd curl
require_cmd php

if [[ "$QUEUE_CHECK_MODE" != "requeue" && "$QUEUE_CHECK_MODE" != "consume" ]]; then
    fail_exit "unsupported E2E_QUEUE_CHECK_MODE=${QUEUE_CHECK_MODE} (expected: requeue|consume)"
fi
if [[ "$BUILD_IMAGES" != "0" && "$BUILD_IMAGES" != "1" ]]; then
    fail_exit "unsupported E2E_BUILD_IMAGES=${BUILD_IMAGES} (expected: 0|1)"
fi

log "reset environment"
docker compose -f "$COMPOSE_FILE" down -v --remove-orphans >/dev/null 2>&1 || true

log "starting rabbitmq + gateway"
if [[ "$BUILD_IMAGES" == "1" ]]; then
    docker compose -f "$COMPOSE_FILE" up -d --build rabbitmq gateway
else
    if ! docker compose -f "$COMPOSE_FILE" up -d --no-build rabbitmq gateway; then
        log "no-build startup failed; retrying with build for missing local image"
        docker compose -f "$COMPOSE_FILE" up -d --build rabbitmq gateway
    fi
fi

log "waiting for rabbitmq management API"
wait_for_rabbit_api "$WAIT_TIMEOUT" || fail_exit "rabbitmq API not ready"

log "waiting for gateway health endpoint"
wait_for_http_200 "gateway health" "$HEALTH_URL" "$WAIT_TIMEOUT" || fail_exit "gateway health not ready"
pass "gateway is healthy"

log "purging request queue=${REQUEST_QUEUE}"
purge_code="$(purge_queue "$REQUEST_QUEUE" || true)"
if [[ "$purge_code" != "204" && "$purge_code" != "404" ]]; then
    fail_exit "failed to purge ${REQUEST_QUEUE} (http=${purge_code})"
fi

# Test 1+2+3: happy path to queue
time_trace="$(new_trace_id 'e2e-ingress')"
LAST_TRACE="$time_trace"
log "phase 1: publish order to gateway (trace=${time_trace})"

response="$(post_order "$time_trace" '{"user_id":1,"product_list":[{"p_key":1,"amount":1}],"total":100}')"
record_response "$response"

if [[ "$LAST_HTTP_CODE" != "202" ]]; then
    fail_exit "unexpected gateway status for valid payload: ${LAST_HTTP_CODE}"
fi
if ! grep -Fq "\"trace_id\":\"${time_trace}\"" <<<"$LAST_HTTP_BODY"; then
    fail_exit "gateway response does not contain expected trace_id"
fi
pass "gateway accepted canonicalizable request and returned trace_id"

if [[ "$QUEUE_CHECK_MODE" == "consume" ]]; then
    if ! wait_for_trace_and_consume "$time_trace" "$REQUEST_QUEUE" "$WAIT_TIMEOUT"; then
        fail_exit "failed to consume trace=${time_trace} in consume mode"
    fi
    pass "request queue consumed trace in consume mode"
else
    if ! wait_for_trace_in_queue "$time_trace" "$REQUEST_QUEUE" "$WAIT_TIMEOUT"; then
        fail_exit "failed to find ingress message in ${REQUEST_QUEUE}"
    fi
    pass "request queue contains canonical ingress payload with SPIFFE metadata"

    # default requeue mode: verify with non-destructive reads first, then consume once deterministically.
    if ! consume_trace_from_queue "$time_trace" "$REQUEST_QUEUE"; then
        fail_exit "failed to consume trace=${time_trace} after requeue verification"
    fi
    pass "request queue consumed trace after requeue verification"
fi

# Test 5: invalid JSON should fail and never enter queue
invalid_json_trace="$(new_trace_id 'e2e-invalid-json')"
LAST_TRACE="$invalid_json_trace"
log "phase 1b: invalid JSON validation (trace=${invalid_json_trace})"
response="$(post_order "$invalid_json_trace" '{"user_id":1,"product_list":')"
record_response "$response"

if [[ "$LAST_HTTP_CODE" != "400" ]]; then
    fail_exit "invalid JSON should return 400, got ${LAST_HTTP_CODE}"
fi
if ! assert_trace_absent_in_queue "$invalid_json_trace" "$REQUEST_QUEUE"; then
    fail_exit "invalid JSON request unexpectedly entered queue"
fi
pass "invalid JSON returns 400 and does not enter queue"

# Test 6: missing required fields should fail and never enter queue
missing_fields_trace="$(new_trace_id 'e2e-missing-fields')"
LAST_TRACE="$missing_fields_trace"
log "phase 1c: missing required fields validation (trace=${missing_fields_trace})"
response="$(post_order "$missing_fields_trace" '{}')"
record_response "$response"

if (( LAST_HTTP_CODE < 400 || LAST_HTTP_CODE > 499 )); then
    fail_exit "missing required fields should return 4xx, got ${LAST_HTTP_CODE}"
fi
if ! assert_trace_absent_in_queue "$missing_fields_trace" "$REQUEST_QUEUE"; then
    fail_exit "missing required fields request unexpectedly entered queue"
fi
pass "missing required fields returns 4xx and does not enter queue"

# Test 4: worker forwarding logs for trusted request
worker_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
log "starting php-worker"
if [[ "$BUILD_IMAGES" == "1" ]]; then
    docker compose -f "$COMPOSE_FILE" up -d --build php-worker
else
    if ! docker compose -f "$COMPOSE_FILE" up -d --no-build php-worker; then
        log "no-build worker startup failed; retrying with build for missing local image"
        docker compose -f "$COMPOSE_FILE" up -d --build php-worker
    fi
fi

wait_for_worker_log "$worker_since" "[worker] listening" "$WAIT_TIMEOUT" || fail_exit "php-worker did not enter listening state"
pass "php-worker is listening"

trace_worker="$(new_trace_id 'e2e-worker')"
LAST_TRACE="$trace_worker"
log "phase 2: publish order and check worker forwarding logs (trace=${trace_worker})"
response="$(post_order "$trace_worker" '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":0}')"
record_response "$response"

if [[ "$LAST_HTTP_CODE" != "202" ]]; then
    fail_exit "unexpected gateway status for worker-forwarding phase: ${LAST_HTTP_CODE}"
fi

wait_for_worker_log "$worker_since" "[request-consumer] verified source=spiffe://zt.local/php-gateway" "$WAIT_TIMEOUT" || fail_exit "worker did not verify SPIFFE source"
wait_for_worker_log "$worker_since" "[request-consumer] published event=App\\Events\\OrderCreateRequestedEvent" "$WAIT_TIMEOUT" || fail_exit "worker did not publish downstream event"
pass "worker consumed trusted request and published downstream event"

# Test 7: untrusted SPIFFE source should be dropped without requeue storm
log "phase 3: publish forged untrusted source message"
forged_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
forged_trace="$(new_trace_id 'e2e-untrusted')"
LAST_TRACE="$forged_trace"

publish_result="$(publish_forged_untrusted_message "$forged_trace")"
if ! grep -Fq '"routed":true' <<<"$publish_result"; then
    fail_exit "failed to publish forged message to exchange"
fi

wait_for_worker_log "$forged_since" "Untrusted SPIFFE source: spiffe://evil.domain/attacker" "$WAIT_TIMEOUT" || fail_exit "worker did not reject untrusted SPIFFE source"

sleep 2
ready_after_untrusted="$(queue_messages_ready "$REQUEST_QUEUE" || true)"
if [[ "$ready_after_untrusted" =~ ^[0-9]+$ ]] && [[ "$ready_after_untrusted" != "0" ]]; then
    fail_exit "request queue is not empty after untrusted message drop (messages_ready=${ready_after_untrusted})"
fi
if ! assert_trace_absent_in_queue "$forged_trace" "$REQUEST_QUEUE"; then
    fail_exit "forged untrusted trace is still present in request queue"
fi

drop_count="$(count_worker_log_occurrences "$forged_since" "Untrusted SPIFFE source: spiffe://evil.domain/attacker" || true)"
if [[ -n "$drop_count" ]] && (( drop_count > 1 )); then
    fail_exit "possible requeue storm detected (untrusted source log count=${drop_count})"
fi

pass "untrusted SPIFFE source is dropped and does not trigger requeue storm"
pass "end-to-end gateway flow test passed"
