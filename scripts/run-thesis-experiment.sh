#!/usr/bin/env bash
# ============================================================================
# Thesis Experiment Runner
#
# Orchestrates the full four-dimension measurement pipeline for the thesis
# evaluation chapter (docs/thesis/ch5-evaluation.md):
#
#   1. Stage 1-5b: latency, LSVID size (synthesized + real-wire), envelope,
#      Saga breakdown, micro-benchmarks     → artifacts/experiment-<STAMP>/
#   2. Security suite A/B/C/D × stages 1-4  → artifacts/security-<STAMP>/
#   3. Aggregator                            → docs/data/thesis-experiment-<STAMP>.json
#                                            → docs/data/thesis-experiment-latest.json
#
# Usage:
#   bash scripts/run-thesis-experiment.sh
#   bash scripts/run-thesis-experiment.sh --micro-only       # skip Docker stages
#   bash scripts/run-thesis-experiment.sh --skip-security    # skip security suite
#   bash scripts/run-thesis-experiment.sh --skip-collect     # reuse last artifacts
#
# Env vars forwarded to underlying scripts:
#   EXP_STRESS_TOTAL  EXP_STRESS_CONC  EXP_LSVID_ITER  EXP_SAGA_SAMPLES
#   SEC_PROFILES      SEC_SKIP_*       COMPOSE_PROFILES
# ============================================================================
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

MICRO_ONLY=0
SKIP_SECURITY=0
SKIP_COLLECT=0
for arg in "$@"; do
    case "$arg" in
        --micro-only)    MICRO_ONLY=1 ;;
        --skip-security) SKIP_SECURITY=1 ;;
        --skip-collect)  SKIP_COLLECT=1 ;;
        -h|--help)
            grep -E '^# ' "$0" | sed 's/^# //'; exit 0 ;;
        *)
            echo "unknown arg: $arg" >&2; exit 2 ;;
    esac
done

BOLD='\033[1m'; GREEN='\033[32m'; RED='\033[31m'; CYAN='\033[36m'; RESET='\033[0m'
log()     { echo -e "${BOLD}[thesis]${RESET} $(date +%T) $*"; }
section() { echo -e "\n${CYAN}${BOLD}══ $* ══${RESET}"; }

export COMPOSE_PROFILES="${COMPOSE_PROFILES:-zt}"
# Enable real-wire L2 capture in the Order service filter during this run.
export LSVID_CAPTURE_DEBUG=1

section "Thesis Experiment Runner"
log "COMPOSE_PROFILES=$COMPOSE_PROFILES MICRO_ONLY=$MICRO_ONLY SKIP_SECURITY=$SKIP_SECURITY SKIP_COLLECT=$SKIP_COLLECT"

# Pre-flight: detect whether Order_service is running (required for L2 capture
# in Stage 5b). Downstream services live under Services/*/docker-compose.yml
# and are started separately from the main docker-compose.
if [[ "$MICRO_ONLY" == "0" ]]; then
    if docker ps --format '{{.Names}}' 2>/dev/null | grep -qE 'order-service'; then
        log "[ok] order-service container detected — L2 real-wire capture available"
        order_env="$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' \
            "$(docker ps --format '{{.Names}}' | grep -E 'order-service' | head -1)" 2>/dev/null || true)"
        if ! grep -q '^LSVID_CAPTURE_DEBUG=1' <<<"$order_env"; then
            log "[warn] order-service not running with LSVID_CAPTURE_DEBUG=1 — L2 capture will be empty."
            log "[warn] restart with: cd Services/Order_service && LSVID_CAPTURE_DEBUG=1 docker compose up -d --force-recreate order-service"
        fi
    else
        log "[warn] order-service container NOT running — Stage 5b will skip L2 capture."
        log "[warn] start downstream services from Services/*/docker-compose.yml if you need full chain measurement."
    fi
fi

EXPERIMENT_DIR=""
SECURITY_DIR=""

if [[ "$SKIP_COLLECT" == "0" ]]; then
    section "Step 1/3 — collect-experiment-data.sh"
    if [[ "$MICRO_ONLY" == "1" ]]; then
        bash scripts/collect-experiment-data.sh --micro-only
    else
        bash scripts/collect-experiment-data.sh
    fi
    EXPERIMENT_DIR="$(ls -td "$PROJECT_DIR"/artifacts/experiment-* 2>/dev/null | head -1 || true)"
    log "experiment artifacts: ${EXPERIMENT_DIR:-<none>}"
else
    log "[skip-collect] reusing latest artifacts/experiment-*"
    EXPERIMENT_DIR="$(ls -td "$PROJECT_DIR"/artifacts/experiment-* 2>/dev/null | head -1 || true)"
fi

if [[ "$SKIP_SECURITY" == "0" && "$MICRO_ONLY" == "0" ]]; then
    section "Step 2/3 — run-security-suite.sh"
    bash scripts/security-suite/run-security-suite.sh || log "[warn] security suite reported errors (continuing)"
    SECURITY_DIR="$(ls -td "$PROJECT_DIR"/artifacts/security-* 2>/dev/null | head -1 || true)"
    log "security artifacts: ${SECURITY_DIR:-<none>}"
else
    log "[skip-security] skipping security suite"
    SECURITY_DIR="$(ls -td "$PROJECT_DIR"/artifacts/security-* 2>/dev/null | head -1 || true)"
fi

section "Step 3/3 — aggregate-thesis-data.php"
EXPERIMENT_DIR="$EXPERIMENT_DIR" SECURITY_DIR="$SECURITY_DIR" \
    php scripts/aggregate-thesis-data.php

section "Done"
log "latest thesis data: docs/data/thesis-experiment-latest.json"
log "thesis chapter     : docs/thesis/ch5-evaluation.md"
