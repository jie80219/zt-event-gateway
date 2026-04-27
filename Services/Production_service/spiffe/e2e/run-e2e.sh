#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════
#  SPIFFE E2E Test — all-in-one runner
#
#  Uses the EXTERNAL SPIRE Server (zt-spire-server) owned by a
#  separate project. This script does NOT start/stop the server.
#
#  Orchestrates:
#    1. Verify SPIRE Server reachable
#    2. Bootstrap (generate join token + register workloads)
#    3. Start SPIRE Agent (attests with join token)
#    4. Run PHP E2E test suite
#    5. Report results
#    6. Tear down (optional: --keep to leave running)
#
#  Usage:
#    ./spiffe/e2e/run-e2e.sh          # run and tear down
#    ./spiffe/e2e/run-e2e.sh --keep   # run and keep environment alive
# ══════════════════════════════════════════════════════════════════
set -euo pipefail

COMPOSE_FILE="docker-compose.yml"
SPIRE_SERVER_CONTAINER="zt-spire-server"
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
        log "Tearing down local services (leaving external SPIRE Server running)..."
        docker compose -f "$COMPOSE_FILE" down -v --remove-orphans 2>/dev/null || true
    else
        log "Keeping environment alive (use 'docker compose -f $COMPOSE_FILE down -v' to clean up)"
    fi
}

trap cleanup EXIT

# ──────────────────────────────────────────────────────────────
#  0. Preflight: external network + server must exist
# ──────────────────────────────────────────────────────────────
log "Verifying external network 'anser_project_network' exists..."
if ! docker network ls --format '{{.Name}}' | grep -qx 'anser_project_network'; then
    fail "External network 'anser_project_network' not found. Start the SPIRE Server project first."
    exit 1
fi

log "Verifying external SPIRE Server container (${SPIRE_SERVER_CONTAINER}) is running..."
if ! docker ps --format '{{.Names}}' | grep -qx "${SPIRE_SERVER_CONTAINER}"; then
    fail "SPIRE Server container '${SPIRE_SERVER_CONTAINER}' not running. Start it from its owning project."
    exit 1
fi

log "Waiting for SPIRE Server healthcheck..."
timeout=60
elapsed=0
until docker exec "${SPIRE_SERVER_CONTAINER}" \
    /opt/spire/bin/spire-server healthcheck 2>/dev/null; do
    sleep 2
    elapsed=$((elapsed + 2))
    if [ "$elapsed" -ge "$timeout" ]; then
        fail "SPIRE Server not healthy after ${timeout}s"
        docker logs "${SPIRE_SERVER_CONTAINER}" --tail=50
        exit 1
    fi
done
log "SPIRE Server is healthy."

# ──────────────────────────────────────────────────────────────
#  1. Clean slate (local services only)
# ──────────────────────────────────────────────────────────────
log "Cleaning previous E2E environment..."
docker compose -f "$COMPOSE_FILE" down -v --remove-orphans 2>/dev/null || true

# ──────────────────────────────────────────────────────────────
#  2. Bootstrap: generate join token + register workloads
# ──────────────────────────────────────────────────────────────
log "Running bootstrap against ${SPIRE_SERVER_CONTAINER}..."
/bin/bash "$PROJECT_DIR/spiffe/e2e/bootstrap.sh"

# ──────────────────────────────────────────────────────────────
#  3. Start SPIRE Agent
# ──────────────────────────────────────────────────────────────
log "Starting SPIRE Agent..."
docker compose -f "$COMPOSE_FILE" up -d spire-agent

log "Waiting for SPIRE Agent to be healthy..."
elapsed=0
until docker compose -f "$COMPOSE_FILE" exec -T spire-agent \
    /opt/spire/bin/spire-agent healthcheck -socketPath /run/spire/sockets/agent.sock 2>/dev/null; do
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
    docker logs "${SPIRE_SERVER_CONTAINER}" --tail=20
    docker compose -f "$COMPOSE_FILE" logs spire-agent --tail=20
fi

exit "$EXIT_CODE"
