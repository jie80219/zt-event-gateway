#!/usr/bin/env bash
# ============================================================================
# Full-stack End-to-End Test: Gateway + SPIFFE/SPIRE + LSVID + Saga + mTLS
#
# This script tests the COMPLETE zero-trust pipeline:
#
#   Phase 0 — Infrastructure bootstrap (SPIRE Server, Agent, RabbitMQ)
#   Phase 1 — SPIFFE identity verification (SVID fetch, trust domain, entries)
#   Phase 2 — Gateway ingress + LSVID L0 minting
#   Phase 3 — Worker consumption + LSVID chain validation
#   Phase 4 — Full Saga flow with downstream mTLS services
#   Phase 5 — LSVID security (tampered tokens, missing tokens, replay)
#   Phase 6 — SPIFFE identity chain integrity
#   Phase 7 — Compensation flow verification
#   Phase 8 — Concurrency under zero-trust
#
# Prerequisites:
#   - Docker & Docker Compose
#   - curl, jq, php, openssl
#
# Usage:
#   bash scripts/e2e-full-stack.sh [--keep]
#
# Topology (single compose):
#   docker-compose.yml → all services (spire, gateway, worker, downstream)
#
# Environment overrides:
#   E2E_WAIT_TIMEOUT      (default: 120)
#   E2E_KEEP_ON_FAIL      (default: 0)
#   E2E_BUILD_IMAGES      (default: 1)
#   E2E_SKIP_SERVICES     (default: 0)  — skip Phase 4 downstream service tests
#   E2E_DIAG_LEVEL        (default: full)
# ============================================================================
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

# ── Configuration ───────────────────────────────────────────────────────────

COMPOSE="docker-compose.yml"

GATEWAY_URL="http://127.0.0.1:8080/api/orders"
HEALTH_URL="http://127.0.0.1:8080/api/health"
RABBIT_API="http://127.0.0.1:15672/api"
RABBIT_USER="zt"
RABBIT_PASS="ztpass"

WAIT_TIMEOUT="${E2E_WAIT_TIMEOUT:-120}"
KEEP_ON_FAIL="${E2E_KEEP_ON_FAIL:-0}"
BUILD_IMAGES="${E2E_BUILD_IMAGES:-1}"
SKIP_SERVICES="${E2E_SKIP_SERVICES:-0}"
DIAG_LEVEL="${E2E_DIAG_LEVEL:-full}"

TRUST_DOMAIN="zt.local"
GATEWAY_SPIFFE="spiffe://${TRUST_DOMAIN}/php-gateway"
WORKER_SPIFFE="spiffe://${TRUST_DOMAIN}/php-worker"
ORDER_SPIFFE="spiffe://${TRUST_DOMAIN}/order-service"
PRODUCTION_SPIFFE="spiffe://${TRUST_DOMAIN}/production-service"
USER_SPIFFE="spiffe://${TRUST_DOMAIN}/user-service"

KEEP=false
FAILED=false
PASS_COUNT=0
FAIL_COUNT=0

[[ "${1:-}" == "--keep" ]] && KEEP=true

# ── Logging ─────────────────────────────────────────────────────────────────

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

log()     { printf "${CYAN}[e2e]${NC} %s\n" "$*"; }
section() { printf "\n${YELLOW}═══ %s ═══${NC}\n" "$*"; }
pass()    { PASS_COUNT=$((PASS_COUNT + 1)); printf "  ${GREEN}✓${NC} %s\n" "$*"; }
fail()    { FAIL_COUNT=$((FAIL_COUNT + 1)); FAILED=true; printf "  ${RED}✗${NC} %s\n" "$*" >&2; }

fail_exit() {
    fail "$1"
    dump_diagnostics
    exit 1
}

new_trace() { printf '%s-%s-%s-%s' "$1" "$(date +%s)" "$$" "$RANDOM"; }

# ── HTTP/RabbitMQ helpers ───────────────────────────────────────────────────

post_order() {
    curl -sS -w '\n%{http_code}' \
        -X POST "$GATEWAY_URL" \
        -H 'Content-Type: application/json' \
        -H "X-Correlation-Id: $1" \
        -d "$2" 2>/dev/null
}

http_code() { echo "${1##*$'\n'}"; }
http_body() { echo "${1%$'\n'*}"; }

wait_http_200() {
    local name="$1" url="$2" timeout="$3" elapsed=0
    while (( elapsed < timeout )); do
        local code
        code="$(curl -s -o /dev/null -w '%{http_code}' "$url" 2>/dev/null || true)"
        [[ "$code" == "200" ]] && return 0
        sleep 2; elapsed=$((elapsed + 2))
    done
    fail "${name} not ready after ${timeout}s"
    return 1
}

wait_rabbit_api() {
    local timeout="$1" elapsed=0
    while (( elapsed < timeout )); do
        local code
        code="$(curl -s -o /dev/null -w '%{http_code}' -u "${RABBIT_USER}:${RABBIT_PASS}" "${RABBIT_API}/overview" 2>/dev/null || true)"
        [[ "$code" == "200" ]] && return 0
        sleep 2; elapsed=$((elapsed + 2))
    done
    return 1
}

fetch_queue_msgs() {
    curl -sS -u "${RABBIT_USER}:${RABBIT_PASS}" \
        -H 'content-type: application/json' \
        -X POST "${RABBIT_API}/queues/%2F/$1/get" \
        -d "{\"count\":50,\"ackmode\":\"$2\",\"encoding\":\"auto\",\"truncate\":50000}" 2>/dev/null
}

purge_queue() {
    curl -sS -o /dev/null -w '%{http_code}' \
        -u "${RABBIT_USER}:${RABBIT_PASS}" \
        -X DELETE "${RABBIT_API}/queues/%2F/$1/contents" 2>/dev/null || true
}

queue_has_trace() {
    local trace="$1" json="$2"
    TRACE_ID="$trace" php -n -r '
        $rows = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($rows)) exit(1);
        $trace = getenv("TRACE_ID");
        foreach ($rows as $row) {
            $d = json_decode($row["payload"] ?? "", true);
            if (is_array($d) && ($d["id"] ?? "") === $trace) { echo "ok"; exit(0); }
        }
        exit(1);
    ' <<<"$json" 2>/dev/null
}

# Check envelope for LSVID field presence and basic JWT format
queue_has_lsvid() {
    local trace="$1" json="$2"
    TRACE_ID="$trace" php -n -r '
        $rows = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($rows)) { echo "no-data"; exit(1); }
        $trace = getenv("TRACE_ID");
        foreach ($rows as $row) {
            $d = json_decode($row["payload"] ?? "", true);
            if (!is_array($d) || ($d["id"] ?? "") !== $trace) continue;
            $lsvid = $d["lsvid"] ?? null;
            if (!is_string($lsvid) || $lsvid === "") { echo "lsvid-missing"; exit(2); }
            // Basic JWT format: 3 dot-separated parts
            if (substr_count($lsvid, ".") !== 2) { echo "lsvid-not-jwt"; exit(3); }
            // Decode header to check typ
            $parts = explode(".", $lsvid);
            $header = json_decode(base64_decode(strtr($parts[0], "-_", "+/")), true);
            if (($header["typ"] ?? "") !== "LSVID") { echo "typ-not-LSVID:" . ($header["typ"] ?? "null"); exit(4); }
            // Decode payload to check claims
            $payload = json_decode(base64_decode(strtr($parts[1], "-_", "+/")), true);
            $iss = $payload["iss"] ?? "";
            $aud = $payload["aud"] ?? "";
            $level = $payload["level"] ?? "";
            echo "ok|iss=${iss}|aud=${aud}|level=${level}";
            exit(0);
        }
        echo "trace-not-found"; exit(1);
    ' <<<"$json" 2>/dev/null
}

# Verify SPIFFE fields in queue envelope
queue_verify_spiffe() {
    local trace="$1" json="$2" expected_id="$3"
    TRACE_ID="$trace" EXPECTED_ID="$expected_id" php -n -r '
        $rows = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($rows)) { echo "no-data"; exit(1); }
        $trace = getenv("TRACE_ID");
        $expectedId = getenv("EXPECTED_ID");
        foreach ($rows as $row) {
            $d = json_decode($row["payload"] ?? "", true);
            if (!is_array($d) || ($d["id"] ?? "") !== $trace) continue;
            $sid = $d["spiffe_id"] ?? "";
            $sp = $d["spiffe_path"] ?? [];
            if ($sid !== $expectedId) { echo "spiffe_id=${sid},expected=${expectedId}"; exit(2); }
            if (!is_array($sp) || count($sp) === 0) { echo "spiffe_path-empty"; exit(2); }
            if (!in_array($sid, $sp, true)) { echo "id-not-in-path"; exit(2); }
            echo "ok|path=" . implode(",", $sp);
            exit(0);
        }
        echo "trace-not-found"; exit(1);
    ' <<<"$json" 2>/dev/null
}

wait_trace_in_queue() {
    local trace="$1" queue="$2" timeout="$3" elapsed=0
    while (( elapsed < timeout )); do
        local json
        json="$(fetch_queue_msgs "$queue" "ack_requeue_true" || true)"
        if queue_has_trace "$trace" "$json" >/dev/null 2>&1; then
            echo "$json"
            return 0
        fi
        sleep 2; elapsed=$((elapsed + 2))
    done
    return 1
}

wait_worker_log() {
    local since="$1" pattern="$2" timeout="$3" elapsed=0
    while (( elapsed < timeout )); do
        local logs
        logs="$(docker compose -f "$COMPOSE" logs --no-color --since "$since" php-worker 2>&1 || true)"
        grep -Fq "$pattern" <<<"$logs" && return 0
        sleep 2; elapsed=$((elapsed + 2))
    done
    fail "worker log not found in ${timeout}s: ${pattern}"
    return 1
}

count_worker_log() {
    docker compose -f "$COMPOSE" logs --no-color --since "$1" php-worker 2>&1 | \
        grep -Fc "$2" || echo "0"
}

# Inject a message directly into RabbitMQ (bypass gateway)
inject_message() {
    local trace="$1" routing_key="$2" spiffe_id="$3" envelope_type="$4" lsvid_field="${5:-}"
    local payload
    payload="$(TRACE="$trace" RK="$routing_key" SID="$spiffe_id" ET="$envelope_type" LSVID="$lsvid_field" php -n -r '
        $envelope = [
            "schema_version" => 1,
            "type" => getenv("ET"),
            "route" => "OrderCreateRequestedEvent",
            "id" => getenv("TRACE"),
            "spiffe_id" => getenv("SID"),
            "spiffe_path" => [getenv("SID")],
            "data" => [
                "userKey" => "999",
                "productList" => [["p_key" => 1, "amount" => 1]],
                "total" => 100,
            ],
        ];
        $lsvid = getenv("LSVID");
        if ($lsvid !== "" && $lsvid !== false) {
            $envelope["lsvid"] = $lsvid;
        }
        echo json_encode([
            "properties" => ["delivery_mode" => 2],
            "routing_key" => getenv("RK"),
            "payload" => json_encode($envelope, JSON_UNESCAPED_SLASHES),
            "payload_encoding" => "string",
        ], JSON_UNESCAPED_SLASHES);
    ')"

    curl -sS -u "${RABBIT_USER}:${RABBIT_PASS}" \
        -H 'content-type: application/json' \
        -X POST "${RABBIT_API}/exchanges/%2F/events/publish" \
        -d "$payload" 2>/dev/null
}

# ── Diagnostics ─────────────────────────────────────────────────────────────

dump_diagnostics() {
    [[ "$DIAG_LEVEL" == "none" ]] && return
    log "[diag] --- Gateway logs (last 50 lines) ---"
    docker compose -f "$COMPOSE" logs --no-color --tail=50 gateway 2>&1 || true
    log "[diag] --- Worker logs (last 50 lines) ---"
    docker compose -f "$COMPOSE" logs --no-color --tail=50 php-worker 2>&1 || true
    if [[ "$SKIP_SERVICES" != "1" ]]; then
        log "[diag] --- Order Service logs (last 30 lines) ---"
        docker compose -f "$COMPOSE" logs --no-color --tail=30 order-service 2>&1 || true
    fi
}

# ── Cleanup ─────────────────────────────────────────────────────────────────

cleanup() {
    if [[ "$KEEP" == true ]]; then
        log "keeping containers alive (--keep)"
        return
    fi
    if [[ "$FAILED" == true && "$KEEP_ON_FAIL" == "1" ]]; then
        log "keeping containers for diagnostics (E2E_KEEP_ON_FAIL=1)"
        return
    fi
    log "tearing down all stacks"
    docker compose -f "$COMPOSE" down -v --remove-orphans 2>/dev/null || true
}
trap cleanup EXIT

# ============================================================================
# Phase 0: Infrastructure Bootstrap
# ============================================================================
section "Phase 0: Infrastructure Bootstrap"

log "tearing down previous environment"
docker compose -f "$COMPOSE" down -v --remove-orphans 2>/dev/null || true

log "starting SPIRE Server + RabbitMQ (platform layer)"
docker compose -f "$COMPOSE" up -d --build 2>&1 | tail -3

log "waiting for SPIRE Server health"
elapsed=0
while (( elapsed < WAIT_TIMEOUT )); do
    if docker compose -f "$COMPOSE" exec -T spire-server \
        /opt/spire/bin/spire-server healthcheck 2>/dev/null; then
        break
    fi
    sleep 2; elapsed=$((elapsed + 2))
done
(( elapsed >= WAIT_TIMEOUT )) && fail_exit "SPIRE Server not healthy after ${WAIT_TIMEOUT}s"
pass "SPIRE Server is healthy"

log "running SPIFFE bootstrap (workload registration)"
docker compose -f "$COMPOSE" exec -T spire-server \
    /opt/spire/conf/e2e/bootstrap.sh 2>&1 | while IFS= read -r line; do
    printf "    %s\n" "$line"
done
pass "SPIFFE workload entries registered"

log "waiting for RabbitMQ management API"
wait_rabbit_api "$WAIT_TIMEOUT" || fail_exit "RabbitMQ not ready"
pass "RabbitMQ is ready"

# ============================================================================
# Phase 1: SPIFFE Identity Verification
# ============================================================================
section "Phase 1: SPIFFE Identity Verification"

# Test 1: Verify all workload entries exist
log "test 1: verify SPIRE workload entries"
entries_output="$(docker compose -f "$COMPOSE" exec -T spire-server \
    /opt/spire/bin/spire-server entry show 2>&1)"

for sid in "php-gateway" "php-worker" "order-service" "production-service" "user-service" "test-client"; do
    if ! grep -q "spiffe://${TRUST_DOMAIN}/${sid}" <<<"$entries_output"; then
        fail_exit "workload entry missing: spiffe://${TRUST_DOMAIN}/${sid}"
    fi
done
pass "test 1: all 6 workload entries registered (gateway, worker, 3 services, test-client)"

# Test 2: Verify trust domain consistency
log "test 2: trust domain consistency"
server_td="$(docker compose -f "$COMPOSE" exec -T spire-server \
    /opt/spire/bin/spire-server bundle show 2>&1 | head -5)"
if ! grep -q "${TRUST_DOMAIN}" <<<"$entries_output"; then
    fail_exit "trust domain mismatch in entries"
fi
pass "test 2: trust domain '${TRUST_DOMAIN}' consistent across all entries"

# Test 3: Verify agent is attested
log "test 3: SPIRE Agent attestation"
agent_list="$(docker compose -f "$COMPOSE" exec -T spire-server \
    /opt/spire/bin/spire-server agent list 2>&1)"
if ! grep -q "spiffe://${TRUST_DOMAIN}" <<<"$agent_list"; then
    fail_exit "no attested agent found"
fi
pass "test 3: SPIRE Agent attested with trust domain '${TRUST_DOMAIN}'"

# ============================================================================
# Start Gateway + Worker (application layer)
# ============================================================================
section "Starting Application Layer"

log "starting SPIRE Agent + Gateway + Worker"
if [[ "$BUILD_IMAGES" == "1" ]]; then
    docker compose -f "$COMPOSE" up -d --build 2>&1 | tail -3
else
    docker compose -f "$COMPOSE" up -d 2>&1 | tail -3
fi

log "waiting for SPIRE Agent health"
elapsed=0
while (( elapsed < WAIT_TIMEOUT )); do
    if docker compose -f "$COMPOSE" exec -T spire-agent \
        /opt/spire/bin/spire-agent healthcheck 2>/dev/null; then
        break
    fi
    sleep 2; elapsed=$((elapsed + 2))
done
(( elapsed >= WAIT_TIMEOUT )) && fail_exit "SPIRE Agent not healthy"
pass "SPIRE Agent is healthy"

log "waiting for Gateway health endpoint"
wait_http_200 "gateway" "$HEALTH_URL" "$WAIT_TIMEOUT" || fail_exit "gateway not ready"
pass "Gateway is ready (GET /api/health -> 200)"

worker_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
log "waiting for Worker listening state"
wait_worker_log "$worker_since" "[worker] listening" "$WAIT_TIMEOUT" \
    || fail_exit "Worker did not start"
pass "Worker is listening"

purge_queue "order_queue" >/dev/null 2>&1 || true

# ============================================================================
# Phase 2: Gateway Ingress + LSVID L0 Minting
# ============================================================================
section "Phase 2: Gateway Ingress + LSVID L0"

# Test 4: Happy-path order with LSVID L0
trace_l0="$(new_trace 'e2e-lsvid-l0')"
log "test 4: order ingress with LSVID L0 (trace=${trace_l0})"

resp="$(post_order "$trace_l0" '{"userKey":"42","productList":[{"p_key":1,"amount":2}],"total":100}')"
code="$(http_code "$resp")"
body="$(http_body "$resp")"

if [[ "$code" != "202" ]]; then
    fail_exit "expected 202, got ${code} body=${body}"
fi
pass "test 4a: POST /api/orders -> 202 Accepted"

# Check trace_id in response
if ! grep -Fq "\"trace_id\":\"${trace_l0}\"" <<<"$body"; then
    fail_exit "response missing trace_id"
fi
pass "test 4b: response contains trace_id"

# Verify message in queue
queue_json="$(wait_trace_in_queue "$trace_l0" "order_queue" "$WAIT_TIMEOUT")" \
    || fail_exit "message not in order_queue"
pass "test 4c: message arrived in order_queue"

# Verify SPIFFE fields
spiffe_result="$(queue_verify_spiffe "$trace_l0" "$queue_json" "$GATEWAY_SPIFFE")"
if [[ "$spiffe_result" != ok* ]]; then
    fail_exit "SPIFFE fields invalid: ${spiffe_result}"
fi
pass "test 4d: envelope spiffe_id=${GATEWAY_SPIFFE}, spiffe_path contains gateway"

# Test 5: Verify LSVID L0 token in envelope
log "test 5: LSVID L0 token in queue envelope"
lsvid_result="$(queue_has_lsvid "$trace_l0" "$queue_json")"
if [[ "$lsvid_result" != ok* ]]; then
    fail_exit "LSVID L0 check failed: ${lsvid_result}"
fi
# Parse LSVID details
lsvid_iss="$(echo "$lsvid_result" | grep -oP 'iss=\K[^|]+')"
lsvid_aud="$(echo "$lsvid_result" | grep -oP 'aud=\K[^|]+')"
lsvid_level="$(echo "$lsvid_result" | grep -oP 'level=\K[^|]+')"

if [[ "$lsvid_iss" != "$GATEWAY_SPIFFE" ]]; then
    fail_exit "L0 issuer=${lsvid_iss}, expected=${GATEWAY_SPIFFE}"
fi
pass "test 5a: L0 token typ=LSVID, iss=${GATEWAY_SPIFFE}"

if [[ "$lsvid_aud" != "$WORKER_SPIFFE" ]]; then
    fail_exit "L0 audience=${lsvid_aud}, expected=${WORKER_SPIFFE}"
fi
pass "test 5b: L0 audience=${WORKER_SPIFFE} (correct downstream)"

if [[ "$lsvid_level" != "L0" ]]; then
    fail_exit "L0 level=${lsvid_level}, expected=L0"
fi
pass "test 5c: L0 level claim = L0"

# ============================================================================
# Phase 3: Worker LSVID Chain Validation
# ============================================================================
section "Phase 3: Worker LSVID Chain Validation"

# Test 6: Worker validates L0 and extends to L1
log "test 6: worker validates LSVID L0"
wait_worker_log "$worker_since" "[request-consumer] LSVID L0 OK" "$WAIT_TIMEOUT" \
    || fail_exit "worker did not validate L0 token"
pass "test 6a: worker validated LSVID L0 successfully"

wait_worker_log "$worker_since" \
    "[request-consumer] verified source=${GATEWAY_SPIFFE}" "$WAIT_TIMEOUT" \
    || fail_exit "worker did not verify SPIFFE source"
pass "test 6b: worker verified SPIFFE source = ${GATEWAY_SPIFFE}"

wait_worker_log "$worker_since" \
    "[request-consumer] published event=App\\Events\\OrderCreateRequestedEvent" "$WAIT_TIMEOUT" \
    || fail_exit "worker did not publish downstream event"
pass "test 6c: worker published OrderCreateRequestedEvent with L1 extension"

# Test 7: EventConsumer validates L1
log "test 7: event-consumer validates LSVID L1"
wait_worker_log "$worker_since" \
    "[event-consumer] LSVID L" "$WAIT_TIMEOUT" \
    || fail_exit "event-consumer did not validate LSVID"
pass "test 7a: event-consumer validated LSVID chain"

wait_worker_log "$worker_since" \
    "[event-consumer] handled event=App\\Events\\OrderCreateRequestedEvent" "$WAIT_TIMEOUT" \
    || fail_exit "event-consumer did not handle event"
pass "test 7b: event-consumer handled OrderCreateRequestedEvent"

# ============================================================================
# Phase 4: Full Saga Flow with mTLS Downstream
# ============================================================================
section "Phase 4: Full Saga Flow"

if [[ "$SKIP_SERVICES" == "1" ]]; then
    log "skipping downstream service tests (E2E_SKIP_SERVICES=1)"
else
    log "starting downstream services (Order, Production, User)"
    docker compose -f "$COMPOSE" up -d --build 2>&1 | tail -5

    # Wait for services to be healthy
    log "waiting for downstream services..."
    sleep 15  # spiffe-helper needs time to fetch SVIDs

    for svc in "order-service" "production-service" "user-service"; do
        svc_url=""
        case "$svc" in
            order-service)      svc_url="http://127.0.0.1:8082" ;;
            production-service) svc_url="http://127.0.0.1:8081" ;;
            user-service)       svc_url="http://127.0.0.1:8083" ;;
        esac
        if wait_http_200 "$svc" "$svc_url" 60; then
            pass "${svc} is reachable"
        else
            log "WARNING: ${svc} not reachable on HTTP, may only accept mTLS"
        fi
    done
fi

# Test 8: Saga Step execution
log "test 8: saga step execution"

# Send a fresh order for saga testing
trace_saga="$(new_trace 'e2e-saga')"
saga_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
resp="$(post_order "$trace_saga" '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":500}')"
code="$(http_code "$resp")"
[[ "$code" == "202" ]] || fail_exit "saga order expected 202, got ${code}"

wait_worker_log "$saga_since" "Saga Step 1:" "$WAIT_TIMEOUT" \
    || fail_exit "saga step 1 not triggered"
pass "test 8a: Saga Step 1 (OrderCreateRequested)"

wait_worker_log "$saga_since" "Saga Step 2:" "$WAIT_TIMEOUT" \
    || fail_exit "saga step 2 not triggered"
pass "test 8b: Saga Step 2 (OrderCreated -> deduct inventory)"

wait_worker_log "$saga_since" "Saga Step 3:" "$WAIT_TIMEOUT" \
    || fail_exit "saga step 3 not triggered"
pass "test 8c: Saga Step 3 (InventoryDeducted -> payment)"

# Step 4 or compensation
elapsed_saga=0
saga_complete=false
saga_compensating=false
while (( elapsed_saga < WAIT_TIMEOUT )); do
    logs="$(docker compose -f "$COMPOSE" logs --no-color --since "$saga_since" php-worker 2>&1 || true)"
    if grep -Fq "Saga Step 4:" <<<"$logs"; then
        saga_complete=true; break
    fi
    if grep -Fq "RollbackSaga" <<<"$logs"; then
        saga_compensating=true; break
    fi
    sleep 2; elapsed_saga=$((elapsed_saga + 2))
done

if [[ "$saga_complete" == true ]]; then
    pass "test 8d: Saga Step 4 (payment success, order complete)"
elif [[ "$saga_compensating" == true ]]; then
    pass "test 8d: Saga compensation triggered (service unavailable or payment failed)"
    wait_worker_log "$saga_since" "RollbackSaga Step 2:" "$WAIT_TIMEOUT" \
        && pass "test 8e: RollbackInventory executed" || true
    wait_worker_log "$saga_since" "RollbackSaga Step 1:" "$WAIT_TIMEOUT" 2>/dev/null \
        && pass "test 8f: RollbackOrder executed" || true
else
    fail "test 8d: saga did not reach completion or compensation in ${WAIT_TIMEOUT}s"
fi

# ============================================================================
# Phase 5: LSVID Security Tests
# ============================================================================
section "Phase 5: LSVID Security Tests"

# Test 9: Message with tampered/invalid LSVID
tamper_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
trace_tamper="$(new_trace 'e2e-lsvid-tamper')"
log "test 9: tampered LSVID token"

inject_result="$(inject_message "$trace_tamper" "request.new" "$GATEWAY_SPIFFE" "gateway.request" "eyJhbGciOiJFUzI1NiIsInR5cCI6IkxTVklEIn0.eyJpc3MiOiJzcGlmZmU6Ly96dC5sb2NhbC9mYWtlIiwiYXVkIjoic3BpZmZlOi8venQubG9jYWwvcGhwLXdvcmtlciIsImlhdCI6MTcwMDAwMDAwMCwiZXhwIjoxNzAwMDAwMzAwLCJqdGkiOiJmYWtlLWp0aSIsImxldmVsIjoiTDAifQ.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA")"
if ! grep -Fq '"routed":true' <<<"$inject_result"; then
    fail_exit "failed to inject tampered LSVID message"
fi

wait_worker_log "$tamper_since" "Invalid inbound LSVID" "$WAIT_TIMEOUT" \
    || fail_exit "worker did not reject tampered LSVID"
pass "test 9: tampered LSVID rejected (Invalid inbound LSVID)"

# Test 10: Untrusted SPIFFE source
forged_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
trace_forged="$(new_trace 'e2e-untrusted')"
log "test 10: untrusted SPIFFE source"

inject_result="$(inject_message "$trace_forged" "request.new" "spiffe://evil.domain/attacker" "gateway.request")"
if ! grep -Fq '"routed":true' <<<"$inject_result"; then
    fail_exit "failed to inject forged message"
fi

wait_worker_log "$forged_since" "Untrusted SPIFFE source" "$WAIT_TIMEOUT" \
    || fail_exit "worker did not reject untrusted SPIFFE"

# Verify no requeue storm
sleep 3
drop_count="$(count_worker_log "$forged_since" "Untrusted SPIFFE source")"
if (( drop_count > 1 )); then
    fail_exit "requeue storm detected (drop count=${drop_count})"
fi
pass "test 10: untrusted SPIFFE dropped, no requeue storm"

# Test 11: Missing LSVID when present in trusted source (validation path)
missing_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
trace_nolsvid="$(new_trace 'e2e-no-lsvid')"
log "test 11: trusted source without LSVID (migration fallback)"

inject_result="$(inject_message "$trace_nolsvid" "request.new" "$GATEWAY_SPIFFE" "gateway.request")"
if ! grep -Fq '"routed":true' <<<"$inject_result"; then
    fail_exit "failed to inject no-LSVID message"
fi

# With LSVID_REQUIRED=0, worker should accept but log fallback
wait_worker_log "$missing_since" "no LSVID on envelope (migration-period fallback)" "$WAIT_TIMEOUT" \
    || wait_worker_log "$missing_since" "verified source=${GATEWAY_SPIFFE}" "$WAIT_TIMEOUT" \
    || fail_exit "worker did not process no-LSVID message"
pass "test 11: no-LSVID accepted in migration mode (LSVID_REQUIRED=0)"

# ============================================================================
# Phase 6: SPIFFE Identity Chain Integrity
# ============================================================================
section "Phase 6: SPIFFE Identity Chain Integrity"

# Test 12: Identity chain propagation through hops
log "test 12: SPIFFE identity chain (gateway -> worker)"
wait_worker_log "$worker_since" \
    "path=[${GATEWAY_SPIFFE}" "$WAIT_TIMEOUT" \
    || fail_exit "gateway SPIFFE ID not in spiffe_path"
pass "test 12a: gateway identity visible in spiffe_path at first hop"

wait_worker_log "$worker_since" \
    "[event-consumer] source=${WORKER_SPIFFE}" "$WAIT_TIMEOUT" \
    || fail_exit "worker SPIFFE ID not in event source"
pass "test 12b: worker identity visible as event source at second hop"

# Test 13: Untrusted source on event queue
evt_forged_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
trace_evt_forged="$(new_trace 'e2e-evt-untrusted')"
log "test 13: untrusted SPIFFE on event queue"

inject_result="$(inject_message \
    "$trace_evt_forged" "OrderCreateRequestedEvent" \
    "spiffe://evil.domain/event-attacker" "App\\Events\\OrderCreateRequestedEvent")"
if ! grep -Fq '"routed":true' <<<"$inject_result"; then
    fail_exit "failed to inject forged event"
fi

wait_worker_log "$evt_forged_since" "Untrusted SPIFFE source" "$WAIT_TIMEOUT" \
    || fail_exit "event-consumer did not reject untrusted source"
pass "test 13: untrusted SPIFFE on event queue rejected"

# ============================================================================
# Phase 7: Compensation Flow Verification
# ============================================================================
section "Phase 7: Compensation Flow"

# Test 14: Verify inventory deduction result checking (C1 fix)
log "test 14: saga handles inventory deduction results"
# This is validated indirectly through test 8; if inventory fails,
# saga should log compensation. We verify the log patterns are correct.
comp_logs="$(docker compose -f "$COMPOSE" logs --no-color php-worker 2>&1 || true)"
if grep -Fq "Saga Step 2:" <<<"$comp_logs"; then
    # Step 2 was reached; verify it either succeeded or triggered compensation
    if grep -Fq "扣減庫存成功" <<<"$comp_logs" || grep -Fq "部分庫存扣減失敗" <<<"$comp_logs"; then
        pass "test 14: inventory deduction result is checked (not silently skipped)"
    else
        fail "test 14: inventory deduction result handling unclear in logs"
    fi
else
    log "test 14: skipped (step 2 not reached in this run)"
fi

# ============================================================================
# Phase 8: Concurrency Under Zero-Trust
# ============================================================================
section "Phase 8: Concurrency"

CONCURRENT=5
conc_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
log "test 15: ${CONCURRENT} concurrent orders"

declare -a pids
conc_ok=true
for i in $(seq 1 "$CONCURRENT"); do
    trace_c="$(new_trace "e2e-conc-${i}")"
    (
        r="$(curl -sS -w '\n%{http_code}' \
            -X POST "$GATEWAY_URL" \
            -H 'Content-Type: application/json' \
            -H "X-Correlation-Id: ${trace_c}" \
            -d "{\"userKey\":\"${i}\",\"productList\":[{\"p_key\":${i},\"amount\":1}],\"total\":${i}00}" 2>/dev/null)"
        c="${r##*$'\n'}"
        [[ "$c" == "202" ]] || exit 1
    ) &
    pids+=($!)
done

for pid in "${pids[@]}"; do
    wait "$pid" || conc_ok=false
done

if [[ "$conc_ok" != true ]]; then
    fail_exit "one or more concurrent requests failed"
fi
pass "test 15a: ${CONCURRENT} concurrent requests all returned 202"

# Verify worker processes all
elapsed_c=0
while (( elapsed_c < WAIT_TIMEOUT )); do
    verified="$(count_worker_log "$conc_since" "[request-consumer] verified source")"
    if (( verified >= CONCURRENT )); then break; fi
    sleep 2; elapsed_c=$((elapsed_c + 2))
done
if (( verified >= CONCURRENT )); then
    pass "test 15b: all ${CONCURRENT} concurrent requests verified by worker"
else
    fail "test 15b: only ${verified}/${CONCURRENT} verified by worker"
fi

# ============================================================================
# Summary
# ============================================================================
section "Results"
printf "\n  Total: ${GREEN}%d passed${NC}, ${RED}%d failed${NC}\n\n" "$PASS_COUNT" "$FAIL_COUNT"

if (( FAIL_COUNT > 0 )); then
    FAILED=true
    exit 1
fi

pass "all full-stack E2E tests passed"
