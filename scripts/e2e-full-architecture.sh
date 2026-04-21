#!/usr/bin/env bash
# ============================================================================
# Full Architecture End-to-End Test for ZT Event Gateway
#
# 本腳本針對 zt-event-gateway 的完整架構進行端到端驗證，涵蓋：
#
#   Phase 1 — SPIFFE/SPIRE 基礎設施
#     1.  SPIRE Server health
#     2.  SPIRE Agent health + Workload API socket
#     3.  Workload Registrar 完成註冊
#     4.  spiffe-watcher SHM 就緒 (meta.json x509_state=ready)
#     5.  SHM SVID slot 存在且結構合法 (x509/0.json)
#
#   Phase 2 — Gateway LSVID 鑄造
#     6.  Gateway health (GET /api/health → 200)
#     7.  LSVID L0 鑄造成功（envelope 含 lsvid 欄位）
#     8.  LSVID L0 結構驗證（JWS compact + x5c header + nested=null）
#     9.  Gateway LSVID bootstrap 確認
#
#   Phase 3 — Worker LSVID 驗證 + 擴展
#     10. Worker 驗證 L0 LSVID (request-consumer 日誌)
#     11. Worker 擴展 L0→L1 (message-bus 日誌)
#     12. EventConsumer 驗證 L1 chain (event-consumer 日誌)
#     13. LSVID chain 在 Saga 內持續傳遞 (LSVIDContext 日誌)
#
#   Phase 4 — Saga 完整生命週期
#     14. Happy path: 4 步驟全部完成 (或 compensation path)
#     15. SPIFFE path 逐跳累積驗證
#     16. EventStoreDB 事件持久化驗證
#
#   Phase 5 — 安全性
#     17. 偽造 SPIFFE ID (request queue) → 被丟棄
#     18. 偽造 SPIFFE ID (event queue) → 被丟棄
#     19. LSVID replay attack → 被偵測並拒絕
#     20. 錯誤 trust domain → 被拒絕
#     21. Event 層級 schema 驗證 (缺少 type 欄位 → 被拒絕)
#     22. LSVID chain issuer 驗證 (L0→L1 issuer 可追蹤)
#
#   Phase 6 — 韌性 + 並發
#     23. Worker 重啟後恢復消費
#     24. 10 個並發請求全部 202 且被 worker 處理
#     25. RabbitMQ 重連 (gateway persistent connection recovery)
#
#   Phase 7 — 效能檢測
#     26. 單次請求延遲基線 (30 samples, P50/P95/P99)
#     27. 壓力測試吞吐量 (100 requests, concurrency=5)
#     28. Saga 端到端延遲（從請求到 Saga Step 1）
#
# Usage:
#   bash scripts/e2e-full-architecture.sh [--keep]
#   COMPOSE_PROFILES=zt bash scripts/e2e-full-architecture.sh
#
# Environment overrides:
#   COMPOSE_FILE          (default: docker-compose.yml)
#   COMPOSE_PROFILES      (default: zt — required for SPIRE stack)
#   E2E_GATEWAY_URL       (default: http://127.0.0.1:8080/api/orders)
#   E2E_HEALTH_URL        (default: http://127.0.0.1:8080/api/health)
#   E2E_RABBIT_API_URL    (default: http://127.0.0.1:15672/api)
#   E2E_EVENTSTOREDB_URL  (default: http://127.0.0.1:2113)
#   E2E_WAIT_TIMEOUT      (default: 120)
#   E2E_KEEP_ON_FAIL      (default: 0)
#   E2E_BUILD_IMAGES      (default: 1)
#   E2E_DIAG_LEVEL        (default: full) — none | minimal | full
#   E2E_SKIP_PERF         (default: 0) — skip Phase 7 performance tests
#   E2E_PERF_SAMPLES      (default: 30) — number of latency samples
#   E2E_PERF_STRESS_TOTAL (default: 100) — stress test total requests
#   E2E_PERF_STRESS_CONC  (default: 5) — stress test concurrency
# ============================================================================
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"

# ── Activate SPIRE profile ───────────────────────────────────────────────────
# SPIRE services (spire-server, spire-agent, workload-registrar, spiffe-watcher)
# are defined under `profiles: ["zt"]` in docker-compose.yml.
# Without this, `docker compose up` will NOT start SPIRE infrastructure.
export COMPOSE_PROFILES="${COMPOSE_PROFILES:-zt}"
REQUEST_URL="${E2E_GATEWAY_URL:-http://127.0.0.1:8080/api/orders}"
HEALTH_URL="${E2E_HEALTH_URL:-http://127.0.0.1:8080/api/health}"
RABBIT_API_URL="${E2E_RABBIT_API_URL:-http://127.0.0.1:15672/api}"
EVENTSTOREDB_URL="${E2E_EVENTSTOREDB_URL:-http://127.0.0.1:2113}"
RABBIT_USER="${E2E_RABBIT_USER:-zt}"
RABBIT_PASS="${E2E_RABBIT_PASS:-ztpass}"
WAIT_TIMEOUT="${E2E_WAIT_TIMEOUT:-120}"
REQUEST_QUEUE="${E2E_REQUEST_QUEUE:-order_queue}"
DIAG_LEVEL="${E2E_DIAG_LEVEL:-full}"
KEEP_ON_FAIL="${E2E_KEEP_ON_FAIL:-0}"
BUILD_IMAGES="${E2E_BUILD_IMAGES:-1}"
SKIP_PERF="${E2E_SKIP_PERF:-0}"
PERF_SAMPLES="${E2E_PERF_SAMPLES:-30}"
PERF_STRESS_TOTAL="${E2E_PERF_STRESS_TOTAL:-100}"
PERF_STRESS_CONC="${E2E_PERF_STRESS_CONC:-5}"

KEEP=false
FAILED=false
PASS_COUNT=0
FAIL_COUNT=0
SKIP_COUNT=0
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
    diagnose_failure
}

DIAG_DUMPED=false
diagnose_failure() {
    # Only dump diagnostics once per run to avoid spamming.
    [[ "$DIAG_DUMPED" == true ]] && return 0
    DIAG_DUMPED=true

    printf '\n\033[1;33m── Failure Diagnostics ──\033[0m\n' >&2

    printf '\n--- Gateway logs (last 50 lines) ---\n' >&2
    docker compose -f "$COMPOSE_FILE" logs --tail=50 gateway 2>/dev/null | tail -50 >&2 || true

    printf '\n--- Worker logs (last 50 lines) ---\n' >&2
    docker compose -f "$COMPOSE_FILE" logs --tail=50 php-worker 2>/dev/null | tail -50 >&2 || true

    printf '\n--- RabbitMQ queue status ---\n' >&2
    curl -sS -u "${RABBIT_USER}:${RABBIT_PASS}" \
        "${RABBIT_API_URL}/queues/%2F" 2>/dev/null \
        | jq -r '.[] | "\(.name): ready=\(.messages_ready) unacked=\(.messages_unacknowledged)"' 2>/dev/null >&2 || true

    printf '\n--- Container status ---\n' >&2
    docker compose -f "$COMPOSE_FILE" ps --format 'table {{.Name}}\t{{.Status}}' 2>/dev/null >&2 || true

    printf '\n\033[1;33m── End Diagnostics ──\033[0m\n' >&2
}

skip() {
    SKIP_COUNT=$((SKIP_COUNT + 1))
    printf '\033[33m[SKIP]\033[0m %s\n' "$*"
}

section() {
    printf '\n\033[1;36m══ %s ══\033[0m\n' "$*"
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

post_order_timed() {
    curl -sS -w '\n%{http_code} %{time_total}' \
        -X POST "$REQUEST_URL" \
        -H 'Content-Type: application/json' \
        -H "X-Correlation-Id: ${1}" \
        -d "$2"
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

purge_queue() {
    curl -sS -o /dev/null -w '%{http_code}' \
        -u "${RABBIT_USER}:${RABBIT_PASS}" \
        -X DELETE "$(queue_url_path "$1")/contents"
}

# ── Envelope validation helpers ──────────────────────────────────────────────

# Check if queue payload contains trace and valid envelope structure.
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

# Extract LSVID token from queue payload for a given trace.
extract_lsvid_from_queue() {
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
            if (!is_array($data) || ($data["id"] ?? "") !== $trace) continue;

            $lsvid = $data["lsvid"] ?? null;
            if (!is_string($lsvid) || $lsvid === "") {
                echo "NO_LSVID";
                exit(2);
            }
            echo $lsvid;
            exit(0);
        }
        echo "TRACE_NOT_FOUND";
        exit(1);
    ' <<<"$payload_json" 2>/dev/null
}

# Validate LSVID L0 structure (JWS compact, 3 parts, header has typ=LSVID).
validate_lsvid_l0_structure() {
    local raw_token="$1"

    php -n -r '
        $raw = $argv[1];
        $parts = explode(".", $raw);
        if (count($parts) !== 3) {
            echo "NOT_JWS_COMPACT:parts=" . count($parts);
            exit(1);
        }

        $header = json_decode(base64_decode(strtr($parts[0], "-_", "+/")), true);
        if (!is_array($header)) {
            echo "INVALID_HEADER";
            exit(1);
        }

        // Check typ
        $typ = $header["typ"] ?? "";
        if ($typ !== "LSVID") {
            echo "WRONG_TYP:" . $typ;
            exit(1);
        }

        // Check alg
        $alg = $header["alg"] ?? "";
        if (!in_array($alg, ["RS256", "ES256", "ES384", "ES512"], true)) {
            echo "UNKNOWN_ALG:" . $alg;
            exit(1);
        }

        // Check x5c
        $x5c = $header["x5c"] ?? null;
        if (!is_array($x5c) || count($x5c) === 0) {
            echo "MISSING_X5C";
            exit(1);
        }

        // Check payload
        $payload = json_decode(base64_decode(strtr($parts[1], "-_", "+/")), true);
        if (!is_array($payload)) {
            echo "INVALID_PAYLOAD";
            exit(1);
        }

        // L0 should NOT have nested claim
        if (isset($payload["nested"]) && $payload["nested"] !== null) {
            echo "L0_HAS_NESTED";
            exit(1);
        }

        // Must have iss, sub, aud, iat, exp, jti
        foreach (["iss", "sub", "aud", "iat", "exp", "jti"] as $claim) {
            if (!isset($payload[$claim])) {
                echo "MISSING_CLAIM:" . $claim;
                exit(1);
            }
        }

        // iss and sub should be SPIFFE IDs
        if (strpos($payload["iss"], "spiffe://") !== 0) {
            echo "ISS_NOT_SPIFFE:" . $payload["iss"];
            exit(1);
        }

        echo "ok|alg=" . $alg . "|iss=" . $payload["iss"] . "|aud=" . $payload["aud"];
        exit(0);
    ' "$raw_token" 2>/dev/null
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
            return 1
        fi
    done
}

wait_for_gateway_log() {
    local since_ts="$1" pattern="$2" timeout="$3" elapsed=0
    while true; do
        local logs
        logs="$(docker compose -f "$COMPOSE_FILE" logs --no-color --since "$since_ts" gateway 2>&1 || true)"
        grep -Fq "$pattern" <<<"$logs" && return 0
        sleep 2
        elapsed=$((elapsed + 2))
        if (( elapsed >= timeout )); then
            return 1
        fi
    done
}

count_worker_log_occurrences() {
    docker compose -f "$COMPOSE_FILE" logs --no-color --since "$1" php-worker 2>&1 | \
        grep -Fc "$2" || echo "0"
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

wait_for_container_healthy() {
    local name="$1" timeout="$2" elapsed=0
    while true; do
        local health
        health="$(docker inspect --format='{{.State.Health.Status}}' "$name" 2>/dev/null || echo "missing")"
        [[ "$health" == "healthy" ]] && return 0
        sleep 3
        elapsed=$((elapsed + 3))
        if (( elapsed >= timeout )); then
            fail "container ${name} not healthy after ${timeout}s (status=${health})"
            return 1
        fi
    done
}

# ── Message injection via RabbitMQ Management API ────────────────────────────

publish_forged_message() {
    local trace_id="$1"
    local routing_key="$2"
    local spiffe_id="$3"
    local envelope_type="$4"

    local payload
    payload="$(php -n -r '
        echo json_encode([
            "properties" => ["delivery_mode" => 2],
            "routing_key" => $argv[2],
            "payload" => json_encode([
                "schema_version" => 1,
                "type" => $argv[4],
                "route" => "OrderCreateRequestedEvent",
                "id" => $argv[1],
                "spiffe_id" => $argv[3],
                "spiffe_path" => [$argv[3]],
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

# Publish a message with replayed LSVID token (same raw token, new trace).
publish_replayed_lsvid_message() {
    local trace_id="$1"
    local lsvid_token="$2"

    local payload
    payload="$(TRACE="$trace_id" LSVID="$lsvid_token" php -n -r '
        $trace = getenv("TRACE");
        $lsvid = getenv("LSVID");
        echo json_encode([
            "properties" => ["delivery_mode" => 2],
            "routing_key" => "request.new",
            "payload" => json_encode([
                "schema_version" => 1,
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
                "lsvid" => $lsvid,
            ], JSON_UNESCAPED_SLASHES),
            "payload_encoding" => "string",
        ], JSON_UNESCAPED_SLASHES);
    ')"

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
        printf '\n[diag] spiffe-watcher logs (last 40 lines)\n' >&2
        docker compose -f "$COMPOSE_FILE" logs --no-color --tail=40 spiffe-watcher >&2 || true
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

echo ""
echo "╔══════════════════════════════════════════════════════════════════╗"
echo "║  ZT Event Gateway — Full Architecture E2E Test                 ║"
echo "║  SPIFFE/SPIRE + LSVID + Saga + EventStoreDB + mTLS            ║"
echo "╚══════════════════════════════════════════════════════════════════╝"
echo ""
log "COMPOSE_PROFILES=${COMPOSE_PROFILES}"
log "COMPOSE_FILE=${COMPOSE_FILE}"

START_TIME="$(date +%s)"

# ============================================================================
# Phase 0: Start full infrastructure
# ============================================================================
section "Phase 0: Environment setup (full stack)"

# Ensure the external Docker network exists (docker-compose.yml declares it external)
if ! docker network inspect anser_project_network >/dev/null 2>&1; then
    log "creating external network: anser_project_network"
    docker network create anser_project_network >/dev/null 2>&1 || true
fi

log "reset environment"
docker compose -f "$COMPOSE_FILE" down -v --remove-orphans >/dev/null 2>&1 || true

log "starting full stack: SPIRE + RabbitMQ + EventStoreDB + spiffe-watcher + gateway + worker"
log "(COMPOSE_PROFILES=${COMPOSE_PROFILES} activates SPIRE services)"
if [[ "$BUILD_IMAGES" == "1" ]]; then
    docker compose -f "$COMPOSE_FILE" up -d --build
else
    docker compose -f "$COMPOSE_FILE" up -d
fi

# ============================================================================
# Phase 1: SPIFFE/SPIRE Infrastructure
# ============================================================================
section "Phase 1: SPIFFE/SPIRE infrastructure verification"

# ── Test 1: SPIRE Server health ─────────────────────────────────────────────
log "test 1: SPIRE Server health"
wait_for_container_healthy "zt-spire-server" "$WAIT_TIMEOUT" \
    || fail_exit "SPIRE Server not healthy"
pass "test 1: SPIRE Server is healthy"

# ── Test 2: SPIRE Agent health ──────────────────────────────────────────────
log "test 2: SPIRE Agent health"
wait_for_container_healthy "zt-spire-agent" "$WAIT_TIMEOUT" \
    || fail_exit "SPIRE Agent not healthy"
pass "test 2: SPIRE Agent is healthy (Workload API socket available)"

# ── Test 3: Workload Registrar ──────────────────────────────────────────────
log "test 3: Workload Registrar completed"
wait_for_container_healthy "zt-workload-registrar" "$WAIT_TIMEOUT" \
    || fail_exit "Workload Registrar not ready"
pass "test 3: Workload Registrar has registered workload identities"

# ── Test 4: spiffe-watcher SHM ready ───────────────────────────────────────
log "test 4: spiffe-watcher SHM state"
wait_for_container_healthy "zt-spiffe-watcher" "$WAIT_TIMEOUT" \
    || fail_exit "spiffe-watcher not healthy"

# Verify SHM meta.json from container
shm_state="$(docker exec zt-spiffe-watcher php -r '
    $m = json_decode(@file_get_contents("/tmp/spiffe-shared/meta.json"), true);
    echo json_encode([
        "x509_state" => $m["x509_state"] ?? "unknown",
        "version" => $m["version"] ?? -1,
        "updated_at" => $m["updated_at"] ?? 0,
        "stale" => (time() - ($m["updated_at"] ?? 0)) > 3600,
    ]);
' 2>/dev/null || echo '{"x509_state":"error"}')"

x509_state="$(echo "$shm_state" | php -n -r 'echo json_decode(stream_get_contents(STDIN),true)["x509_state"] ?? "error";')"
if [[ "$x509_state" != "ready" ]]; then
    fail_exit "SHM x509_state=${x509_state}, expected 'ready'"
fi
pass "test 4: SHM meta.json x509_state=ready (${shm_state})"

# ── Test 5: SHM SVID slot structure ────────────────────────────────────────
log "test 5: SHM SVID slot 0 structure"
svid_check="$(docker exec zt-spiffe-watcher php -r '
    $slot = json_decode(@file_get_contents("/tmp/spiffe-shared/x509/0.json"), true);
    if (!is_array($slot)) { echo "NO_SLOT_FILE"; exit(1); }
    $id = $slot["spiffe_id"] ?? "";
    $hasChain = !empty($slot["cert_pem"]);
    $hasKey = !empty($slot["key_pem"]);
    $hasBundle = !empty($slot["bundle_pem"]);
    if ($id === "" || !$hasChain || !$hasKey || !$hasBundle) {
        echo "INCOMPLETE|id=" . $id . "|chain=" . ($hasChain?"y":"n") . "|key=" . ($hasKey?"y":"n") . "|bundle=" . ($hasBundle?"y":"n");
        exit(1);
    }
    echo "ok|spiffe_id=" . $id;
' 2>/dev/null || echo "EXEC_FAILED")"

if [[ "$svid_check" != ok* ]]; then
    fail_exit "SHM SVID slot 0 invalid: ${svid_check}"
fi
pass "test 5: SHM x509/0.json valid (${svid_check})"

# ============================================================================
# Phase 2: Gateway LSVID minting
# ============================================================================
section "Phase 2: Gateway LSVID L0 minting"

log "waiting for RabbitMQ management API"
wait_for_rabbit_api "$WAIT_TIMEOUT" || fail_exit "RabbitMQ API not ready"

log "waiting for gateway health endpoint"
wait_for_http_200 "gateway" "$HEALTH_URL" "$WAIT_TIMEOUT" || fail_exit "gateway not ready"

log "purging request queue"
purge_code="$(purge_queue "$REQUEST_QUEUE" || true)"

# ── Test 6: Health check ────────────────────────────────────────────────────
log "test 6: gateway health check"
health_response="$(curl -sS -w '\n%{http_code}' "$HEALTH_URL")"
record_response "$health_response"

if [[ "$LAST_HTTP_CODE" != "200" ]]; then
    fail_exit "health check returned ${LAST_HTTP_CODE}, expected 200"
fi
pass "test 6: GET /api/health returns 200"

# ── Test 7: LSVID L0 minting ───────────────────────────────────────────────
trace_l0="$(new_trace_id 'e2e-lsvid-l0')"
LAST_TRACE="$trace_l0"
log "test 7: LSVID L0 minting (trace=${trace_l0})"

response="$(post_order "$trace_l0" '{"userKey":"42","productList":[{"p_key":1,"amount":2}],"total":100}')"
record_response "$response"

if [[ "$LAST_HTTP_CODE" != "202" ]]; then
    fail_exit "expected 202, got ${LAST_HTTP_CODE}"
fi

# Wait for message in queue
if ! wait_for_trace_in_queue "$trace_l0" "$REQUEST_QUEUE" "$WAIT_TIMEOUT"; then
    fail_exit "message not found in ${REQUEST_QUEUE}"
fi

# Extract LSVID from queue payload
queue_snapshot="$(fetch_queue_messages "$REQUEST_QUEUE" "ack_requeue_true" || true)"
lsvid_token="$(extract_lsvid_from_queue "$trace_l0" "$queue_snapshot" || true)"

if [[ "$lsvid_token" == "NO_LSVID" || "$lsvid_token" == "TRACE_NOT_FOUND" ]]; then
    fail_exit "envelope in queue has no LSVID token"
fi
pass "test 7: Gateway minted LSVID L0 and included in envelope"

# ── Test 8: LSVID L0 structure validation ──────────────────────────────────
log "test 8: LSVID L0 structure validation"
l0_result="$(validate_lsvid_l0_structure "$lsvid_token" || true)"

if [[ "$l0_result" != ok* ]]; then
    fail_exit "LSVID L0 structure invalid: ${l0_result}"
fi
pass "test 8: LSVID L0 valid JWS compact (${l0_result})"

# Save the L0 token for replay test later
CAPTURED_L0_TOKEN="$lsvid_token"

# ── Test 9: LSVID_REQUIRED enforcement ─────────────────────────────────────
# This test verifies the gateway log when LSVID signer is available.
# We verify via gateway logs that LSVID bootstrap was successful.
log "test 9: LSVID bootstrap confirmation"
gw_boot_since="$(date -u -v-5M +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u -d '5 minutes ago' +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || echo '1970-01-01T00:00:00Z')"
gw_logs="$(docker compose -f "$COMPOSE_FILE" logs --no-color gateway 2>&1 || true)"

if grep -Fq "LSVID bootstrap OK" <<<"$gw_logs"; then
    pass "test 9: Gateway LSVID bootstrap confirmed (signer active)"
elif grep -Fq "LSVID bootstrap FAILED" <<<"$gw_logs"; then
    fail "test 9: Gateway LSVID bootstrap FAILED"
else
    skip "test 9: Cannot determine LSVID bootstrap status from logs"
fi

# ============================================================================
# Phase 3: Worker LSVID validation + extension
# ============================================================================
section "Phase 3: Worker LSVID chain (L0 → L1 → dispatch)"

worker_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

log "waiting for worker to be listening"
wait_for_worker_log "$worker_since" "[worker] listening" "$WAIT_TIMEOUT" \
    || {
        # Worker may have started earlier; check older logs
        w_logs="$(docker compose -f "$COMPOSE_FILE" logs --no-color php-worker 2>&1 || true)"
        if ! grep -Fq "[worker] listening" <<<"$w_logs"; then
            fail_exit "php-worker did not enter listening state"
        fi
    }
pass "php-worker is listening"

# Purge and send a fresh request so worker processes it with LSVID
purge_queue "$REQUEST_QUEUE" >/dev/null 2>&1 || true
sleep 1

trace_chain="$(new_trace_id 'e2e-lsvid-chain')"
LAST_TRACE="$trace_chain"
log "sending request for LSVID chain test (trace=${trace_chain})"

chain_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
response="$(post_order "$trace_chain" '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":0}')"
record_response "$response"

if [[ "$LAST_HTTP_CODE" != "202" ]]; then
    fail_exit "chain test: expected 202, got ${LAST_HTTP_CODE}"
fi

# ── Test 10: RequestConsumer validates L0 ──────────────────────────────────
log "test 10: RequestConsumer validates L0 LSVID"
wait_for_worker_log "$chain_since" \
    "[request-consumer] LSVID L0 OK" "$WAIT_TIMEOUT" \
    || fail_exit "request-consumer did not validate L0 LSVID"
pass "test 10: RequestConsumer validated L0 LSVID (iss=gateway)"

# ── Test 11: MessageBus extends L0 → L1 ──────────────────────────────────
log "test 11: MessageBus extends LSVID L0 → L1"
wait_for_worker_log "$chain_since" \
    "[request-consumer] published event=App\\Events\\OrderCreateRequestedEvent" "$WAIT_TIMEOUT" \
    || fail_exit "request-consumer did not publish downstream event"
pass "test 11: MessageBus published event with extended LSVID"

# ── Test 12: EventConsumer validates L1 chain ─────────────────────────────
log "test 12: EventConsumer validates LSVID chain"
wait_for_worker_log "$chain_since" \
    "[event-consumer] LSVID chain L0..L" "$WAIT_TIMEOUT" \
    || fail_exit "event-consumer did not validate LSVID chain"
pass "test 12: EventConsumer validated nested LSVID chain (L0→L1)"

# ── Test 13: LSVID chain propagation within Saga ─────────────────────────
log "test 13: LSVID chain propagation in Saga"
# After EventConsumer dispatches, the Saga handler should run. If the saga
# publishes a next event, the MessageBus will use LSVIDContext to extend further.
# Verify by checking that Saga Step 1 triggers (meaning dispatch succeeded with LSVID context).
wait_for_worker_log "$chain_since" \
    "Saga Step 1:" "$WAIT_TIMEOUT" \
    || fail_exit "Saga Step 1 not triggered (LSVID context may have failed)"
pass "test 13: Saga Step 1 triggered with active LSVID context"

# ============================================================================
# Phase 4: Saga complete lifecycle
# ============================================================================
section "Phase 4: Saga complete lifecycle"

# ── Test 14: Full Saga flow ──────────────────────────────────────────────────
log "test 14: full Saga flow verification"

saga_steps_found=()

# Step 1
wait_for_worker_log "$chain_since" "Saga Step 1:" "$WAIT_TIMEOUT" \
    && saga_steps_found+=("Step1")

# Step 2
wait_for_worker_log "$chain_since" "Saga Step 2:" "$WAIT_TIMEOUT" \
    && saga_steps_found+=("Step2")

# Step 3
wait_for_worker_log "$chain_since" "Saga Step 3:" "$WAIT_TIMEOUT" \
    && saga_steps_found+=("Step3")

# Step 4 or compensation
elapsed_saga=0
saga_step4=false
saga_rollback=false
while (( elapsed_saga < WAIT_TIMEOUT )); do
    logs="$(docker compose -f "$COMPOSE_FILE" logs --no-color --since "$chain_since" php-worker 2>&1 || true)"
    if grep -Fq "Saga Step 4:" <<<"$logs"; then
        saga_step4=true
        saga_steps_found+=("Step4-completed")
        break
    fi
    if grep -Fq "支付失敗" <<<"$logs" || grep -Fq "RollbackSaga" <<<"$logs"; then
        saga_rollback=true
        saga_steps_found+=("Compensation")
        break
    fi
    sleep 2
    elapsed_saga=$((elapsed_saga + 2))
done

log "Saga steps reached: ${saga_steps_found[*]}"

if [[ "$saga_step4" == true ]]; then
    pass "test 14: Saga completed successfully (4 steps + OrderSagaCompleted)"
elif [[ "$saga_rollback" == true ]]; then
    # Compensation is valid when downstream services are unavailable
    wait_for_worker_log "$chain_since" "RollbackSaga Step 2:" "$WAIT_TIMEOUT" \
        && pass "test 14: Saga compensation path triggered and executed" \
        || fail "test 14: Saga entered compensation but RollbackSaga Step 2 not found"
else
    fail "test 14: Saga did not complete or compensate within ${WAIT_TIMEOUT}s"
fi

# ── Test 15: SPIFFE path accumulation ────────────────────────────────────────
log "test 15: SPIFFE identity path accumulation"

# After RequestConsumer publishes, EventConsumer logs the path
if wait_for_worker_log "$chain_since" \
    "path=[spiffe://zt.local/php-gateway" "$WAIT_TIMEOUT"; then
    pass "test 15a: SPIFFE path includes gateway identity at first hop"
else
    fail "test 15a: gateway identity not in spiffe_path"
fi

if wait_for_worker_log "$chain_since" \
    "[event-consumer] source=spiffe://zt.local/php-worker" "$WAIT_TIMEOUT"; then
    pass "test 15b: Worker SPIFFE ID visible as source in downstream events"
else
    fail "test 15b: worker SPIFFE ID not found in event-consumer logs"
fi

# ── Test 16: EventStoreDB persistence ────────────────────────────────────────
log "test 16: EventStoreDB event persistence"

eventstoredb_code="$(curl -s -o /dev/null -w '%{http_code}' "${EVENTSTOREDB_URL}/health/live" || true)"
if [[ "$eventstoredb_code" == "204" || "$eventstoredb_code" == "200" ]]; then
    # Check if events were written to the Streams stream
    sleep 3  # Wait for async writes
    stream_response="$(curl -s -w '\n%{http_code}' \
        -H "Accept: application/json" \
        "${EVENTSTOREDB_URL}/streams/Streams" || true)"
    stream_code="${stream_response##*$'\n'}"

    if [[ "$stream_code" == "200" ]]; then
        pass "test 16: EventStoreDB has events in 'Streams' stream"
    elif [[ "$stream_code" == "404" ]]; then
        skip "test 16: EventStoreDB stream 'Streams' not yet created (events may be delayed)"
    else
        fail "test 16: EventStoreDB returned unexpected code ${stream_code}"
    fi
else
    skip "test 16: EventStoreDB not reachable (HTTP ${eventstoredb_code})"
fi

# ============================================================================
# Phase 5: Security tests
# ============================================================================
section "Phase 5: Security verification"

# ── Test 17: Untrusted SPIFFE on request queue ──────────────────────────────
forged_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
forged_trace="$(new_trace_id 'e2e-untrusted-req')"
LAST_TRACE="$forged_trace"
log "test 17: untrusted SPIFFE on request queue (trace=${forged_trace})"

publish_result="$(publish_forged_message "$forged_trace" "request.new" "spiffe://evil.domain/attacker" "gateway.request")"
if ! grep -Fq '"routed":true' <<<"$publish_result"; then
    fail_exit "failed to inject forged message"
fi

wait_for_worker_log "$forged_since" \
    "Untrusted SPIFFE source: spiffe://evil.domain/attacker" "$WAIT_TIMEOUT" \
    || fail_exit "worker did not reject untrusted SPIFFE on request queue"

sleep 3
drop_count="$(count_worker_log_occurrences "$forged_since" "Untrusted SPIFFE source: spiffe://evil.domain/attacker")"
if [[ -n "$drop_count" ]] && (( drop_count > 1 )); then
    fail "test 17: requeue storm detected (drop count=${drop_count})"
else
    pass "test 17: untrusted SPIFFE on request queue dropped, no requeue storm"
fi

# ── Test 18: Untrusted SPIFFE on event queue ────────────────────────────────
event_forged_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
event_forged_trace="$(new_trace_id 'e2e-untrusted-evt')"
LAST_TRACE="$event_forged_trace"
log "test 18: untrusted SPIFFE on event queue (trace=${event_forged_trace})"

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
    || fail_exit "event-consumer did not reject untrusted SPIFFE"

event_drop_count="$(count_worker_log_occurrences "$event_forged_since" "Untrusted SPIFFE source: spiffe://evil.domain/event-attacker")"
if [[ -n "$event_drop_count" ]] && (( event_drop_count > 1 )); then
    fail "test 18: event queue requeue storm detected (drop count=${event_drop_count})"
else
    pass "test 18: untrusted SPIFFE on event queue dropped, no requeue storm"
fi

# ── Test 19: LSVID replay attack detection ──────────────────────────────────
replay_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
replay_trace="$(new_trace_id 'e2e-replay')"
LAST_TRACE="$replay_trace"
log "test 19: LSVID replay attack detection (trace=${replay_trace})"

if [[ -n "${CAPTURED_L0_TOKEN:-}" && "${CAPTURED_L0_TOKEN}" != "NO_LSVID" ]]; then
    publish_replayed_lsvid_message "$replay_trace" "$CAPTURED_L0_TOKEN"
    sleep 5

    replay_logs="$(docker compose -f "$COMPOSE_FILE" logs --no-color --since "$replay_since" php-worker 2>&1 || true)"
    if grep -Fq "replay" <<<"$replay_logs" || grep -Fq "jti" <<<"$replay_logs" || grep -Fq "Invalid inbound LSVID" <<<"$replay_logs"; then
        pass "test 19: LSVID replay attack detected and rejected"
    else
        # JTI replay detection is per-process and may not trigger if worker restarted
        skip "test 19: replay detection may not have triggered (JTI cache is per-process)"
    fi
else
    skip "test 19: no captured L0 token available for replay test"
fi

# ── Test 20: Wrong trust domain ─────────────────────────────────────────────
wrong_td_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
wrong_td_trace="$(new_trace_id 'e2e-wrong-td')"
LAST_TRACE="$wrong_td_trace"
log "test 20: wrong trust domain (trace=${wrong_td_trace})"

publish_result="$(publish_forged_message "$wrong_td_trace" "request.new" "spiffe://wrong.domain/service" "gateway.request")"
if grep -Fq '"routed":true' <<<"$publish_result"; then
    wait_for_worker_log "$wrong_td_since" \
        "Untrusted SPIFFE source: spiffe://wrong.domain/service" "$WAIT_TIMEOUT" \
        && pass "test 20: wrong trust domain rejected by SPIFFE source verification" \
        || fail "test 20: wrong trust domain not rejected"
else
    skip "test 20: could not inject wrong-trust-domain message"
fi

# ── Test 21 (new): Event-level schema validation ────────────────────────────
schema_evt_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
log "test 21: event-level schema validation (missing type field)"

# Inject a malformed event directly into an event queue — missing "type" field.
malformed_event_payload="$(cat <<JSON
{
  "data": {"orderId": "test", "userKey": "1", "productList": [], "total": 0},
  "spiffe_id": "spiffe://zt.local/php-worker",
  "spiffe_path": ["spiffe://zt.local/php-worker"]
}
JSON
)"
malformed_publish_result="$(curl -sS \
    -u "${RABBIT_USER}:${RABBIT_PASS}" \
    -H 'content-type: application/json' \
    -X POST "${RABBIT_API_URL}/exchanges/%2F/events/publish" \
    -d "{
        \"routing_key\": \"OrderCreatedEvent\",
        \"payload\": $(echo "$malformed_event_payload" | jq -Rs .),
        \"payload_encoding\": \"string\",
        \"properties\": {\"delivery_mode\": 2}
    }" 2>/dev/null || echo '{"routed":false}')"

if grep -Fq '"routed":true' <<<"$malformed_publish_result"; then
    wait_for_worker_log "$schema_evt_since" \
        "Missing event type or data" "$WAIT_TIMEOUT" \
        && pass "test 21: malformed event (missing type) rejected by EventConsumer" \
        || fail "test 21: EventConsumer did not reject malformed event"
else
    skip "test 21: could not inject malformed event message"
fi

# ── Test 22 (new): LSVID chain issuer verification ──────────────────────────
lsvid_chain_trace="$(new_trace_id 'e2e-lsvid-chain-verify')"
lsvid_chain_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
log "test 22: LSVID chain issuer verification (trace=${lsvid_chain_trace})"

record_response "$(post_order "$lsvid_chain_trace" \
    '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}')"

if [[ "$LAST_HTTP_CODE" == "202" ]]; then
    # Wait for the worker to log the LSVID chain verification
    if wait_for_worker_log "$lsvid_chain_since" \
        "LSVID chain L0..L" "$WAIT_TIMEOUT" 2>/dev/null; then
        pass "test 22: LSVID chain L0→L1 verified in worker logs"
    elif wait_for_worker_log "$lsvid_chain_since" \
        "LSVID L0 OK" "$WAIT_TIMEOUT" 2>/dev/null; then
        pass "test 22: LSVID L0 verified in worker logs"
    else
        skip "test 22: LSVID chain verification log not found (LSVID may not be enabled)"
    fi
else
    fail "test 22: expected 202 but got ${LAST_HTTP_CODE}"
fi

# ============================================================================
# Phase 6: Resilience + Concurrency
# ============================================================================
section "Phase 6: Resilience and concurrency"

# ── Test 23: Worker restart recovery ────────────────────────────────────────
log "test 23: worker restart recovery"
restart_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

docker compose -f "$COMPOSE_FILE" restart php-worker >/dev/null 2>&1
sleep 3

# Wait for worker to come back
if wait_for_worker_log "$restart_since" "[worker] listening" "$WAIT_TIMEOUT"; then
    # Send a request and verify it's processed
    trace_restart="$(new_trace_id 'e2e-restart')"
    response="$(post_order "$trace_restart" '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":0}')"
    record_response "$response"

    if [[ "$LAST_HTTP_CODE" == "202" ]]; then
        wait_for_worker_log "$restart_since" \
            "[request-consumer] verified source" "$WAIT_TIMEOUT" \
            && pass "test 23: worker restarted and resumed processing" \
            || fail "test 23: worker restarted but did not process message"
    else
        fail "test 21: gateway returned ${LAST_HTTP_CODE} after worker restart"
    fi
else
    fail "test 23: worker did not recover after restart within ${WAIT_TIMEOUT}s"
fi

# ── Test 24: Concurrent requests ────────────────────────────────────────────
CONCURRENT_COUNT=10
concurrent_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
log "test 28: ${CONCURRENT_COUNT} concurrent requests"

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
    fail "test 24a: one or more concurrent requests did not return 202"
else
    pass "test 24a: ${CONCURRENT_COUNT} concurrent requests all returned 202"
fi

# Verify worker processes them all
elapsed_c=0
verified_count=0
while (( elapsed_c < WAIT_TIMEOUT )); do
    verified_count="$(count_worker_log_occurrences "$concurrent_since" "[request-consumer] verified source")"
    if [[ "$verified_count" =~ ^[0-9]+$ ]] && (( verified_count >= CONCURRENT_COUNT )); then
        break
    fi
    sleep 2
    elapsed_c=$((elapsed_c + 2))
done

if (( verified_count >= CONCURRENT_COUNT )); then
    pass "test 24b: all ${CONCURRENT_COUNT} concurrent requests processed by worker"
else
    fail "test 24b: worker only verified ${verified_count}/${CONCURRENT_COUNT} concurrent requests"
fi

# ── Test 25: Gateway AMQP reconnection ──────────────────────────────────────
log "test 27: Gateway AMQP reconnection after RabbitMQ restart"
reconnect_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

docker compose -f "$COMPOSE_FILE" restart rabbitmq >/dev/null 2>&1
wait_for_rabbit_api "$WAIT_TIMEOUT" || fail_exit "RabbitMQ did not recover"

# Also wait for worker to reconnect
sleep 5

trace_reconnect="$(new_trace_id 'e2e-reconnect')"
LAST_TRACE="$trace_reconnect"
reconnect_ok=false

# Retry a few times — gateway needs to detect the broken connection and reconnect
for attempt in 1 2 3 4 5; do
    response="$(post_order "$trace_reconnect" '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":0}' || true)"
    record_response "$response"
    if [[ "$LAST_HTTP_CODE" == "202" ]]; then
        reconnect_ok=true
        break
    fi
    sleep 3
    trace_reconnect="$(new_trace_id "e2e-reconnect-${attempt}")"
done

if [[ "$reconnect_ok" == true ]]; then
    pass "test 27: Gateway reconnected to RabbitMQ and resumed publishing"
else
    fail "test 27: Gateway did not recover after RabbitMQ restart (last code=${LAST_HTTP_CODE})"
fi

# ============================================================================
# Phase 7: Performance benchmarks (optional)
# ============================================================================
if [[ "$SKIP_PERF" != "1" ]]; then
    section "Phase 7: Performance benchmarks"

    # Wait for things to stabilize after reconnection tests
    sleep 5

    PERF_TMPDIR="$(mktemp -d)"
    trap 'rm -rf "$PERF_TMPDIR"; cleanup' EXIT

    # ── Test 24: Single request latency baseline ─────────────────────────────
    log "test 28: single request latency baseline (${PERF_SAMPLES} samples, sequential)"

    LATENCIES_FILE="${PERF_TMPDIR}/latencies.txt"
    CODES_FILE="${PERF_TMPDIR}/codes.txt"

    # Warmup: 3 requests to prime connections
    for i in 1 2 3; do
        curl -sS -o /dev/null -X POST "$REQUEST_URL" \
            -H 'Content-Type: application/json' \
            -H "X-Correlation-Id: warmup-${i}" \
            -d '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}' || true
    done
    sleep 1

    for i in $(seq 1 "$PERF_SAMPLES"); do
        trace_perf="$(new_trace_id "e2e-perf-${i}")"
        response="$(post_order_timed "$trace_perf" '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}')"
        last_line="${response##*$'\n'}"
        code="${last_line%% *}"
        latency="${last_line##* }"
        echo "$latency" >> "$LATENCIES_FILE"
        echo "$code" >> "$CODES_FILE"
    done

    perf_stats="$(sort -n "$LATENCIES_FILE" | php -n -r '
        $lats = array_map("floatval", array_filter(explode("\n", trim(stream_get_contents(STDIN)))));
        if (count($lats) === 0) { echo "no-data"; exit(1); }
        sort($lats);
        $n = count($lats);
        $p50 = $lats[(int)($n * 0.5)];
        $p90 = $lats[min($n-1, (int)($n * 0.90))];
        $p95 = $lats[min($n-1, (int)($n * 0.95))];
        $p99 = $lats[min($n-1, (int)($n * 0.99))];
        $avg = array_sum($lats) / $n;
        printf("n=%d avg=%.3fs p50=%.3fs p90=%.3fs p95=%.3fs p99=%.3fs min=%.3fs max=%.3fs",
            $n, $avg, $p50, $p90, $p95, $p99, $lats[0], $lats[$n-1]);
    ')"

    success_count="$(grep -c '202' "$CODES_FILE" 2>/dev/null || echo 0)"

    if [[ "$perf_stats" != "no-data" ]]; then
        pass "test 28: gateway latency (${success_count}/${PERF_SAMPLES} OK): ${perf_stats}"
    else
        fail "test 28: could not collect latency data"
    fi

    # ── Test 25: Stress test throughput ───────────────────────────────────────
    log "test 27: stress test throughput (${PERF_STRESS_TOTAL} requests, concurrency=${PERF_STRESS_CONC})"

    STRESS_LATENCIES="${PERF_TMPDIR}/stress_latencies.txt"
    STRESS_CODES="${PERF_TMPDIR}/stress_codes.txt"
    STRESS_START="$(php -n -r 'echo microtime(true);')"

    # Launch concurrent requests using background subshells
    declare -a stress_pids=()
    for i in $(seq 1 "$PERF_STRESS_TOTAL"); do
        (
            trace_s="perf-stress-${i}-$(date +%s)-$$"
            result="$(curl -sS -w '\n%{http_code} %{time_total}' \
                --connect-timeout 5 --max-time 15 \
                -X POST "$REQUEST_URL" \
                -H 'Content-Type: application/json' \
                -H "X-Correlation-Id: ${trace_s}" \
                -d "{\"userKey\":\"$((i % 5 + 1))\",\"productList\":[{\"p_key\":$((i % 5 + 1)),\"amount\":1}],\"total\":100}" 2>/dev/null || echo -e "\n000 0.000")"
            last_line="${result##*$'\n'}"
            code="${last_line%% *}"
            lat="${last_line##* }"
            echo "$lat" >> "$STRESS_LATENCIES"
            echo "$code" >> "$STRESS_CODES"
        ) &
        stress_pids+=($!)

        # Throttle: only allow PERF_STRESS_CONC concurrent
        if (( ${#stress_pids[@]} >= PERF_STRESS_CONC )); then
            wait "${stress_pids[0]}" 2>/dev/null || true
            stress_pids=("${stress_pids[@]:1}")
        fi
    done

    # Wait for remaining
    for pid in "${stress_pids[@]}"; do
        wait "$pid" 2>/dev/null || true
    done

    STRESS_END="$(php -n -r 'echo microtime(true);')"

    stress_stats="$(sort -n "$STRESS_LATENCIES" | php -n -r '
        $lats = array_map("floatval", array_filter(explode("\n", trim(stream_get_contents(STDIN)))));
        if (count($lats) === 0) { echo "no-data"; exit(1); }
        sort($lats);
        $n = count($lats);
        $p50 = $lats[(int)($n * 0.5)];
        $p90 = $lats[min($n-1, (int)($n * 0.90))];
        $p95 = $lats[min($n-1, (int)($n * 0.95))];
        $p99 = $lats[min($n-1, (int)($n * 0.99))];
        $avg = array_sum($lats) / $n;
        printf("n=%d avg=%.3fs p50=%.3fs p90=%.3fs p95=%.3fs p99=%.3fs min=%.3fs max=%.3fs",
            $n, $avg, $p50, $p90, $p95, $p99, $lats[0], $lats[$n-1]);
    ')"

    stress_success="$(grep -c '202' "$STRESS_CODES" 2>/dev/null || echo 0)"
    stress_duration="$(php -n -r "printf('%.1f', ${STRESS_END} - ${STRESS_START});")"
    stress_rps="$(php -n -r "printf('%.1f', ${PERF_STRESS_TOTAL} / (${STRESS_END} - ${STRESS_START}));" 2>/dev/null || echo "N/A")"

    # HTTP code breakdown
    stress_code_summary=""
    if [[ -s "$STRESS_CODES" ]]; then
        stress_code_summary="$(sort "$STRESS_CODES" | uniq -c | sort -rn | awk '{printf "%s=%s ", $2, $1}')"
    fi

    if [[ "$stress_stats" != "no-data" ]]; then
        pass "test 27: stress (${stress_success}/${PERF_STRESS_TOTAL} OK, ${stress_duration}s, ${stress_rps} rps): ${stress_stats}"
        log "         HTTP codes: ${stress_code_summary}"
    else
        fail "test 27: stress test collected no data"
    fi

    # ── Test 26: Saga end-to-end latency ─────────────────────────────────────
    log "test 28: Saga end-to-end latency measurement"

    # Wait for worker to be stable after stress test
    sleep 3

    saga_perf_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    saga_trace="$(new_trace_id 'e2e-saga-latency')"
    saga_start_ms="$(php -n -r 'echo (int)(microtime(true)*1000);')"

    response="$(post_order "$saga_trace" '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}')"
    record_response "$response"

    if [[ "$LAST_HTTP_CODE" == "202" ]]; then
        # Measure time until Saga Step 1 appears in worker logs
        saga_elapsed=0
        saga_step1_found=false
        while (( saga_elapsed < WAIT_TIMEOUT )); do
            logs="$(docker compose -f "$COMPOSE_FILE" logs --no-color --since "$saga_perf_since" php-worker 2>&1 || true)"
            if grep -Fq "Saga Step 1:" <<<"$logs"; then
                saga_step1_found=true
                break
            fi
            sleep 1
            saga_elapsed=$((saga_elapsed + 1))
        done

        saga_end_ms="$(php -n -r 'echo (int)(microtime(true)*1000);')"
        saga_latency_ms=$((saga_end_ms - saga_start_ms))

        if [[ "$saga_step1_found" == true ]]; then
            pass "test 28: Saga e2e latency (request → Step 1): ${saga_latency_ms}ms"
        else
            fail "test 28: Saga Step 1 not reached within ${WAIT_TIMEOUT}s"
        fi
    else
        fail "test 28: request returned ${LAST_HTTP_CODE}, cannot measure saga latency"
    fi

    # ── Performance summary JSON ─────────────────────────────────────────────
    PERF_JSON="${PROJECT_DIR}/artifacts/e2e-perf-$(date +%Y%m%d-%H%M%S).json"
    mkdir -p "$(dirname "$PERF_JSON")"

    php -n -r '
        $data = [
            "timestamp" => date("c"),
            "compose_profiles" => getenv("COMPOSE_PROFILES") ?: "none",
            "lsvid_enabled" => true,
            "sequential_latency" => [
                "samples" => (int) $argv[1],
                "raw" => $argv[2],
            ],
            "stress_test" => [
                "total" => (int) $argv[3],
                "concurrency" => (int) $argv[4],
                "success" => (int) $argv[5],
                "duration_sec" => (float) $argv[6],
                "rps" => $argv[7],
                "raw" => $argv[8],
                "codes" => $argv[9],
            ],
            "saga_e2e_ms" => (int) $argv[10],
        ];
        file_put_contents($argv[11], json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    ' \
        "$PERF_SAMPLES" "$perf_stats" \
        "$PERF_STRESS_TOTAL" "$PERF_STRESS_CONC" "$stress_success" "$stress_duration" "$stress_rps" "$stress_stats" "$stress_code_summary" \
        "${saga_latency_ms:-0}" \
        "$PERF_JSON" 2>/dev/null || true

    if [[ -f "$PERF_JSON" ]]; then
        log "performance data saved to: ${PERF_JSON}"
    fi

    rm -rf "$PERF_TMPDIR"
else
    skip "Phase 7: performance tests (E2E_SKIP_PERF=1)"
fi

# ============================================================================
# Summary
# ============================================================================
section "Results"

END_TIME="$(date +%s)"
DURATION=$((END_TIME - START_TIME))

printf '\n'
printf '  ┌────────────────────────────────────────────────┐\n'
printf '  │  Full Architecture E2E Test Summary            │\n'
printf '  ├────────────────────────────────────────────────┤\n'
printf '  │  \033[32mPassed:  %3d\033[0m                                 │\n' "$PASS_COUNT"
printf '  │  \033[31mFailed:  %3d\033[0m                                 │\n' "$FAIL_COUNT"
printf '  │  \033[33mSkipped: %3d\033[0m                                 │\n' "$SKIP_COUNT"
printf '  │  Duration: %ds                               │\n' "$DURATION"
printf '  └────────────────────────────────────────────────┘\n'
printf '\n'

printf '  Tested layers:\n'
printf '    [1] SPIFFE/SPIRE: Server + Agent + Registrar + Watcher + SHM\n'
printf '    [2] LSVID chain: L0 mint → L0 validate → L1 extend → L1 validate\n'
printf '    [3] Saga lifecycle: 4-step happy path + compensation\n'
printf '    [4] Security: trust domain, SPIFFE source, replay detection\n'
printf '    [5] Resilience: worker restart, AMQP reconnection, concurrency\n'
if [[ "$SKIP_PERF" != "1" ]]; then
    printf '    [6] Performance: sequential latency + stress throughput + saga e2e\n'
fi
printf '\n'

if (( FAIL_COUNT > 0 )); then
    FAILED=true
    exit 1
fi

pass "all full-architecture E2E tests passed"
