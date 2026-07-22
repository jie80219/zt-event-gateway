#!/usr/bin/env bash
# CVE#15: Insufficient Network Visibility / post-foothold forensic blind spot
#   (CVE-2024-6387 — OpenSSH "regreSSHion" pre-auth RCE gives an attacker a host
#    foothold; this probe measures how much of that attacker's activity a
#    forensic responder can reconstruct from each stack's built-in audit trail).
#
# Maps to attack category: observability-diff
#
# Compares the audit trail produced by each stack for a single legit Saga.
# The question: can a forensic responder reconstruct (a) caller identity,
# (b) call chain hops, (c) per-hop timing, (d) authorization decision from
# the stack's built-in observability alone?
#
# Cases:
#   OB1  observability-diff  caller-identity-recovery
#   OB2  observability-diff  call-chain-reconstruction
#   OB3  observability-diff  per-hop-timing
#   OB4  observability-diff  authz-decision-trail
#
# Verdict semantics:
#   accepted = visibility gap exists (attacker actions could not be
#              reconstructed from audit trail)
#   rejected = audit trail covers this aspect
#   n/a      = field not applicable to this stack
#
# Implementation: trigger one happy-path Saga, then probe each stack's
# canonical observability source (worker log + LSVID chain for
# spiffe-keycloak; worker log + KC JWT / Vault cert for vault).

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

CVE="CVE-2024-6387"   # post-foothold forensic-reconstruction framing (see header)

trigger_saga() {
    local trace=$1
    local hdrs="Content-Type: application/json"
    case "$STACK" in
        spiffe-keycloak|vault)
            local tok
            tok=$(kc_token); [[ -n "$tok" ]] && hdrs=$'Content-Type: application/json\nAuthorization: Bearer '"$tok"
            ;;
    esac
    ssh_curl "$GATEWAY_ALIAS" "$A_GW" POST "$hdrs"$'\nX-Correlation-Id: '"$trace" \
        '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}' >/dev/null
    sleep 6
}

log "=== Tier 2 / CVE#15: Observability Diff (post-foothold forensic reconstruction) ==="

TRACE="obs-${STACK}-$(date +%s)"
trigger_saga "$TRACE"

# ── OB1: caller-identity recovery ───────────────────────────────────────────
case "$STACK" in
    spiffe-keycloak)
        # Search worker log for SPIFFE ID + LSVID iss/sub fields
        found=$(ssh -n "$GATEWAY_ALIAS" "docker logs --since=60s --tail 500 zt-php-worker 2>&1 \
            | grep -F '$TRACE' | grep -oE 'spiffe://[^ ,\"]+' | head -1") || found=""
        if [[ -n "$found" ]]; then
            emit "observability-diff" "OB1" "$CVE" "rejected" "0" "lsvid-chain" \
                "caller identity recoverable: $found"
        else
            emit "observability-diff" "OB1" "$CVE" "accepted" "0" "lsvid-chain" \
                "no SPIFFE ID found in worker log for trace=$TRACE"
        fi
        ;;
    vault)
        # Vault PKI gives X.509 only at TLS layer; no app-level identity log.
        # Look for KC JWT sub / Vault cert CN markers as best-effort.
        found=$(ssh -n "$GATEWAY_ALIAS" "docker logs --since=60s --tail 500 zt-php-worker 2>&1 \
            | grep -F '$TRACE' | grep -oE '(sub=|CN=)[^ ,\"]+' | head -1") || found=""
        if [[ -n "$found" ]]; then
            emit "observability-diff" "OB1" "$CVE" "rejected" "0" "kc-jwt+vault-cert" \
                "caller hinted: $found (no nested chain — single hop only)"
        else
            emit "observability-diff" "OB1" "$CVE" "accepted" "0" "kc-jwt+vault-cert" \
                "no caller identity recoverable from worker log for trace=$TRACE (gap)"
        fi
        ;;
esac

# ── OB2: call-chain reconstruction ──────────────────────────────────────────
# Count distinct hops we can confirm for this trace
case "$STACK" in
    spiffe-keycloak|vault)
        hops=$(ssh -n "$GATEWAY_ALIAS" "docker logs --since=60s --tail 500 zt-php-worker 2>&1 \
            | grep -F '$TRACE' | grep -oE 'Saga Step [0-9]+' | sort -u | wc -l") || hops=0
        if [[ "$hops" -ge 3 ]]; then
            emit "observability-diff" "OB2" "$CVE" "rejected" "0" "saga-log" \
                "call chain reconstructable: $hops Saga steps logged for $TRACE"
        else
            emit "observability-diff" "OB2" "$CVE" "accepted" "0" "saga-log" \
                "only $hops Saga steps visible — partial visibility"
        fi
        ;;
esac

# ── OB3: per-hop timing ─────────────────────────────────────────────────────
case "$STACK" in
    spiffe-keycloak|vault)
        # Worker logs the receive→complete timings per Saga step
        has_timing=$(ssh -n "$GATEWAY_ALIAS" "docker logs --since=60s --tail 500 zt-php-worker 2>&1 \
            | grep -F '$TRACE' | grep -cE '(ms|elapsed|duration)'") || has_timing=0
        if [[ "$has_timing" -ge 1 ]]; then
            emit "observability-diff" "OB3" "$CVE" "rejected" "0" "saga-log" \
                "per-step timing present ($has_timing markers)"
        else
            emit "observability-diff" "OB3" "$CVE" "accepted" "0" "saga-log" \
                "no per-step timing in worker log (gap)"
        fi
        ;;
esac

# ── OB4: authz decision trail ───────────────────────────────────────────────
case "$STACK" in
    spiffe-keycloak)
        # Look for explicit "rejected"/"accepted"/"trust domain"/"audience" markers
        markers=$(ssh -n "$GATEWAY_ALIAS" "docker logs --since=60s --tail 500 zt-php-worker 2>&1 \
            | grep -F '$TRACE' | grep -cE 'trust.?domain|audience|accepted|rejected|validated'") || markers=0
        if [[ "$markers" -ge 1 ]]; then
            emit "observability-diff" "OB4" "$CVE" "rejected" "0" "lsvid-validator-log" \
                "authz decisions logged ($markers markers)"
        else
            emit "observability-diff" "OB4" "$CVE" "accepted" "0" "lsvid-validator-log" \
                "no explicit authz decision markers (gap)"
        fi
        ;;
    vault)
        # Vault PKI does TLS at network layer (no app-level decision log);
        # Keycloak does JWT validation (some markers). Count both.
        markers=$(ssh -n "$GATEWAY_ALIAS" "docker logs --since=60s --tail 500 zt-php-worker 2>&1 \
            | grep -F '$TRACE' | grep -cE 'JWT|audience|accepted|rejected|validated'") || markers=0
        if [[ "$markers" -ge 1 ]]; then
            emit "observability-diff" "OB4" "$CVE" "rejected" "0" "kc-jwt-validator-log" \
                "authz decisions logged ($markers markers) — JWT only, no mTLS decisions"
        else
            emit "observability-diff" "OB4" "$CVE" "accepted" "0" "kc-jwt-validator-log" \
                "no explicit authz decision markers (gap) — Vault mTLS at TLS layer only"
        fi
        ;;
esac

log "visibility probe done"
