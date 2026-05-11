#!/usr/bin/env bash
# Fork N php-worker processes inside the single zt-php-worker container.
# Multi-process consumption parallelises saga throughput across one queue
# (RabbitMQ round-robins to consumers). Keeps the container name stable so
# all existing scripts that `docker exec/logs/restart zt-php-worker` keep
# working — unlike compose `--scale`, which renames containers.
#
# Env:
#   WORKER_PROCESSES  number of `php bin/worker.php` children (default 1)
set -e

N="${WORKER_PROCESSES:-1}"
case "$N" in
    ''|*[!0-9]*) echo "[entrypoint] WORKER_PROCESSES must be a positive integer, got '$N'" >&2; exit 1 ;;
esac
if [ "$N" -lt 1 ]; then
    echo "[entrypoint] WORKER_PROCESSES must be >= 1, got '$N'" >&2
    exit 1
fi

echo "[entrypoint] starting $N worker process(es)" >&2

pids=""
for i in $(seq 1 "$N"); do
    php bin/worker.php &
    pids="$pids $!"
done

# Exit the container if any child dies so docker `restart: unless-stopped`
# (or the operator) brings the whole thing back instead of running degraded
# with N-1 workers.
wait -n
exit_code=$?
echo "[entrypoint] a worker exited (code=$exit_code); shutting container down" >&2
# Best-effort: ask remaining workers to drain before we exit. They share the
# container PID namespace so SIGTERM reaches them via the kill below.
for p in $pids; do
    kill -TERM "$p" 2>/dev/null || true
done
exit "$exit_code"
