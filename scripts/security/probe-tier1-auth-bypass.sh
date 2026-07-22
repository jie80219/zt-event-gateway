#!/usr/bin/env bash
# CVE#1 Authentication Bypass (CVE-2024-1709 — ConnectWise ScreenConnect
#   setup-wizard auth bypass, CVSS 10) — representative pattern, realised as
#   ingress JWT bypass against the SUT.
# CVE#2 Identity-token Forgery (CVE-2024-45409 — Ruby-SAML signature
#   verification bypass / assertion forgery) — realised as SPIFFE-ID / nested
#   LSVID forgery in the canonical envelope.
#
# Maps to attack categories: http-ingress, lsvid-forgery
#
# Cases:
#   AB1  http-ingress  jwt-missing               (no Authorization header)
#   AB2  http-ingress  jwt-bad-sig               (HS256 self-signed token)
#   AB3  http-ingress  jwt-expired               (exp=0 token)
#   AB4  http-ingress  happy-path-control        (valid request — should pass)
#   LF1  lsvid-forgery spiffe-id-forged          (envelope w/ attacker.example SPIFFE ID)
#   LF2  lsvid-forgery lsvid-missing             (envelope w/o LSVID at all)
#   LF3  lsvid-forgery lsvid-tampered-nested     (mutated nested signature)
#   LF4  lsvid-forgery lsvid-wrong-audience      (aud != downstream service)
#
# Expected outcomes:
#   spiffe-keycloak: AB1/AB2/AB3 rejected by KeycloakIngressJwtFilter (401);
#                    LF1-LF4 rejected by RequestConsumer / EventConsumer (no
#                    downstream processing; verified via worker log).
#   vault:           AB1/AB2/AB3 rejected by KeycloakIngressJwtFilter (401);
#                    LF1-LF4 rejected by RequestConsumer / EventConsumer
#                    canonical envelope check (no LSVID layer, but envelope
#                    validation still runs).

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

CVE="CVE-2024-1709"
CVE_LSVID="CVE-2024-45409"

# ── http-ingress (JWT layer) ─────────────────────────────────────────────────
do_http() {
    local case_id=$1 desc=$2 headers=$3 body=$4 cve=$5
    local r code ms
    r=$(ssh_curl "$GATEWAY_ALIAS" "$A_GW" POST "$headers" "$body")
    code="${r%%|*}"; ms="${r##*|}"
    emit "http-ingress" "$case_id" "$cve" "$(classify_http "$code")" "$ms" "$A_GW" \
        "$desc http=$code"
}

HAPPY_BODY='{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}'

run_http_ingress() {
    log "AB1 jwt-missing"
    do_http AB1 "no-Authorization" "Content-Type: application/json" "$HAPPY_BODY" "$CVE"

    log "AB2 jwt-bad-sig"
    # HS256 token signed with attacker key; payload claims iss=attacker
    local bad_jwt
    bad_jwt=$(python3 - <<'PY'
import base64,hmac,hashlib,json,time
hdr=base64.urlsafe_b64encode(json.dumps({"alg":"HS256","typ":"JWT"}).encode()).rstrip(b"=").decode()
pld=base64.urlsafe_b64encode(json.dumps({"sub":"attacker","iss":"https://evil","exp":int(time.time())+3600}).encode()).rstrip(b"=").decode()
sig=base64.urlsafe_b64encode(hmac.new(b"attackerkey",f"{hdr}.{pld}".encode(),hashlib.sha256).digest()).rstrip(b"=").decode()
print(f"{hdr}.{pld}.{sig}")
PY
)
    do_http AB2 "self-signed-jwt" $'Content-Type: application/json\nAuthorization: Bearer '"$bad_jwt" "$HAPPY_BODY" "$CVE"

    log "AB3 jwt-expired"
    local exp_jwt
    exp_jwt=$(python3 - <<'PY'
import base64,hmac,hashlib,json
hdr=base64.urlsafe_b64encode(json.dumps({"alg":"HS256","typ":"JWT"}).encode()).rstrip(b"=").decode()
pld=base64.urlsafe_b64encode(json.dumps({"sub":"u1","iss":"https://kc","exp":0}).encode()).rstrip(b"=").decode()
sig=base64.urlsafe_b64encode(hmac.new(b"x",f"{hdr}.{pld}".encode(),hashlib.sha256).digest()).rstrip(b"=").decode()
print(f"{hdr}.{pld}.{sig}")
PY
)
    do_http AB3 "expired-jwt" $'Content-Type: application/json\nAuthorization: Bearer '"$exp_jwt" "$HAPPY_BODY" "$CVE"

    log "AB4 happy-path-control"
    local hdrs="Content-Type: application/json"
    case "$STACK" in
        spiffe-keycloak|vault)
            local tok
            tok=$(kc_token)
            if [[ -n "$tok" ]]; then
                hdrs=$'Content-Type: application/json\nAuthorization: Bearer '"$tok"
            fi
            ;;
    esac
    do_http AB4 "control-valid-request" "$hdrs" "$HAPPY_BODY" "$CVE"
}

# ── lsvid-forgery (envelope at AMQP layer) ───────────────────────────────────
do_amqp() {
    local case_id=$1 desc=$2 envelope=$3 cve=$4
    local trace="$(echo "$case_id" | tr '[:upper:]' '[:lower:]')-$(date +%s)"
    # inject trace into envelope so we can correlate worker log
    local mutated
    mutated=$(printf '%s' "$envelope" | TRACE="$trace" python3 -c '
import json,sys,os
e=json.loads(sys.stdin.read())
e.setdefault("data",{})["correlation_id"]=os.environ["TRACE"]
e["id"]=os.environ["TRACE"]
print(json.dumps(e))
')
    local route
    route=$(amqp_publish "order_queue" "$mutated")
    # Did worker actually process it? (any saga step appearing == accepted)
    sleep 1
    local processed
    processed=$(wait_for_saga_step "$trace" "Saga Step 1|OrderCreatedEvent" 5)
    local result
    if [[ "$route" == "broker-rejected" ]]; then
        result=blocked
    elif [[ "$processed" == "ok" ]]; then
        result=accepted
    else
        result=rejected
    fi
    emit "lsvid-forgery" "$case_id" "$cve" "$result" "0" "rabbitmq/order_queue" \
        "$desc broker=$route worker=$processed trace=$trace"
}

run_lsvid_forgery() {
    log "LF1 spiffe-id-forged"
    do_amqp LF1 "spiffe_id=attacker.example" '
{"schema_version":1,"type":"gateway.request","route":"OrderCreateRequestedEvent","spiffe_id":"spiffe://attacker.example/svc","spiffe_path":["spiffe://attacker.example/svc"],"data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}}' "$CVE_LSVID"

    log "LF2 lsvid-missing"
    do_amqp LF2 "no lsvid field" '
{"schema_version":1,"type":"gateway.request","route":"OrderCreateRequestedEvent","spiffe_id":"spiffe://zt.local/gateway","spiffe_path":["spiffe://zt.local/gateway"],"data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}}' "$CVE_LSVID"

    log "LF3 lsvid-tampered-nested"
    # Fabricate a fake L0 with mutated signature byte
    local fake_lsvid
    fake_lsvid=$(python3 - <<'PY'
import base64,json
hdr=base64.urlsafe_b64encode(json.dumps({"alg":"ES256","typ":"LSVID"}).encode()).rstrip(b"=").decode()
pld=base64.urlsafe_b64encode(json.dumps({"iss":"spiffe://zt.local/gateway","aud":"spiffe://zt.local/worker","sub":"spiffe://zt.local/client","jti":"forged-l0"}).encode()).rstrip(b"=").decode()
sig=base64.urlsafe_b64encode(b"X"*64).rstrip(b"=").decode()
print(f"{hdr}.{pld}.{sig}")
PY
)
    do_amqp LF3 "lsvid mutated sig" "$(L="$fake_lsvid" python3 -c '
import json,os
e={"schema_version":1,"type":"gateway.request","route":"OrderCreateRequestedEvent","spiffe_id":"spiffe://zt.local/gateway","spiffe_path":["spiffe://zt.local/gateway"],"lsvid":os.environ["L"],"data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}}
print(json.dumps(e))')" "$CVE_LSVID"

    log "LF4 lsvid-wrong-audience"
    local wrong_aud_lsvid
    wrong_aud_lsvid=$(python3 - <<'PY'
import base64,json
hdr=base64.urlsafe_b64encode(json.dumps({"alg":"ES256","typ":"LSVID"}).encode()).rstrip(b"=").decode()
pld=base64.urlsafe_b64encode(json.dumps({"iss":"spiffe://zt.local/gateway","aud":"spiffe://other-trust/x","sub":"spiffe://zt.local/client","jti":"wrong-aud"}).encode()).rstrip(b"=").decode()
sig=base64.urlsafe_b64encode(b"Y"*64).rstrip(b"=").decode()
print(f"{hdr}.{pld}.{sig}")
PY
)
    do_amqp LF4 "lsvid aud=other-trust" "$(L="$wrong_aud_lsvid" python3 -c '
import json,os
e={"schema_version":1,"type":"gateway.request","route":"OrderCreateRequestedEvent","spiffe_id":"spiffe://zt.local/gateway","spiffe_path":["spiffe://zt.local/gateway"],"lsvid":os.environ["L"],"data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}}
print(json.dumps(e))')" "$CVE_LSVID"
}

log "=== Tier 1 / CVE#1+#2: Authentication Bypass + LSVID Forgery ==="
run_http_ingress
run_lsvid_forgery
log "auth-bypass probe done"
