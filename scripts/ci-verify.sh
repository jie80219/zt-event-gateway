#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
E2E_SCRIPT="${E2E_SCRIPT:-scripts/e2e-gateway.sh}"
E2E_RUNS="${E2E_RUNS:-20}"
E2E_DIAG_LEVEL="${E2E_DIAG_LEVEL:-full}"
ARTIFACT_DIR="${CI_ARTIFACT_DIR:-artifacts/ci}"
PREBUILD_IMAGES="${CI_PREBUILD_IMAGES:-1}"

cd "$PROJECT_DIR"

log() {
    printf '[ci-verify] %s %s\n' "$(date +%T)" "$*"
}

fail() {
    printf '[ci-verify] FAIL: %s\n' "$*" >&2
}

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        fail "missing command: $1"
        exit 1
    fi
}

if ! [[ "$E2E_RUNS" =~ ^[1-9][0-9]*$ ]]; then
    fail "E2E_RUNS must be a positive integer, got: ${E2E_RUNS}"
    exit 1
fi
if [[ "$PREBUILD_IMAGES" != "0" && "$PREBUILD_IMAGES" != "1" ]]; then
    fail "CI_PREBUILD_IMAGES must be 0 or 1, got: ${PREBUILD_IMAGES}"
    exit 1
fi

require_cmd php
require_cmd docker

mkdir -p "$ARTIFACT_DIR"

UNIT_LOG="$ARTIFACT_DIR/unit.log"

log "running unit tests"
if ! vendor/bin/phpunit -c phpunit.xml 2>&1 | tee "$UNIT_LOG"; then
    fail "unit tests failed; see ${UNIT_LOG}"
    exit 1
fi

if [[ "$PREBUILD_IMAGES" == "1" ]]; then
    log "prebuilding docker images once for stable e2e runs"
    if ! docker compose -f "$COMPOSE_FILE" build gateway php-worker >"$ARTIFACT_DIR/prebuild.log" 2>&1; then
        fail "docker prebuild failed; see ${ARTIFACT_DIR}/prebuild.log"
        exit 1
    fi
fi

success_count=0

for run in $(seq 1 "$E2E_RUNS"); do
    run_dir="$ARTIFACT_DIR/e2e-run-${run}"
    run_log="$run_dir/e2e.log"
    mkdir -p "$run_dir"

    log "running e2e ${run}/${E2E_RUNS}"

    set +e
    E2E_KEEP_ON_FAIL=1 E2E_BUILD_IMAGES=0 E2E_DIAG_LEVEL="$E2E_DIAG_LEVEL" COMPOSE_FILE="$COMPOSE_FILE" "$E2E_SCRIPT" >"$run_log" 2>&1
    run_status=$?
    set -e

    if [[ "$run_status" -eq 0 ]]; then
        success_count=$((success_count + 1))
        continue
    fi

    fail "e2e run ${run} failed; collecting docker logs into ${run_dir}"
    docker compose -f "$COMPOSE_FILE" ps >"$run_dir/docker-ps.txt" 2>&1 || true
    docker compose -f "$COMPOSE_FILE" logs --no-color >"$run_dir/compose.log" 2>&1 || true
    docker compose -f "$COMPOSE_FILE" logs --no-color gateway >"$run_dir/gateway.log" 2>&1 || true
    docker compose -f "$COMPOSE_FILE" logs --no-color php-worker >"$run_dir/php-worker.log" 2>&1 || true
    docker compose -f "$COMPOSE_FILE" logs --no-color rabbitmq >"$run_dir/rabbitmq.log" 2>&1 || true
    docker compose -f "$COMPOSE_FILE" down -v --remove-orphans >"$run_dir/compose-down.log" 2>&1 || true

    fail "pipeline stopped on failing run ${run}/${E2E_RUNS}; success=${success_count}"
    exit 1
done

log "all checks passed: unit + e2e ${success_count}/${E2E_RUNS}"
