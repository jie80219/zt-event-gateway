#!/bin/bash

set -euo pipefail

cd "$(dirname "$0")/.."

mkdir -p tmp/pids tmp/logs

QUEUES=(
  "OrderCreateRequestedEvent"
  "OrderCreatedEvent"
  "InventoryDeductedEvent"
  "PaymentProcessedEvent"
)

echo "Starting event consumers..."

for q in "${QUEUES[@]}"; do
  echo "Starting queue: ${q}"
  nohup php consumer.php "${q}" > "tmp/logs/${q}.log" 2>&1 &
  echo $! > "tmp/pids/${q}.pid"
done

echo "All consumers started."
