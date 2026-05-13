#!/usr/bin/env bash
# ============================================================================
# Security probe driver for `main` (no service mesh, no identity layer).
#
# Threat model on baseline:
#   - Plain HTTP between gateway and downstream services
#   - End-user identity is per-request X-User-key header (no signature)
#   - No SPIFFE, no Keycloak, no mTLS
#
# This is the control case. Expected behaviour:
#   A — gateway envelope validation rejects malformed bodies
#   B — *all* direct downstream calls accepted (no auth check)
#   C — schema-violating envelopes rejected by RequestConsumer; identity-
#       bearing forged envelopes (C4–C6) accepted-by-broker AND processed
#       (no spiffe verification)
#   D — confirms which container ports are exposed without a mesh
#   E — replay accepted three times (no idempotency)
#
# Usage (run from local Mac):
#   bash scripts/security/probe-baseline.sh
#   OUT=artifacts/sec-baseline-$(date +%s) bash scripts/security/probe-baseline.sh
# ============================================================================
set -euo pipefail

STACK="baseline"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

run_category_a
run_category_b
run_category_c

# ── Category D: Container port exposure (no mesh) ──────────────────────────
# Verifies which ports are exposed on each host so we can compare attack
# surface against mesh-bearing stacks. On baseline we expect the service
# port to be reachable on its host but no mesh sidecar ports to exist.
log "Category D: Container port exposure (no mesh)"

d_local_port() {
    local case_id=$1 host_alias=$2 port=$3 path=$4 desc=$5 is_attack=${6:-1}
    local code
    code=$(ssh "$host_alias" "curl -sS -o /dev/null -w '%{http_code}' --max-time 3 http://127.0.0.1:$port$path" 2>/dev/null || echo 000)
    local verdict
    [[ "$code" != "000" ]] && verdict="reachable" || verdict="not-reachable"
    emit "$case_id" "port-exposure" "$host_alias:$port$path" "$code" "$verdict" "$desc" "$is_attack"
}

# Service ports — should be reachable from their own host (sanity, not attacks).
d_local_port D1 "$GATEWAY_ALIAS" 8080 /api/health "gateway service port" 0
d_local_port D2 "$ORDER_ALIAS"   8082 /api/health "order service port"   0
d_local_port D3 "$PROD_ALIAS"    8083 /api/health "production service port" 0
d_local_port D4 "$USER_ALIAS"    8084 /api/health "user service port"  0

# Mesh sidecar ports — expected NOT to exist on baseline. If reachable, that's
# a finding (misconfiguration), so probe-as-attack: REJECT (not-reachable) means
# baseline is correctly free of mesh leftovers.
d_local_port D5 "$GATEWAY_ALIAS" 9990 /            "linkerd admin :9990 (expected absent)"
d_local_port D6 "$GATEWAY_ALIAS" 4140 /            "linkerd outgoing :4140 (expected absent)"
d_local_port D7 "$GATEWAY_ALIAS" 4141 /            "linkerd incoming :4141 (expected absent)"

# Cross-host service-port reachability — same probe shape as linkerd's D4/D5
# but hitting the bare service port instead of a sidecar. ACCEPT here means
# lateral movement is possible without a mesh.
d_cross_service() {
    local case_id=$1 from=$2 to_ip=$3 to_port=$4 desc=$5 is_attack=${6:-1}
    local code
    code=$(ssh "$from" "curl -sS -o /dev/null -w '%{http_code}' --max-time 3 http://$to_ip:$to_port/api/health" 2>/dev/null || echo 000)
    local verdict
    [[ "$code" =~ ^[1-5]..$ ]] && verdict="reachable($code)" || verdict="blocked"
    emit "$case_id" "cross-host" "$from -> $to_ip:$to_port" "$code" "$verdict" "$desc" "$is_attack"
}
d_cross_service D8 "$ORDER_ALIAS" 10.1.1.207 8083 "from order-host probe production-host service port"
d_cross_service D9 "$PROD_ALIAS"  10.1.1.214 8084 "from prod-host probe user-host service port"

run_category_e

# Category K + L (Paper-5 attack toolkit: tcpreplay + fakelib)
source "$SCRIPT_DIR/probe-paper5-attacks.sh"
run_category_k
run_category_l

print_summary
