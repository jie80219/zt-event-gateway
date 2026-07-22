#!/usr/bin/env bash
# CVE#7: Privilege Escalation (CVE-2025-32463 — Sudo chroot local-root privesc,
#   realised as cross-service identity impersonation across the trust boundary).
#   (Workload-attestation / ESXi-style CVE-2024-37085 now lives in
#    probe-tier2-attestation.sh, not here.)
#
# Maps to attack categories: id-bypass-inventory, id-bypass-order, id-bypass-wallet
#
# Service-to-service impersonation: an attacker who has compromised one
# downstream container tries to act as another service identity. The
# differentiator is whether the target service verifies the caller's identity
# beyond an HTTP header.
#
# Cases:
#   PE1  id-bypass-inventory  inventory-reduce-as-attacker  (no auth headers)
#   PE2  id-bypass-inventory  inventory-reduce-cross-user   (X-User-key=99)
#   PE3  id-bypass-order      list-order-as-cross-user      (X-User-key=2)
#   PE4  id-bypass-order      modify-order-as-attacker      (PATCH w/o LSVID)
#   PE5  id-bypass-wallet     wallet-read-as-cross-user     (X-User-key=2)
#   PE6  id-bypass-wallet     wallet-deduct-as-attacker     (POST deduction w/o LSVID)
#
# Expected:
#   spiffe-keycloak: all rejected (downstream services require LSVID + mTLS).
#   vault:           mTLS-protected paths rejected (Vault-issued cert required);
#                    LSVID-specific checks n/a (flat X.509, no nested chain).

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

CVE="CVE-2025-32463"

do_call() {
    local case_id=$1 category=$2 alias=$3 url=$4 method=$5 headers=$6 body=$7 desc=$8
    local r code ms
    r=$(ssh_curl "$alias" "$url" "$method" "$headers" "$body")
    code="${r%%|*}"; ms="${r##*|}"
    emit "$category" "$case_id" "$CVE" "$(classify_http "$code")" "$ms" "$url" \
        "$desc http=$code"
}

log "=== Tier 2 / CVE#7: Privilege Escalation ==="

# ── id-bypass-inventory ─────────────────────────────────────────────────────
do_call PE1 id-bypass-inventory "$PROD_ALIAS" \
    "http://127.0.0.1:8083/api/v1/inventory/reduceInventory" POST \
    "Content-Type: application/json" '{"p_key":1,"amount":1}' \
    "no-auth reduceInventory"

do_call PE2 id-bypass-inventory "$PROD_ALIAS" \
    "http://127.0.0.1:8083/api/v1/inventory/reduceInventory" POST \
    $'Content-Type: application/json\nX-User-key: 99' '{"p_key":1,"amount":1}' \
    "cross-user reduceInventory"

# ── id-bypass-order ─────────────────────────────────────────────────────────
do_call PE3 id-bypass-order "$ORDER_ALIAS" \
    "http://127.0.0.1:8082/api/v1/order" GET \
    "X-User-key: 2" "" \
    "cross-user order list"

do_call PE4 id-bypass-order "$ORDER_ALIAS" \
    "http://127.0.0.1:8082/api/v1/order/1" PATCH \
    "Content-Type: application/json" '{"status":"completed"}' \
    "no-LSVID order modification"

# ── id-bypass-wallet ────────────────────────────────────────────────────────
do_call PE5 id-bypass-wallet "$USER_ALIAS" \
    "http://127.0.0.1:8084/api/v1/wallet" GET \
    "X-User-key: 2" "" \
    "cross-user wallet read"

do_call PE6 id-bypass-wallet "$USER_ALIAS" \
    "http://127.0.0.1:8084/api/v1/wallet/deduct" POST \
    $'Content-Type: application/json\nX-User-key: 1' '{"amount":1}' \
    "no-LSVID wallet deduction"

log "privesc probe done"
