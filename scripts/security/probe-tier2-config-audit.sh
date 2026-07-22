#!/usr/bin/env bash
# CVE#8: Container isolation / runtime misconfiguration
#   (CVE-2024-21626 — runc "Leaky Vessels" fd/working-dir container escape;
#    audited here as the container-hardening posture that would contain such an
#    escape: cap_drop, read_only, no-new-privileges, tmpfs noexec).
# CVE#9: Control-plane / management-port exposure
#   (CVE-2024-57727 / CVE-2024-57728 — SimpleHelp path-traversal + arbitrary
#    upload; audited here as sidecar/control-plane and RabbitMQ mgmt exposure).
#
# Maps to attack categories: config-audit (CVE#8) + port-exposure (CVE#9)
#
# This probe is non-destructive — it audits docker-compose / docker inspect
# state on each stack and records security-relevant attributes:
#   - cap_drop / cap_add
#   - read_only filesystem
#   - tmpfs noexec / nosuid
#   - security_opt (no-new-privileges, apparmor, seccomp)
#   - exposed ports beyond the documented surface
#
# Cases:
#   CA1  config-audit   cap-drop-coverage     ratio of containers with cap_drop:[ALL]
#   CA2  config-audit   read-only-fs          ratio of containers with read_only:true
#   CA3  config-audit   no-new-privileges     ratio with security_opt no-new-privileges
#   CA4  config-audit   tmpfs-noexec          ratio of tmpfs mounts with noexec
#   PX1  port-exposure  sidecar-control-port  8081 (SPIRE) / 8200 (Vault) if exposed
#   PX2  port-exposure  rabbitmq-mgmt         15672 reachable from outside Docker network
#
# Verdict semantics:
#   accepted = misconfiguration present (attacker would benefit)
#   rejected = hardening present (defense applied)
#   n/a      = container/port doesn't exist on this stack

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

CVE_CONFIG="CVE-2024-21626"   # container-isolation / runc escape → hardening audit
CVE_PORT="CVE-2024-57727"     # control-plane / mgmt-port exposure (+ CVE-2024-57728)

# Containers to audit per stack
case "$STACK" in
    spiffe-keycloak)
        AUDIT_CONTAINERS=( zt-gateway zt-php-worker zt-rabbitmq zt-spire-server zt-spire-agent zt-spiffe-watcher zt-keycloak-watcher )
        SIDECAR_HOST="$GATEWAY_ALIAS"
        SIDECAR_PORTS=( 8081 )  # SPIRE server admin (if exposed)
        ;;
    vault)
        # Real feat/vault-pki (paper's "Vault PKI" baseline) container names.
        # zt-vault-agent-worker is the identity/secret-management analogue of
        # spiffe-watcher and MUST carry the identical hardening profile so
        # container-config is a controlled variable (see the hardening block
        # on vault-agent-worker in feat/vault-pki docker-compose.yml, and the
        # capability-class notes in compare-stacks.py). zt-keycloak-watcher is
        # listed for parity with test runs that add a KC ingress watcher; if
        # absent it is simply skipped as 'missing'.
        AUDIT_CONTAINERS=( zt-gateway zt-php-worker zt-rabbitmq zt-vault \
                           zt-vault-agent-worker zt-keycloak-watcher )
        SIDECAR_HOST="$GATEWAY_ALIAS"
        SIDECAR_PORTS=( 8200 )  # Vault HTTP API (if exposed)
        ;;
esac

# Inspect helper: returns "1" if container has the requested security_opt /
# cap_drop / read_only attribute, else "0". Containers not present return "".
inspect_attr() {
    local host=$1 container=$2 jq_expr=$3
    ssh -n "$host" "docker inspect '$container' 2>/dev/null | jq -r '.[0] | $jq_expr // \"missing\"'" 2>/dev/null
}

count_present() {
    # echo "<met>/<total>"; ignores 'missing'
    local met=$1 total=$2
    printf '%d/%d' "$met" "$total"
}

log "=== Tier 2 / CVE#8+#9: Config Audit + Port Exposure ==="

# CA1: cap_drop=[ALL]
met=0; tot=0
for c in "${AUDIT_CONTAINERS[@]}"; do
    v=$(inspect_attr "$GATEWAY_ALIAS" "$c" '.HostConfig.CapDrop | select(.) | map(ascii_downcase) | contains(["all"])')
    [[ "$v" == "missing" ]] && continue
    tot=$((tot+1))
    [[ "$v" == "true" ]] && met=$((met+1))
done
ratio=$(count_present "$met" "$tot")
result="accepted"; [[ "$tot" -gt 0 && "$met" -eq "$tot" ]] && result="rejected"
emit "config-audit" "CA1" "$CVE_CONFIG" "$result" "0" "$STACK" "cap_drop=[ALL] coverage=$ratio"

# CA2: read_only filesystem
met=0; tot=0
for c in "${AUDIT_CONTAINERS[@]}"; do
    v=$(inspect_attr "$GATEWAY_ALIAS" "$c" '.HostConfig.ReadonlyRootfs')
    [[ "$v" == "missing" ]] && continue
    tot=$((tot+1))
    [[ "$v" == "true" ]] && met=$((met+1))
done
ratio=$(count_present "$met" "$tot")
# Threshold: ≥1 hardened critical container (spiffe-watcher on spiffe-keycloak,
# vault-agent-worker on vault — the controlled identical hardening profile)
result="accepted"
[[ "$met" -ge 1 ]] && result="rejected"
emit "config-audit" "CA2" "$CVE_CONFIG" "$result" "0" "$STACK" "read_only coverage=$ratio (>=1 critical hardened ⇒ rejected)"

# CA3: no-new-privileges
met=0; tot=0
for c in "${AUDIT_CONTAINERS[@]}"; do
    v=$(inspect_attr "$GATEWAY_ALIAS" "$c" '.HostConfig.SecurityOpt | select(.) | map(ascii_downcase) | any(. == "no-new-privileges:true")')
    [[ "$v" == "missing" ]] && continue
    tot=$((tot+1))
    [[ "$v" == "true" ]] && met=$((met+1))
done
ratio=$(count_present "$met" "$tot")
result="accepted"
[[ "$met" -ge 1 ]] && result="rejected"
emit "config-audit" "CA3" "$CVE_CONFIG" "$result" "0" "$STACK" "no-new-privileges coverage=$ratio"

# CA4: tmpfs noexec — check that at least one container has /tmp tmpfs with noexec
met=0; tot=0
for c in "${AUDIT_CONTAINERS[@]}"; do
    v=$(inspect_attr "$GATEWAY_ALIAS" "$c" '.HostConfig.Tmpfs | select(.) | to_entries | any(.value | tostring | test("noexec"))')
    [[ "$v" == "missing" ]] && continue
    tot=$((tot+1))
    [[ "$v" == "true" ]] && met=$((met+1))
done
ratio=$(count_present "$met" "$tot")
result="accepted"
[[ "$met" -ge 1 ]] && result="rejected"
emit "config-audit" "CA4" "$CVE_CONFIG" "$result" "0" "$STACK" "tmpfs noexec coverage=$ratio"

# ── PX1: sidecar/control-plane ports reachable from outside container net ──
for p in "${SIDECAR_PORTS[@]}"; do
    r=$(ssh -n "$SIDECAR_HOST" "curl -sS -o /dev/null -w '%{http_code}' --max-time 3 http://127.0.0.1:$p/ 2>/dev/null" || echo 000)
    case "$r" in
        000) emit "port-exposure" "PX1-$p" "$CVE_PORT" "rejected" "0" "$SIDECAR_HOST:$p" "port closed/filtered http=$r" ;;
        *)   emit "port-exposure" "PX1-$p" "$CVE_PORT" "accepted" "0" "$SIDECAR_HOST:$p" "port reachable http=$r — control-plane exposed" ;;
    esac
done

# ── PX2: rabbitmq mgmt UI ──────────────────────────────────────────────────
r=$(ssh -n "$GATEWAY_ALIAS" "curl -sS -o /dev/null -w '%{http_code}' --max-time 3 -u guest:guest http://127.0.0.1:15672/api/overview 2>/dev/null" || echo 000)
case "$r" in
    2*) emit "port-exposure" "PX2" "$CVE_PORT" "accepted" "0" "rabbitmq:15672" "default-creds-or-anon http=$r — mgmt UI exposed" ;;
    401|403) emit "port-exposure" "PX2" "$CVE_PORT" "rejected" "0" "rabbitmq:15672" "auth required http=$r" ;;
    *)  emit "port-exposure" "PX2" "$CVE_PORT" "rejected" "0" "rabbitmq:15672" "not reachable http=$r" ;;
esac

log "config-audit probe done"
