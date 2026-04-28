#!/usr/bin/env bash
# ============================================================================
# Stage 2 — HTTP ingress smoke test.
#
# Gateway's /api/orders builds its own canonical envelope from a flat JSON
# body, so forged LSVIDs cannot be injected via HTTP — those attacks go
# through stage3 (direct AMQP publish). Stage2 validates that the ingress
# itself correctly accepts well-formed requests and rejects malformed ones.
#
# Output: $OUT_DIR/http-attacks.json
# ============================================================================
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$PROJECT_DIR"

OUT_DIR="${OUT_DIR:-${PROJECT_DIR}/artifacts/security-adhoc}"
PROFILE="${PROFILE:-D-full-zt}"
GATEWAY_URL="${GATEWAY_URL:-http://127.0.0.1:8080/api/orders}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1:8080/api/health}"

mkdir -p "$OUT_DIR"
OUT_FILE="${OUT_DIR}/http-attacks.json"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

echo "[stage2] profile=${PROFILE} target=${GATEWAY_URL} → ${OUT_FILE}"

wait_health() {
    local timeout=30 elapsed=0 code
    while (( elapsed < timeout )); do
        code="$(curl -s -o /dev/null -w '%{http_code}' "$HEALTH_URL" 2>/dev/null || echo 000)"
        [[ "$code" == "200" ]] && return 0
        sleep 1; elapsed=$((elapsed+1))
    done
    echo "[stage2] WARN: gateway health timeout" >&2
    return 1
}

wait_health || true

# ── Probes ──────────────────────────────────────────────────────────────────
# H-LEGIT   legit flat order body — should be accepted with 202
# H-BADJSON malformed JSON — should be rejected with 4xx
# H-MISSING missing userKey — should be rejected with 4xx
# H-ENV     forged envelope shape (from attack_client HAPPY) — should be
#           rejected because gateway does not accept pre-built envelopes
# H-FORGED  flat order body + forged lsvid header — header ignored by gateway,
#           expect 202 (gateway mints its own L0 regardless)

CASES_JSON="${TMP_DIR}/cases.json"
echo '[]' > "$CASES_JSON"

probe() {
    local case_id="$1" desc="$2" layer="$3" body="$4" expect_status="$5"
    local start_us end_us http_code latency_us status is_expected
    start_us=$(python3 -c 'import time; print(int(time.time()*1000000))')
    http_code=$(curl -s -o "${TMP_DIR}/${case_id}.body" -w '%{http_code}' \
        -X POST "$GATEWAY_URL" -H 'Content-Type: application/json' \
        --data-binary "$body" --max-time 10 2>/dev/null || echo 000)
    end_us=$(python3 -c 'import time; print(int(time.time()*1000000))')
    latency_us=$((end_us - start_us))

    if [[ "$http_code" == "200" || "$http_code" == "202" ]]; then
        status="accepted"
    elif [[ "$http_code" == "000" ]]; then
        status="error"
    else
        status="rejected"
    fi

    if [[ "$expect_status" == "accept" && "$status" == "accepted" ]]; then
        is_expected=true
    elif [[ "$expect_status" == "reject" && "$status" == "rejected" ]]; then
        is_expected=true
    else
        is_expected=false
    fi

    jq -n \
        --arg case_id "$case_id" --arg desc "$desc" --arg layer "$layer" \
        --arg profile "$PROFILE" \
        --arg sent_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        --arg status "$status" --argjson http "$http_code" \
        --argjson latency "$latency_us" --argjson is_expected "$is_expected" \
        '{
            case_id: $case_id, category: "http-ingress", description: $desc,
            layer_expected: $layer, profile: $profile,
            attempt: {sent_at: $sent_at, request_kind: "http", payload_sha256: ""},
            outcome: {
                status: $status, is_expected: $is_expected, http_code: $http,
                amqp_ack: null, rejected_by: (if $status == "rejected" then "Gateway ingress" else null end),
                reject_reason: (if $status == "rejected" then "http_\($http)" else null end),
                exception: null, detect_latency_us: $latency
            },
            log_snippet: ""
        }'
}

LEGIT='{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}'
BADJSON='{"userKey":"1","productL'
MISSING='{"productList":[{"p_key":1,"amount":1}],"total":100}'
ENV_SHAPE=$(php -d display_errors=stderr "${PROJECT_DIR}/scripts/security-suite/lib/attack_client.php" HAPPY | jq -c '.envelope')
FORGED_LSVID=$(php -d display_errors=stderr "${PROJECT_DIR}/scripts/security-suite/lib/attack_client.php" F02 | jq -r '.lsvid_raw')
FORGED_FLAT=$(jq -nc --arg l "$FORGED_LSVID" --argjson b "$LEGIT" '$b + {lsvid: $l}')

echo "[stage2] → H-LEGIT"
REC=$(probe H-LEGIT "legitimate flat order body" "Gateway" "$LEGIT" accept)
jq ". += [${REC}]" "$CASES_JSON" > "${CASES_JSON}.new" && mv "${CASES_JSON}.new" "$CASES_JSON"

echo "[stage2] → H-BADJSON"
REC=$(probe H-BADJSON "malformed JSON body" "Gateway" "$BADJSON" reject)
jq ". += [${REC}]" "$CASES_JSON" > "${CASES_JSON}.new" && mv "${CASES_JSON}.new" "$CASES_JSON"

echo "[stage2] → H-MISSING"
REC=$(probe H-MISSING "missing userKey" "Gateway" "$MISSING" reject)
jq ". += [${REC}]" "$CASES_JSON" > "${CASES_JSON}.new" && mv "${CASES_JSON}.new" "$CASES_JSON"

echo "[stage2] → H-ENV"
REC=$(probe H-ENV "envelope-shaped body (gateway does not accept)" "Gateway" "$ENV_SHAPE" reject)
jq ". += [${REC}]" "$CASES_JSON" > "${CASES_JSON}.new" && mv "${CASES_JSON}.new" "$CASES_JSON"

echo "[stage2] → H-FORGED"
REC=$(probe H-FORGED "flat body + forged lsvid field (gateway ignores, mints its own)" "Gateway" "$FORGED_FLAT" accept)
jq ". += [${REC}]" "$CASES_JSON" > "${CASES_JSON}.new" && mv "${CASES_JSON}.new" "$CASES_JSON"

jq -n --arg stage "http" --arg profile "$PROFILE" \
    --arg gen "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --slurpfile cases "$CASES_JSON" \
    '{stage: $stage, profile: $profile, generated_at: $gen, cases: $cases[0]}' > "$OUT_FILE"

echo "[stage2] done — $(jq 'length' "$CASES_JSON") ingress probes recorded"
