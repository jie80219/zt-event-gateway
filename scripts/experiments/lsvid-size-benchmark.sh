#!/usr/bin/env bash
# ============================================================================
# LSVID token-size experiment: compare envelope/token byte size when the
# LSVID layer is OFF (LSVID_ENABLED=0) vs. ON (LSVID_ENABLED=1, levels
# L0 / L1 / L2). The script is read-only — it only generates representative
# samples in-process via the LSVID test PKI.
#
# Output:
#   - On stdout: a markdown table with per-layer min/mean/p50/p95/max +
#     overhead percentage relative to the no-LSVID baseline.
#   - When --write is passed: docs/experiments/lsvid-size-comparison.md is
#     written/replaced with the same table plus run metadata.
#
# Usage:
#   bash scripts/experiments/lsvid-size-benchmark.sh [--samples=50]
#                                                    [--product-count=1]
#                                                    [--write]
# ============================================================================
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$PROJECT_DIR"

SAMPLES=50
PRODUCT_COUNT=1
WRITE_DOC=0
OUT_DOC="docs/experiments/lsvid-size-comparison.md"

for arg in "$@"; do
    case "$arg" in
        --samples=*) SAMPLES="${arg#*=}" ;;
        --product-count=*) PRODUCT_COUNT="${arg#*=}" ;;
        --write) WRITE_DOC=1 ;;
        --out=*) OUT_DOC="${arg#*=}" ;;
        *)
            echo "unknown flag: $arg" >&2
            exit 1
            ;;
    esac
done

if ! command -v php >/dev/null 2>&1; then
    echo "ERROR: php not on PATH" >&2
    exit 1
fi

TMPDIR="$(mktemp -d)"
trap 'rm -rf "$TMPDIR"' EXIT

RAW_FILE="$TMPDIR/raw.ndjson"

echo "[bench] generating ${SAMPLES} samples per variant (product_count=${PRODUCT_COUNT})..." >&2
php scripts/experiments/measure-envelope.php \
    --samples="$SAMPLES" \
    --variant=both \
    --product-count="$PRODUCT_COUNT" \
    >"$RAW_FILE" 2>/dev/null

if [[ ! -s "$RAW_FILE" ]]; then
    echo "ERROR: measure-envelope.php produced no output" >&2
    exit 2
fi

# ── Aggregate via inline PHP ────────────────────────────────────────────────
# Output is a markdown table; we let PHP do the percentile + overhead math
# instead of awk to keep precision consistent with the source samples.
SUMMARY="$(SAMPLES="$SAMPLES" PRODUCT_COUNT="$PRODUCT_COUNT" RAW_FILE="$RAW_FILE" php -n -r '
    $rows = [];
    foreach (file(getenv("RAW_FILE")) as $line) {
        $line = trim($line);
        if ($line === "") continue;
        $r = json_decode($line, true);
        if (!is_array($r) || !isset($r["layer"], $r["bytes"])) continue;
        $rows[$r["layer"]][] = (int) $r["bytes"];
    }

    $stat = function (array $vals): array {
        sort($vals);
        $n = count($vals);
        if ($n === 0) {
            return ["n" => 0, "min" => 0, "mean" => 0, "p50" => 0, "p95" => 0, "max" => 0];
        }
        $sum = array_sum($vals);
        return [
            "n"    => $n,
            "min"  => $vals[0],
            "mean" => (int) round($sum / $n),
            "p50"  => $vals[(int) floor(0.50 * ($n - 1))],
            "p95"  => $vals[(int) floor(0.95 * ($n - 1))],
            "max"  => $vals[$n - 1],
        ];
    };

    $layers = [
        "envelope_no_lsvid"  => "Envelope (LSVID off)",
        "envelope_l0"        => "Envelope w/ L0 (gw → worker)",
        "envelope_l1"        => "Envelope w/ L1 (worker → worker)",
        "lsvid_l0"           => "LSVID token L0",
        "lsvid_l1"           => "LSVID token L1",
        "lsvid_l2"           => "LSVID token L2",
        "downstream_http_l2" => "Downstream HTTP request (w/ L2)",
    ];

    $stats = [];
    foreach ($layers as $key => $_) {
        $stats[$key] = $stat($rows[$key] ?? []);
    }

    $baseline = $stats["envelope_no_lsvid"]["mean"] ?? 0;

    echo "## Sample sizes\n\n";
    echo "| Layer | n | min | mean | p50 | p95 | max | Δ vs no-LSVID |\n";
    echo "|---|---:|---:|---:|---:|---:|---:|---:|\n";

    foreach ($layers as $key => $label) {
        $s = $stats[$key];
        if ($s["n"] === 0) {
            printf("| %s | 0 | — | — | — | — | — | — |\n", $label);
            continue;
        }
        $delta = "";
        if ($baseline > 0 && in_array($key, ["envelope_l0", "envelope_l1", "downstream_http_l2"], true)) {
            $diff = $s["mean"] - $baseline;
            $pct = ($diff / $baseline) * 100;
            $delta = sprintf("+%d B (+%.1f%%)", $diff, $pct);
        } elseif (in_array($key, ["lsvid_l0", "lsvid_l1", "lsvid_l2"], true)) {
            $delta = "(token only)";
        }
        printf(
            "| %s | %d | %s | %s | %s | %s | %s | %s |\n",
            $label,
            $s["n"],
            number_format($s["min"]),
            number_format($s["mean"]),
            number_format($s["p50"]),
            number_format($s["p95"]),
            number_format($s["max"]),
            $delta,
        );
    }

    echo "\n";
    echo "**Notes**\n\n";
    echo "- All sizes are in bytes.\n";
    echo "- LSVID tokens are compact-JWS (`header.payload.signature`) with the leaf X.509 cert in the `x5c` header — the dominant cost.\n";
    echo "- L1 nests L0 inside `nested`; L2 nests L1; size grows roughly linearly.\n";
    echo "- The no-LSVID baseline carries `spiffe_id` + `spiffe_path` but no token; comparing against it shows the marginal cost of LSVID, not of SPIFFE identity.\n";
')"

# ── Emit ────────────────────────────────────────────────────────────────────
header="# LSVID Token-Size Comparison

Generated by \`scripts/experiments/lsvid-size-benchmark.sh\` on $(date -u '+%Y-%m-%d %H:%M:%S UTC').

- samples: ${SAMPLES} per variant
- productList items per envelope: ${PRODUCT_COUNT}
- PHP: $(php -n -r 'echo PHP_VERSION;' 2>/dev/null)

"

printf '%s%s\n' "$header" "$SUMMARY"

if (( WRITE_DOC == 1 )); then
    out_dir="$(dirname "$OUT_DOC")"
    mkdir -p "$out_dir"
    {
        printf '%s%s\n' "$header" "$SUMMARY"
    } >"$OUT_DOC"
    echo "[bench] wrote ${OUT_DOC}" >&2
fi
