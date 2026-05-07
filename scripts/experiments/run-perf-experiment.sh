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

SCALES_RAW="${PERF_SCALES:-5000 10000 20000}"
read -r -a SCALES <<<"$SCALES_RAW"
CONCURRENCY="${PERF_CONCURRENCY:-100}"
DRAIN_SEC="${PERF_DRAIN_SEC:-60}"
FULL_RESET="${PERF_FULL_RESET:-0}"
MTLS_PROBE_COUNT="${PERF_MTLS_PROBE_COUNT:-200}"
GATEWAY_URL="${PERF_GATEWAY_URL:-http://127.0.0.1:8080/api/orders}"
HEALTH_URL="${PERF_HEALTH_URL:-http://127.0.0.1:8080/api/health}"
TODAY="$(date +%F)"
OUT="${PERF_OUT:-artifacts/${TODAY}_Experimental}"

# Keycloak ingress (when gateway has KEYCLOAK_INGRESS_ENABLED=1 — default on
# feat/spiffe-keycloak). load-driver.py picks these up via env and does ROPC at
# startup to obtain a Bearer JWT for `testuser`, then signs every /api/orders
# call. Override any of these to point at a different Keycloak / user.
export KEYCLOAK_TOKEN_URL="${KEYCLOAK_TOKEN_URL:-http://127.0.0.1:8180/realms/zt/protocol/openid-connect/token}"
export KEYCLOAK_USER_CLIENT_ID="${KEYCLOAK_USER_CLIENT_ID:-client-app}"
export KEYCLOAK_USER_CLIENT_SECRET="${KEYCLOAK_USER_CLIENT_SECRET:-client-app-dev-secret}"
export KEYCLOAK_USER_USERNAME="${KEYCLOAK_USER_USERNAME:-testuser}"
export KEYCLOAK_USER_PASSWORD="${KEYCLOAK_USER_PASSWORD:-testpass}"

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
        PERF_METRIC_ENABLED=1 docker compose down -v --remove-orphans >/dev/null 2>&1 || true
        PERF_METRIC_ENABLED=1 docker compose up -d --build >/dev/null
        # SPIRE workloads re-registered automatically by zt-workload-registrar
        # (see registrar service in docker-compose.yml). Otherwise run:
        #   bash spiffe/scripts/register-workloads.sh
        wait_health 240
    else
        log "LIGHT reset: rebuild + recreate gateway + php-worker + spiffe-watcher"
        # Need PERF env + latest code in containers — build then recreate.
        # Also recreate spiffe-watcher: long-lived gRPC FetchX509SVID streams
        # can wedge after stream-interrupt, leaving SHM frozen and gateway
        # eventually rejecting all requests with "leaf X.509 certificate
        # expired" once the cached SVID hits the grace window.
        PERF_METRIC_ENABLED=1 docker compose build gateway php-worker >/dev/null
        PERF_METRIC_ENABLED=1 docker compose up -d --no-deps --force-recreate \
            spiffe-watcher gateway php-worker >/dev/null
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
    log "seeding wallet + inventory"
    docker exec user_service-user_DB-1 psql -U root -d user -q -c \
        "UPDATE wallet SET balance = 2000000000 WHERE u_key = 1;" >/dev/null 2>&1 || true
    docker exec production_service-production_DB-1 psql -U root -d production -q -c \
        "UPDATE inventory SET amount = 2000000000 WHERE p_key IN (1,2,3,4,5);" >/dev/null 2>&1 || true
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

    log "draining ${DRAIN_SEC}s for in-flight sagas to complete"
    sleep "$DRAIN_SEC"

    LOG_FILE="$OUT/raw/worker_${N}.log"
    : >"$LOG_FILE"
    {
        docker compose logs --no-color --since "$SINCE_TS" php-worker 2>&1 \
            | grep -E '\[perf-(saga-complete|saga-step1)\]' || true
        docker compose logs --no-color --since "$SINCE_TS" gateway 2>&1 \
            | grep -E '\[perf-request-in\]' || true
    } >>"$LOG_FILE"

    # mTLS handshake probe: openssl s_server + curl with SPIFFE certs.
    # Captures handshake cost on the same container/CPU running the saga.
    log "running mTLS handshake probe (count=${MTLS_PROBE_COUNT})"
    if docker compose exec -T php-worker bash /app/scripts/experiments/mtls-probe.sh "$MTLS_PROBE_COUNT" \
        2>>"$OUT/raw/mtls_${N}.err" \
        | grep -E '^\[perf-mtls\]' >>"$LOG_FILE"; then
        :
    else
        log "  → mTLS probe failed (see $OUT/raw/mtls_${N}.err)"
    fi

    saga_complete_count="$(grep -c '\[perf-saga-complete\]' "$LOG_FILE" || true)"
    saga_step1_count="$(grep -c '\[perf-saga-step1\]' "$LOG_FILE" || true)"
    mtls_count="$(grep -c '\[perf-mtls\]' "$LOG_FILE" || true)"
    request_in_count="$(grep -c '\[perf-request-in\]' "$LOG_FILE" || true)"
    log "  → captured: req_in=${request_in_count} saga_step1=${saga_step1_count} saga_complete=${saga_complete_count} mtls=${mtls_count}"
done

log "all scales complete — running analyzer"
python3 scripts/experiments/analyze-perf-experiment.py \
    --in "$OUT/raw" \
    --out "$OUT" \
    --scales "${SCALES[*]}"

log "DONE → $OUT"
ls -la "$OUT" >&2
