#!/usr/bin/env bash
# ============================================================================
# Lean per-Run perf measurement (concurrency-bounded).
#
# Unlike run-dualmode-distributed.sh (which pins --concurrency = --count and so
# collapses the downstream services under pathological connection counts), this
# driver fixes a BOUNDED concurrency so sagas actually complete end-to-end and
# per-Run deltas in completion-rate / latency are observable. Use the
# distributed runner for the final publishable numbers; use this to compare
# the optimization Runs apples-to-apples while the stack is stressed but alive.
#
# Topology (driven from the gateway host itself):
#   GATEWAY_HOST   ssh target for docker exec/logs on the gateway box (self)
#   DRIVER_HOST    ssh target that runs load-driver.py in a python container
#   GATEWAY_URL    order ingress URL reachable from DRIVER_HOST
#
# Usage:  bash scripts/experiments/measure-run.sh <out-dir>
# Env:    MEASURE_COUNT (2000) MEASURE_CONC (100) MEASURE_ROUND (warm)
#         MEASURE_DRAIN_MAX (300) MEASURE_DRAIN_IDLE (30)
# Output: <out-dir>/raw/load_<round>_<count>.csv
#         <out-dir>/raw/worker_<round>_<count>.log
#         <out-dir>/summary.json
# ============================================================================
set -euo pipefail

OUT="${1:?usage: $0 <out-dir>}"
COUNT="${MEASURE_COUNT:-2000}"
CONC="${MEASURE_CONC:-100}"
ROUND="${MEASURE_ROUND:-warm}"
GW_HOST="${GATEWAY_HOST:-10.1.1.209}"
DRIVER_HOST="${DRIVER_HOST:-zt-order}"
GW_URL="${GATEWAY_URL:-http://10.1.1.209:8080/api/orders}"
DRAIN_MAX="${MEASURE_DRAIN_MAX:-300}"
DRAIN_IDLE_TARGET="${MEASURE_DRAIN_IDLE:-30}"
DRAIN_POLL="${MEASURE_DRAIN_POLL:-10}"

PROJECT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
mkdir -p "$OUT/raw"
log() { printf '[measure] %s %s\n' "$(date +%T)" "$*" >&2; }

csv="$OUT/raw/load_${ROUND}_${COUNT}.csv"
wlog="$OUT/raw/worker_${ROUND}_${COUNT}.log"

log "mint token"
TOKEN=$(ssh "$GW_HOST" "docker exec zt-gateway curl -fsS -m5 -X POST \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode grant_type=password --data-urlencode client_id=client-app \
  --data-urlencode client_secret=client-app-dev-secret \
  --data-urlencode username=testuser --data-urlencode password=testpass \
  http://keycloak:8080/realms/zt/protocol/openid-connect/token" \
  | python3 -c "import json,sys;print(json.load(sys.stdin)['access_token'])")
log "token len=${#TOKEN}"

log "purge queues"
ssh "$GW_HOST" '
  for q in order_queue OrderCreateRequestedEvent OrderCreatedEvent \
           InventoryDeductedEvent PaymentProcessedEvent OrderSagaCompletedEvent \
           RollbackInventoryEvent RollbackOrderEvent; do
    docker exec zt-rabbitmq rabbitmqctl --quiet purge_queue "$q" 2>/dev/null || true
  done'

woff=$(ssh "$GW_HOST" 'docker logs zt-php-worker 2>&1 | wc -l')
log "worker log offset=$woff"

log "driver: count=$COUNT concurrency=$CONC round=$ROUND on $DRIVER_HOST"
ssh "$DRIVER_HOST" "
  docker run --rm --network host --ulimit nofile=65535:65535 \
    --security-opt apparmor=unconfined --security-opt seccomp=unconfined \
    -v /root/load-driver.py:/load.py:ro -v /root:/work python:3.11-slim sh -c '
      pip install --quiet aiohttp >/dev/null 2>&1 || pip install aiohttp >/dev/null
      python3 /load.py --url \"$GW_URL\" --count $COUNT --concurrency $CONC \
        --tag \"${ROUND}-${COUNT}\" --token \"$TOKEN\" --round \"$ROUND\" \
        --out /work/load.csv
    ' 2>&1 | tail -3
"
scp -q "$DRIVER_HOST:/root/load.csv" "$csv"
log "load.csv pulled ($(wc -l < "$csv") lines)"

log "drain (max ${DRAIN_MAX}s, idle target ${DRAIN_IDLE_TARGET}s)"
elapsed=0 idle=0
while (( elapsed < DRAIN_MAX )); do
  sleep "$DRAIN_POLL"; elapsed=$((elapsed + DRAIN_POLL))
  depth=$(ssh "$GW_HOST" "docker exec zt-rabbitmq rabbitmqctl --quiet list_queues name messages 2>/dev/null | awk '\$2 ~ /^[0-9]+\$/ {s+=\$2} END{print s+0}'") || depth=0
  depth="${depth:-0}"
  log "  drain t=${elapsed}s all_queues=${depth}"
  if [[ "$depth" == "0" ]]; then
    idle=$((idle + DRAIN_POLL))
    (( idle >= DRAIN_IDLE_TARGET )) && { log "  drained"; break; }
  else idle=0; fi
done

log "pull worker perf log slice"
ssh "$GW_HOST" "docker logs zt-php-worker 2>&1 | tail -n +$((woff+1))" \
  | grep -E 'perf-saga-step1|perf-saga-complete|perf-saga-rolled-back|RollbackSaga|商品資訊查詢失敗' \
  > "$wlog" || true
log "worker.log lines=$(wc -l < "$wlog")"

log "compute metrics"
MEASURE_OUT="$OUT" MEASURE_CSV="$csv" MEASURE_WLOG="$wlog" \
MEASURE_COUNT="$COUNT" MEASURE_CONC="$CONC" MEASURE_ROUND="$ROUND" \
python3 "$PROJECT_DIR/scripts/experiments/measure-metrics.py"
cat "$OUT/summary.json"
