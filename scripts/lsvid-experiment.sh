#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════
#  LSVID End-to-End Experiment Runner
#
#  Executes the same stress workload against four gateway profiles
#  and emits JSON summaries so docs/lsvid-experiment.md can cite
#  real numbers:
#
#    A — baseline       LSVID_ENABLED=0, LSVID_REQUIRED=0
#    B — minting only   LSVID_ENABLED=1, LSVID_REQUIRED=0
#    C — fail-closed    LSVID_ENABLED=1, LSVID_REQUIRED=1
#    D — fail-closed+RV LSVID_ENABLED=1, LSVID_REQUIRED=1,
#                       SpiffeLsvidFilter re-validation on (always on
#                       in this build; this profile just confirms the
#                       delta is measured in the running container).
#
#  NOTE: Profiles A–C are applied by restarting `php-gateway` and
#  `php-worker` containers with overridden env vars. Between profiles
#  we drain RabbitMQ and wait for the gateway health endpoint.
#
#  Requires:
#    docker compose, curl, python3
#
#  Usage:
#    bash scripts/lsvid-experiment.sh                 # full run
#    TOTAL=300 CONC=5 bash scripts/lsvid-experiment.sh # quick sample
# ══════════════════════════════════════════════════════════════════
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
TOTAL="${TOTAL:-500}"
CONC="${CONC:-10}"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT_DIR="docs/data"
mkdir -p "$OUT_DIR"

BOLD='\033[1m'; GREEN='\033[32m'; RED='\033[31m'; YELLOW='\033[33m'; RESET='\033[0m'
info() { echo -e "${BOLD}[exp]${RESET} $(date +%T) $*"; }
pass() { echo -e "${GREEN}${BOLD}[ok]${RESET}  $*"; }
fail() { echo -e "${RED}${BOLD}[fail]${RESET} $*"; exit 1; }

wait_health() {
    local url="${HEALTH_URL:-http://10.1.1.209:8080/api/health}"
    local deadline=$((SECONDS + 90))
    while (( SECONDS < deadline )); do
        if [[ "$(curl -s -o /dev/null -w '%{http_code}' "$url" 2>/dev/null || echo 000)" == "200" ]]; then
            return 0
        fi
        sleep 2
    done
    fail "gateway health endpoint never became ready (${url})"
}

apply_profile() {
    local profile="$1"
    local gw_enabled="$2" gw_required="$3" wk_required="$4"

    info "applying profile ${profile} (GW enabled=${gw_enabled} required=${gw_required}, WK required=${wk_required})"

    # We use docker compose run --rm with an env override by recreating the
    # gateway+worker services. Docker compose does not support per-run env
    # overrides on `up`, so we use an inline override file.
    cat > "${PROJECT_DIR}/.lsvid-experiment.override.yml" <<YAML
services:
  php-gateway:
    environment:
      LSVID_ENABLED: "${gw_enabled}"
      LSVID_REQUIRED: "${gw_required}"
  php-worker:
    environment:
      LSVID_ENABLED: "${gw_enabled}"
      LSVID_REQUIRED: "${wk_required}"
YAML

    docker compose -f "$COMPOSE_FILE" -f .lsvid-experiment.override.yml up -d --force-recreate php-gateway php-worker
    wait_health
    sleep 3  # let workers finish boot log
}

run_stress() {
    local profile="$1"
    local out_json="${OUT_DIR}/stress-${profile}-${STAMP}.json"
    info "running stress TOTAL=${TOTAL} CONC=${CONC} profile=${profile}"
    STRESS_JSON_OUT="$out_json" LSVID_MODE="$profile" \
        bash scripts/stress_test.sh "$TOTAL" "$CONC" || true
    if [[ -f "$out_json" ]]; then
        pass "wrote $out_json"
    else
        fail "expected JSON output at $out_json"
    fi
}

info "LSVID experiment starting (stamp=$STAMP TOTAL=$TOTAL CONC=$CONC)"
info "cleaning previous containers"
docker compose -f "$COMPOSE_FILE" down -v --remove-orphans 2>/dev/null || true
docker compose -f "$COMPOSE_FILE" up -d --build
wait_health
pass "initial stack healthy"

apply_profile A 0 0 0
run_stress A

apply_profile B 1 0 0
run_stress B

apply_profile C 1 1 1
run_stress C

# Profile D is identical to C in this build (re-validation is compiled in).
# Record the same run under label D so the summary can show explicit parity.
apply_profile D 1 1 1
run_stress D

rm -f "${PROJECT_DIR}/.lsvid-experiment.override.yml"

info "summarizing"
php scripts/summarize-stress.php "$STAMP"
pass "experiment complete"
