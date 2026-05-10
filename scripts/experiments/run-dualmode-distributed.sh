#!/usr/bin/env bash
# ============================================================================
# Distributed dual-mode perf experiment runner — driver on a separate LAN host.
#
#   Driver host       : zt-order-lan         (load-driver in docker python:3.11-slim)
#   Gateway/Worker    : zt-gateway-lan       (zt-gateway, zt-php-worker, zt-rabbitmq, zt-keycloak-watcher)
#   Downstream svc    : zt-prod-lan, zt-order-lan, zt-user-lan
#   Target URL        : http://10.1.1.209:8080/api/orders
#
# Round 1 = warm (current state)
# Round 2 = cold (after restarting zt-gateway + zt-php-worker on gateway-lan)
#
# Outputs (on local Mac, under $OUT/raw/):
#   load_<round>_<scale>.csv     ← from driver host
#   worker_<round>_<scale>.log   ← gateway+worker [perf-*] lines
#   mtls_<round>_<scale>.err     ← mTLS probe output
# ============================================================================
set -euo pipefail

OUT="${1:?usage: $0 <out-dir>}"
SCALES=(${SCALES:-5000 10000 20000})
# Drain budget mirrors feat/Linkerd1's run-perf-experiment.sh so cross-branch
# Incomplete-transaction-rate uses the same window: N/25+120 (320/520/920s for
# 5k/10k/20k), poll exits early once all queues idle for PERF_DRAIN_IDLE (60s).
DRAIN_SEC_OVERRIDE="${PERF_DRAIN_SEC:-${DRAIN_SEC:-}}"
DRAIN_SEC_PER_REQ="${PERF_DRAIN_SEC_PER_REQ:-25}"
DRAIN_SEC_BASE="${PERF_DRAIN_SEC_BASE:-120}"
DRAIN_IDLE_TARGET="${PERF_DRAIN_IDLE:-60}"
DRAIN_POLL="${PERF_DRAIN_POLL:-10}"
MTLS_PROBE_COUNT="${MTLS_PROBE_COUNT:-200}"
ROUNDS=(${ROUNDS:-warm cold})

compute_drain_sec() {
    local n=$1
    if [[ -n "$DRAIN_SEC_OVERRIDE" ]]; then
        printf '%s' "$DRAIN_SEC_OVERRIDE"
    else
        printf '%s' "$(( n / DRAIN_SEC_PER_REQ + DRAIN_SEC_BASE ))"
    fi
}

GATEWAY_HOST="${GATEWAY_HOST:-zt-gateway}"
DRIVER_HOST="${DRIVER_HOST:-zt-order}"
GATEWAY_URL="http://10.1.1.209:8080/api/orders"
HEALTH_URL="http://10.1.1.209:8080/api/health"

PROJECT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
mkdir -p "$OUT/raw"

log() { printf '[exp] %s %s\n' "$(date +%T)" "$*" >&2; }

# --- Helpers running on remote hosts ---------------------------------------

mint_token() {
    # Keycloak ingress (KEYCLOAK_INGRESS_ENABLED=1) requires a user-level
    # Bearer JWT. Mint via ROPC. Must hit Keycloak through its docker-internal
    # URL (http://keycloak:8080/...) so the issued `iss` matches what gateway's
    # JwtValidator expects (KEYCLOAK_ISSUER=http://keycloak:8080/realms/zt).
    # We exec the curl inside zt-gateway (which is on anser_project_network).
    ssh "$GATEWAY_HOST" "docker exec zt-gateway curl -fsS -m 5 -X POST \
        -H 'Content-Type: application/x-www-form-urlencoded' \
        --data-urlencode 'grant_type=password' \
        --data-urlencode 'client_id=client-app' \
        --data-urlencode 'client_secret=client-app-dev-secret' \
        --data-urlencode 'username=testuser' \
        --data-urlencode 'password=testpass' \
        http://keycloak:8080/realms/zt/protocol/openid-connect/token" \
        | python3 -c "import json,sys; print(json.load(sys.stdin).get('access_token',''))"
}

purge_queues() {
    ssh "$GATEWAY_HOST" '
        for q in order_queue OrderCreateRequestedEvent OrderCreatedEvent \
                 InventoryDeductedEvent PaymentProcessedEvent OrderSagaCompletedEvent \
                 RollbackInventoryEvent RollbackOrderEvent; do
            docker exec zt-rabbitmq rabbitmqctl --quiet purge_queue "$q" 2>/dev/null || true
        done
    '
}

wait_health() {
    # Mac driver isn't on the 10.1.1.x LAN; ssh-wrap the probe through
    # GATEWAY_HOST (zt-gateway-lan) which has localhost access to :8080.
    for _ in $(seq 1 60); do
        if ssh "$GATEWAY_HOST" "curl -fsS -m 3 http://127.0.0.1:8080/api/health" >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
    done
    log "gateway health check failed"
    return 1
}

cold_restart_gateway() {
    log "cold round: restarting zt-gateway and zt-php-worker"
    ssh "$GATEWAY_HOST" 'docker restart zt-gateway zt-php-worker' >/dev/null
    sleep 15
    wait_health
}

# --- Per-scale runner ------------------------------------------------------

run_scale() {
    local round="$1" n="$2"
    local csv_local="$OUT/raw/load_${round}_${n}.csv"
    local wlog_local="$OUT/raw/worker_${round}_${n}.log"
    local mlog_local="$OUT/raw/mtls_${round}_${n}.err"

    local token; token="$(mint_token)"
    if [[ -n "$token" ]]; then
        log "round=$round scale=$n — token minted (len=${#token})"
    fi

    log "round=$round scale=$n — purging queues"
    purge_queues

    # Mark log offsets on gateway host
    ssh "$GATEWAY_HOST" "docker logs zt-gateway 2>&1 | wc -l > /tmp/gw_off.txt; docker logs zt-php-worker 2>&1 | wc -l > /tmp/wk_off.txt"

    log "round=$round scale=$n — running driver on $DRIVER_HOST (concurrency=$n)"
    # Driver runs inside a python container on the driver host, with --network host
    # so it can reach 10.1.1.209:8080. Output CSV is written to /root/load.csv on the
    # driver host then scp'd back.
    ssh "$DRIVER_HOST" "
        ulimit -n 65535 2>/dev/null || true
        docker run --rm --network host \\
            --ulimit nofile=65535:65535 \\
            --security-opt apparmor=unconfined \\
            --security-opt seccomp=unconfined \\
            -v /root/load-driver.py:/load.py:ro \\
            -v /root:/work \\
            python:3.11-slim sh -c '
                pip install --quiet aiohttp >/dev/null 2>&1 || pip install aiohttp >/dev/null
                python3 /load.py \\
                    --url \"$GATEWAY_URL\" \\
                    --count $n --concurrency $n \\
                    --tag \"${round}-${n}\" \\
                    --token \"$token\" \\
                    --round \"$round\" \\
                    --out /work/load.csv
            ' 2>&1 | tail -5
    "
    scp -q "$DRIVER_HOST:/root/load.csv" "$csv_local"
    log "round=$round scale=$n — load.csv pulled ($(wc -l < "$csv_local") lines)"

    local drain_sec; drain_sec=$(compute_drain_sec "$n")
    log "round=$round scale=$n — draining (max ${drain_sec}s, poll all queues)"
    local drain_elapsed=0 drain_idle=0 depth
    while (( drain_elapsed < drain_sec )); do
        sleep "$DRAIN_POLL"
        drain_elapsed=$((drain_elapsed + DRAIN_POLL))
        # Sum across ALL queues — sagas pass through order_queue + the 7 event
        # queues; watching only order_queue under-reports in-flight work.
        depth=$(ssh "$GATEWAY_HOST" "docker exec zt-rabbitmq rabbitmqctl --quiet list_queues name messages 2>/dev/null | awk '\$2 ~ /^[0-9]+\$/ {sum+=\$2} END{print sum+0}'") || depth=0
        depth="${depth:-0}"
        log "  drain t=${drain_elapsed}s all_queues=${depth}"
        if [[ "$depth" == "0" ]]; then
            drain_idle=$((drain_idle + DRAIN_POLL))
            if (( drain_idle >= DRAIN_IDLE_TARGET )); then
                log "  all queues idle ${drain_idle}s — draining complete"
                break
            fi
        else
            drain_idle=0
        fi
    done

    # Capture only this scale's perf lines from gateway+worker logs.
    log "round=$round scale=$n — pulling worker/gateway perf logs"
    ssh "$GATEWAY_HOST" '
        goff=$(cat /tmp/gw_off.txt)
        woff=$(cat /tmp/wk_off.txt)
        {
            docker logs zt-gateway 2>&1 | tail -n +$((goff+1)) \
                | grep -E "\[perf-request-in\]"
            docker logs zt-php-worker 2>&1 | tail -n +$((woff+1)) \
                | grep -E "\[perf-saga-step1\]|\[perf-saga-complete\]|商品資訊查詢失敗|RollbackOrderEvent|❌|Saga 完成|RollbackSaga"
        }
    ' > "$wlog_local"
    log "round=$round scale=$n — worker.log lines=$(wc -l < "$wlog_local")"

    # mTLS probe inside the worker container.
    log "round=$round scale=$n — running mTLS probe (count=$MTLS_PROBE_COUNT)"
    scp -q "$PROJECT_DIR/scripts/experiments/mtls-probe.sh" \
        "$GATEWAY_HOST:/tmp/mtls-probe.sh"
    ssh "$GATEWAY_HOST" "
        docker cp /tmp/mtls-probe.sh zt-php-worker:/tmp/mtls-probe.sh
        docker exec zt-php-worker bash /tmp/mtls-probe.sh $MTLS_PROBE_COUNT
    " > "$mlog_local" 2>&1 || true
    log "round=$round scale=$n — mTLS lines=$(grep -c perf-mtls "$mlog_local" || true)"
}

# --- Main --------------------------------------------------------------

wait_health

for round in "${ROUNDS[@]}"; do
    for n in "${SCALES[@]}"; do
        if [[ "$round" == "cold" ]]; then
            cold_restart_gateway
        fi
        run_scale "$round" "$n"
    done
done

log "all rounds done. raw outputs in $OUT/raw/"
