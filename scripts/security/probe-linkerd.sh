#!/usr/bin/env bash
# ============================================================================
# Security probe driver for feat/Linkerd1 (Linkerd 1.x mesh, no identity).
#
# Threat model on this branch:
#   - Linkerd does L7 routing/observability (no user auth)
#   - Phase 2: mTLS off between sidecars
#   - End-user identity is per-request X-User-key header (no signature)
#
# Categories run:
#   A. HTTP ingress validation     (lib.sh)
#   B. Downstream identity bypass  (lib.sh)
#   C. AMQP envelope injection     (lib.sh)
#   D. Linkerd port exposure       (this file)
#   E. Replay                      (lib.sh)
#
# Usage (run from local Mac, all SSH via -lan aliases):
#   bash scripts/security/probe-linkerd.sh
#   OUT=artifacts/sec-linkerd-$(date +%s) bash scripts/security/probe-linkerd.sh
# ============================================================================
set -euo pipefail

STACK="linkerd"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

run_category_a
run_category_b
run_category_c

# ── Category D: Linkerd sidecar port exposure ──────────────────────────────
# 4140 outgoing proxy, 4141 incoming proxy, 9990 admin — they expose service
# discovery details and can be misused if reachable from untrusted networks.
log "Category D: Linkerd sidecar port exposure (host LAN reachability)"

d_check() {
    local case_id=$1 host_alias=$2 port=$3 desc=$4 is_attack=${5:-1}
    local code
    code=$(ssh "$host_alias" "curl -sS -o /dev/null -w '%{http_code}' --max-time 3 http://127.0.0.1:$port/" 2>/dev/null || echo 000)
    local verdict
    [[ "$code" != "000" ]] && verdict="reachable" || verdict="not-reachable"
    emit "$case_id" "port-exposure" "$host_alias:$port" "$code" "$verdict" "$desc" "$is_attack"
}

d_check D1 "$GATEWAY_ALIAS" 9990 "linkerd admin :9990 from gateway localhost — sanity" 0
d_check D2 "$GATEWAY_ALIAS" 4140 "linkerd outgoing :4140 — should require Host header for valid response"
d_check D3 "$GATEWAY_ALIAS" 4141 "linkerd incoming :4141 — gateway side normally has no incoming proxy"

d_cross() {
    local case_id=$1 from=$2 to_ip=$3 to_port=$4 desc=$5 is_attack=${6:-1}
    local code
    code=$(ssh "$from" "curl -sS -o /dev/null -w '%{http_code}' --max-time 3 -H 'Host: ProductionService' http://$to_ip:$to_port/api/health" 2>/dev/null || echo 000)
    local verdict
    [[ "$code" =~ ^[1-5]..$ ]] && verdict="reachable($code)" || verdict="blocked"
    emit "$case_id" "cross-host" "$from -> $to_ip:$to_port" "$code" "$verdict" "$desc" "$is_attack"
}
d_cross D4 "$ORDER_ALIAS" 10.1.1.207 4141 "from order-host probe production-host linkerd incoming with Host:ProductionService"
d_cross D5 "$PROD_ALIAS"  10.1.1.214 4141 "from prod-host probe user-host linkerd incoming with Host:UserService"

run_category_e

print_summary
