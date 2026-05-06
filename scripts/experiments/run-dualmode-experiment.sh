#!/usr/bin/env bash
# ============================================================================
# Dual-mode (SPIFFE+Keycloak) perf experiment runner — 5000/10000/20000 × 2 rounds
#
# Round 1 = warm (current state)
# Round 2 = cold (after `docker compose restart gateway`)
#
# Outputs:
#   $OUT/raw/load_<round>_<scale>.csv
#   $OUT/raw/worker_<round>_<scale>.log
#   $OUT/raw/mtls_<round>_<scale>.err   (mTLS probe lines)
# ============================================================================
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$PROJECT_DIR"

OUT="${OUT:?must set OUT=artifacts/<dir>}"
SCALES=(${SCALES:-5000 10000 20000})
CONCURRENCY="${CONCURRENCY:-50}"
DRAIN_SEC="${DRAIN_SEC:-60}"
MTLS_PROBE_COUNT="${MTLS_PROBE_COUNT:-200}"
ROUND="${ROUND:-warm}"
GATEWAY_URL="http://127.0.0.1:8080/api/orders"
HEALTH_URL="http://127.0.0.1:8080/api/health"

log() { printf '[exp] %s %s\n' "$(date +%T)" "$*" >&2; }

mint_token() {
    docker exec zt-keycloak-watcher curl -sS -m 5 -X POST \
        -d "client_id=client-app&client_secret=client-app-dev-secret&grant_type=client_credentials" \
        http://keycloak:8080/realms/zt/protocol/openid-connect/token \
        | python3 -c 'import json,sys;print(json.load(sys.stdin)["access_token"])'
}

wait_health() {
    for _ in $(seq 1 90); do
        if curl -fsS "$HEALTH_URL" >/dev/null 2>&1; then return 0; fi
        sleep 1
    done
    log "gateway /api/health unreachable"; return 1
}

purge_queues() {
    docker exec zt-rabbitmq rabbitmqctl --quiet purge_queue order_queue 2>/dev/null || true
    for q in OrderCreateRequestedEvent OrderCreatedEvent InventoryDeductedEvent \
             PaymentProcessedEvent OrderSagaCompletedEvent \
             RollbackInventoryEvent RollbackOrderEvent; do
        docker exec zt-rabbitmq rabbitmqctl --quiet purge_queue "$q" 2>/dev/null || true
    done
}

run_scale() {
    local n="$1"
    local csv="$OUT/raw/load_${ROUND}_${n}.csv"
    local wlog="$OUT/raw/worker_${ROUND}_${n}.log"
    local mlog="$OUT/raw/mtls_${ROUND}_${n}.err"

    log "scale=$n round=$ROUND — minting token"
    local token; token=$(mint_token)
    if [ -z "$token" ]; then log "token mint failed"; return 1; fi

    log "scale=$n round=$ROUND — purging queues"
    purge_queues

    # Mark worker log offset
    docker logs zt-php-worker 2>&1 | wc -l > /tmp/wlog_offset_$$.txt
    docker logs zt-gateway    2>&1 | wc -l > /tmp/glog_offset_$$.txt

    log "scale=$n round=$ROUND — starting load (concurrency=$CONCURRENCY)"
    python3 scripts/experiments/load-driver.py \
        --url "$GATEWAY_URL" \
        --count "$n" \
        --concurrency "$CONCURRENCY" \
        --tag "${ROUND}-${n}" \
        --token "$token" \
        --round "$ROUND" \
        --out "$csv" \
        2>&1 | tail -5

    log "scale=$n round=$ROUND — draining ${DRAIN_SEC}s"
    sleep "$DRAIN_SEC"

    # Capture only this scale's worker + gateway logs
    local woff goff
    woff=$(cat /tmp/wlog_offset_$$.txt)
    goff=$(cat /tmp/glog_offset_$$.txt)
    {
        docker logs zt-gateway 2>&1 | tail -n +$((goff+1)) \
            | grep -E '\[perf-request-in\]'
        docker logs zt-php-worker 2>&1 | tail -n +$((woff+1)) \
            | grep -E '\[perf-saga-step1\]|\[perf-saga-complete\]|商品資訊查詢失敗|RollbackOrderEvent|❌|Saga 完成|RollbackSaga'
    } > "$wlog"

    log "scale=$n round=$ROUND — mTLS probe (count=$MTLS_PROBE_COUNT)"
    docker cp scripts/experiments/mtls-probe.sh zt-php-worker:/tmp/mtls-probe.sh
    docker exec zt-php-worker bash /tmp/mtls-probe.sh "$MTLS_PROBE_COUNT" \
        > "$mlog" 2>&1 || true

    rm -f /tmp/wlog_offset_$$.txt /tmp/glog_offset_$$.txt
    log "scale=$n round=$ROUND — done. csv=$csv wlog_lines=$(wc -l < "$wlog") mtls_lines=$(grep -c perf-mtls "$mlog" || true)"
}

wait_health
mkdir -p "$OUT/raw"
for n in "${SCALES[@]}"; do
    run_scale "$n"
done
log "round=$ROUND done."
