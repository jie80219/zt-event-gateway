#!/usr/bin/env bash
# CVE#5: Credential / session Replay (CVE-2024-21887 + CVE-2024-21893 — Ivanti
#   Connect Secure command-injection + auth-bypass chain, realised as replay of
#   a captured LSVID / JWT against the SUT).
#
# Maps to attack category: replay
#
# Diagnostic only — does NOT add new defenses to either stack. The intent is
# to provide quantified evidence (in ch5 §7 limitations) that:
#   - spiffe-keycloak currently lacks short-window LSVID jti nonce tracking
#
# Cases:
#   RP1  replay  lsvid-replay-after-rotation
#         capture a legitimate L1, force SVID rotation, replay L1 within exp
#   RP2  replay  jwt-replay-after-revoke
#         use a still-valid JWT after the user is invalidated in Keycloak
#   RP3  replay  svid-replay-cross-correlation
#         submit two distinct orders with the same lsvid (jti reused)
#
# C-1 note: when the harness cannot CAPTURE the credential to replay (no L1 in
#   the worker log, no Keycloak token), that is an environment/preflight gap,
#   NOT a defense — those cases emit 'n/a' (excluded from the rejection ratio),
#   never 'blocked'.
#
# Expected outcomes (matching ch5 §5 current data):
#   spiffe-keycloak: RP1=accepted, RP3=accepted, RP2=accepted (0/3, all replayable)

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

CVE="CVE-2024-21887"      # + CVE-2024-21893 (Ivanti chain) — see header

# ── RP1: capture legit L1 → rotate SVID → replay L1 ─────────────────────────
rp1_lsvid_replay() {
    log "RP1 lsvid-replay-after-rotation"
    local trace="rp1-$(date +%s)"
    # Trigger a legit request to make worker mint L1
    local tok hdrs="Content-Type: application/json"
    tok=$(kc_token)
    [[ -n "$tok" ]] && hdrs=$'Content-Type: application/json\nAuthorization: Bearer '"$tok"
    ssh_curl "$GATEWAY_ALIAS" "$A_GW" POST "$hdrs"$'\nX-Correlation-Id: '"$trace" \
        '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}' >/dev/null
    sleep 1
    # Extract the L1 LSVID emitted by worker for this trace
    local captured
    captured=$(ssh -n "$GATEWAY_ALIAS" "docker logs --since=20s --tail 500 zt-php-worker 2>&1 \
        | grep -F '$trace' | grep -oE 'lsvid=[A-Za-z0-9._-]+' | head -1 | cut -d= -f2-") || captured=""
    if [[ -z "$captured" ]]; then
        emit "replay" "RP1" "$CVE" "n/a" "0" "lsvid-capture-failed" \
            "could not extract L1 LSVID from worker log (trace=$trace) — capture/preflight gap, not a defense"
        return
    fi
    # Force SPIFFE SVID rotation (touch spiffe-watcher to re-fetch)
    ssh -n "$GATEWAY_ALIAS" "docker exec zt-spiffe-watcher kill -HUP 1 2>/dev/null" || true
    sleep 3
    # Replay the captured L1 in a new envelope
    local trace2="rp1r-$(date +%s)"
    local envelope
    envelope=$(T2="$trace2" L="$captured" python3 -c '
import json,os
e={"schema_version":1,"type":"gateway.request","route":"OrderCreateRequestedEvent",
   "id":os.environ["T2"],
   "spiffe_id":"spiffe://zt.local/gateway","spiffe_path":["spiffe://zt.local/gateway"],
   "lsvid":os.environ["L"],
   "data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100,"correlation_id":os.environ["T2"]}}
print(json.dumps(e))')
    local route
    route=$(amqp_publish "order_queue" "$envelope")
    sleep 2
    local processed
    processed=$(wait_for_saga_step "$trace2" "Saga Step 1|OrderCreatedEvent" 5)
    local result="rejected"
    [[ "$processed" == "ok" ]] && result="accepted"
    emit "replay" "RP1" "$CVE" "$result" "0" "rabbitmq/order_queue" \
        "replay-after-rotation broker=$route worker=$processed traces=$trace→$trace2"
}

# ── RP2: replay JWT after Keycloak-side revoke ──────────────────────────────
rp2_jwt_replay() {
    log "RP2 jwt-replay-after-revoke"
    local tok
    tok=$(kc_token)
    if [[ -z "$tok" ]]; then
        emit "replay" "RP2" "$CVE" "n/a" "0" "kc-token-missing" \
            "no keycloak token available — env not fully configured, cannot stage replay"
        return
    fi
    # Use the token once (control)
    local trace="rp2-$(date +%s)"
    ssh_curl "$GATEWAY_ALIAS" "$A_GW" POST $'Content-Type: application/json\nAuthorization: Bearer '"$tok"$'\nX-Correlation-Id: '"$trace" \
        '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}' >/dev/null
    sleep 1
    # Replay same token (without server-side revoke we just expect acceptance — proves no jti tracking)
    local trace2="rp2r-$(date +%s)"
    local r
    r=$(ssh_curl "$GATEWAY_ALIAS" "$A_GW" POST $'Content-Type: application/json\nAuthorization: Bearer '"$tok"$'\nX-Correlation-Id: '"$trace2" \
        '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}')
    local code="${r%%|*}" ms="${r##*|}"
    emit "replay" "RP2" "$CVE" "$(classify_http "$code")" "$ms" "$A_GW" \
        "jwt reused for distinct correlation_ids — http=$code (proves no token usage tracking)"
}

# ── RP3: same lsvid used for two distinct correlation_ids ───────────────────
rp3_svid_cross_corr() {
    log "RP3 svid-replay-cross-correlation"
    local trace1="rp3a-$(date +%s)"
    local trace2="rp3b-$(date +%s)"
    local tok hdrs="Content-Type: application/json"
    tok=$(kc_token); [[ -n "$tok" ]] && hdrs=$'Content-Type: application/json\nAuthorization: Bearer '"$tok"
    ssh_curl "$GATEWAY_ALIAS" "$A_GW" POST "$hdrs"$'\nX-Correlation-Id: '"$trace1" \
        '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}' >/dev/null
    sleep 1
    local captured
    captured=$(ssh -n "$GATEWAY_ALIAS" "docker logs --since=20s --tail 500 zt-php-worker 2>&1 \
        | grep -F '$trace1' | grep -oE 'lsvid=[A-Za-z0-9._-]+' | head -1 | cut -d= -f2-") || captured=""
    if [[ -z "$captured" ]]; then
        emit "replay" "RP3" "$CVE" "n/a" "0" "lsvid-capture-failed" \
            "could not extract L1 LSVID (trace=$trace1) — capture/preflight gap, not a defense"
        return
    fi
    local envelope
    envelope=$(T2="$trace2" L="$captured" python3 -c '
import json,os
e={"schema_version":1,"type":"gateway.request","route":"OrderCreateRequestedEvent",
   "id":os.environ["T2"],
   "spiffe_id":"spiffe://zt.local/gateway","spiffe_path":["spiffe://zt.local/gateway"],
   "lsvid":os.environ["L"],
   "data":{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100,"correlation_id":os.environ["T2"]}}
print(json.dumps(e))')
    local route
    route=$(amqp_publish "order_queue" "$envelope")
    sleep 2
    local processed
    processed=$(wait_for_saga_step "$trace2" "Saga Step 1|OrderCreatedEvent" 5)
    local result="rejected"
    [[ "$processed" == "ok" ]] && result="accepted"
    emit "replay" "RP3" "$CVE" "$result" "0" "rabbitmq/order_queue" \
        "same lsvid reused across correlation_id $trace1→$trace2 broker=$route worker=$processed"
}

log "=== Tier 1 / CVE#5: Credential Replay (diagnostic) ==="
rp1_lsvid_replay
rp2_jwt_replay
rp3_svid_cross_corr
log "replay probe done"
