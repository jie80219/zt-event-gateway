#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════
#  3-Arm Experiment Driver — SPIFFE vs Keycloak vs Baseline
#
#  Triggers the three experiments (latency, security, storage) for a
#  single arm. Designed to be branch-agnostic: the caller sets the
#  branch (feat/spiffe for A/D, feat/keycloak for F) and the
#  associated docker compose stack before invoking.
#
#  Usage:
#    EXP_ARM=A bash scripts/run-3arm-experiment.sh                 # all 3
#    EXP_ARM=D bash scripts/run-3arm-experiment.sh --only=lat
#    EXP_ARM=F bash scripts/run-3arm-experiment.sh --only=sec
#    EXP_ARM=D bash scripts/run-3arm-experiment.sh --only=storage
#
#  Env knobs (forwarded to underlying harnesses):
#    EXP_ARM         A | D | F                  (required)
#    EXP_TOTAL       requests per cell          (default: 1000; 50 for smoke)
#    EXP_PAYLOADS    payload sweep              (default: "1 5 20")
#    EXP_CONCS       concurrency sweep          (default: "1 10 50")
#    EXP_WARMUP      warmup requests per cell   (default: 20)
#    SEC_PROFILES    forwarded to security-suite (overrides arm default)
#    STORAGE_DIM     storage.sh --dim value     (default: all)
#
#  Output:
#    artifacts/3arm-${EXP_ARM}-${STAMP}/
#      ├── lat/        (zt-cost-matrix per-cell + summary)
#      ├── sec/        (security-suite per-stage + aggregate)
#      ├── storage/    (static / wire / perhop / cache JSON)
#      └── meta.json   (arm label, branch, env vars, timestamps)
# ══════════════════════════════════════════════════════════════════
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

EXP_ARM="${EXP_ARM:-}"
ONLY=""
for arg in "$@"; do
    case "$arg" in
        --only=*) ONLY="${arg#--only=}" ;;
        --help|-h)
            sed -n '2,30p' "$0"
            exit 0
            ;;
        *) echo "unknown arg: $arg" >&2; exit 2 ;;
    esac
done

if [[ -z "$EXP_ARM" ]]; then
    echo "ERROR: EXP_ARM must be set to A, D, or F" >&2
    exit 2
fi

PROFILE_FILE="$PROJECT_DIR/scripts/perf-suite/profiles/${EXP_ARM}.env"
if [[ ! -f "$PROFILE_FILE" ]]; then
    echo "ERROR: profile file not found: $PROFILE_FILE" >&2
    exit 2
fi
# shellcheck disable=SC1090
source "$PROFILE_FILE"

# ── Branch sanity check ─────────────────────────────────────────────────────
CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
if [[ "$CURRENT_BRANCH" != "$ARM_BRANCH" ]]; then
    echo "WARNING: current branch '$CURRENT_BRANCH' differs from arm's expected '$ARM_BRANCH'" >&2
    echo "         (continuing — set EXP_FORCE=1 to silence this; abort with Ctrl-C)" >&2
    [[ "${EXP_FORCE:-0}" == "1" ]] || sleep 3
fi

STAMP="$(date +%Y%m%d-%H%M%S)"
OUT_DIR="${PROJECT_DIR}/artifacts/3arm-${EXP_ARM}-${STAMP}"
mkdir -p "$OUT_DIR/lat" "$OUT_DIR/sec" "$OUT_DIR/storage"

BOLD='\033[1m'; GREEN='\033[32m'; YELLOW='\033[33m'; CYAN='\033[36m'; RESET='\033[0m'
log()  { echo -e "${BOLD}[3arm:${EXP_ARM}]${RESET} $(date +%T) $*"; }
sect() { echo -e "\n${CYAN}${BOLD}══ $* ══${RESET}"; }
ok()   { echo -e "${GREEN}${BOLD}[ok]${RESET}  $*"; }
warn() { echo -e "${YELLOW}${BOLD}[warn]${RESET} $*"; }

# ── Defaults forwarded to underlying harnesses ─────────────────────────────
export TOTAL="${EXP_TOTAL:-1000}"
export PAYLOADS="${EXP_PAYLOADS:-1 5 20}"
export CONCS="${EXP_CONCS:-1 10 50}"
export WARMUP="${EXP_WARMUP:-20}"

run_lat() {
    sect "experiment 1 — latency (arm $EXP_ARM)"
    local lat_out="$OUT_DIR/lat"
    # zt-cost-matrix sweeps multiple profiles — we restrict it to this arm's
    # ZT_COST_PROFILE via PROFILES env (matrix's PROFILES expects label, but
    # the script hard-codes the array. We use OUT_DIR to redirect output and
    # post-filter the cells.). To keep this lean we just call the matrix and
    # symlink the relevant cells into $lat_out.
    local matrix_out="${PROJECT_DIR}/artifacts/zt-cost-${STAMP}-${EXP_ARM}"
    OUT_DIR="$matrix_out" bash "$PROJECT_DIR/scripts/perf-suite/zt-cost-matrix.sh" \
        || warn "zt-cost-matrix exited non-zero"
    if [[ -d "$matrix_out" ]]; then
        # Copy only cells matching this arm's profile label
        for f in "$matrix_out"/cell-*.json; do
            [[ -f "$f" ]] || continue
            if [[ "$(basename "$f")" == cell-${ZT_COST_PROFILE}-* ]]; then
                cp "$f" "$lat_out/"
            fi
        done
        [[ -f "$matrix_out/matrix-summary.json" ]] && cp "$matrix_out/matrix-summary.json" "$lat_out/"
    fi
    ok "latency artifacts → $lat_out"
}

run_sec() {
    sect "experiment 2 — security (arm $EXP_ARM)"
    # security-suite's run-security-suite.sh handles its own STAMP / OUT_DIR.
    # We point SEC_PROFILES at this arm's label and copy the result back.
    SEC_PROFILES="${SEC_PROFILES:-$SEC_PROFILE}" \
        bash "$PROJECT_DIR/scripts/security-suite/run-security-suite.sh" \
        || warn "security-suite exited non-zero"
    # Find the most recent artifacts/security-* and pull the profile dir
    local latest
    latest="$(ls -1dt "$PROJECT_DIR"/artifacts/security-* 2>/dev/null | head -1 || true)"
    if [[ -n "$latest" && -d "$latest/$SEC_PROFILE" ]]; then
        cp -R "$latest/$SEC_PROFILE" "$OUT_DIR/sec/"
        [[ -f "$latest/security-summary.json" ]] && cp "$latest/security-summary.json" "$OUT_DIR/sec/"
        ok "security artifacts → $OUT_DIR/sec"
    else
        warn "could not locate security-suite output for profile $SEC_PROFILE"
    fi
}

run_storage() {
    sect "experiment 3 — storage (arm $EXP_ARM)"
    local dim="${STORAGE_DIM:-all}"
    EXP_ARM="$EXP_ARM" OUT_DIR="$OUT_DIR/storage" \
        bash "$PROJECT_DIR/scripts/perf-suite/storage.sh" --dim="$dim" \
        || warn "storage.sh exited non-zero"
    ok "storage artifacts → $OUT_DIR/storage"
}

# ── Meta sidecar ────────────────────────────────────────────────────────────
write_meta() {
    python3 - "$OUT_DIR/meta.json" <<PY
import json, os, datetime
data = {
    "arm":         "$EXP_ARM",
    "arm_label":   "$ARM_LABEL",
    "arm_desc":    "$ARM_DESC",
    "branch_expected": "$ARM_BRANCH",
    "branch_actual":   "$CURRENT_BRANCH",
    "stamp":       "$STAMP",
    "started_at":  datetime.datetime.utcnow().isoformat() + "Z",
    "experiments": "${ONLY:-all}",
    "total":         int(os.environ.get("TOTAL", 0)),
    "payloads":      os.environ.get("PAYLOADS", ""),
    "concurrencies": os.environ.get("CONCS", ""),
    "toggles": {
        "SPIFFE_ENABLED":      "$SPIFFE_ENABLED",
        "LSVID_ENABLED":       "$LSVID_ENABLED",
        "LSVID_REQUIRED":      "$LSVID_REQUIRED",
        "SPIFFE_MTLS_ENABLED": "$SPIFFE_MTLS_ENABLED",
        "KEYCLOAK_ENABLED":    "$KEYCLOAK_ENABLED",
        "KEYCLOAK_REQUIRED":   "$KEYCLOAK_REQUIRED",
    },
}
import sys
with open(sys.argv[1], "w") as f:
    json.dump(data, f, indent=2)
PY
}

write_meta
log "arm=$EXP_ARM branch=$CURRENT_BRANCH out=$OUT_DIR experiments=${ONLY:-all}"

case "${ONLY:-all}" in
    lat)     run_lat ;;
    sec)     run_sec ;;
    storage) run_storage ;;
    all|"")  run_lat; run_sec; run_storage ;;
    *) echo "unknown --only value: $ONLY (use lat|sec|storage)" >&2; exit 2 ;;
esac

ok "3-arm run complete: $OUT_DIR"
echo "Next step (after running all 3 arms): python3 scripts/aggregate-3arm.py --in artifacts/ --out artifacts/3arm-final-${STAMP}/"
