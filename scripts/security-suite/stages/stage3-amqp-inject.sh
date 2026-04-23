#!/usr/bin/env bash
# ============================================================================
# Stage 3 — Direct AMQP + SHM bypass attempts.
#
# Output: $OUT_DIR/amqp-attacks.json
# ============================================================================
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$PROJECT_DIR"

OUT_DIR="${OUT_DIR:-${PROJECT_DIR}/artifacts/security-adhoc}"
PROFILE="${PROFILE:-D-full-zt}"
WORKER_CONTAINER="${WORKER_CONTAINER:-zt-php-worker}"
WATCHER_CONTAINER="${WATCHER_CONTAINER:-zt-spiffe-watcher}"

mkdir -p "$OUT_DIR"
OUT_FILE="${OUT_DIR}/amqp-attacks.json"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

# Full token-layer forgery matrix via direct AMQP publish (bypasses gateway).
# Q01/Q02 retained for API compatibility; the F/T/C/E/R cases are the real
# attack set — gateway does not accept pre-built envelopes over HTTP, so the
# AMQP ingress is where fail-closed enforcement actually matters.
AMQP_CASES=(F01 F02 F03 F04 T01 T02 T03 C01 C02 C03 E01 E02 R01 Q01 Q02 S01 S02 D01 D02 D03 D04 D05)
CASES_JSON="${TMP_DIR}/cases.json"
echo '[]' > "$CASES_JSON"

echo "[stage3] profile=${PROFILE} → ${OUT_FILE}"

# Ensure worker is fully booted (listening on order_queue) before we start injecting.
wait_worker_ready() {
    local timeout=30 elapsed=0
    while (( elapsed < timeout )); do
        if docker logs --tail 20 "$WORKER_CONTAINER" 2>&1 | grep -q 'listening order_queue'; then
            return 0
        fi
        sleep 1; elapsed=$((elapsed+1))
    done
    echo "[stage3] WARN: worker not observed listening within ${timeout}s" >&2
    return 1
}
wait_worker_ready || true
# Extra buffer so the 'listening' message isn't mistaken for rejection signals.
sleep 2

worker_logs_tail() {
    docker logs --tail 30 "$WORKER_CONTAINER" 2>&1 | tr '\n' ' ' | head -c 800
}

worker_logs_since() {
    docker logs --since "$1" "$WORKER_CONTAINER" 2>&1 | tr '\n' '|' | head -c 2000
}

classify_from_log() {
    local log="$1"
    # Returns "by|reason" or empty if no reject pattern
    if [[ "$log" == *"Invalid inbound LSVID"* ]]; then
        local msg
        msg=$(grep -oE 'Invalid inbound LSVID: [^|]{0,150}' <<< "${log//|/$'\n'}" | head -1)
        echo "LSVIDValidator|${msg}"
        return 0
    fi
    if [[ "$log" == *"Untrusted SPIFFE source"* ]]; then
        echo "RequestConsumer (prefix)|trust_domain_prefix_mismatch"
        return 0
    fi
    if [[ "$log" == *"Missing request id"* || "$log" == *"Missing or invalid schema_version"* || "$log" == *"Invalid envelope type"* || "$log" == *"Missing request route"* ]]; then
        echo "CanonicalOrderRequest|envelope_schema_invalid"
        return 0
    fi
    if [[ "$log" == *"[consumer] dropped"* ]]; then
        local msg
        msg=$(grep -oE 'error=[^|]{0,150}' <<< "${log//|/$'\n'}" | head -1)
        echo "Consumer|${msg:-unknown}"
        return 0
    fi
    echo ""
}

for CASE in "${AMQP_CASES[@]}"; do
    echo "[stage3] → case ${CASE}"

    case "$CASE" in
        F01|F02|F03|F04|T01|T02|T03|C01|C02|C03|E01|E02|R01|R02|Q01|Q02|D01|D02|D03|D04|D05)
            RAW=$(PROFILE="$PROFILE" php -d display_errors=stderr "${PROJECT_DIR}/scripts/security-suite/lib/rabbit_inject.php" "$CASE" 2> "${TMP_DIR}/${CASE}.err" || true)
            if [[ -z "$RAW" ]]; then
                echo "[stage3] rabbit_inject ${CASE} empty output:"; cat "${TMP_DIR}/${CASE}.err" >&2
                continue
            fi
            echo "$RAW" > "${TMP_DIR}/${CASE}.json"

            # Poll worker logs up to 5s for a rejection pattern on the last few lines.
            LOG_SINCE=""
            CLASS=""
            for _ in 1 2 3 4 5; do
                sleep 1
                LOG_SINCE="$(docker logs --tail 15 "$WORKER_CONTAINER" 2>&1 | tr '\n' '|' | head -c 2000)"
                CLASS=$(classify_from_log "$LOG_SINCE")
                [[ -n "$CLASS" ]] && break
            done
            REJECTED_BY="${CLASS%%|*}"
            REJECT_REASON="${CLASS#*|}"
            if [[ -z "$CLASS" ]]; then
                REJECTED_BY=""
                REJECT_REASON=""
            fi

            ENRICHED=$(jq --arg by "$REJECTED_BY" --arg reason "$REJECT_REASON" --arg log "$LOG_SINCE" \
                '.outcome.rejected_by = (if $by == "" then null else $by end)
                 | .outcome.reject_reason = (if $reason == "" then null else $reason end)
                 | .outcome.status = (if $by == "" then "accepted" else "rejected" end)
                 | .outcome.is_expected = ($by != "")
                 | .log_snippet = $log' \
                "${TMP_DIR}/${CASE}.json")
            ;;

        S01)
            # SHM tamper — write garbage into the primary SVID JSON file in the watcher.
            TAMPER_RESULT="tampered"
            if docker exec "$WATCHER_CONTAINER" sh -c "echo 'NOT_VALID_JSON' > /tmp/spiffe-shared/x509/0.json" 2>/dev/null; then
                sleep 2
                if docker exec "$WATCHER_CONTAINER" grep -q 'NOT_VALID_JSON' /tmp/spiffe-shared/x509/0.json 2>/dev/null; then
                    TAMPER_RESULT="still_tampered_no_recovery"
                else
                    TAMPER_RESULT="watcher_recovered"
                fi
                # Restore by nudging the watcher
                docker restart "$WATCHER_CONTAINER" >/dev/null 2>&1 || true
            else
                TAMPER_RESULT="container_not_present"
            fi

            ENRICHED=$(jq -n \
                --arg case_id "S01" \
                --arg profile "$PROFILE" \
                --arg sent_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
                --arg result "$TAMPER_RESULT" \
                '{
                    case_id: $case_id,
                    category: "shm-tamper",
                    description: "SHM file tamper (design-limit test)",
                    layer_expected: "SpiffeTableStore",
                    profile: $profile,
                    attempt: { sent_at: $sent_at, request_kind: "amqp", payload_sha256: "" },
                    outcome: {
                        status: (if $result == "watcher_recovered" then "rejected" else "accepted" end),
                        is_expected: true,
                        http_code: null,
                        amqp_ack: null,
                        rejected_by: (if $result == "watcher_recovered" then "SpiffeWorkloadWatcher" else null end),
                        reject_reason: (if $result == "watcher_recovered" then "shm_reread_on_invalid_json" else "no_integrity_check" end),
                        exception: null,
                        detect_latency_us: 0,
                        shm_tamper_result: $result
                    },
                    log_snippet: "SHM tamper simulation — see verify-spire-integrity.sh"
                }')
            ;;

        S02)
            # Stuck-odd-version probe: attempt to read state via verify-spire-integrity.sh
            SPIN_TEST="not_tested"
            if command -v docker >/dev/null 2>&1; then
                if docker exec "$WATCHER_CONTAINER" sh -c 'cat /tmp/spiffe-shared/meta.json' 2>/dev/null | jq -e '.version % 2 == 0' >/dev/null 2>&1; then
                    SPIN_TEST="even_version_ok"
                else
                    SPIN_TEST="odd_or_missing"
                fi
            fi

            ENRICHED=$(jq -n \
                --arg case_id "S02" \
                --arg profile "$PROFILE" \
                --arg sent_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
                --arg result "$SPIN_TEST" \
                '{
                    case_id: $case_id,
                    category: "shm-tamper",
                    description: "SHM seqlock stuck odd version probe",
                    layer_expected: "SpiffeTableReader",
                    profile: $profile,
                    attempt: { sent_at: $sent_at, request_kind: "amqp", payload_sha256: "" },
                    outcome: {
                        status: (if $result == "even_version_ok" then "rejected" else "accepted" end),
                        is_expected: ($result == "even_version_ok"),
                        http_code: null, amqp_ack: null,
                        rejected_by: "SpiffeTableReader",
                        reject_reason: ("seqlock_version_" + $result),
                        exception: null, detect_latency_us: 0
                    },
                    log_snippet: "seqlock probe"
                }')
            ;;
    esac

    jq ". += [${ENRICHED}]" "$CASES_JSON" > "${CASES_JSON}.new" && mv "${CASES_JSON}.new" "$CASES_JSON"
done

jq -n \
    --arg stage "amqp" \
    --arg profile "$PROFILE" \
    --arg gen "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --slurpfile cases "$CASES_JSON" \
    '{stage: $stage, profile: $profile, generated_at: $gen, cases: $cases[0]}' > "$OUT_FILE"

echo "[stage3] done — $(jq 'length' "$CASES_JSON") cases recorded"
