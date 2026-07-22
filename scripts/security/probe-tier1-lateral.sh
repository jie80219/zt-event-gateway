#!/usr/bin/env bash
# CVE#3 Network-segmentation bypass (CVE-2024-3661 — "TunnelVision", routing
#   trust-boundary break) — realised as direct downstream calls that bypass
#   the gateway.
# CVE#4 Input-validation bypass (CVE-2024-38856 — Apache OFBiz auth-bypass to
#   unauthenticated execution) — realised as broker-level envelope injection
#   into the event bus.
#
# Maps to attack categories: cross-host + amqp-inject
#
# Cases:
#   LM1  cross-host  bypass-gateway-direct-order       (curl :8082/api/v1/order)
#   LM2  cross-host  bypass-gateway-direct-production  (curl :8083/api/v1/inventory/reduceInventory)
#   LM3  cross-host  bypass-gateway-direct-user        (curl :8084/api/v1/wallet)
#   AI1  amqp-inject schema-violation                  (schema_version=99)
#   AI2  amqp-inject type-mutation                     (type=malicious)
#   AI3  amqp-inject missing-data                      (data field absent)
#   AI4  amqp-inject route-mutation                    (route=unknown-event)
#
# (The former LM4 forged-SVID case now lives in probe-tier2-attestation.sh as
#  AT2, where it belongs to the SPIFFE-attestation capability class.)
#
# Expected outcomes:
#   spiffe-keycloak: LM1-LM3 rejected by downstream mTLS / LSVID validation;
#                    AI1-AI4 rejected by RequestConsumer canonical envelope check.
#   vault:           LM1-LM3 rejected by downstream mTLS (Vault-issued cert);
#                    AI1-AI4 rejected by RequestConsumer canonical envelope check.

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

CVE="CVE-2024-3661"
CVE_INJECT="CVE-2024-38856"

# ── cross-host: direct downstream calls ──────────────────────────────────────
do_cross_host() {
    local case_id=$1 alias=$2 url=$3 method=$4 body=$5 desc=$6
    local headers="Content-Type: application/json"
    local r code ms
    r=$(ssh_curl "$alias" "$url" "$method" "$headers" "$body")
    code="${r%%|*}"; ms="${r##*|}"
    emit "cross-host" "$case_id" "$CVE" "$(classify_http "$code")" "$ms" "$url" \
        "$desc http=$code"
}

run_cross_host() {
    log "LM1 direct order"
    do_cross_host LM1 "$ORDER_ALIAS" "http://127.0.0.1:8082/api/v1/order" GET "" "no-auth direct call to order service"

    log "LM2 direct production reduceInventory"
    do_cross_host LM2 "$PROD_ALIAS" "http://127.0.0.1:8083/api/v1/inventory/reduceInventory" POST \
        '{"p_key":1,"amount":1}' "no-auth direct reduceInventory"

    log "LM3 direct user wallet"
    do_cross_host LM3 "$USER_ALIAS" "http://127.0.0.1:8084/api/v1/wallet" GET "" "no-auth direct wallet read"
}

# ── amqp-inject: envelope mutations ──────────────────────────────────────────
do_amqp_inject() {
    local case_id=$1 desc=$2 envelope=$3
    local trace="$(echo "$case_id" | tr '[:upper:]' '[:lower:]')-$(date +%s)"
    local mutated
    mutated=$(printf '%s' "$envelope" | TRACE="$trace" python3 -c '
import json,sys,os
e=json.loads(sys.stdin.read())
e["id"]=os.environ["TRACE"]
e.setdefault("data",{})["correlation_id"]=os.environ["TRACE"]
print(json.dumps(e))
') || mutated="$envelope"
    local route
    route=$(amqp_publish "order_queue" "$mutated")
    sleep 1
    local processed
    processed=$(wait_for_saga_step "$trace" "Saga Step 1|OrderCreatedEvent" 4)
    local result
    if [[ "$route" == "broker-rejected" ]]; then
        result=blocked
    elif [[ "$processed" == "ok" ]]; then
        result=accepted
    else
        result=rejected
    fi
    emit "amqp-inject" "$case_id" "$CVE_INJECT" "$result" "0" "rabbitmq/order_queue" \
        "$desc broker=$route worker=$processed trace=$trace"
}

run_amqp_inject() {
    log "AI1 schema_version=99"
    do_amqp_inject AI1 "schema_version=99" \
        '{"schema_version":99,"type":"gateway.request","route":"OrderCreateRequestedEvent","spiffe_id":"spiffe://zt.local/gateway","spiffe_path":["spiffe://zt.local/gateway"],"data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}}'

    log "AI2 type=malicious"
    do_amqp_inject AI2 "type=malicious" \
        '{"schema_version":1,"type":"malicious","route":"OrderCreateRequestedEvent","spiffe_id":"spiffe://zt.local/gateway","spiffe_path":["spiffe://zt.local/gateway"],"data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}}'

    log "AI3 missing data"
    do_amqp_inject AI3 "no-data-field" \
        '{"schema_version":1,"type":"gateway.request","route":"OrderCreateRequestedEvent","spiffe_id":"spiffe://zt.local/gateway","spiffe_path":["spiffe://zt.local/gateway"]}'

    log "AI4 route=unknown"
    do_amqp_inject AI4 "route=UnknownEvent" \
        '{"schema_version":1,"type":"gateway.request","route":"UnknownEvent","spiffe_id":"spiffe://zt.local/gateway","spiffe_path":["spiffe://zt.local/gateway"],"data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}}'
}

log "=== Tier 1 / CVE#3+#4: Lateral Movement (cross-host + amqp-inject) ==="
run_cross_host
run_amqp_inject
log "lateral probe done"
