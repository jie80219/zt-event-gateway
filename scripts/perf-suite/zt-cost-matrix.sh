#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════
#  Zero-Trust Cost Matrix (B1)
#
#  Sweeps 4 security profiles × 3 payload sizes × 3 concurrency
#  levels = 36 cells, to quantify the latency / throughput cost of
#  each zero-trust layer (LSVID minting, LSVID validation, mTLS).
#
#    A baseline   SPIFFE_ENABLED=0 — everything off
#    B mtls-only  SPIFFE_ENABLED=1, LSVID_ENABLED=0, MTLS=1
#    C lsvid-only SPIFFE_ENABLED=1, LSVID_ENABLED=1, LSVID_REQUIRED=1, MTLS=0
#    D full-zt    SPIFFE_ENABLED=1, LSVID_ENABLED=1, LSVID_REQUIRED=1, MTLS=1
#
#  NOTE: mTLS delta is only observable when downstream microservices
#  are running (scripts/e2e-suite/full-microservice.sh). With the
#  default docker-compose.yml (gateway + worker + RabbitMQ only),
#  profile B and D approximate A and C respectively — the matrix
#  still records the cells so the boundary is documented.
#
#  Outputs per cell:      artifacts/zt-cost-<STAMP>/cell-<P>-p<N>-c<C>.json
#  Aggregated matrix:     artifacts/zt-cost-<STAMP>/matrix-summary.json
#
#  Usage:
#    bash scripts/perf-suite/zt-cost-matrix.sh
#    TOTAL=200 PAYLOADS="1 5" CONCS="1 10" \
#      bash scripts/perf-suite/zt-cost-matrix.sh   # quick smoke run
# ══════════════════════════════════════════════════════════════════
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$PROJECT_DIR"

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
TOTAL="${TOTAL:-1000}"
PAYLOADS="${PAYLOADS:-1 5 20}"
CONCS="${CONCS:-1 10 50}"
WARMUP="${WARMUP:-20}"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT_DIR="${OUT_DIR:-artifacts/zt-cost-$STAMP}"
OVERRIDE_FILE="${PROJECT_DIR}/.zt-cost-matrix.override.yml"

mkdir -p "$OUT_DIR"

BOLD='\033[1m'; GREEN='\033[32m'; RED='\033[31m'; YELLOW='\033[33m'; DIM='\033[2m'; RESET='\033[0m'
info() { echo -e "${BOLD}[cost]${RESET} $(date +%T) $*"; }
pass() { echo -e "${GREEN}${BOLD}[ok]${RESET}  $*"; }
warn() { echo -e "${YELLOW}${BOLD}[warn]${RESET} $*"; }
fail() { echo -e "${RED}${BOLD}[fail]${RESET} $*"; exit 1; }

cleanup() { rm -f "$OVERRIDE_FILE"; }
trap cleanup EXIT

wait_health() {
    local url="${HEALTH_URL:-http://10.1.1.209:8080/api/health}"
    local deadline=$((SECONDS + 120))
    while (( SECONDS < deadline )); do
        if [[ "$(curl -s -o /dev/null -w '%{http_code}' "$url" 2>/dev/null || echo 000)" == "200" ]]; then
            return 0
        fi
        sleep 2
    done
    fail "gateway health endpoint never became ready (${url})"
}

# ── Profile application: single override file, restart gateway+worker ────────
apply_profile() {
    local profile="$1" spiffe="$2" lsvid_en="$3" lsvid_req="$4" mtls="$5"
    info "profile=${profile}  SPIFFE=${spiffe}  LSVID=${lsvid_en}  REQUIRED=${lsvid_req}  MTLS=${mtls}"

    cat > "$OVERRIDE_FILE" <<YAML
services:
  php-gateway:
    environment:
      SPIFFE_ENABLED: "${spiffe}"
      LSVID_ENABLED: "${lsvid_en}"
      LSVID_REQUIRED: "${lsvid_req}"
      SPIFFE_MTLS_ENABLED: "${mtls}"
  php-worker:
    environment:
      SPIFFE_ENABLED: "${spiffe}"
      LSVID_ENABLED: "${lsvid_en}"
      LSVID_REQUIRED: "${lsvid_req}"
      SPIFFE_MTLS_ENABLED: "${mtls}"
YAML

    docker compose -f "$COMPOSE_FILE" -f "$OVERRIDE_FILE" \
        up -d --force-recreate php-gateway php-worker >/dev/null
    wait_health
    sleep 3
}

# ── Warmup: small request burst to prime worker caches ───────────────────────
warmup() {
    local profile="$1"
    info "warmup ($WARMUP reqs) for ${profile}"
    STRESS_PRODUCT_COUNT=1 LSVID_MODE="warmup-$profile" \
        bash scripts/stress_test.sh "$WARMUP" 1 >/dev/null 2>&1 || true
}

# ── One matrix cell: payload × concurrency under a pinned profile ────────────
run_cell() {
    local profile="$1" payload="$2" conc="$3"
    local out="$OUT_DIR/cell-${profile}-p${payload}-c${conc}.json"
    info "cell profile=${profile}  payload=${payload}  concurrency=${conc}  total=${TOTAL}"

    STRESS_PRODUCT_COUNT="$payload" STRESS_JSON_OUT="$out" LSVID_MODE="$profile" \
        bash scripts/stress_test.sh "$TOTAL" "$conc" >/dev/null 2>&1 || true

    if [[ -f "$out" ]]; then
        pass "wrote $(basename "$out")"
    else
        warn "no JSON produced for ${profile}/p${payload}/c${conc} — cell skipped"
    fi
}

# ── Matrix driver ────────────────────────────────────────────────────────────
info "zt-cost-matrix starting (stamp=$STAMP total=$TOTAL payloads='$PAYLOADS' concs='$CONCS')"

# Profile order is deliberate: cheapest → most expensive, so an early abort
# still gives a usable cost ordering.
# Columns: label spiffe lsvid_en lsvid_req mtls
PROFILES=(
    "A-baseline  0 0 0 0"
    "B-mtls      1 0 0 1"
    "C-lsvid     1 1 1 0"
    "D-full-zt   1 1 1 1"
)

for row in "${PROFILES[@]}"; do
    # shellcheck disable=SC2206
    fields=($row)
    profile="${fields[0]}"
    apply_profile "$profile" "${fields[1]}" "${fields[2]}" "${fields[3]}" "${fields[4]}"
    warmup "$profile"
    for payload in $PAYLOADS; do
        for conc in $CONCS; do
            run_cell "$profile" "$payload" "$conc"
        done
    done
done

# ── Aggregate every cell into a single matrix-summary.json ───────────────────
info "aggregating matrix summary"
python3 - "$OUT_DIR" <<'PY'
import json, sys, pathlib, statistics

root = pathlib.Path(sys.argv[1])
cells = sorted(root.glob("cell-*.json"))
matrix = []
for p in cells:
    try:
        d = json.loads(p.read_text())
    except Exception as e:
        print(f"skip {p.name}: {e}", file=sys.stderr)
        continue
    lat = d.get("latency_sec", {})
    matrix.append({
        "cell":           p.stem,
        "profile":        d.get("lsvid_mode"),
        "product_count":  d.get("product_count"),
        "concurrency":    d.get("concurrency"),
        "total_requests": d.get("total_requests"),
        "success":        d.get("success"),
        "failed":         d.get("failed"),
        "rps":            d.get("rate_per_sec"),
        "p50_ms":         round(lat.get("p50", 0) * 1000, 3),
        "p95_ms":         round(lat.get("p95", 0) * 1000, 3),
        "p99_ms":         round(lat.get("p99", 0) * 1000, 3),
        "avg_ms":         round(lat.get("avg", 0) * 1000, 3),
    })

# Baseline = A, product_count=1, concurrency=1 — use it as reference for deltas
ref = next((c for c in matrix if c["profile"] == "A-baseline"
            and c["product_count"] == 1 and c["concurrency"] == 1), None)

if ref:
    for c in matrix:
        if ref["p50_ms"] > 0:
            c["delta_p50_pct_vs_baseline"] = round(
                (c["p50_ms"] - ref["p50_ms"]) / ref["p50_ms"] * 100, 2
            )
        if ref["rps"] and ref["rps"] > 0:
            c["delta_rps_pct_vs_baseline"] = round(
                (c["rps"] - ref["rps"]) / ref["rps"] * 100, 2
            )

out = {
    "generated_at":   __import__("datetime").datetime.now().isoformat(),
    "cell_count":     len(matrix),
    "reference_cell": ref["cell"] if ref else None,
    "cells":          matrix,
}
(root / "matrix-summary.json").write_text(json.dumps(out, indent=2) + "\n")
print(f"wrote matrix-summary.json ({len(matrix)} cells)")
PY

# ── Human-readable table ─────────────────────────────────────────────────────
info "matrix summary"
python3 - "$OUT_DIR/matrix-summary.json" <<'PY'
import json, sys
d = json.loads(open(sys.argv[1]).read())
print(f"\n{'profile':<12} {'pld':>4} {'conc':>5} {'p50_ms':>8} {'p95_ms':>8} {'p99_ms':>8} {'rps':>8} {'Δp50%':>8}")
print("-" * 72)
for c in d["cells"]:
    print(f"{c['profile']:<12} {c['product_count']:>4} {c['concurrency']:>5} "
          f"{c['p50_ms']:>8.2f} {c['p95_ms']:>8.2f} {c['p99_ms']:>8.2f} "
          f"{(c['rps'] or 0):>8.1f} {c.get('delta_p50_pct_vs_baseline', ''):>8}")
PY

pass "zt-cost-matrix complete → $OUT_DIR"
