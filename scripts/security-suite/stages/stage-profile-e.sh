#!/usr/bin/env bash
# ============================================================================
# Stage — Profile E (OAuth 2.0 Bearer + Static JWT) cross-architecture probe.
#
# Replaces stages 1-3 for Profile E. Walks the same case_id matrix used by
# the main profiles but maps each case to its JWT-Bearer equivalent via
# attack_client_profile_e.php. Outputs one unified JSON consumed by the
# plot_security.py D-vs-E heatmap.
#
# Why this isn't just a stage2 variant: for Profile E, HTTP is the only
# meaningful defensive surface. stage1 (unit LSVIDValidator) is irrelevant,
# stage3 (direct AMQP inject bypassing gateway) expects accepted_violation by
# design. This script collapses all three into one pass tailored to the
# bearer-JWT threat surface.
#
# Output: $OUT_DIR/profile-e-attacks.json
# ============================================================================
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$PROJECT_DIR"

OUT_DIR="${OUT_DIR:-${PROJECT_DIR}/artifacts/security-adhoc}"
PROFILE="${PROFILE:-E-oauth2-bearer}"
GATEWAY_URL="${GATEWAY_URL:-http://127.0.0.1:8080/api/orders}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1:8080/api/health}"

mkdir -p "$OUT_DIR"
OUT_FILE="${OUT_DIR}/profile-e-attacks.json"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

# Load JWT key/iss/aud so attack_client_profile_e.php can mint valid tokens.
# shellcheck disable=SC1091
source "${PROJECT_DIR}/scripts/security-suite/profiles/E-oauth2-bearer.env"
export PROFILE_E_JWT_HS256_KEY PROFILE_E_JWT_ISSUER PROFILE_E_JWT_AUDIENCE

echo "[stage-E] profile=${PROFILE} target=${GATEWAY_URL}"

# ── Wait for jwt-gateway health ─────────────────────────────────────────────
elapsed=0
while (( elapsed < 30 )); do
    code=$(curl -s -o /dev/null -w '%{http_code}' "$HEALTH_URL" 2>/dev/null || echo 000)
    [[ "$code" == "200" ]] && break
    sleep 1
    elapsed=$((elapsed+1))
done
if (( elapsed >= 30 )); then
    echo "[stage-E] WARN: jwt-gateway health timeout" >&2
fi

CASES=(F01 F02 F03 F04 T01 T02 T03 C01 C02 C03 E01 E02 E03 R01 R02 M01 M02 M03 Q01 Q02 S01 S02 HAPPY D01 D02 D03 D04 D05)

CASES_JSON="${TMP_DIR}/cases.json"
echo '[]' > "$CASES_JSON"

ATTACK_CLIENT="${PROJECT_DIR}/scripts/security-suite/lib/attack_client_profile_e.php"

# ── Probe a single JWT-Bearer attack against jwt-gateway ────────────────────
# Prints one JSON object (matching security-summary schema) to stdout.
probe_http() {
    local case_id="$1" bearer="$2" body="$3" category="$4" description="$5" expect="$6"
    local start_us end_us latency_us http_code status is_expected

    start_us=$(python3 -c 'import time; print(int(time.time()*1000000))')
    if [[ -n "$bearer" ]]; then
        http_code=$(curl -s -o "${TMP_DIR}/${case_id}.body" -w '%{http_code}' \
            -X POST "$GATEWAY_URL" \
            -H "Authorization: Bearer ${bearer}" \
            -H 'Content-Type: application/json' \
            --data-binary "$body" --max-time 10 2>/dev/null || echo 000)
    else
        http_code=$(curl -s -o "${TMP_DIR}/${case_id}.body" -w '%{http_code}' \
            -X POST "$GATEWAY_URL" \
            -H 'Content-Type: application/json' \
            --data-binary "$body" --max-time 10 2>/dev/null || echo 000)
    fi
    end_us=$(python3 -c 'import time; print(int(time.time()*1000000))')
    latency_us=$((end_us - start_us))

    if [[ "$http_code" == "200" || "$http_code" == "202" ]]; then
        status="accepted"
    elif [[ "$http_code" == "000" ]]; then
        status="error"
    else
        status="rejected"
    fi

    # Compare outcome to expectation (see attack_client_profile_e.php expect_status).
    case "$expect" in
        accept)
            is_expected=$([[ "$status" == "accepted" ]] && echo true || echo false) ;;
        reject)
            is_expected=$([[ "$status" == "rejected" ]] && echo true || echo false) ;;
        accepted_violation)
            # Measurable structural gap: we EXPECT the gateway to (incorrectly)
            # accept this case — is_expected=false records it as a coverage miss.
            is_expected=$([[ "$status" == "accepted" ]] && echo false || echo true) ;;
        *)
            is_expected=false ;;
    esac

    local reject_reason=""
    if [[ "$status" == "rejected" ]]; then
        reject_reason=$(jq -r '.reason // "http_error"' "${TMP_DIR}/${case_id}.body" 2>/dev/null || echo "http_${http_code}")
    fi

    jq -n \
        --arg case_id "$case_id" --arg cat "$category" --arg desc "$description" \
        --arg profile "$PROFILE" --arg status "$status" \
        --arg sent_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        --arg reject_reason "$reject_reason" \
        --argjson http "$http_code" --argjson latency "$latency_us" \
        --argjson is_expected "$is_expected" \
        '{
            case_id: $case_id, category: $cat, description: $desc,
            layer_expected: "jwt-gateway", profile: $profile,
            attempt: {sent_at: $sent_at, request_kind: "http", payload_sha256: ""},
            outcome: {
                status: $status, is_expected: $is_expected, http_code: $http,
                amqp_ack: null,
                rejected_by: (if $status == "rejected" then "jwt-gateway" else null end),
                reject_reason: (if $reject_reason == "" then null else $reject_reason end),
                exception: null, detect_latency_us: $latency
            },
            log_snippet: ""
        }'
}

# ── Record a structural-gap case without making an HTTP call ────────────────
record_structural_gap() {
    local case_id="$1" category="$2" description="$3" reason="$4"
    jq -n \
        --arg case_id "$case_id" --arg cat "$category" --arg desc "$description" \
        --arg profile "$PROFILE" --arg reason "$reason" \
        --arg sent_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        '{
            case_id: $case_id, category: $cat, description: $desc,
            layer_expected: "jwt-gateway", profile: $profile,
            attempt: {sent_at: $sent_at, request_kind: "n/a", payload_sha256: ""},
            outcome: {
                status: "accepted", is_expected: false,
                http_code: null, amqp_ack: null,
                rejected_by: null, reject_reason: "structural_gap",
                exception: null, detect_latency_us: 0
            },
            log_snippet: $reason
        }'
}

# ── Walk the 28-case matrix ─────────────────────────────────────────────────
for case_id in "${CASES[@]}"; do
    echo "[stage-E] → $case_id"
    if ! built=$(php -d display_errors=stderr "$ATTACK_CLIENT" "$case_id" 2>"${TMP_DIR}/${case_id}.err"); then
        echo "[stage-E] ERR: attack client failed for $case_id" >&2
        cat "${TMP_DIR}/${case_id}.err" >&2
        continue
    fi
    applicability=$(echo "$built" | jq -r '.applicability')
    expect_status=$(echo "$built" | jq -r '.expect_status')
    description=$(echo "$built"  | jq -r '.description')
    category=$(echo "$built"     | jq -r '.category')
    body=$(echo "$built"         | jq -c '.body')
    bearer=$(echo "$built"       | jq -r '.bearer // ""')
    reason=$(echo "$built"       | jq -r '.extra.reason // .extra.mutation // ""')

    if [[ "$applicability" == "structural_gap" ]]; then
        REC=$(record_structural_gap "$case_id" "$category" "$description" "$reason")
    elif [[ "$applicability" == "applicable" && "$expect_status" == "accepted_violation" && -z "$bearer" ]]; then
        # AMQP-inject-class case (Q01/Q02) — Profile E worker has no auth, so
        # we don't actually send HTTP; instead mark as accepted_violation. The
        # ingress defence can't stop this in principle.
        REC=$(record_structural_gap "$case_id" "$category" "$description" "Profile E worker accepts unauthenticated AMQP")
    else
        REC=$(probe_http "$case_id" "$bearer" "$body" "$category" "$description" "$expect_status")
        # Replay: R01 sends twice sequentially. Count the SECOND send's outcome.
        if [[ "$case_id" == "R01" || "$case_id" == "R02" ]]; then
            REC=$(probe_http "$case_id" "$bearer" "$body" "$category" "$description" "$expect_status")
        fi
    fi

    jq ". += [$REC]" "$CASES_JSON" > "${CASES_JSON}.new" && mv "${CASES_JSON}.new" "$CASES_JSON"
done

jq -n \
    --arg stage "profile-e" --arg profile "$PROFILE" \
    --arg gen "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --slurpfile cases "$CASES_JSON" \
    '{stage: $stage, profile: $profile, generated_at: $gen, cases: $cases[0]}' > "$OUT_FILE"

count=$(jq 'length' "$CASES_JSON")
echo "[stage-E] done — $count cases recorded → $OUT_FILE"
