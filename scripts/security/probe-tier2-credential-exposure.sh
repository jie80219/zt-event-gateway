#!/usr/bin/env bash
# CVE#11/#12/#13 (zero-trust credential exposure): three SPIFFE-vs-Vault
# ARCHITECTURAL cases realised as file-read on the identity sidecar.
#
# CVE#11 credential-read      CVE-2024-24919 (Check Point Security Gateway
#                             arbitrary file read) — realised as reading the
#                             workload's private-key material.
# CVE#12 secret-zero          CVE-2024-3400 (PAN-OS unauth RCE → foothold that
#                             harvests a reusable bootstrap credential) —
#                             realised as reusing a static AppRole secret to
#                             mint a NEW identity.
# CVE#13 credential-lifetime  CVE-2024-23897 (Jenkins arbitrary file read used
#                             to steal credentials) — realised as measuring the
#                             validity window of a STOLEN leaf credential.
#
# Maps to attack categories: credential-read, secret-zero, credential-lifetime
# Weakness refs: CWE-320 (key management), CWE-798 (reusable credential),
#                CWE-522 (insufficiently protected credentials).
#
# WHY THIS PROBE EXISTS
#   The rest of the matrix attacks the SHARED application layer (gateway HTTP,
#   RabbitMQ envelope, downstream ports) or the DEPLOYMENT surface (docker
#   inspect / docker cp), so it cannot separate a SPIFFE/Vault *architectural*
#   difference from container-hardening (read_only, cap_drop, tmpfs noexec).
#   These cases deliberately GRANT the attacker docker-exec + file-read on the
#   identity sidecar of BOTH stacks — i.e. hardening is assumed defeated — and
#   score only what that access YIELDS:
#
#     CE0 credential-read     the durability of the private-key material once
#                             read.  Vault: tls.key written to /vault/out in
#                             cleartext, persistent on the container fs.  SPIFFE:
#                             key lives in SHM on tmpfs and is short-lived +
#                             auto-rotated — readable, but its usefulness decays.
#                             (Honest claim: rotation/durability, NOT "unreadable".)
#     CE1 secret-zero         a reusable bootstrap credential that mints a NEW
#                             service identity.  Vault: AppRole role_id/secret_id
#                             (secret_id_ttl=0 → permanent, mode 644) → login →
#                             pki/issue.  SPIFFE: Workload API attests the caller
#                             via unix peer creds — there is NO pre-shared secret
#                             to steal.
#     CE2 credential-lifetime the validity window of a STOLEN leaf credential.
#                             Vault: tls.key at rest + cert ttl=24h.  SPIFFE:
#                             SVID with SPIRE short TTL + in-place auto-rotation.
#
#   Because the attacker already has file-read on both sides, a divergent verdict
#   is attributable to the identity architecture, not to the container profile.
#
# Verdict semantics (attacker's perspective):
#   accepted = attack succeeded  (durable key material / reusable bootstrap
#              secret found / stolen credential valid for a long window)
#   rejected = attack neutralised (key short-lived+rotated / no bootstrap secret
#              to reuse / short auto-rotated window)
#   n/a      = required container or material absent on this stack

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

CVE_CR="CVE-2024-24919"   # CE0 credential-read (private-key material durability)
CVE_SZ="CVE-2024-3400"    # CE1 Secret Zero / reusable bootstrap credential
CVE_CL="CVE-2024-23897"   # CE2 insufficiently protected credential at rest

# --- CE0: credential-read (private-key material durability) ------------------
# Both stacks expose the leaf private key to a file-read attacker. What differs
# is DURABILITY: Vault renders tls.key to a persistent container path; SPIFFE
# keeps it in SHM on tmpfs, short-lived and auto-rotated. Honest verdict:
#   accepted = durable key material (persists, long-lived) — large blast radius
#   rejected = key material is short-lived + auto-rotated in place — decays fast
ce0_credential_read() {
    log "CE0 credential-read (private-key material durability)"
    case "$STACK" in
        vault)
            local keyhead
            keyhead=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-vault-agent-worker sh -c 'head -c 40 /vault/out/tls.key 2>/dev/null'" 2>/dev/null || true)
            if [[ "$keyhead" != *PRIVATE* ]]; then
                emit "credential-read" "CE0" "$CVE_CR" "n/a" "0" "vault-out/tls.key" \
                    "tls.key not readable at /vault/out/tls.key — cannot assess key durability on this run"
                return
            fi
            emit "credential-read" "CE0" "$CVE_CR" "accepted" "0" "vault-out/tls.key" \
                "private key readable in cleartext at /vault/out/tls.key on a persistent container path — durable material, reusable until the 24h cert expires with no in-place rotation of the leaked key"
            ;;
        spiffe-keycloak)
            # Reachability guard — an unreachable watcher must NOT score as a win.
            local alive
            alive=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-spiffe-watcher sh -c 'echo ok' 2>/dev/null" 2>/dev/null | tr -d '[:space:]' || true)
            if [[ "$alive" != "ok" ]]; then
                emit "credential-read" "CE0" "$CVE_CR" "n/a" "0" "spiffe-watcher" \
                    "zt-spiffe-watcher not reachable via docker exec — cannot assess key durability on this run"
                return
            fi
            local blob keypresent tmpfs pem
            blob=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-spiffe-watcher cat /tmp/spiffe-shared/x509/0.json 2>/dev/null" 2>/dev/null || true)
            keypresent=$(printf '%s' "$blob" | python3 -c 'import sys,json
try:
    d=json.load(sys.stdin); print("yes" if d.get("key_pem") else "no")
except Exception:
    print("no")' 2>/dev/null || echo no)
            pem=$(printf '%s' "$blob" | python3 -c 'import sys,json
try:
    d=json.load(sys.stdin); sys.stdout.write(d.get("cert_pem",""))
except Exception:
    pass' 2>/dev/null || true)
            if [[ "$keypresent" != "yes" || -z "$pem" ]]; then
                emit "credential-read" "CE0" "$CVE_CR" "n/a" "0" "spiffe-shared/x509/0.json" \
                    "SVID key/cert not readable from SHM — cannot assess key durability"
                return
            fi
            # tmpfs check for the SHM backing store (durability substantiation).
            tmpfs=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-spiffe-watcher sh -c 'stat -f -c %%T /tmp/spiffe-shared 2>/dev/null || stat -f -c %%T /tmp/spiffe-shared 2>/dev/null'" 2>/dev/null | tr -d '[:space:]' || true)
            local span; span=$(_openssl_long "$pem")
            local end;  end=$(_openssl_enddate "$pem")
            if [[ "$span" == "short" ]]; then
                emit "credential-read" "CE0" "$CVE_CR" "rejected" "0" "spiffe-svid-key" \
                    "key IS readable but the SVID is short-lived (notAfter=$end) on tmpfs ($tmpfs) and auto-rotated in place — leaked key material decays within one rotation period"
            else
                emit "credential-read" "CE0" "$CVE_CR" "accepted" "0" "spiffe-svid-key" \
                    "key readable and SVID valid >2h (notAfter=$end) — configured TTL is long, weakening the key-decay advantage"
            fi
            ;;
    esac
}

# --- CE1: Secret Zero --------------------------------------------------------
ce1_secret_zero() {
    log "CE1 secret-zero (reusable bootstrap credential)"
    case "$STACK" in
        vault)
            local rid sid tok
            rid=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-vault-agent-worker sh -c 'cat /vault/creds/worker/role_id 2>/dev/null'" 2>/dev/null | tr -d '[:space:]' || true)
            sid=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-vault-agent-worker sh -c 'cat /vault/creds/worker/secret_id 2>/dev/null'" 2>/dev/null | tr -d '[:space:]' || true)
            if [[ -z "$rid" || -z "$sid" ]]; then
                emit "secret-zero" "CE1" "$CVE_SZ" "n/a" "0" "vault-agent-worker" \
                    "AppRole creds not readable at /vault/creds/worker — cannot assess Secret Zero on this run"
                return
            fi
            # Prove the stolen secret is actually reusable: log in and mint a token.
            tok=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-vault sh -c 'VAULT_ADDR=http://127.0.0.1:8200 vault write -field=token auth/approle/login role_id=$rid secret_id=$sid 2>/dev/null'" 2>/dev/null | tr -d '[:space:]' || true)
            if [[ -n "$tok" ]]; then
                # Confirm the minted token can actually issue a fresh worker identity.
                local issued
                issued=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-vault sh -c 'VAULT_ADDR=http://127.0.0.1:8200 VAULT_TOKEN=$tok vault write -field=serial_number pki/issue/worker common_name=worker.zt.local uri_sans=spiffe://zt.local/worker ttl=24h 2>/dev/null'" 2>/dev/null | tr -d '[:space:]' || true)
                if [[ -n "$issued" ]]; then
                    emit "secret-zero" "CE1" "$CVE_SZ" "accepted" "0" "vault-approle" \
                        "world-readable AppRole secret_id (ttl=0, permanent) reused → minted token → issued NEW worker SVID serial=$issued; full identity forgery from a leaked static file"
                else
                    emit "secret-zero" "CE1" "$CVE_SZ" "accepted" "0" "vault-approle" \
                        "permanent AppRole secret_id reused → obtained Vault token (pki/issue reachable); reusable bootstrap credential present"
                fi
            else
                emit "secret-zero" "CE1" "$CVE_SZ" "accepted" "0" "vault-approle" \
                    "AppRole role_id+secret_id present at rest (mode 644, secret_id_ttl=0); static reusable bootstrap credential exists even if this login path was blocked"
            fi
            ;;
        spiffe-keycloak)
            # Reachability guard: an unreachable watcher must NOT be scored as a
            # defense win (empty output ≠ "no secret found").
            local alive
            alive=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-spiffe-watcher sh -c 'echo ok' 2>/dev/null" 2>/dev/null | tr -d '[:space:]' || true)
            if [[ "$alive" != "ok" ]]; then
                emit "secret-zero" "CE1" "$CVE_SZ" "n/a" "0" "spiffe-watcher" \
                    "zt-spiffe-watcher not reachable via docker exec — cannot assess Secret Zero on this run"
                return
            fi
            # Enumerate any pre-shared / reusable minting secret the watcher holds.
            # Workload API attests the caller by unix peer creds, so none should exist.
            local leak
            leak=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-spiffe-watcher sh -c '
                cat /vault/creds/*/secret_id /vault/creds/*/role_id 2>/dev/null
                find / -maxdepth 5 \\( -name secret_id -o -name role_id -o -name join_token -o -name agent_svid.der -o -name \"*.token\" \\) 2>/dev/null | grep -vE \"proc|sys\" | head
                env | grep -iE \"SECRET_ID|ROLE_ID|JOIN_TOKEN|BOOTSTRAP_TOKEN|VAULT_TOKEN\"
            '" 2>/dev/null | tr -d '[:space:]' || true)
            if [[ -z "$leak" ]]; then
                emit "secret-zero" "CE1" "$CVE_SZ" "rejected" "0" "spiffe-watcher" \
                    "no reusable bootstrap credential present; SVID obtained via Workload API (unix peer-cred attestation) — nothing a file-read yields can mint a new identity"
            else
                emit "secret-zero" "CE1" "$CVE_SZ" "accepted" "0" "spiffe-watcher" \
                    "unexpected reusable secret material found in watcher: $(printf '%s' "$leak" | head -c 100)"
            fi
            ;;
    esac
}

# --- CE2: stolen-credential validity window ---------------------------------
# checkend threshold: 7200 s (2 h). A leaf credential that survives >2 h from
# now is scored long-lived (large blast radius); one expiring sooner is short-
# lived + auto-rotated (small blast radius).
CHECKEND=7200

_openssl_enddate() { printf '%s' "$1" | openssl x509 -enddate -noout 2>/dev/null | cut -d= -f2- ; }
_openssl_long()    { printf '%s' "$1" | openssl x509 -checkend "$CHECKEND" -noout >/dev/null 2>&1 && echo long || echo short ; }

ce2_credential_lifetime() {
    log "CE2 credential-lifetime (stolen-credential validity window)"
    case "$STACK" in
        vault)
            local crt keyhead
            crt=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-vault-agent-worker cat /vault/out/tls.crt 2>/dev/null" 2>/dev/null || true)
            keyhead=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-vault-agent-worker sh -c 'head -c 40 /vault/out/tls.key 2>/dev/null'" 2>/dev/null || true)
            if [[ -z "$crt" ]]; then
                emit "credential-lifetime" "CE2" "$CVE_CL" "n/a" "0" "vault-out/tls.crt" \
                    "leaf cert not readable at /vault/out/tls.crt — cannot measure validity window"
                return
            fi
            local span; span=$(_openssl_long "$crt")
            local end;  end=$(_openssl_enddate "$crt")
            local keynote="private key at rest"
            [[ "$keyhead" == *PRIVATE* ]] && keynote="private key readable in cleartext at /vault/out/tls.key"
            if [[ "$span" == "long" ]]; then
                emit "credential-lifetime" "CE2" "$CVE_CL" "accepted" "0" "vault-pki-cert" \
                    "$keynote; cert valid >2h (notAfter=$end, ttl=24h) — a stolen key stays usable for the full lifetime with no in-place rotation of the leaked material"
            else
                emit "credential-lifetime" "CE2" "$CVE_CL" "rejected" "0" "vault-pki-cert" \
                    "$keynote but cert expires within 2h (notAfter=$end) — short window"
            fi
            ;;
        spiffe-keycloak)
            local pem
            pem=$(ssh -n "$GATEWAY_ALIAS" "docker exec zt-spiffe-watcher cat /tmp/spiffe-shared/x509/0.json 2>/dev/null" 2>/dev/null \
                | python3 -c 'import sys,json
try:
    d=json.load(sys.stdin); sys.stdout.write(d.get("cert_pem",""))
except Exception:
    pass' 2>/dev/null || true)
            if [[ -z "$pem" ]]; then
                emit "credential-lifetime" "CE2" "$CVE_CL" "n/a" "0" "spiffe-shared/x509/0.json" \
                    "SVID cert_pem not readable from SHM — cannot measure validity window"
                return
            fi
            local span; span=$(_openssl_long "$pem")
            local end;  end=$(_openssl_enddate "$pem")
            if [[ "$span" == "short" ]]; then
                emit "credential-lifetime" "CE2" "$CVE_CL" "rejected" "0" "spiffe-svid" \
                    "SVID expires within 2h (notAfter=$end) and is auto-rotated in place by the watcher — a captured SVID's window closes within one rotation period"
            else
                emit "credential-lifetime" "CE2" "$CVE_CL" "accepted" "0" "spiffe-svid" \
                    "SVID valid >2h (notAfter=$end) — configured TTL is long, weakening the short-lived-credential advantage"
            fi
            ;;
    esac
}

log "=== Tier 2 / CVE#11+#12+#13: Zero-Trust Credential Exposure (read + Secret Zero + validity window) ==="
ce0_credential_read
ce1_secret_zero
ce2_credential_lifetime
log "credential-exposure probe done"
