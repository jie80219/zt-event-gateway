#!/bin/bash

set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -d "tmp/pids" ]; then
  echo "No PID directory found."
  exit 0
fi

for pidFile in tmp/pids/*.pid; do
  [ -e "$pidFile" ] || continue
  queueName=$(basename "$pidFile" .pid)
  pid=$(cat "$pidFile")

  if kill -0 "$pid" 2>/dev/null; then
    echo "[RUNNING] ${queueName} (pid: ${pid})"
  else
    echo "[STOPPED] ${queueName} (stale pid: ${pid})"
  fi
done
