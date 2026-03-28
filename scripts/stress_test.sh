#!/bin/bash

# ──────────────────────────────────────────────────────────────
#  Stress Test — sends TOTAL_REQUESTS sequential POST requests
#  to the Gateway and reports success/failure statistics.
# ──────────────────────────────────────────────────────────────

URL="${STRESS_URL:-http://127.0.0.1:8080/api/orders}"
TOTAL_REQUESTS=${1:-100}

USER_IDS=(1 2 3 4 5)
PRODUCT_KEYS=(1 2 3 4 5)

SUCCESS=0
FAIL=0
FAIL_CODES=""

get_timestamp_ms() {
    if [[ "$OSTYPE" == "darwin"* ]]; then
        python3 -c 'import time; print(int(time.time() * 1000))'
    else
        date +%s%3N
    fi
}

echo "🚀 Sending $TOTAL_REQUESTS requests to $URL"
echo "-----------------------------------------------------"

START_TIME=$(get_timestamp_ms)

for i in $(seq 1 $TOTAL_REQUESTS); do
    USER_ID=${USER_IDS[$((RANDOM % ${#USER_IDS[@]}))]}
    PRODUCT_KEY=${PRODUCT_KEYS[$((RANDOM % ${#PRODUCT_KEYS[@]}))]}

    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
        --connect-timeout 5 --max-time 10 \
        -X POST "$URL" \
        -H "Content-Type: application/json" \
        -d "{\"user_id\": $USER_ID, \"product_list\": [{\"p_key\": $PRODUCT_KEY, \"amount\": 1}]}")

    if [ "$HTTP_CODE" -eq 202 ] || [ "$HTTP_CODE" -eq 201 ]; then
        SUCCESS=$((SUCCESS + 1))
    else
        FAIL=$((FAIL + 1))
        FAIL_CODES="$FAIL_CODES $HTTP_CODE"
    fi

    # Progress every 100 requests
    if (( i % 100 == 0 )); then
        PCT=$((i * 100 / TOTAL_REQUESTS))
        echo -ne "  [$PCT%] $i/$TOTAL_REQUESTS — ✅ $SUCCESS  ❌ $FAIL\r"
    fi
done

END_TIME=$(get_timestamp_ms)
DURATION=$((END_TIME - START_TIME))

echo -e "\n-----------------------------------------------------"
echo "📊 Results"
echo "   Total:    $TOTAL_REQUESTS"
echo "   ✅ OK:     $SUCCESS"
echo "   ❌ Failed: $FAIL"

if [ "$FAIL" -gt 0 ]; then
    # Count unique HTTP codes
    echo "   Error codes: $(echo "$FAIL_CODES" | tr ' ' '\n' | sort | uniq -c | sort -rn | tr '\n' ' ')"
fi

RATE=$(python3 -c "print(f'{$TOTAL_REQUESTS / ($DURATION / 1000):.1f}')" 2>/dev/null || echo "N/A")
echo "   ⏱️  Duration: ${DURATION} ms ($RATE req/s)"
echo "-----------------------------------------------------"

# Exit 1 if any failures
[ "$FAIL" -gt 0 ] && exit 1 || exit 0
