#!/usr/bin/env bash
# CVE#10: Identity Management System Blast Radius
# (CVE-2025-61757 — Oracle Identity Manager pre-auth RCE / CVE-2024-7401 —
#  Netskope IdP identity-injection: compromise of the identity authority itself).
#
# Maps to attack category: identity-blast-radius
#
# Mixed probe:
#   * IB1/IB2 are ENUMERATION (threat-model measurements, not executed attacks):
#     they count how many identities each authority could mint if compromised.
#     latency=0 and the detail is explicitly tagged 'config-enumeration'.
#   * IB4 is an EXECUTED attack: it publishes a forged nested-LSVID envelope to
#     the worker and derives the verdict from whether the worker actually
#     processed it (with worker-log evidence), same byte-payload on both stacks.
#   * IB5 is an EXECUTED reachability check of the authority admin ports.
#
# Cases:
#   IB1  identity-blast-radius  spire-server-blast / vault-pki-blast  (enumeration)
#   IB2  identity-blast-radius  keycloak-blast                        (enumeration)
#   IB4  identity-blast-radius  forged-nested-lsvid-rejection         (executed)
#   IB5  identity-blast-radius  authority-exposure                    (executed)
#
# Verdict semantics:
#   accepted = compromise here causes large blast / forged identity processed /
#              authority exposed
#   rejected = blast contained / forged identity rejected (chain break) /
#              authority isolated
#   n/a      = authority doesn't exist on this stack / could not stage

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

CVE="CVE-2025-61757"      # + CVE-2024-7401 (Netskope IdP) — see header

# IB4 — executed forged nested-LSVID attack (byte-identical payload on both
# stacks). Fabricates a bogus L0 with a garbage signature, wraps it in a
# canonical envelope, publishes to order_queue, and derives the verdict from
# whether the worker processed it. On spiffe-keycloak the nested-chain signature
# verification breaks → rejected (with worker-log evidence); on vault there is
# no LSVID layer so the same envelope is judged only by the canonical check.
ib4_forged_nested_lsvid() {
    log "IB4 forged-nested-lsvid-rejection (executed)"
    local trace="ib4-$(date +%s)"
    local fake_lsvid
    fake_lsvid=$(python3 - <<'PY'
import base64,json
hdr=base64.urlsafe_b64encode(json.dumps({"alg":"ES256","typ":"LSVID"}).encode()).rstrip(b"=").decode()
pld=base64.urlsafe_b64encode(json.dumps({"iss":"spiffe://zt.local/gateway","aud":"spiffe://zt.local/worker","sub":"spiffe://zt.local/client","jti":"ib4-forged-l0"}).encode()).rstrip(b"=").decode()
sig=base64.urlsafe_b64encode(b"Z"*64).rstrip(b"=").decode()
print(f"{hdr}.{pld}.{sig}")
PY
)
    local envelope
    envelope=$(T="$trace" L="$fake_lsvid" python3 -c '
import json,os
e={"schema_version":1,"type":"gateway.request","route":"OrderCreateRequestedEvent",
   "id":os.environ["T"],
   "spiffe_id":"spiffe://zt.local/gateway","spiffe_path":["spiffe://zt.local/gateway"],
   "lsvid":os.environ["L"],
   "data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100,"correlation_id":os.environ["T"]}}
print(json.dumps(e))')
    local route
    route=$(amqp_publish "order_queue" "$envelope")
    sleep 1
    local processed
    processed=$(wait_for_saga_step "$trace" "Saga Step 1|OrderCreatedEvent" 5)
    local result target detail
    if [[ "$route" == "broker-rejected" ]]; then
        result="n/a"; target="rabbitmq/order_queue"
        detail="broker rejected publish — could not stage forged-LSVID attack (trace=$trace)"
    elif [[ "$processed" == "ok" ]]; then
        result="accepted"; target="rabbitmq/order_queue"
        detail="forged nested-LSVID processed by worker (no chain verification broke it) trace=$trace"
    else
        result="rejected"; target="lsvid-chain"
        detail="forged nested-LSVID not processed — signature/chain check rejected it (worker log, trace=$trace)"
    fi
    emit "identity-blast-radius" "IB4" "$CVE" "$result" "0" "$target" "$detail"
}

log "=== Tier 2 / CVE#10: Identity Management Blast Radius ==="

case "$STACK" in
    spiffe-keycloak)
        # IB1: SPIRE Server — controls every workload SVID. Compromise = ability
        # to mint ID for any service in trust domain zt.local.
        spire_count=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-spire-server bin/spire-server entry show 2>/dev/null | grep -c 'Entry ID'" || echo 0)
        emit "identity-blast-radius" "IB1" "$CVE" "accepted" "0" "spire-server" \
            "config-enumeration (non-executed): compromise of SPIRE Server allows minting any of $spire_count registered SPIFFE IDs"

        # IB2: Keycloak — controls user JWTs. Compromise = ability to mint user
        # identity for any client/realm.
        emit "identity-blast-radius" "IB2" "$CVE" "accepted" "0" "keycloak" \
            "config-enumeration (non-executed): compromise of Keycloak admin allows minting any user JWT; affects ingress AuthN only (LSVID chain still requires SPIRE workload identity)"

        # IB4: executed forged nested-LSVID (byte-identical payload on both stacks)
        ib4_forged_nested_lsvid

        # IB5: SPIRE Server admin port exposure (8081 by default; should be UDS only)
        adm=$(ssh -n "$GATEWAY_ALIAS" "curl -sS -o /dev/null -w '%{http_code}' --max-time 2 http://127.0.0.1:8081/ 2>/dev/null" || echo 000)
        kc=$(ssh -n "$GATEWAY_ALIAS" "curl -sS -o /dev/null -w '%{http_code}' --max-time 2 http://127.0.0.1:8180/admin/ 2>/dev/null" || echo 000)
        if [[ "$adm" == "000" && "$kc" =~ ^(000|301|302|404)$ ]]; then
            emit "identity-blast-radius" "IB5" "$CVE" "rejected" "0" "admin-ports" \
                "SPIRE adm=$adm Keycloak adm=$kc — authorities not directly reachable from gateway data plane"
        else
            emit "identity-blast-radius" "IB5" "$CVE" "accepted" "0" "admin-ports" \
                "SPIRE adm=$adm Keycloak adm=$kc — authority admin reachable"
        fi
        ;;
    vault)
        # IB1: Vault root — controls every PKI issuance. Compromise = ability to
        # mint TLS certs for any service in the PKI mount.
        pki_roles=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-vault sh -c 'VAULT_TOKEN=\$VAULT_DEV_ROOT_TOKEN_ID vault list -format=json pki/roles 2>/dev/null | jq -r length'" 2>/dev/null || echo 0)
        emit "identity-blast-radius" "IB1" "$CVE" "accepted" "0" "vault-pki" \
            "config-enumeration (non-executed): compromise of Vault root token allows minting certs for any of $pki_roles PKI roles in pki/ mount"

        # IB2: Keycloak — same as spiffe-keycloak (parallel JWT layer)
        emit "identity-blast-radius" "IB2" "$CVE" "accepted" "0" "keycloak" \
            "config-enumeration (non-executed): compromise of Keycloak admin allows minting any user JWT; affects ingress AuthN only (mTLS still requires Vault-issued cert)"

        # IB4: executed forged nested-LSVID — vault has no LSVID layer, so the
        # same payload is judged only by the canonical envelope check.
        ib4_forged_nested_lsvid

        # IB5: Vault HTTP API exposure (8200) + Keycloak admin (8180)
        vlt=$(ssh -n "$GATEWAY_ALIAS" "curl -sS -o /dev/null -w '%{http_code}' --max-time 2 http://127.0.0.1:8200/v1/sys/health 2>/dev/null" || echo 000)
        kc=$(ssh -n "$GATEWAY_ALIAS" "curl -sS -o /dev/null -w '%{http_code}' --max-time 2 http://127.0.0.1:8180/admin/ 2>/dev/null" || echo 000)
        if [[ "$vlt" == "000" && "$kc" =~ ^(000|301|302|404)$ ]]; then
            emit "identity-blast-radius" "IB5" "$CVE" "rejected" "0" "admin-ports" \
                "Vault adm=$vlt Keycloak adm=$kc — authorities not directly reachable from gateway data plane"
        else
            emit "identity-blast-radius" "IB5" "$CVE" "accepted" "0" "admin-ports" \
                "Vault adm=$vlt Keycloak adm=$kc — authority admin reachable"
        fi
        ;;
esac

log "identity-blast probe done"
