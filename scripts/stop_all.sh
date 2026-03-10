#!/bin/bash

set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -d "tmp/pids" ]; then
  echo "No PID directory found."
  exit 0
fi

for pidFile in tmp/pids/*.pid; do
  [ -e "$pidFile" ] || continue
  pid=$(cat "$pidFile")
  queueName=$(basename "$pidFile" .pid)

  if kill -0 "$pid" 2>/dev/null; then
    kill "$pid"
    echo "Stopped ${queueName} (pid: ${pid})"
  else
    echo "Skip ${queueName}, process not running."
  fi
done

rm -f tmp/pids/*.pid
echo "All consumers stopped."
