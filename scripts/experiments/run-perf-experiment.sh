#!/usr/bin/env bash
# ============================================================================
# Performance experiment driver: 5000 / 10000 / 20000 requests
#
# Measures four metrics per scale:
#   1. Gateway request reception time      (load-driver client-side latency)
#   2. Order completion time               (saga step1 → saga complete)
#   3. Incomplete-transaction rate         (no OrderSagaCompletedEvent within drain window)
#   4. mTLS handshake cost                 (Guzzle on_stats appconnect_time)
#
# Each scale produces:
#   $OUT/raw/load_<N>.csv      — per-request gateway latency (load-driver)
#   $OUT/raw/worker_<N>.log    — filtered [perf-*] log lines from worker+gateway
#
# After all scales, analyze-perf-experiment.py emits xlsx + png to $OUT.
#
# Usage:
#   bash scripts/experiments/run-perf-experiment.sh
#   PERF_SCALES="100 200" bash scripts/experiments/run-perf-experiment.sh    # smoke
#   PERF_FULL_RESET=1 bash scripts/experiments/run-perf-experiment.sh        # down -v + up
# ============================================================================
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$PROJECT_DIR"

# Auto-detect Linkerd overlay so reset_stack does not strip the sidecar
# integration when recreating gateway + php-worker between scales.
COMPOSE_ARGS=(-f docker-compose.yml)
if [[ -f docker-compose.linkerd.yml ]]; then
    COMPOSE_ARGS+=(-f docker-compose.linkerd.yml)
fi

SCALES_RAW="${PERF_SCALES:-5000 10000 20000}"
read -r -a SCALES <<<"$SCALES_RAW"
CONCURRENCY="${PERF_CONCURRENCY:-100}"
# DRAIN budget is computed per-scale via compute_drain_sec() unless the user
# pins it explicitly via PERF_DRAIN_SEC. Worker sustains ~30 saga events/sec
# regardless of N, so the time to flush all in-flight sagas after load-driver
# finishes scales linearly with N. The default formula (N/25 + 120) gives:
#   N=5000  → 320s   N=10000 → 520s   N=20000 → 920s
# plus the 60s idle window before capture, with ~60s safety margin baked in.
DRAIN_SEC_OVERRIDE="${PERF_DRAIN_SEC:-}"
DRAIN_SEC_PER_REQ="${PERF_DRAIN_SEC_PER_REQ:-25}"   # seconds per (req / divisor)
DRAIN_SEC_BASE="${PERF_DRAIN_SEC_BASE:-120}"
compute_drain_sec() {
    local n=$1
    if [[ -n "$DRAIN_SEC_OVERRIDE" ]]; then
        printf '%s' "$DRAIN_SEC_OVERRIDE"
    else
        printf '%s' "$(( n / DRAIN_SEC_PER_REQ + DRAIN_SEC_BASE ))"
    fi
}
FULL_RESET="${PERF_FULL_RESET:-0}"
MTLS_PROBE_COUNT="${PERF_MTLS_PROBE_COUNT:-200}"
GATEWAY_URL="${PERF_GATEWAY_URL:-http://127.0.0.1:8080/api/orders}"
HEALTH_URL="${PERF_HEALTH_URL:-http://127.0.0.1:8080/api/health}"
TODAY="$(date +%F)"
OUT="${PERF_OUT:-artifacts/${TODAY}_Experimental}"

mkdir -p "$OUT/raw"

log()  { printf '[perf] %s %s\n' "$(date +%T)" "$*" >&2; }
fail() { printf '[perf][FAIL] %s\n' "$*" >&2; exit 1; }

wait_health() {
    local timeout="${1:-180}"
    local elapsed=0
    while ! curl -fsS "$HEALTH_URL" >/dev/null 2>&1; do
        sleep 2
        elapsed=$((elapsed + 2))
        if (( elapsed >= timeout )); then
            fail "gateway /api/health not ready within ${timeout}s"
        fi
    done
    log "gateway healthy at ${HEALTH_URL}"
}

reset_stack() {
    if [[ "$FULL_RESET" == "1" ]]; then
        log "FULL reset: docker compose down -v + up -d --build"
        PERF_METRIC_ENABLED=1 docker compose "${COMPOSE_ARGS[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
        PERF_METRIC_ENABLED=1 docker compose "${COMPOSE_ARGS[@]}" up -d --build >/dev/null
        wait_health 240
    else
        log "LIGHT reset: rebuild + recreate gateway + php-worker"
        PERF_METRIC_ENABLED=1 docker compose "${COMPOSE_ARGS[@]}" build gateway php-worker >/dev/null
        PERF_METRIC_ENABLED=1 docker compose "${COMPOSE_ARGS[@]}" up -d --no-deps --force-recreate \
            gateway php-worker >/dev/null
        wait_health 180
    fi
    purge_queues
}

purge_queues() {
    log "purging RabbitMQ queues for clean run"
    # Queues are declared idempotently by the worker on startup; purge is best-effort
    docker exec zt-rabbitmq rabbitmqctl --quiet list_queues name 2>/dev/null \
        | tail -n +2 \
        | grep -v '^$' \
        | while read -r q; do
            docker exec zt-rabbitmq rabbitmqctl --quiet purge_queue "$q" >/dev/null 2>&1 || true
        done
}

seed_dbs() {
    # Top up user 1's wallet (load-driver hardcodes userKey="1") and replenish
    # the most-trafficked inventory rows so transactions can complete instead
    # of all rolling back due to insufficient funds / stock.
    #
    # In multi-host deployments the user_DB and production_DB containers live
    # on dedicated hosts. Set PERF_SEED_USER_HOST / PERF_SEED_PROD_HOST to the
    # remote host (or alias) to seed via SSH; "local" keeps the original
    # docker-exec path for single-host dev.
    local user_host="${PERF_SEED_USER_HOST:-local}"
    local prod_host="${PERF_SEED_PROD_HOST:-local}"
    local ssh_opts="${PERF_SSH_OPTS:--o BatchMode=yes -o ConnectTimeout=5}"
    log "seeding wallet (user_host=${user_host}) + inventory (prod_host=${prod_host})"

    local user_sql="UPDATE wallet SET balance = 2000000000 WHERE u_key = 1;"
    if [[ "$user_host" == "local" ]]; then
        docker exec user_service-user_DB-1 psql -U root -d user -q -c "$user_sql" >/dev/null 2>&1 || true
    else
        ssh $ssh_opts "$user_host" "docker exec user_service-user_DB-1 psql -U root -d user -q -c \"$user_sql\"" >/dev/null 2>&1 || true
    fi

    local prod_sql="UPDATE inventory SET amount = 2000000000 WHERE p_key IN (1,2,3,4,5);"
    if [[ "$prod_host" == "local" ]]; then
        docker exec production_service-production_DB-1 psql -U root -d production -q -c "$prod_sql" >/dev/null 2>&1 || true
    else
        ssh $ssh_opts "$prod_host" "docker exec production_service-production_DB-1 psql -U root -d production -q -c \"$prod_sql\"" >/dev/null 2>&1 || true
    fi
}

log "experiment start: scales=[${SCALES[*]}] concurrency=${CONCURRENCY} out=${OUT}"
log "PERF_METRIC_ENABLED will be injected into gateway + php-worker"

# Initial bring-up — ensures PERF env is present in containers
reset_stack
seed_dbs

for N in "${SCALES[@]}"; do
    log "================ scale: ${N} ================"
    if [[ "$N" != "${SCALES[0]}" ]]; then
        reset_stack
    fi
    seed_dbs

    SINCE_TS="$(date -u +%FT%TZ)"
    log "load-driver: count=${N} concurrency=${CONCURRENCY} → $OUT/raw/load_${N}.csv"
    python3 scripts/experiments/load-driver.py \
        --url "$GATEWAY_URL" \
        --count "$N" \
        --concurrency "$CONCURRENCY" \
        --tag "perf${N}" \
        --out "$OUT/raw/load_${N}.csv"

    drain_sec=$(compute_drain_sec "$N")
    log "draining (max ${drain_sec}s, poll all queues) for in-flight sagas to complete"
    drain_elapsed=0
    drain_poll=10
    drain_idle=0
    drain_idle_target="${PERF_DRAIN_IDLE:-60}"
    while (( drain_elapsed < drain_sec )); do
        sleep "$drain_poll"
        drain_elapsed=$((drain_elapsed + drain_poll))
        # Sum across ALL queues — sagas pass through order_queue *and* the
        # OrderCreateRequestedEvent / OrderCreatedEvent / InventoryDeductedEvent
        # / PaymentProcessedEvent / OrderSagaCompleted / RollbackInventoryEvent
        # / RollbackOrderEvent queues. Watching only order_queue under-reports
        # in-flight work and lets the drain exit while sagas are still firing.
        depth=$(docker exec zt-rabbitmq rabbitmqctl --quiet list_queues name messages 2>/dev/null \
            | awk '$2 ~ /^[0-9]+$/ {sum+=$2} END{print sum+0}')
        depth="${depth:-0}"
        log "  drain t=${drain_elapsed}s all_queues=${depth}"
        if [[ "$depth" == "0" ]]; then
            drain_idle=$((drain_idle + drain_poll))
            if (( drain_idle >= drain_idle_target )); then
                log "  all queues idle ${drain_idle}s — draining complete"
                break
            fi
        else
            drain_idle=0
        fi
    done

    LOG_FILE="$OUT/raw/worker_${N}.log"
    : >"$LOG_FILE"
    {
        docker compose "${COMPOSE_ARGS[@]}" logs --no-color --since "$SINCE_TS" php-worker 2>&1 \
            | grep -E '\[perf-(saga-complete|saga-step1|saga-rolled-back)\]' || true
        docker compose "${COMPOSE_ARGS[@]}" logs --no-color --since "$SINCE_TS" gateway 2>&1 \
            | grep -E '\[perf-request-in\]' || true
    } >>"$LOG_FILE"

    saga_complete_count="$(grep -c '\[perf-saga-complete\]' "$LOG_FILE" || true)"
    saga_step1_count="$(grep -c '\[perf-saga-step1\]' "$LOG_FILE" || true)"
    request_in_count="$(grep -c '\[perf-request-in\]' "$LOG_FILE" || true)"
    log "  → captured: req_in=${request_in_count} saga_step1=${saga_step1_count} saga_complete=${saga_complete_count}"
done

log "all scales complete — running analyzer"
python3 scripts/experiments/analyze-perf-experiment.py \
    --in "$OUT/raw" \
    --out "$OUT" \
    --scales "${SCALES[*]}"

log "DONE → $OUT"
ls -la "$OUT" >&2
