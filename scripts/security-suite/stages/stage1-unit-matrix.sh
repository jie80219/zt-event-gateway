#!/usr/bin/env bash
# ============================================================================
# Stage 1 — Unit-level attack matrix.
#
# Runs each attack case from attack_client.php against an in-process
# LSVIDValidator (no Docker, no network) and records the outcome.
#
# Output: $OUT_DIR/unit-results.json
# ============================================================================
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/../../.." && pwd)"
OUT_DIR="${OUT_DIR:-${PROJECT_DIR}/artifacts/security-adhoc}"
PROFILE="${PROFILE:-D-full-zt}"

mkdir -p "$OUT_DIR"
OUT_FILE="${OUT_DIR}/unit-results.json"

echo "[stage1] profile=${PROFILE} → ${OUT_FILE}"

php -d display_errors=stderr "${PROJECT_DIR}/scripts/security-suite/lib/run_unit_matrix.php" \
    --profile "${PROFILE}" \
    --out "${OUT_FILE}"

echo "[stage1] done — $(grep -c '"case_id"' "${OUT_FILE}" || echo 0) cases recorded"
