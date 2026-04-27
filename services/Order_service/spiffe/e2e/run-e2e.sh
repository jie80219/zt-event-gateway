#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════
#  SPIFFE E2E Test — all-in-one runner
#
#  Orchestrates the full lifecycle:
#    1. Build images
#    2. Start SPIRE Agent (connects to external SPIRE Server)
#    3. Run PHP E2E test suite
#    4. Report results
#    5. Tear down (optional: --keep to leave running)
#
#  Prerequisites:
#    - External SPIRE Server must be running and reachable
#    - Set SPIRE_SERVER_ADDRESS and SPIRE_SERVER_PORT env vars
#
#  Usage:
#    ./spiffe/e2e/run-e2e.sh          # run and tear down
#    ./spiffe/e2e/run-e2e.sh --keep   # run and keep environment alive
# ══════════════════════════════════════════════════════════════════
set -euo pipefail

COMPOSE_FILE="docker-compose.spiffe.yml"
PROJECT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$PROJECT_DIR"

KEEP=false
[[ "${1:-}" == "--keep" ]] && KEEP=true

RED='\033[31m'
GREEN='\033[32m'
BOLD='\033[1m'
RESET='\033[0m'

log()  { echo -e "${BOLD}[e2e]${RESET} $(date +%T) $*"; }
pass() { echo -e "${GREEN}${BOLD}[PASS]${RESET} $*"; }
fail() { echo -e "${RED}${BOLD}[FAIL]${RESET} $*"; }

cleanup() {
    if [ "$KEEP" = false ]; then
        log "Tearing down..."
        docker compose -f "$COMPOSE_FILE" down -v --remove-orphans 2>/dev/null || true
    else
        log "Keeping environment alive (use 'docker compose -f $COMPOSE_FILE down -v' to clean up)"
    fi
}

trap cleanup EXIT

# ──────────────────────────────────────────────────────────────
#  1. Clean slate
# ──────────────────────────────────────────────────────────────
log "Cleaning previous E2E environment..."
docker compose -f "$COMPOSE_FILE" down -v --remove-orphans 2>/dev/null || true

# ──────────────────────────────────────────────────────────────
#  2. Build PHP image
# ──────────────────────────────────────────────────────────────
log "Building PHP E2E image..."
docker compose -f "$COMPOSE_FILE" build php-spiffe-e2e

# ──────────────────────────────────────────────────────────────
#  3. Start SPIRE Agent (connects to external SPIRE Server)
# ──────────────────────────────────────────────────────────────
log "Starting SPIRE Agent..."
docker compose -f "$COMPOSE_FILE" up -d spire-agent

log "Waiting for SPIRE Agent to be healthy..."
timeout=60
elapsed=0
until docker compose -f "$COMPOSE_FILE" exec -T spire-agent \
    /opt/spire/bin/spire-agent healthcheck 2>/dev/null; do
    sleep 2
    elapsed=$((elapsed + 2))
    if [ "$elapsed" -ge "$timeout" ]; then
        fail "SPIRE Agent not healthy after ${timeout}s"
        docker compose -f "$COMPOSE_FILE" logs spire-agent
        exit 1
    fi
done
log "SPIRE Agent is healthy."

# ──────────────────────────────────────────────────────────────
#  4. Run PHP E2E tests
# ──────────────────────────────────────────────────────────────
log "Running PHP E2E test suite..."
echo ""

EXIT_CODE=0
docker compose -f "$COMPOSE_FILE" run --rm php-spiffe-e2e || EXIT_CODE=$?

echo ""

if [ "$EXIT_CODE" -eq 0 ]; then
    pass "All E2E tests passed!"
else
    fail "E2E tests failed (exit code: ${EXIT_CODE})"
    echo ""
    log "Dumping logs for debugging:"
    docker compose -f "$COMPOSE_FILE" logs spire-agent --tail=20
fi

exit "$EXIT_CODE"
