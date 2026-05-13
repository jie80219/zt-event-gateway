#!/usr/bin/env bash
# ============================================================================
# Security probe driver for SPIFFE-only stack (feat/spiffe-keycloak with
# KEYCLOAK_ENABLED=0, or feat/spiffe).
#
# Threat model on this stack:
#   - SPIRE Server + Agent issues X.509-SVID / JWT-SVID per service
#   - LSVID nested chain on every AMQP envelope (gateway L0 → worker L1 → L2)
#   - mTLS between gateway/worker and downstream services
#   - NO end-user JWT layer (Keycloak disabled)
#
# Categories run:
#   A. HTTP ingress validation        (lib.sh)
#   B. Downstream identity bypass     (lib.sh — should be REJECTED at mTLS/LSVID)
#   C. AMQP envelope injection        (lib.sh — C4-C6 rejected by SPIFFE/LSVID
#                                      verification in RequestConsumer)
#   D. SPIRE port exposure            (this file — SPIRE Server gRPC + service
#                                      ports + cross-host probes)
#   E. Replay                         (lib.sh)
#   F. LSVID identity-layer forgery   (this file — forged X-LSVID on downstream)
#
# Output: artifacts/sec-spiffe-<ts>/results.jsonl (compatible with
# compare-stacks.py for SPIFFE-vs-Linkerd matrix).
#
# Usage (run from local Mac, all SSH via -lan aliases):
#   bash scripts/security/probe-spiffe.sh
#   OUT=artifacts/sec-spiffe-$(date +%s) bash scripts/security/probe-spiffe.sh
# ============================================================================
set -euo pipefail

STACK="spiffe"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

run_category_a
run_category_b
run_category_c

# ── Category D: SPIRE port exposure ────────────────────────────────────────
# zt-gateway publishes :8081 (SPIRE Server gRPC, for off-host agents). It is
# required for the trust plane to work, but should not be world-reachable.
# We probe each host's loopback (sanity) and across hosts (lateral movement).
log "Category D: SPIRE port exposure"

d_check() {
    local case_id=$1 host_alias=$2 port=$3 path=$4 desc=$5 is_attack=${6:-1}
    local code
    code=$(ssh "$host_alias" "curl -sS -o /dev/null -w '%{http_code}' --max-time 3 http://127.0.0.1:${port}${path}" 2>/dev/null || echo 000)
    local verdict
    [[ "$code" != "000" ]] && verdict="reachable" || verdict="not-reachable"
    emit "$case_id" "port-exposure" "$host_alias:${port}${path}" "$code" "$verdict" "$desc" "$is_attack"
}

# Sanity / control: service ports are reachable from their own host.
d_check D1 "$GATEWAY_ALIAS" 8080 /api/health "gateway service port — sanity" 0
d_check D2 "$ORDER_ALIAS"   8082 /api/health "order service port — sanity"   0
d_check D3 "$PROD_ALIAS"    8083 /api/health "production service port — sanity" 0
d_check D4 "$USER_ALIAS"    8084 /api/health "user service port — sanity"   0

# Trust-plane control plane — SHOULD only listen on gateway host. From
# gateway-localhost it answers; cross-host probes below check lateral leak.
d_check D5 "$GATEWAY_ALIAS" 8081 /            "SPIRE Server gRPC :8081 (gateway-local — expected reachable)" 0

# Cross-host lateral probes: an attacker on order/prod host pokes
# gateway's SPIRE Server. If reachable, lateral SVID minting may be possible
# from a compromised downstream service host (still gated by node-attestor).
d_cross() {
    local case_id=$1 from=$2 to_ip=$3 to_port=$4 path=$5 desc=$6 is_attack=${7:-1}
    local code
    code=$(ssh "$from" "curl -sS -o /dev/null -w '%{http_code}' --max-time 3 http://${to_ip}:${to_port}${path}" 2>/dev/null || echo 000)
    local verdict
    [[ "$code" =~ ^[1-5]..$ ]] && verdict="reachable($code)" || verdict="blocked"
    emit "$case_id" "cross-host" "$from -> ${to_ip}:${to_port}${path}" "$code" "$verdict" "$desc" "$is_attack"
}

# Gateway host LAN IP (from ~/.ssh/config zt-gateway-lan).
GATEWAY_IP="${GATEWAY_IP:-10.1.1.209}"
d_cross D6 "$ORDER_ALIAS" "$GATEWAY_IP" 8081 "/" "from order-host probe gateway SPIRE Server :8081"
d_cross D7 "$PROD_ALIAS"  "$GATEWAY_IP" 8081 "/" "from prod-host probe gateway SPIRE Server :8081"

# Direct downstream service port from another service host. On a fully
# zero-trust stack these should still be reachable at L4 (mTLS terminates
# at L7 inside the service); the service itself must reject without client cert.
d_cross_service() {
    local case_id=$1 from=$2 to_ip=$3 to_port=$4 desc=$5 is_attack=${6:-1}
    local code
    code=$(ssh "$from" "curl -sS -o /dev/null -w '%{http_code}' --max-time 3 http://${to_ip}:${to_port}/api/health" 2>/dev/null || echo 000)
    local verdict
    [[ "$code" =~ ^[1-5]..$ ]] && verdict="reachable($code)" || verdict="blocked"
    emit "$case_id" "cross-host" "$from -> ${to_ip}:${to_port}" "$code" "$verdict" "$desc" "$is_attack"
}
ORDER_IP="${ORDER_IP:-10.1.1.210}"
PROD_IP="${PROD_IP:-10.1.1.207}"
USER_IP="${USER_IP:-10.1.1.214}"
d_cross_service D8 "$ORDER_ALIAS" "$PROD_IP" 8083 "from order-host probe production-host service port"
d_cross_service D9 "$PROD_ALIAS"  "$USER_IP" 8084 "from prod-host probe user-host service port"

run_category_e

# ── Category F: LSVID identity-layer forgery ───────────────────────────────
# These cases only exist on identity-aware stacks. The worker / downstream
# services reject envelopes / requests whose LSVID chain doesn't validate
# against the SPIRE trust bundle.
log "Category F: LSVID identity-layer forgery"

# Forged LSVID header on downstream call. Mirrors B's "direct call without
# identity", but explicitly populates X-LSVID with a string that has the
# JWT-like shape but no valid signature against the trust bundle.
FORGED_LSVID="eyJhbGciOiJFUzI1NiJ9.eyJpc3MiOiJzcGlmZmU6Ly9hdHRhY2tlci5leGFtcGxlL3d3dyIsInN1YiI6InNwaWZmZTovL2F0dGFja2VyLmV4YW1wbGUvd3d3IiwiYXVkIjoic3BpZmZlOi8venQubG9jYWwvb3JkZXItc2VydmljZSIsImV4cCI6OTk5OTk5OTk5OX0.AAAA"

f_lsvid_get() {
    local case_id=$1 host_alias=$2 port=$3 path=$4 desc=$5 is_attack=${6:-1}
    local code
    code=$(ssh "$host_alias" "curl -sS -o /dev/null -w '%{http_code}' --max-time 5 \
        -H 'X-LSVID: $FORGED_LSVID' \
        -H 'X-User-key: 1' \
        http://127.0.0.1:${port}${path}" 2>/dev/null || echo 000)
    emit "$case_id" "lsvid-forgery" "$host_alias:${port}${path}" "$code" "$(_verdict_http "$code")" "$desc" "$is_attack"
}

f_lsvid_get F1 "$ORDER_ALIAS" 8082 /api/v1/order   "forged X-LSVID — order service must reject"
f_lsvid_get F2 "$USER_ALIAS"  8084 /api/v1/wallet  "forged X-LSVID — user service must reject"
f_lsvid_get F3 "$PROD_ALIAS"  8083 /api/health     "forged X-LSVID hits health (likely 200 since health is unauth)" 0

# Category K + L (Paper-5 attack toolkit: tcpreplay + fakelib)
source "$SCRIPT_DIR/probe-paper5-attacks.sh"
run_category_k
run_category_l

print_summary
