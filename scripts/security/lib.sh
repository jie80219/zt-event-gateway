#!/usr/bin/env bash
# ============================================================================
# Shared helpers for security probes. Sourced by per-stack drivers.
#
# Required env (set by the driver before sourcing):
#   STACK            stack name written into every JSONL line
#                    (baseline | linkerd | keycloak | keycloak-spiffe | mesh-id)
#   GATEWAY_ALIAS    SSH alias for gateway host  (default zt-gateway-lan)
#   ORDER_ALIAS      SSH alias for order host    (default zt-order-lan)
#   PROD_ALIAS       SSH alias for production    (default zt-prod-lan)
#   USER_ALIAS       SSH alias for user host     (default zt-user-lan)
#
# Optional env:
#   OUT              output dir; defaults to artifacts/sec-$STACK-<ts>
#   GATEWAY_URL      override gateway URL (default http://127.0.0.1:8080)
#
# Categories provided here (stack-agnostic):
#   run_category_a   HTTP ingress validation against gateway
#   run_category_b   Downstream identity bypass (direct calls)
#   run_category_c   AMQP envelope injection (schema-violating)
#   run_category_e   Replay (same X-Correlation-Id submitted three times)
#
# Stack-specific drivers add their own category D (port exposure / control
# plane) and any identity-layer cases (F/G/H/I) inline.
# ============================================================================

GATEWAY_ALIAS="${GATEWAY_ALIAS:-zt-gateway-lan}"
ORDER_ALIAS="${ORDER_ALIAS:-zt-order-lan}"
PROD_ALIAS="${PROD_ALIAS:-zt-prod-lan}"
USER_ALIAS="${USER_ALIAS:-zt-user-lan}"

GATEWAY_URL="${GATEWAY_URL:-http://127.0.0.1:8080}"
A_GW="$GATEWAY_URL/api/orders"

if [[ -z "${STACK:-}" ]]; then
    echo "lib.sh: STACK must be set before sourcing" >&2
    return 1 2>/dev/null || exit 1
fi

OUT="${OUT:-artifacts/sec-$STACK-$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$OUT"
RESULTS="$OUT/results.jsonl"
: >"$RESULTS"

log()  { printf '[sec:%s] %s %s\n' "$STACK" "$(date +%T)" "$*" >&2; }

emit() {
    local case_id=$1 category=$2 target=$3 status=$4 verdict=$5 notes=$6 is_attack=${7:-1}
    jq -nc \
        --arg stack     "$STACK" \
        --arg case_id   "$case_id" \
        --arg category  "$category" \
        --arg target    "$target" \
        --arg status    "$status" \
        --arg verdict   "$verdict" \
        --arg notes     "$notes" \
        --argjson attack "$is_attack" \
        '{stack:$stack, case_id:$case_id, category:$category, target:$target, status:$status, verdict:$verdict, notes:$notes, is_attack:$attack}' \
        >>"$RESULTS"
    printf '  %-6s %-22s status=%-3s verdict=%-12s attack=%s\n' \
        "$case_id" "$category" "$status" "$verdict" "$is_attack" >&2
}

# Classify HTTP status — 2xx/3xx is "accepted by server", everything else
# (including connect failure code 000) is "rejected".
_verdict_http() {
    [[ "$1" =~ ^(2..|3..)$ ]] && echo accepted || echo rejected
}

# ── Category A: HTTP ingress validation ────────────────────────────────────
# Posts the same set of well-formed/malformed bodies regardless of stack.
# Identity-aware stacks (Keycloak) may also reject A9 unless an X-API-Key /
# bearer token is supplied — drivers can override A9 expectation in notes.
a_post() {
    local case_id=$1 body=$2 expected=$3 desc=$4 is_attack=${5:-1}
    local code
    code=$(ssh "$GATEWAY_ALIAS" "curl -sS -o /dev/null -w '%{http_code}' \
        -X POST '$A_GW' -H 'Content-Type: application/json' \
        --data-binary '$body'" 2>/dev/null || echo "000")
    emit "$case_id" "http-ingress" "$A_GW" "$code" "$(_verdict_http "$code")" \
        "$desc — expect=$expected got=$code" "$is_attack"
}

run_category_a() {
    log "Category A: HTTP ingress validation"
    a_post A1  '{}'                                                                        "4xx" "empty body"
    a_post A2  '{"productList":[{"p_key":1,"amount":1}],"total":100}'                      "4xx" "missing userKey"
    a_post A3  '{"userKey":"","productList":[{"p_key":1,"amount":1}],"total":100}'         "4xx" "empty userKey"
    a_post A4  '{"userKey":"1","productList":[],"total":100}'                              "4xx" "empty productList"
    a_post A5  '{"userKey":"1","productList":[{"p_key":1,"amount":-1}],"total":100}'       "4xx" "negative amount"
    a_post A6  '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":-100}'       "4xx" "negative total"
    a_post A7  '{"userKey":"1","productList":[{"p_key":1,"amount":99999999}],"total":100}' "any" "absurd amount"
    a_post A8  '{"userKey":"abc","productList":[{"p_key":1,"amount":1}],"total":100}'      "4xx" "non-numeric userKey"
    a_post A9  '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}'        "2xx" "happy path (control)" 0
    a_post A10 '{not-json'                                                                 "4xx" "malformed JSON"
    a_post A11 'plain-text-body'                                                           "4xx" "non-JSON body"
    a_post A12 '{"userKey":"1 OR 1=1--","productList":[{"p_key":1,"amount":1}],"total":100}' "4xx" "SQL-style userKey"
}

# ── Category B: Downstream identity bypass ─────────────────────────────────
# Direct calls to order:8082 / user:8084 / production:8083 spoofing the
# X-User-key header. On stacks where downstream services only check header
# presence (baseline, linkerd) these all succeed. On identity-aware stacks
# (keycloak / spiffe) they should be rejected.
b_get_order() {
    local case_id=$1 user_key=$2 desc=$3 is_attack=${4:-1}
    local code
    code=$(ssh "$ORDER_ALIAS" "curl -sS -o /dev/stderr -w '%{http_code}' \
        -H 'X-User-key: $user_key' http://127.0.0.1:8082/api/v1/order" 2>"$OUT/.body" || echo 000)
    local snip
    snip=$(head -c 80 "$OUT/.body" | tr '\n' ' ')
    emit "$case_id" "id-bypass-order" "order:8082/api/v1/order" "$code" \
        "$([[ "$code" =~ ^2..$ ]] && echo accepted || echo rejected)" \
        "X-User-key=$user_key — $desc — body=$snip" "$is_attack"
}

b_wallet_show() {
    local case_id=$1 user_key=$2 desc=$3 is_attack=${4:-1}
    local code
    code=$(ssh "$USER_ALIAS" "curl -sS -o /dev/stderr -w '%{http_code}' \
        -H 'X-User-key: $user_key' http://127.0.0.1:8084/api/v1/wallet" 2>"$OUT/.body" || echo 000)
    local snip
    snip=$(head -c 80 "$OUT/.body" | tr '\n' ' ')
    emit "$case_id" "id-bypass-wallet" "user:8084/api/v1/wallet" "$code" \
        "$([[ "$code" =~ ^2..$ ]] && echo accepted || echo rejected)" \
        "X-User-key=$user_key — $desc — body=$snip" "$is_attack"
}

b_inventory_reduce() {
    local case_id=$1 body=$2 desc=$3 is_attack=${4:-1}
    local code
    code=$(ssh "$PROD_ALIAS" "curl -sS -o /dev/stderr -w '%{http_code}' \
        -X POST -H 'Content-Type: application/json' \
        -d '$body' http://127.0.0.1:8083/api/v1/inventory/reduceInventory" 2>"$OUT/.body" || echo 000)
    local snip
    snip=$(head -c 80 "$OUT/.body" | tr '\n' ' ')
    emit "$case_id" "id-bypass-inventory" "production:8083/api/v1/inventory/reduceInventory" "$code" \
        "$([[ "$code" =~ ^2..$ ]] && echo accepted || echo rejected)" \
        "$desc — body=$snip" "$is_attack"
}

run_category_b() {
    log "Category B: Downstream identity bypass (direct calls)"
    b_get_order      B1 1   "list user 1's orders"
    b_get_order      B2 2   "list user 2's orders (cross-user)"
    b_get_order      B3 999 "non-existent user — does service still 200?"
    b_wallet_show    B4 1   "read user 1 wallet"
    b_wallet_show    B5 2   "read user 2 wallet (cross-user)"
    b_inventory_reduce B6 '{"p_key":1,"amount":1}' "reduce inventory directly (no auth)"
}

# ── Category C: AMQP envelope injection ────────────────────────────────────
# Publishes forged envelopes directly to order_queue via rabbitmqadmin —
# bypasses the gateway entirely. RequestConsumer's schema check should reject
# C1–C3 on every stack; C4–C6 (identity-aware) only matter on stacks that
# verify spiffe_id / signature.
c_inject() {
    local case_id=$1 envelope=$2 desc=$3 is_attack=${4:-1}
    # rabbitmqadmin 3.13.7 has a regression that breaks `publish exchange=''`
    # (KeyError 'exchange'), so we go via the management HTTP API on :15672.
    # Default exchange name is empty → URL path `/api/exchanges/%2F//publish`.
    local payload
    payload=$(jq -nc --arg p "$envelope" \
        '{properties:{},routing_key:"order_queue",payload:$p,payload_encoding:"string"}')
    local resp
    resp=$(ssh "$GATEWAY_ALIAS" "curl -sS --max-time 5 -u zt:ztpass -X POST \
        'http://127.0.0.1:15672/api/exchanges/%2F//publish' \
        -H 'Content-Type: application/json' --data '$payload' 2>&1 | head -1") || true
    local verdict
    if   [[ "$resp" =~ \"routed\":true  ]]; then verdict="accepted-by-broker"
    elif [[ "$resp" =~ \"routed\":false ]]; then verdict="broker-accepted-no-route"
    else                                         verdict="broker-rejected"
    fi
    emit "$case_id" "amqp-inject" "rabbitmq.order_queue" "—" "$verdict" \
        "$desc — broker_response=$resp" "$is_attack"
}

run_category_c() {
    log "Category C: AMQP envelope injection"
    c_inject C1 \
        '{"schema_version":99,"type":"gateway.request","route":"OrderCreateRequestedEvent","id":"mal-c1","spiffe_id":"","spiffe_path":[],"data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}}' \
        "schema_version=99 — RequestConsumer should reject"
    c_inject C2 \
        '{"schema_version":1,"type":"malicious","route":"OrderCreateRequestedEvent","id":"mal-c2","spiffe_id":"","spiffe_path":[],"data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}}' \
        "type=malicious — should be rejected"
    c_inject C3 \
        '{"schema_version":1,"type":"gateway.request","route":"OrderCreateRequestedEvent","id":"mal-c3","spiffe_id":"","spiffe_path":[]}' \
        "missing data — should be rejected"
    # C4–C6: identity-aware envelope cases. On stacks without spiffe_id
    # verification these will be accepted-by-broker AND processed by the
    # consumer (the differentiator).
    c_inject C4 \
        '{"schema_version":1,"type":"gateway.request","route":"OrderCreateRequestedEvent","id":"mal-c4","spiffe_id":"spiffe://attacker.example/svc","spiffe_path":["spiffe://attacker.example/svc"],"data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}}' \
        "forged spiffe_id from attacker domain"
    c_inject C5 \
        '{"schema_version":1,"type":"gateway.request","route":"OrderCreateRequestedEvent","id":"mal-c5","spiffe_id":"","spiffe_path":[],"data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}}' \
        "no spiffe_id (acceptable on stacks without identity layer)"
    c_inject C6 \
        '{"schema_version":1,"type":"gateway.request","route":"OrderCreateRequestedEvent","id":"mal-c6","spiffe_id":"spiffe://zt.local/order","spiffe_path":["spiffe://zt.local/order"],"data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}}' \
        "valid-looking spiffe_id but no signature (replay-style)"
}

# ── Category E: Replay ─────────────────────────────────────────────────────
# Same X-Correlation-Id sent three times. Application layer is expected to
# reject duplicates only if it implements idempotency keys — neither
# baseline, linkerd, nor pure-identity stacks add this for free.
e_post() {
    local case_id=$1 trace=$2 desc=$3 is_attack=${4:-1}
    local code
    code=$(ssh "$GATEWAY_ALIAS" "curl -sS -o /dev/null -w '%{http_code}' \
        -X POST '$A_GW' -H 'Content-Type: application/json' \
        -H 'X-Correlation-Id: $trace' \
        --data '{\"userKey\":\"1\",\"productList\":[{\"p_key\":1,\"amount\":1}],\"total\":100}'" 2>/dev/null || echo 000)
    emit "$case_id" "replay" "$A_GW" "$code" \
        "$(_verdict_http "$code")" \
        "trace=$trace — $desc" "$is_attack"
}

run_category_e() {
    log "Category E: Replay"
    local trace="replay-$STACK-$(date +%s)"
    e_post E1 "$trace" "first send (control)" 0
    sleep 1
    e_post E2 "$trace" "duplicate send (same trace_id)"
    sleep 1
    e_post E3 "$trace" "third send (same trace_id)"
}

# ── Summary helper ─────────────────────────────────────────────────────────
print_summary() {
    log "all probes done — results: $RESULTS"
    echo
    echo "── case-by-case ($STACK) ──"
    jq -r '. | "\(.case_id)\t\(.category)\t\(.status)\t\(.verdict)\t\(.notes[:80])"' "$RESULTS" \
        | column -t -s $'\t' || cat "$RESULTS"
}
