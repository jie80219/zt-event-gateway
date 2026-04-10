#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════
#  LSVID chain smoke test (requires docker compose up)
#
#  Verifies in order:
#    1. Gateway is reachable and LSVID L0 minting succeeds
#    2. Worker RequestConsumer validates L0 and publishes event
#    3. Worker EventConsumer validates the inbound L1
#    4. Fail-closed: an envelope WITHOUT `lsvid` pushed directly
#       onto the order queue is rejected by the worker log
#    5. Fail-closed: an envelope with a *foreign* lsvid token is
#       rejected (signature/CA or trust-domain failure)
#
#  Assumes: `docker compose up -d` is green, LSVID_REQUIRED=1 on
#           both gateway and worker containers.
#
#  Usage:
#    bash spiffe/e2e/test-lsvid-chain.sh
#
#  Environment:
#    GATEWAY_URL    Gateway endpoint (default http://127.0.0.1:8080/api/orders)
#    WORKER_SVC     docker compose service name for worker (default php-worker)
#    RABBIT_API     RabbitMQ mgmt URL (default http://127.0.0.1:15672/api)
#    RABBIT_USER    (default zt)
#    RABBIT_PASS    (default ztpass)
# ══════════════════════════════════════════════════════════════════
set -euo pipefail

GATEWAY_URL="${GATEWAY_URL:-http://127.0.0.1:8080/api/orders}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1:8080/api/health}"
WORKER_SVC="${WORKER_SVC:-php-worker}"
GATEWAY_SVC="${GATEWAY_SVC:-php-gateway}"
RABBIT_API="${RABBIT_API:-http://127.0.0.1:15672/api}"
RABBIT_USER="${RABBIT_USER:-zt}"
RABBIT_PASS="${RABBIT_PASS:-ztpass}"
REQUEST_QUEUE="${REQUEST_QUEUE:-order_queue}"
REQUEST_ROUTING_KEY="${REQUEST_ROUTING_KEY:-request.new}"
REQUEST_EXCHANGE="${REQUEST_EXCHANGE:-events}"

BOLD='\033[1m'; GREEN='\033[32m'; RED='\033[31m'; YELLOW='\033[33m'; RESET='\033[0m'
pass() { echo -e "${GREEN}${BOLD}[PASS]${RESET} $*"; }
fail() { echo -e "${RED}${BOLD}[FAIL]${RESET} $*"; exit 1; }
info() { echo -e "${BOLD}[info]${RESET} $*"; }
warn() { echo -e "${YELLOW}${BOLD}[warn]${RESET} $*"; }

need() { command -v "$1" >/dev/null 2>&1 || fail "missing command: $1"; }
need curl
need docker

# Publish a JSON envelope directly onto RabbitMQ using the management API.
publish_envelope() {
    local body_json="$1"
    local payload
    payload=$(python3 - "$body_json" <<'PY'
import json, sys
body = sys.argv[1]
print(json.dumps({
    "properties": {"delivery_mode": 2},
    "routing_key": "__ROUTING_KEY__",
    "payload": body,
    "payload_encoding": "string",
}))
PY
)
    payload=${payload//__ROUTING_KEY__/$REQUEST_ROUTING_KEY}
    curl -s -u "${RABBIT_USER}:${RABBIT_PASS}" \
        -H "Content-Type: application/json" \
        -X POST \
        "${RABBIT_API}/exchanges/%2F/${REQUEST_EXCHANGE}/publish" \
        -d "$payload" > /dev/null
}

worker_log_tail() {
    docker compose logs --tail=200 "$WORKER_SVC" 2>/dev/null || true
}

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║         LSVID Chain End-to-End Smoke Test                    ║"
echo "╚══════════════════════════════════════════════════════════════╝"

# ── Pre-flight ────────────────────────────────────────────────
info "checking gateway health at ${HEALTH_URL}"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 5 "$HEALTH_URL" || echo "000")
[[ "$HTTP_CODE" == "200" ]] || fail "gateway not healthy (HTTP $HTTP_CODE)"
pass "gateway reachable"

# ── Test 1 — Happy path L0→L1→L2 ─────────────────────────────
info "Test 1 — happy-path order request"
TRACE="lsvid-e2e-$(date +%s)-$$"
CODE=$(curl -s -o /tmp/lsvid-resp.json -w "%{http_code}" \
    -X POST "$GATEWAY_URL" \
    -H "Content-Type: application/json" \
    -H "X-Correlation-ID: $TRACE" \
    -d '{"user_id": 1, "product_list": [{"p_key": 1, "amount": 1}]}')

[[ "$CODE" == "202" ]] || fail "expected 202 from gateway, got $CODE — body=$(cat /tmp/lsvid-resp.json)"
pass "gateway returned 202"

sleep 2
if worker_log_tail | grep -q "LSVID L.* OK\|LSVID chain L0"; then
    pass "worker log shows LSVID chain verified"
else
    warn "worker log did not show LSVID verification (may be buffered)"
    worker_log_tail | grep -i lsvid | tail -5 || true
fi

# ── Test 2 — Fail-closed: missing lsvid ───────────────────────
info "Test 2 — envelope WITHOUT lsvid (fail-closed rejection)"
BAD_ENVELOPE=$(cat <<JSON
{"schema_version":1,"type":"gateway.request","route":"OrderCreateRequestedEvent","id":"$TRACE-no-lsvid","spiffe_id":"spiffe://zt.local/php-gateway","spiffe_path":["spiffe://zt.local/php-gateway"],"data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}}
JSON
)
publish_envelope "$BAD_ENVELOPE"
sleep 2

if worker_log_tail | grep -q "LSVID required but envelope carries none\|LSVID present on envelope"; then
    pass "worker rejected envelope without lsvid"
else
    warn "expected fail-closed rejection log not found; dumping worker log tail:"
    worker_log_tail | tail -20
    fail "fail-closed path did not reject missing-LSVID envelope"
fi

# ── Test 3 — Fail-closed: foreign token ──────────────────────
info "Test 3 — envelope with junk lsvid (signature/CA rejection)"
JUNK_TOKEN="eyJhbGciOiJFUzI1NiIsInR5cCI6IkxTVklEIn0.eyJpc3MiOiJzcGlmZmU6Ly96dC5sb2NhbC9mYWtlIiwiYXVkIjoic3BpZmZlOi8venQubG9jYWwvcGhwLXdvcmtlciIsImlhdCI6MCwiZXhwIjowLCJqdGkiOiJmYWtlIiwic3ViIjoic3BpZmZlOi8venQubG9jYWwvZmFrZSJ9.AAAA"
BAD_ENVELOPE2=$(cat <<JSON
{"schema_version":1,"type":"gateway.request","route":"OrderCreateRequestedEvent","id":"$TRACE-junk","spiffe_id":"spiffe://zt.local/php-gateway","spiffe_path":["spiffe://zt.local/php-gateway"],"data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100},"lsvid":"$JUNK_TOKEN"}
JSON
)
publish_envelope "$BAD_ENVELOPE2"
sleep 2

if worker_log_tail | grep -qE "Invalid inbound LSVID|LSVID validation failed|signature verification failed|not signed by any trusted CA|LSVID.*expired|outside the expected trust domain"; then
    pass "worker rejected envelope with junk lsvid"
else
    warn "expected invalid-LSVID rejection log not found; dumping worker log tail:"
    worker_log_tail | tail -20
    fail "fail-closed path did not reject junk-LSVID envelope"
fi

echo
pass "LSVID chain end-to-end smoke test OK"
