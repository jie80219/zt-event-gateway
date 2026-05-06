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
DRAIN_SEC="${DRAIN_SEC:-90}"
MTLS_PROBE_COUNT="${MTLS_PROBE_COUNT:-200}"
ROUNDS=(${ROUNDS:-warm cold})

GATEWAY_HOST="zt-gateway-lan"
DRIVER_HOST="zt-order-lan"
GATEWAY_URL="http://10.1.1.209:8080/api/orders"
HEALTH_URL="http://10.1.1.209:8080/api/health"

PROJECT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
mkdir -p "$OUT/raw"

log() { printf '[exp] %s %s\n' "$(date +%T)" "$*" >&2; }

# --- Helpers running on remote hosts ---------------------------------------

mint_token() {
    # Keycloak ingress is disabled in the current zt-gateway-lan deployment
    # (no zt-keycloak-watcher container). Gateway accepts unauthenticated requests.
    # Returning empty string makes load-driver skip the Authorization header.
    echo ""
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
    for _ in $(seq 1 60); do
        if curl -fsS -m 3 "$HEALTH_URL" >/dev/null 2>&1; then return 0; fi
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

    log "round=$round scale=$n — draining ${DRAIN_SEC}s"
    sleep "$DRAIN_SEC"

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
    if [[ "$round" == "cold" ]]; then
        cold_restart_gateway
    fi
    for n in "${SCALES[@]}"; do
        run_scale "$round" "$n"
    done
done

log "all rounds done. raw outputs in $OUT/raw/"
