#!/usr/bin/env bash
# ============================================================================
# CI pipeline: unit tests + repeated E2E runs for stability verification.
#
# Usage:
#   bash scripts/ci-verify.sh
#
# Environment overrides:
#   COMPOSE_FILE          (default: docker-compose.yml)
#   CI_MODE               (default: gateway) — gateway | full | baseline
#                           gateway  → scripts/e2e-gateway.sh (light, no SPIRE)
#                           full     → scripts/e2e-full-architecture.sh (SPIRE + LSVID + Saga)
#                           baseline → scripts/e2e-gateway.sh with SPIFFE_ENABLED=0
#   CI_PROFILE            (default: auto — "zt" when CI_MODE=full, empty otherwise)
#                           Forwarded as COMPOSE_PROFILES so the SPIRE stack
#                           profile is honored by docker compose.
#   E2E_RUNS              (default: 3) — number of full E2E cycles
#   E2E_DIAG_LEVEL        (default: full)
#   CI_PREBUILD_IMAGES    (default: 1) — prebuild Docker images once
#   CI_ARTIFACT_DIR       (default: artifacts/ci)
#   CI_SKIP_UNIT          (default: 0) — skip unit tests
#   CI_SKIP_SPIRE_PROBE   (default: 0) — skip standalone SPIRE integrity probe
# ============================================================================
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
CI_MODE="${CI_MODE:-gateway}"
E2E_RUNS="${E2E_RUNS:-3}"
E2E_DIAG_LEVEL="${E2E_DIAG_LEVEL:-full}"
ARTIFACT_DIR="${CI_ARTIFACT_DIR:-artifacts/ci}"
PREBUILD_IMAGES="${CI_PREBUILD_IMAGES:-1}"
SKIP_UNIT="${CI_SKIP_UNIT:-0}"
SKIP_SPIRE_PROBE="${CI_SKIP_SPIRE_PROBE:-0}"

case "$CI_MODE" in
    gateway)
        E2E_SCRIPT="${PROJECT_DIR}/scripts/e2e-gateway.sh"
        ;;
    full)
        E2E_SCRIPT="${PROJECT_DIR}/scripts/e2e-full-architecture.sh"
        # Activate the zt profile so the SPIRE stack (server/agent/registrar/
        # watcher) comes up. docker-compose.yml guards them behind this profile
        # so Baseline ablation runs stay minimal.
        : "${CI_PROFILE:=zt}"
        ;;
    baseline)
        E2E_SCRIPT="${PROJECT_DIR}/scripts/e2e-gateway.sh"
        export SPIFFE_ENABLED="${SPIFFE_ENABLED:-0}"
        ;;
    *)
        printf '\033[31m[ci] FAIL: invalid CI_MODE=%s (want gateway|full|baseline)\033[0m\n' "$CI_MODE" >&2
        exit 2
        ;;
esac

if [[ -n "${CI_PROFILE:-}" ]]; then
    export COMPOSE_PROFILES="$CI_PROFILE"
fi

cd "$PROJECT_DIR"

log() {
    printf '[ci] %s %s\n' "$(date +%T)" "$*"
}

fail() {
    printf '\033[31m[ci] FAIL: %s\033[0m\n' "$*" >&2
}

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        fail "missing command: $1"
        exit 1
    fi
}

# ── Validation ───────────────────────────────────────────────────────────────

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

# ── Step 1: Unit tests ──────────────────────────────────────────────────────

if [[ "$SKIP_UNIT" != "1" ]]; then
    UNIT_LOG="$ARTIFACT_DIR/unit.log"
    log "running unit tests"
    if ! vendor/bin/phpunit -c phpunit.xml 2>&1 | tee "$UNIT_LOG"; then
        fail "unit tests failed; see ${UNIT_LOG}"
        exit 1
    fi
    log "unit tests passed"
else
    log "skipping unit tests (CI_SKIP_UNIT=1)"
fi

# ── Step 2: Prebuild Docker images ──────────────────────────────────────────

if [[ "$PREBUILD_IMAGES" == "1" ]]; then
    log "prebuilding Docker images (mode=${CI_MODE}, profiles=${COMPOSE_PROFILES:-<none>})"
    # In full mode the zt profile exposes additional services that need
    # their images built (spiffe-watcher shares the php-openswoole Dockerfile
    # but workload-registrar has its own). Pass services explicitly so
    # profile-gated builds are selected correctly.
    build_targets=(gateway php-worker)
    if [[ "$CI_MODE" == "full" ]]; then
        build_targets+=(spiffe-watcher workload-registrar)
    fi
    if ! docker compose -f "$COMPOSE_FILE" build "${build_targets[@]}" >"$ARTIFACT_DIR/prebuild.log" 2>&1; then
        fail "Docker prebuild failed; see ${ARTIFACT_DIR}/prebuild.log"
        exit 1
    fi
    log "Docker images built successfully"
fi

# ── Step 3: Repeated E2E runs ───────────────────────────────────────────────

success_count=0
start_time="$(date +%s)"

for run in $(seq 1 "$E2E_RUNS"); do
    run_dir="$ARTIFACT_DIR/e2e-run-${run}"
    run_log="$run_dir/e2e.log"
    mkdir -p "$run_dir"

    log "E2E run ${run}/${E2E_RUNS}"

    set +e
    E2E_KEEP_ON_FAIL=1 \
    E2E_BUILD_IMAGES=0 \
    E2E_DIAG_LEVEL="$E2E_DIAG_LEVEL" \
    COMPOSE_FILE="$COMPOSE_FILE" \
        bash "$E2E_SCRIPT" >"$run_log" 2>&1
    run_status=$?
    set -e

    if [[ "$run_status" -eq 0 ]]; then
        success_count=$((success_count + 1))
        log "E2E run ${run} passed"
        continue
    fi

    fail "E2E run ${run} failed; collecting diagnostics -> ${run_dir}"
    docker compose -f "$COMPOSE_FILE" ps >"$run_dir/docker-ps.txt" 2>&1 || true
    docker compose -f "$COMPOSE_FILE" logs --no-color >"$run_dir/compose.log" 2>&1 || true
    docker compose -f "$COMPOSE_FILE" logs --no-color gateway >"$run_dir/gateway.log" 2>&1 || true
    docker compose -f "$COMPOSE_FILE" logs --no-color php-worker >"$run_dir/php-worker.log" 2>&1 || true
    docker compose -f "$COMPOSE_FILE" logs --no-color rabbitmq >"$run_dir/rabbitmq.log" 2>&1 || true
    docker compose -f "$COMPOSE_FILE" down -v --remove-orphans >"$run_dir/compose-down.log" 2>&1 || true

    fail "stopped at run ${run}/${E2E_RUNS}; passed=${success_count}"
    exit 1
done

elapsed=$(( $(date +%s) - start_time ))

log "all checks passed: mode=${CI_MODE} unit+E2E ${success_count}/${E2E_RUNS} (${elapsed}s)"
