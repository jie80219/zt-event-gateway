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
# Drain budget mirrors feat/Linkerd1's run-perf-experiment.sh: N/25+120 with
# idle-exit at PERF_DRAIN_IDLE (60s). Same env vars as the perf runner so
# cross-branch comparisons share one knob.
DRAIN_SEC_OVERRIDE="${PERF_DRAIN_SEC:-${DRAIN_SEC:-}}"
DRAIN_SEC_PER_REQ="${PERF_DRAIN_SEC_PER_REQ:-25}"
DRAIN_SEC_BASE="${PERF_DRAIN_SEC_BASE:-120}"
DRAIN_IDLE_TARGET="${PERF_DRAIN_IDLE:-60}"
DRAIN_POLL="${PERF_DRAIN_POLL:-10}"
MTLS_PROBE_COUNT="${MTLS_PROBE_COUNT:-200}"
ROUND="${ROUND:-warm}"
GATEWAY_URL="http://127.0.0.1:8080/api/orders"
HEALTH_URL="http://127.0.0.1:8080/api/health"

compute_drain_sec() {
    local n=$1
    if [[ -n "$DRAIN_SEC_OVERRIDE" ]]; then
        printf '%s' "$DRAIN_SEC_OVERRIDE"
    else
        printf '%s' "$(( n / DRAIN_SEC_PER_REQ + DRAIN_SEC_BASE ))"
    fi
}

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

    local drain_sec; drain_sec=$(compute_drain_sec "$n")
    log "scale=$n round=$ROUND — draining (max ${drain_sec}s, poll all queues)"
    local drain_elapsed=0 drain_idle=0 depth
    while (( drain_elapsed < drain_sec )); do
        sleep "$DRAIN_POLL"
        drain_elapsed=$((drain_elapsed + DRAIN_POLL))
        depth=$(docker exec zt-rabbitmq rabbitmqctl --quiet list_queues name messages 2>/dev/null \
            | awk '$2 ~ /^[0-9]+$/ {sum+=$2} END{print sum+0}') || depth=0
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
