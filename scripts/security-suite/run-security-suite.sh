#!/usr/bin/env bash
# ============================================================================
# SPIFFE/SPIRE Security Suite Driver
#
# Runs the 4-stage security evaluation (unit / http / amqp / rotation) across
# the 4 standard profiles (A/B/C/D) and aggregates every case outcome into a
# single security-summary.json for the plot_security.py renderer.
#
# Usage:
#   bash scripts/security-suite/run-security-suite.sh
#   SEC_PROFILES="D-full-zt" bash scripts/security-suite/run-security-suite.sh  # single profile
#   SEC_SKIP_HTTP=1 bash scripts/security-suite/run-security-suite.sh            # skip a stage
#
# Env:
#   SEC_PROFILES     Space-separated profile list (default: all 4)
#   SEC_SKIP_UNIT    Set to 1 to skip stage1
#   SEC_SKIP_HTTP    Set to 1 to skip stage2
#   SEC_SKIP_AMQP    Set to 1 to skip stage3
#   SEC_SKIP_ROTATE  Set to 1 to skip stage4
#   COMPOSE_FILE     default: docker-compose.yml
# ============================================================================
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$PROJECT_DIR"

export COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
export COMPOSE_PROFILES="${COMPOSE_PROFILES:-zt}"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT_DIR="${PROJECT_DIR}/artifacts/security-${STAMP}"
mkdir -p "$OUT_DIR"
export OUT_DIR

PROFILES=(${SEC_PROFILES:-A-baseline B-mtls-only C-lsvid-only D-full-zt})

BOLD='\033[1m'; GREEN='\033[32m'; RED='\033[31m'; CYAN='\033[36m'; YELLOW='\033[33m'; RESET='\033[0m'
log()     { echo -e "${BOLD}[sec]${RESET} $(date +%T) $*"; }
section() { echo -e "\n${CYAN}${BOLD}══ $* ══${RESET}"; }
warn()    { echo -e "${YELLOW}[warn]${RESET} $*"; }

# ── Profile-to-env mapping ──────────────────────────────────────────────────
apply_profile() {
    local p="$1"
    case "$p" in
        A-baseline)   export SPIFFE_ENABLED=0 LSVID_ENABLED=0 LSVID_REQUIRED=0 SPIFFE_MTLS_ENABLED=0 ;;
        B-mtls-only)  export SPIFFE_ENABLED=1 LSVID_ENABLED=0 LSVID_REQUIRED=0 SPIFFE_MTLS_ENABLED=1 ;;
        C-lsvid-only) export SPIFFE_ENABLED=1 LSVID_ENABLED=1 LSVID_REQUIRED=1 SPIFFE_MTLS_ENABLED=0 ;;
        D-full-zt)    export SPIFFE_ENABLED=1 LSVID_ENABLED=1 LSVID_REQUIRED=1 SPIFFE_MTLS_ENABLED=1 ;;
        *) echo "unknown profile: $p" >&2; return 1 ;;
    esac
    log "profile=$p SPIFFE_ENABLED=$SPIFFE_ENABLED LSVID_ENABLED=$LSVID_ENABLED LSVID_REQUIRED=$LSVID_REQUIRED SPIFFE_MTLS_ENABLED=$SPIFFE_MTLS_ENABLED"
}

restart_stack_for_profile() {
    # Only bother restarting the stack when env actually changes app behavior.
    # For unit-only stages we can skip.
    if [[ "${SEC_SKIP_DOCKER:-0}" == "1" ]]; then return 0; fi
    if ! command -v docker >/dev/null 2>&1; then
        warn "docker not available — running unit stage only"
        return 1
    fi
    log "recreating gateway + worker for profile..."
    docker compose -f "$COMPOSE_FILE" --profile zt up -d --force-recreate gateway php-worker spiffe-watcher 2>&1 | tail -5 || true
    sleep 5
}

# ── Per-profile run ─────────────────────────────────────────────────────────
echo "╔══════════════════════════════════════════════════════════════════╗"
echo "║  SPIFFE/SPIRE Security Suite                                     ║"
echo "║  Output: artifacts/security-${STAMP}/                             ║"
echo "╚══════════════════════════════════════════════════════════════════╝"

START="$(date +%s)"

for P in "${PROFILES[@]}"; do
    section "profile $P"
    apply_profile "$P"
    PROFILE_DIR="${OUT_DIR}/${P}"
    mkdir -p "$PROFILE_DIR"
    export OUT_DIR="$PROFILE_DIR" PROFILE="$P"

    restart_stack_for_profile || warn "continuing without Docker stack"

    if [[ "${SEC_SKIP_UNIT:-0}" != "1" ]]; then
        bash "${PROJECT_DIR}/scripts/security-suite/stages/stage1-unit-matrix.sh" || warn "stage1 failed"
    fi
    if [[ "${SEC_SKIP_HTTP:-0}" != "1" ]]; then
        bash "${PROJECT_DIR}/scripts/security-suite/stages/stage2-e2e-http.sh" || warn "stage2 failed"
    fi
    if [[ "${SEC_SKIP_AMQP:-0}" != "1" ]]; then
        bash "${PROJECT_DIR}/scripts/security-suite/stages/stage3-amqp-inject.sh" || warn "stage3 failed"
    fi
    if [[ "${SEC_SKIP_ROTATE:-0}" != "1" && "$P" == "D-full-zt" ]]; then
        bash "${PROJECT_DIR}/scripts/security-suite/stages/stage4-rotation-race.sh" || warn "stage4 failed"
    fi

    # Restore OUT_DIR for the next profile iteration
    export OUT_DIR="${PROJECT_DIR}/artifacts/security-${STAMP}"
done

# ── Aggregate everything into security-summary.json ─────────────────────────
section "aggregating"

python3 - "$OUT_DIR" <<'PYCODE'
import json, os, sys
from pathlib import Path

root = Path(sys.argv[1])
all_cases = []
for profile_dir in sorted(root.iterdir()):
    if not profile_dir.is_dir():
        continue
    for jf in profile_dir.glob('*.json'):
        try:
            data = json.loads(jf.read_text())
        except Exception as e:
            print(f'[agg] skip {jf}: {e}', file=sys.stderr)
            continue
        for c in data.get('cases', []):
            c.setdefault('stage', data.get('stage', 'unknown'))
            all_cases.append(c)

# Aggregate stats
by_profile = {}
by_layer = {}
by_category = {}
detect_latencies = {}
for c in all_cases:
    p = c['profile']
    o = c['outcome']
    by_profile.setdefault(p, {'total': 0, 'rejected_as_expected': 0, 'accepted_violations': 0, 'accepted_expected': 0, 'missed': 0, 'error': 0})
    by_profile[p]['total'] += 1
    status = o.get('status')
    expected = o.get('is_expected')
    if status == 'rejected' and expected:
        by_profile[p]['rejected_as_expected'] += 1
    elif status == 'accepted' and not expected:
        by_profile[p]['accepted_violations'] += 1
    elif status == 'accepted' and expected:
        by_profile[p]['accepted_expected'] += 1
    elif status == 'error':
        by_profile[p]['error'] += 1
    else:
        by_profile[p]['missed'] += 1

    if o.get('rejected_by'):
        by_layer.setdefault(o['rejected_by'], 0)
        by_layer[o['rejected_by']] += 1

    cat = c.get('category', 'other')
    lat = o.get('detect_latency_us')
    if isinstance(lat, int) and lat > 0:
        detect_latencies.setdefault(cat, []).append(lat)

summary = {
    'stamp': root.name.replace('security-', ''),
    'generated_at': __import__('datetime').datetime.utcnow().isoformat() + 'Z',
    'profiles': sorted(by_profile.keys()),
    'cases': all_cases,
    'by_profile': by_profile,
    'by_layer': by_layer,
    'detect_latency_us_by_category': {
        cat: {
            'count': len(v),
            'min':   min(v),
            'p50':   sorted(v)[len(v)//2],
            'p95':   sorted(v)[max(0, int(len(v)*0.95)-1)],
            'max':   max(v),
        } for cat, v in detect_latencies.items() if v
    },
}
out_path = root / 'security-summary.json'
out_path.write_text(json.dumps(summary, indent=2, ensure_ascii=False))
print(f'[agg] wrote {out_path} with {len(all_cases)} cases across {len(by_profile)} profiles')
PYCODE

ELAPSED=$(( $(date +%s) - START ))
section "done"
log "total time: ${ELAPSED}s"
log "artifacts: ${OUT_DIR}"
log "next: python3 scripts/security-suite/plot/plot_security.py ${OUT_DIR}"
