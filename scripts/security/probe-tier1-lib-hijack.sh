#!/usr/bin/env bash
# CVE#6: Library Hijack / Supply Chain (CVE-2024-3094 — XZ Utils liblzma
#   backdoor: a malicious shared object smuggled into a trusted library load
#   path, realised as an LD_PRELOAD .so injected into the identity sidecars).
#
# Maps to attack category: lib-hijack
#
# Cases (target containers vary per stack):
#   LH1  lib-hijack  fakelib-evil-so-in-gateway-php-worker
#   LH2  lib-hijack  fakelib-evil-so-in-spiffe-watcher / vault-agent-worker
#   LH3  lib-hijack  fakelib-evil-so-in-keycloak-watcher (n/a where absent)
#
# Verdict semantics:
#   blocked   = docker hardening prevented inject (read_only, cap_drop, etc.)
#               — a REAL defense of the target container profile.
#   accepted  = inject succeeded AND constructor ran (hijacked marker present)
#   rejected  = inject succeeded but marker absent (constructor didn't run)
#   n/a       = target container absent on this stack, OR the harness itself
#               could not stage the attack (no gcc to build evil.so, host build
#               failed) — a preflight gap, NOT a defense; excluded from ratio.

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"
# shellcheck source=lib/fakelib.sh
source "$HERE/lib/fakelib.sh"

CVE="CVE-2024-3094"

attack_target() {
    local case_id=$1 host_alias=$2 container=$3 desc=$4
    # Probe container exists
    local exists
    exists=$(ssh -n "$host_alias" "docker ps --format '{{.Names}}' | grep -Fx '$container'" 2>/dev/null || true)
    if [[ -z "$exists" ]]; then
        emit "lib-hijack" "$case_id" "$CVE" "n/a" "0" "$container@$host_alias" \
            "$desc — container not present on $STACK"
        return
    fi

    # Build evil.so on host
    local build
    build=$(make_fake_so_on_host "$host_alias" "/tmp/evil-${case_id}.so")
    if [[ "$build" == "MISSING_GCC" ]]; then
        emit "lib-hijack" "$case_id" "$CVE" "n/a" "0" "$container@$host_alias" \
            "$desc — gcc missing on host (cannot build evil.so; preflight gap, not a defense)"
        return
    fi
    if [[ "$build" != "ok" ]]; then
        emit "lib-hijack" "$case_id" "$CVE" "n/a" "0" "$container@$host_alias" \
            "$desc — host build failed: $build (harness could not stage attack, not a defense)"
        return
    fi

    # Try to inject
    local inject
    inject=$(inject_into_container "$host_alias" "$container" "/tmp/evil-${case_id}.so")
    if [[ "$inject" != "ok" ]]; then
        emit "lib-hijack" "$case_id" "$CVE" "blocked" "0" "$container" \
            "$desc — docker cp blocked: $inject (hardening worked)"
        ssh -n "$host_alias" "rm -f /tmp/evil-${case_id}.so" >/dev/null 2>&1 || true
        return
    fi

    # Force constructor to run: exec /bin/true with LD_PRELOAD
    local exec_out
    exec_out=$(ssh -n "$host_alias" "docker exec -e LD_PRELOAD=/tmp/evil/evil.so '$container' /bin/true 2>&1" || true)
    sleep 1

    # Check marker
    local marker
    marker=$(hijacked_marker "$host_alias" "$container")
    local result detail
    if [[ "$marker" == "yes" ]]; then
        result="accepted"
        detail="$desc — INJECT SUCCESS, marker /tmp/hijacked present"
    else
        if [[ "$exec_out" =~ exec|denied|permission|read.only ]]; then
            result="blocked"
            detail="$desc — exec blocked: $(echo "$exec_out" | tr '\n' ' ' | head -c 120)"
        else
            result="rejected"
            detail="$desc — inject ok but constructor did not run (marker absent)"
        fi
    fi
    emit "lib-hijack" "$case_id" "$CVE" "$result" "0" "$container" "$detail"

    cleanup_fakelib "$host_alias" "$container"
    ssh -n "$host_alias" "rm -f /tmp/evil-${case_id}.so" >/dev/null 2>&1 || true
}

log "=== Tier 1 / CVE#6: Library Hijack (CVE-2024-3094 analogue) ==="

case "$STACK" in
    spiffe-keycloak)
        attack_target LH1 "$GATEWAY_ALIAS" "zt-php-worker"        "PHP worker container"
        attack_target LH2 "$GATEWAY_ALIAS" "zt-spiffe-watcher"    "SPIFFE watcher (read_only+tmpfs noexec+cap_drop ALL)"
        attack_target LH3 "$GATEWAY_ALIAS" "zt-keycloak-watcher"  "Keycloak token watcher"
        ;;
    vault)
        attack_target LH1 "$GATEWAY_ALIAS" "zt-php-worker"          "PHP worker container"
        attack_target LH2 "$GATEWAY_ALIAS" "zt-vault-agent-worker"  "Vault agent (identity sidecar)"
        attack_target LH3 "$GATEWAY_ALIAS" "zt-keycloak-watcher"    "Keycloak token watcher"
        ;;
esac

log "lib-hijack probe done"
