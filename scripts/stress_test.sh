#!/bin/bash
# ──────────────────────────────────────────────────────────────
#  Stress Test — sends requests to the Gateway and reports
#  success/failure statistics with latency percentiles.
#
#  Usage:
#    bash scripts/stress_test.sh [TOTAL] [CONCURRENCY]
#
#  Examples:
#    bash scripts/stress_test.sh              # 100 sequential
#    bash scripts/stress_test.sh 500 10       # 500 reqs, 10 concurrent
#
#  Environment:
#    STRESS_URL    Gateway endpoint (default: http://127.0.0.1:8080/api/orders)
#    HEALTH_URL    Health endpoint  (default: http://127.0.0.1:8080/api/health)
# ──────────────────────────────────────────────────────────────
set -euo pipefail

URL="${STRESS_URL:-http://127.0.0.1:8080/api/orders}"
HEALTH="${HEALTH_URL:-http://127.0.0.1:8080/api/health}"
TOTAL_REQUESTS=${1:-50}
CONCURRENCY=${2:-1}

USER_IDS=(1 2 3 4 5)
PRODUCT_KEYS=(1 2 3 4 5)

TMPDIR_STRESS="$(mktemp -d)"
trap 'rm -rf "$TMPDIR_STRESS"' EXIT

# ── Helpers ──────────────────────────────────────────────────

get_timestamp_ms() {
    if [[ "$OSTYPE" == "darwin"* ]]; then
        python3 -c 'import time; print(int(time.time() * 1000))'
    else
        date +%s%3N
    fi
}

calc() {
    python3 -c "$1" 2>/dev/null || echo "N/A"
}

# ── Pre-flight: health check ────────────────────────────────

echo "Checking gateway health at $HEALTH ..."
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 5 --max-time 10 "$HEALTH" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" != "200" ]; then
    echo "ERROR: Gateway not reachable (HTTP $HTTP_CODE). Is docker compose up?"
    exit 1
fi
echo "Gateway is healthy."
echo ""

# ── Worker function (one request) ───────────────────────────

send_request() {
    local idx=$1
    local result_file="$TMPDIR_STRESS/result_${idx}"
    local user_id=$(( (RANDOM % 5) + 1 ))
    local product_key=$(( (RANDOM % 5) + 1 ))
    local amount=$(( (RANDOM % 5) + 1 ))

    local output
    output=$(curl -s -w "\n%{http_code} %{time_total}" \
        --connect-timeout 5 --max-time 15 \
        -X POST "$URL" \
        -H "Content-Type: application/json" \
        -H "X-Correlation-ID: stress-${idx}-$(date +%s)" \
        -d "{\"user_id\": $user_id, \"product_list\": [{\"p_key\": $product_key, \"amount\": $amount}]}" 2>/dev/null || echo -e "\n000 0.000")

    local last_line
    last_line=$(echo "$output" | tail -1)
    local http_code="${last_line%% *}"
    local latency="${last_line##* }"

    echo "${http_code} ${latency}" > "$result_file"
}

export -f send_request
export URL TMPDIR_STRESS

# ── Run stress test ─────────────────────────────────────────

echo "Sending $TOTAL_REQUESTS requests (concurrency=$CONCURRENCY) to $URL"
echo "-----------------------------------------------------"

START_TIME=$(get_timestamp_ms)
PROGRESS_STEP=0
if (( TOTAL_REQUESTS >= 20 )); then
    PROGRESS_STEP=$((TOTAL_REQUESTS / 20))
fi

if [ "$CONCURRENCY" -le 1 ]; then
    # Sequential mode with progress
    for i in $(seq 1 "$TOTAL_REQUESTS"); do
        send_request "$i"

        # Progress update
        show_progress=0
        if (( PROGRESS_STEP > 0 )); then
            if (( i % PROGRESS_STEP == 0 )); then
                show_progress=1
            fi
        fi
        if (( i == TOTAL_REQUESTS )); then
            show_progress=1
        fi
        if (( show_progress == 1 )); then
            pct=$((i * 100 / TOTAL_REQUESTS))
            printf "  [%3d%%] %d/%d\r" "$pct" "$i" "$TOTAL_REQUESTS"
        fi
    done
    echo ""
else
    # Concurrent mode using xargs
    seq 1 "$TOTAL_REQUESTS" | xargs -P "$CONCURRENCY" -I {} bash -c 'send_request "$@"' _ {}

    # Show completion (xargs doesn't support inline progress easily)
    echo "  [100%] $TOTAL_REQUESTS/$TOTAL_REQUESTS"
fi

END_TIME=$(get_timestamp_ms)
DURATION=$((END_TIME - START_TIME))

# ── Aggregate results ───────────────────────────────────────

SUCCESS=0
FAIL=0
LATENCIES=()
CODE_COUNTS_FILE="$TMPDIR_STRESS/code_counts.txt"
touch "$CODE_COUNTS_FILE"

for f in "$TMPDIR_STRESS"/result_*; do
    [ -f "$f" ] || continue
    read -r code latency < "$f"

    # Count by HTTP code (Bash 3 compatible: file-based aggregation)
    echo "$code" >> "$CODE_COUNTS_FILE"

    if [ "$code" = "202" ]; then
        SUCCESS=$((SUCCESS + 1))
    else
        FAIL=$((FAIL + 1))
    fi

    LATENCIES+=("$latency")
done

# ── Latency percentiles ────────────────────────────────────

LATENCY_FILE="$TMPDIR_STRESS/latencies.txt"
printf '%s\n' "${LATENCIES[@]}" | sort -n > "$LATENCY_FILE"
LATENCY_COUNT=${#LATENCIES[@]}

percentile() {
    local p=$1
    local idx
    idx=$(calc "print(max(0, int($LATENCY_COUNT * $p / 100) - 1))")
    sed -n "$((idx + 1))p" "$LATENCY_FILE"
}

if [ "$LATENCY_COUNT" -gt 0 ]; then
    P50=$(percentile 50)
    P90=$(percentile 90)
    P95=$(percentile 95)
    P99=$(percentile 99)
    PMIN=$(head -1 "$LATENCY_FILE")
    PMAX=$(tail -1 "$LATENCY_FILE")
    AVG=$(calc "lats=[float(l) for l in open('$LATENCY_FILE').read().split()]; print(f'{sum(lats)/len(lats):.3f}')")
fi

# ── Report ──────────────────────────────────────────────────

echo "-----------------------------------------------------"
echo "Results"
echo "   Total:       $TOTAL_REQUESTS"
echo "   Concurrency: $CONCURRENCY"
echo "   Success:     $SUCCESS (HTTP 202)"
echo "   Failed:      $FAIL"

if [ -s "$CODE_COUNTS_FILE" ]; then
    echo ""
    echo "   HTTP code breakdown:"
    while read -r count code; do
        pct=$(calc "print(f'{$count/$TOTAL_REQUESTS*100:.1f}')")
        label=""
        case $code in
            202) label="Accepted" ;;
            400) label="Bad Request" ;;
            422) label="Unprocessable" ;;
            500) label="Server Error" ;;
            503) label="Unavailable" ;;
            000) label="Connection Failed" ;;
            *)   label="" ;;
        esac
        printf "     %s %-18s %5d  (%s%%)\n" "$code" "$label" "$count" "$pct"
    done < <(sort "$CODE_COUNTS_FILE" | uniq -c | awk '{print $1, $2}' | sort -k2,2n)
fi

if [ "$LATENCY_COUNT" -gt 0 ]; then
    echo ""
    echo "   Latency (seconds):"
    echo "     min=$PMIN  avg=$AVG  max=$PMAX"
    echo "     p50=$P50  p90=$P90  p95=$P95  p99=$P99"
fi

RATE=$(calc "print(f'{$TOTAL_REQUESTS / ($DURATION / 1000):.1f}')")
echo ""
echo "   Duration: ${DURATION} ms  ($RATE req/s)"
echo "-----------------------------------------------------"

[ "$FAIL" -gt 0 ] && exit 1 || exit 0
