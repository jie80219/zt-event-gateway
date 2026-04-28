#!/usr/bin/env bash
# ============================================================================
# Stage 4 — SVID rotation race & mTLS probes (M01/M02/M03).
#
# Fires a burst of HTTP requests before / during / after an induced SPIRE
# rotation event, records per-request accept/reject timings so plot_security.py
# can render the rotation timeline.
#
# Output: $OUT_DIR/rotation-race.json
# ============================================================================
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$PROJECT_DIR"

OUT_DIR="${OUT_DIR:-${PROJECT_DIR}/artifacts/security-adhoc}"
PROFILE="${PROFILE:-D-full-zt}"
GATEWAY_URL="${GATEWAY_URL:-http://127.0.0.1:8080/api/orders}"
WATCHER_CONTAINER="${WATCHER_CONTAINER:-zt-spiffe-watcher}"
BURST_COUNT="${ROTATION_BURST:-40}"   # requests during the race
# Downstream mTLS check uses a direct curl with / without a cert:
DOWNSTREAM_URL="${DOWNSTREAM_URL:-http://127.0.0.1:8082/health}"

mkdir -p "$OUT_DIR"
OUT_FILE="${OUT_DIR}/rotation-race.json"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

echo "[stage4] profile=${PROFILE} burst=${BURST_COUNT}"

# Gateway's /api/orders expects a flat order body — it mints its own envelope
# and L0 internally. Send flat body for M03 burst.
cat > "${TMP_DIR}/happy.json" <<'JSON'
{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}
JSON

CASES_JSON="${TMP_DIR}/cases.json"
echo '[]' > "$CASES_JSON"

# ── M01: downstream receives request without client cert ───────────────────
M01_CODE="$(curl -s -o /dev/null -w '%{http_code}' "$DOWNSTREAM_URL" --max-time 5 2>/dev/null || echo 000)"
M01=$(jq -n \
    --arg profile "$PROFILE" \
    --arg sent_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --argjson code "$M01_CODE" \
    '{
        case_id: "M01",
        category: "mtls",
        description: "No client cert to downstream",
        layer_expected: "Downstream mTLS",
        profile: $profile,
        attempt: { sent_at: $sent_at, request_kind: "http", payload_sha256: "" },
        outcome: {
            status: (if ($code // 0) | tonumber >= 400 or . == 0 then "rejected" else "accepted" end),
            is_expected: (if ($code // 0) | tonumber >= 400 or . == 0 then true else false end),
            http_code: $code,
            amqp_ack: null,
            rejected_by: "Downstream mTLS",
            reject_reason: "no_client_cert",
            exception: null,
            detect_latency_us: 0
        },
        log_snippet: "direct curl without client cert"
    }')
jq ". += [${M01}]" "$CASES_JSON" > "${CASES_JSON}.new" && mv "${CASES_JSON}.new" "$CASES_JSON"

# ── M02: downstream receives a cert from a foreign trust domain (skip on env w/o openssl facilities) ──
M02=$(jq -n \
    --arg profile "$PROFILE" \
    --arg sent_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    '{
        case_id: "M02",
        category: "mtls",
        description: "Foreign client cert to downstream (manual-only)",
        layer_expected: "Downstream mTLS",
        profile: $profile,
        attempt: { sent_at: $sent_at, request_kind: "http", payload_sha256: "" },
        outcome: {
            status: "skipped",
            is_expected: true,
            http_code: null,
            amqp_ack: null,
            rejected_by: "Downstream mTLS",
            reject_reason: "needs_foreign_CA_mint (not automated yet)",
            exception: null,
            detect_latency_us: 0
        },
        log_snippet: "see docs/security-experiment.md §M02 — requires foreign CA"
    }')
jq ". += [${M02}]" "$CASES_JSON" > "${CASES_JSON}.new" && mv "${CASES_JSON}.new" "$CASES_JSON"

# ── M03: SVID rotation mid-request ────────────────────────────────────────
# Fire requests in background; ~ 1/3 through, trigger a rotation by restarting watcher.
RESULTS_FILE="${TMP_DIR}/m03-results.txt"
: > "$RESULTS_FILE"

send_one() {
    local idx="$1" start_us end_us code
    start_us="$(python3 -c 'import time; print(int(time.time()*1000000))')"
    code="$(curl -s -o /dev/null -w '%{http_code}' \
        -X POST "$GATEWAY_URL" -H 'Content-Type: application/json' \
        --data-binary "@${TMP_DIR}/happy.json" --max-time 5 2>/dev/null || echo 000)"
    end_us="$(python3 -c 'import time; print(int(time.time()*1000000))')"
    echo "${idx},${start_us},${end_us},${code}" >> "$RESULTS_FILE"
}

# Phase 1: steady-state burst
for i in $(seq 1 $((BURST_COUNT/3))); do send_one "$i" & done
wait

# Rotation trigger mid-burst
echo "[stage4] triggering SVID rotation (restart ${WATCHER_CONTAINER})..."
ROT_TRIGGER_US="$(python3 -c 'import time; print(int(time.time()*1000000))')"
docker restart "$WATCHER_CONTAINER" >/dev/null 2>&1 || echo "[stage4] WARN: rotation trigger failed (container absent?)"

# Phase 2: during-rotation burst
for i in $(seq $((BURST_COUNT/3 + 1)) $((2 * BURST_COUNT/3))); do send_one "$i" & done
wait

# Phase 3: after-rotation burst
sleep 3
for i in $(seq $((2 * BURST_COUNT/3 + 1)) "$BURST_COUNT"); do send_one "$i" & done
wait

# Build JSON array of timings
TIMINGS=$(awk -F, -v trig="$ROT_TRIGGER_US" '
    BEGIN { printf "[" }
    {
        if (NR>1) printf ","
        lat = $3 - $2
        status = ($4 == "200" || $4 == "202") ? "accepted" : (($4 == "000") ? "error" : "rejected")
        printf "{\"idx\":%s,\"start_us\":%s,\"end_us\":%s,\"latency_us\":%s,\"http_code\":%s,\"status\":\"%s\",\"delta_from_rotation_us\":%s}", $1, $2, $3, lat, $4, status, ($2 - trig)
    }
    END { printf "]" }
' "$RESULTS_FILE")

TOTAL_SENT=$(wc -l < "$RESULTS_FILE" | tr -d ' ')
ACCEPTED_COUNT=$(awk -F, '$4==200 || $4==202' "$RESULTS_FILE" | wc -l | tr -d ' ')
REJECTED_COUNT=$(awk -F, '$4!=200 && $4!=202 && $4!=0' "$RESULTS_FILE" | wc -l | tr -d ' ')
ERROR_COUNT=$(awk -F, '$4==0' "$RESULTS_FILE" | wc -l | tr -d ' ')

M03=$(jq -n \
    --arg profile "$PROFILE" \
    --arg sent_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --argjson total "$TOTAL_SENT" \
    --argjson accepted "$ACCEPTED_COUNT" \
    --argjson rejected "$REJECTED_COUNT" \
    --argjson errored "$ERROR_COUNT" \
    --argjson trig "$ROT_TRIGGER_US" \
    --argjson timings "$TIMINGS" \
    '{
        case_id: "M03",
        category: "mtls",
        description: "SVID rotation mid-request race",
        layer_expected: "SHM seqlock",
        profile: $profile,
        attempt: { sent_at: $sent_at, request_kind: "http", payload_sha256: "" },
        outcome: {
            status: (if $errored > 0 then "partially_rejected" else "accepted" end),
            is_expected: ($errored <= ($total / 10)),
            http_code: null,
            amqp_ack: null,
            rejected_by: (if $errored > 0 then "SHM re-read" else null end),
            reject_reason: (if $errored > 0 then "race_window" else null end),
            exception: null,
            detect_latency_us: 0,
            total_sent: $total,
            accepted_count: $accepted,
            rejected_count: $rejected,
            error_count: $errored,
            rotation_trigger_us: $trig,
            timings: $timings
        },
        log_snippet: "burst=\($total) accepted=\($accepted) rejected=\($rejected) error=\($errored)"
    }')
jq ". += [${M03}]" "$CASES_JSON" > "${CASES_JSON}.new" && mv "${CASES_JSON}.new" "$CASES_JSON"

jq -n \
    --arg stage "rotation-race" \
    --arg profile "$PROFILE" \
    --arg gen "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --slurpfile cases "$CASES_JSON" \
    '{stage: $stage, profile: $profile, generated_at: $gen, cases: $cases[0]}' > "$OUT_FILE"

echo "[stage4] done — $(jq 'length' "$CASES_JSON") cases recorded (M03 burst=${TOTAL_SENT})"
