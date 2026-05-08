#!/usr/bin/env bash
# ============================================================================
# Security probe for feat/Linkerd1 (Linkerd 1.x mesh, no identity layer).
#
# Threat model on this branch:
#   - Linkerd does L7 routing/observability (no user auth)
#   - Phase 2: mTLS off between sidecars
#   - End-user identity is per-request X-User-key header (no signature)
#
# Categories:
#   A. HTTP ingress validation     curl matrix against gateway
#   B. Downstream identity bypass  direct calls bypassing gateway
#   C. AMQP envelope injection     publish forged envelopes via rabbitmqadmin
#   D. Linkerd port exposure       which sidecar ports are reachable from where
#   E. Replay                       same X-Correlation-Id sent twice
#
# Each case: case_id, category, target, sent, status, accepted/rejected,
# notes — JSON line per case for downstream analysis.
#
# Usage (run from local Mac, all SSH via -lan aliases):
#   bash scripts/security/probe-linkerd.sh
#   OUT=artifacts/sec-linkerd-$(date +%s) bash scripts/security/probe-linkerd.sh
# ============================================================================
set -euo pipefail

GATEWAY_ALIAS="${GATEWAY_ALIAS:-zt-gateway-lan}"
ORDER_ALIAS="${ORDER_ALIAS:-zt-order-lan}"
PROD_ALIAS="${PROD_ALIAS:-zt-prod-lan}"
USER_ALIAS="${USER_ALIAS:-zt-user-lan}"

OUT="${OUT:-artifacts/sec-linkerd-$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$OUT"
RESULTS="$OUT/results.jsonl"
: >"$RESULTS"

log()  { printf '[sec] %s %s\n' "$(date +%T)" "$*" >&2; }

emit() {
    local case_id=$1 category=$2 target=$3 status=$4 verdict=$5 notes=$6
    python3 - <<PY >>"$RESULTS"
import json
print(json.dumps({
  "case_id": "$case_id",
  "category": "$category",
  "target":   "$target",
  "status":   "$status",
  "verdict":  "$verdict",
  "notes":    """$notes""".strip(),
}))
PY
    printf '  %-6s %-20s status=%-3s verdict=%s\n' "$case_id" "$category" "$status" "$verdict" >&2
}

# ── Category A: HTTP ingress validation against Gateway ─────────────────────
log "Category A: HTTP ingress validation"
A_GW="http://127.0.0.1:8080/api/orders"

a_post() {
    local case_id=$1 body=$2 expected=$3 desc=$4
    local code
    code=$(ssh "$GATEWAY_ALIAS" "curl -sS -o /dev/null -w '%{http_code}' \
        -X POST '$A_GW' -H 'Content-Type: application/json' \
        --data-binary '$body'" 2>/dev/null || echo "000")
    local verdict
    if [[ "$code" =~ ^(2..|3..)$ ]]; then
        verdict="accepted"
    else
        verdict="rejected"
    fi
    emit "$case_id" "http-ingress" "$A_GW" "$code" "$verdict" "$desc — expect=$expected got=$code"
}

a_post A1 '{}'                                                              "4xx" "empty body"
a_post A2 '{"productList":[{"p_key":1,"amount":1}],"total":100}'           "4xx" "missing userKey"
a_post A3 '{"userKey":"","productList":[{"p_key":1,"amount":1}],"total":100}' "4xx" "empty userKey"
a_post A4 '{"userKey":"1","productList":[],"total":100}'                   "4xx" "empty productList"
a_post A5 '{"userKey":"1","productList":[{"p_key":1,"amount":-1}],"total":100}' "4xx" "negative amount"
a_post A6 '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":-100}' "4xx" "negative total"
a_post A7 '{"userKey":"1","productList":[{"p_key":1,"amount":99999999}],"total":100}' "any" "absurd amount"
a_post A8 '{"userKey":"abc","productList":[{"p_key":1,"amount":1}],"total":100}' "4xx" "non-numeric userKey"
a_post A9 '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}' "2xx" "happy path (control)"

# Malformed JSON — needs raw bytes; do via stdin
a_post_raw() {
    local case_id=$1 body=$2 desc=$3
    local code
    code=$(ssh "$GATEWAY_ALIAS" "curl -sS -o /dev/null -w '%{http_code}' \
        -X POST '$A_GW' -H 'Content-Type: application/json' --data-binary '$body'" 2>/dev/null || echo "000")
    local verdict
    [[ "$code" =~ ^(2..|3..)$ ]] && verdict="accepted" || verdict="rejected"
    emit "$case_id" "http-ingress" "$A_GW" "$code" "$verdict" "$desc"
}
a_post_raw A10 '{not-json'                                                  "malformed JSON"
a_post_raw A11 'plain-text-body'                                            "non-JSON body"

# SQL/header injection — userKey "1' OR 1=1--"
ssh "$GATEWAY_ALIAS" "curl -sS -o /dev/null -w '%{http_code}' \
    -X POST '$A_GW' -H 'Content-Type: application/json' \
    --data '{\"userKey\":\"1 OR 1=1--\",\"productList\":[{\"p_key\":1,\"amount\":1}],\"total\":100}'" >"$OUT/.tmp" 2>/dev/null || echo 000 >"$OUT/.tmp"
A12_CODE=$(cat "$OUT/.tmp")
emit A12 "http-ingress" "$A_GW" "$A12_CODE" "$( [[ $A12_CODE =~ ^(2..|3..)$ ]] && echo accepted || echo rejected)" "SQL-style userKey"

# ── Category B: Downstream identity bypass (direct, no gateway, no mesh) ───
# UserFilter on Order/User services only checks PRESENCE of X-User-key.
# A network-adjacent attacker can call any user's order/wallet by spoofing.
log "Category B: Downstream identity bypass (direct calls)"

b_get_order() {
    local case_id=$1 user_key=$2 desc=$3
    local code body
    body=$(ssh "$ORDER_ALIAS" "curl -sS -o /dev/stderr -w '%{http_code}' \
        -H 'X-User-key: $user_key' http://127.0.0.1:8082/api/v1/order" 2>"$OUT/.body" || echo 000)
    code="$body"
    local snip
    snip=$(head -c 80 "$OUT/.body" | tr '\n' ' ')
    local verdict
    [[ "$code" =~ ^2..$ ]] && verdict="accepted" || verdict="rejected"
    emit "$case_id" "id-bypass-order" "order:8082/api/v1/order" "$code" "$verdict" "X-User-key=$user_key — $desc — body=$snip"
}
b_get_order B1 1   "list user 1's orders"
b_get_order B2 2   "list user 2's orders (no auth on whose orders we see)"
b_get_order B3 999 "non-existent user — does service still 200?"

b_wallet_show() {
    local case_id=$1 user_key=$2 desc=$3
    local code body
    body=$(ssh "$USER_ALIAS" "curl -sS -o /dev/stderr -w '%{http_code}' \
        -H 'X-User-key: $user_key' http://127.0.0.1:8084/api/v1/wallet" 2>"$OUT/.body" || echo 000)
    code="$body"
    local snip
    snip=$(head -c 80 "$OUT/.body" | tr '\n' ' ')
    local verdict
    [[ "$code" =~ ^2..$ ]] && verdict="accepted" || verdict="rejected"
    emit "$case_id" "id-bypass-wallet" "user:8084/api/v1/wallet" "$code" "$verdict" "X-User-key=$user_key — $desc — body=$snip"
}
b_wallet_show B4 1 "read user 1 wallet"
b_wallet_show B5 2 "read user 2 wallet (cross-user)"

b_inventory_reduce() {
    local case_id=$1 body=$2 desc=$3
    local code resp
    resp=$(ssh "$PROD_ALIAS" "curl -sS -o /dev/stderr -w '%{http_code}' \
        -X POST -H 'Content-Type: application/json' \
        -d '$body' http://127.0.0.1:8083/api/v1/inventory/reduceInventory" 2>"$OUT/.body" || echo 000)
    code="$resp"
    local snip
    snip=$(head -c 80 "$OUT/.body" | tr '\n' ' ')
    local verdict
    [[ "$code" =~ ^2..$ ]] && verdict="accepted" || verdict="rejected"
    emit "$case_id" "id-bypass-inventory" "production:8083/api/v1/inventory/reduceInventory" "$code" "$verdict" "$desc — body=$snip"
}
b_inventory_reduce B6 '{"p_key":1,"amount":1}' "reduce inventory directly (no auth)"

# ── Category C: AMQP envelope injection (skip if rabbitmqadmin missing) ────
log "Category C: AMQP envelope injection"

c_inject() {
    local case_id=$1 envelope=$2 desc=$3
    local code
    code=$(ssh "$GATEWAY_ALIAS" "docker exec zt-rabbitmq rabbitmqadmin -u zt -p ztpass \
        publish exchange='' routing_key=order_queue payload='$envelope' 2>&1 | head -1") || true
    local verdict
    if [[ "$code" =~ "Message published" ]]; then
        verdict="accepted-by-broker"
    else
        verdict="broker-rejected"
    fi
    emit "$case_id" "amqp-inject" "rabbitmq.order_queue" "—" "$verdict" "$desc — broker_response=$code"
}

# Bad schema_version
c_inject C1 '{"schema_version":99,"type":"gateway.request","route":"OrderCreateRequestedEvent","id":"mal-c1","spiffe_id":"","spiffe_path":[],"data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}}' \
    "schema_version=99 — RequestConsumer should reject"
# Wrong type
c_inject C2 '{"schema_version":1,"type":"malicious","route":"OrderCreateRequestedEvent","id":"mal-c2","spiffe_id":"","spiffe_path":[],"data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}}' \
    "type=malicious — should be rejected"
# Missing data field
c_inject C3 '{"schema_version":1,"type":"gateway.request","route":"OrderCreateRequestedEvent","id":"mal-c3","spiffe_id":"","spiffe_path":[]}' \
    "missing data — should be rejected"

# ── Category D: Linkerd sidecar port exposure ───────────────────────────────
# 4140 outgoing proxy, 4141 incoming proxy, 9990 admin — they expose service
# discovery details and can be misused if reachable from untrusted networks.
log "Category D: Linkerd sidecar port exposure (host LAN reachability)"

d_check() {
    local case_id=$1 host_alias=$2 port=$3 desc=$4
    local code
    code=$(ssh "$host_alias" "curl -sS -o /dev/null -w '%{http_code}' --max-time 3 http://127.0.0.1:$port/" 2>/dev/null || echo 000)
    local verdict
    [[ "$code" != "000" ]] && verdict="reachable" || verdict="not-reachable"
    emit "$case_id" "port-exposure" "$host_alias:$port" "$code" "$verdict" "$desc"
}
# 9990 admin from localhost is expected (we use it for ping). The point is whether it's bound to 0.0.0.0 and reachable from peers.
d_check D1 zt-gateway-lan 9990 "linkerd admin :9990 from gateway localhost — sanity"
d_check D2 zt-gateway-lan 4140 "linkerd outgoing :4140 — should require Host header for valid response"
d_check D3 zt-gateway-lan 4141 "linkerd incoming :4141 — gateway side normally has no incoming proxy"

# Cross-host probes — can production host hit user host's linkerd 4141 directly?
# This tells us whether one compromised service can talk to peer linkerds.
d_cross() {
    local case_id=$1 from=$2 to_ip=$3 to_port=$4 desc=$5
    local code
    code=$(ssh "$from" "curl -sS -o /dev/null -w '%{http_code}' --max-time 3 -H 'Host: ProductionService' http://$to_ip:$to_port/api/health" 2>/dev/null || echo 000)
    local verdict
    [[ "$code" =~ ^[1-5]..$ ]] && verdict="reachable($code)" || verdict="blocked"
    emit "$case_id" "cross-host" "$from -> $to_ip:$to_port" "$code" "$verdict" "$desc"
}
d_cross D4 zt-order-lan 10.1.1.207 4141 "from order-host probe production-host linkerd incoming with Host:ProductionService"
d_cross D5 zt-prod-lan  10.1.1.214 4141 "from prod-host probe user-host linkerd incoming with Host:UserService"

# ── Category E: Replay (same X-Correlation-Id twice) ─────────────────────────
log "Category E: Replay"
TRACE="replay-$(date +%s)"
e_post() {
    local case_id=$1 trace=$2 desc=$3
    local code
    code=$(ssh "$GATEWAY_ALIAS" "curl -sS -o /dev/null -w '%{http_code}' \
        -X POST '$A_GW' -H 'Content-Type: application/json' \
        -H 'X-Correlation-Id: $trace' \
        --data '{\"userKey\":\"1\",\"productList\":[{\"p_key\":1,\"amount\":1}],\"total\":100}'" 2>/dev/null || echo 000)
    local verdict
    [[ "$code" =~ ^2..$ ]] && verdict="accepted" || verdict="rejected"
    emit "$case_id" "replay" "$A_GW" "$code" "$verdict" "trace=$trace — $desc"
}
e_post E1 "$TRACE" "first send"
sleep 1
e_post E2 "$TRACE" "duplicate send (same trace_id)"
sleep 1
e_post E3 "$TRACE" "third send (same trace_id)"

# ── Summary ──────────────────────────────────────────────────────────────────
log "all probes done — results: $RESULTS"
echo
echo "── case-by-case ──"
column -t -s $'\t' < <(jq -r '. | "\(.case_id)\t\(.category)\t\(.status)\t\(.verdict)\t\(.notes[:80])"' "$RESULTS" 2>/dev/null) || cat "$RESULTS"
