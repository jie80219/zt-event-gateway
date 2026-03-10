#!/bin/bash

# 設定目標 URL (請確認你的 Docker Port 是 8080)
URL="http://localhost:8080/api/orders"

# 請求總數
TOTAL_REQUESTS=2000

# 使用者 ID 範圍
USER_IDS=(1 2 3 4 5)

# 產品對照表 (可依實際資料調整)
PRODUCT_KEYS=(1 2 3 4 5)
PRODUCT_NAMES=(1 2 3 4 5)

# 定義獲取毫秒時間戳的函式 (跨平台兼容)
get_timestamp_ms() {
    # 檢查是否為 macOS (Darwin)
    if [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS 使用 python3 獲取毫秒
        python3 -c 'import time; print(int(time.time() * 1000))'
    else
        # Linux 使用 date 獲取毫秒 (%3N 代表毫秒)
        date +%s%3N
    fi
}

echo "🚀 [Start] 發送 $TOTAL_REQUESTS 個請求至 Gateway..."
echo "-----------------------------------------------------"

# 取得開始時間 (毫秒)
START_TIME=$(get_timestamp_ms)

for i in $(seq 1 $TOTAL_REQUESTS)
do
   # 產生隨機資料
   USER_ID=${USER_IDS[$((RANDOM % ${#USER_IDS[@]}))]}
   PRODUCT_INDEX=$((RANDOM % ${#PRODUCT_KEYS[@]}))
   PRODUCT_KEY=${PRODUCT_KEYS[$PRODUCT_INDEX]}
   PRODUCT_NAME=${PRODUCT_NAMES[$PRODUCT_INDEX]}
   
   # 發送請求 (安靜模式，只抓 HTTP Code)
   HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$URL" \
     -H "Content-Type: application/json" \
     -d "{\"user_id\": $USER_ID, \"product_list\": [{\"p_key\": $PRODUCT_KEY, \"amount\": 1}], \"note\": \"LoadTest-$i ($PRODUCT_NAME)\"}")

   # 顯示進度
   if [ "$HTTP_CODE" -eq 201 ] || [ "$HTTP_CODE" -eq 202 ]; then
       # 使用 \r 讓游標回到行首，覆蓋輸出，製造計數器效果
       echo -ne "✅ Req $i: user_id=$USER_ID product=$PRODUCT_NAME (p_key=$PRODUCT_KEY) 202 Accepted \r"
   else
       echo -e "\n❌ Req $i Failed: user_id=$USER_ID product=$PRODUCT_NAME (p_key=$PRODUCT_KEY) HTTP $HTTP_CODE"
   fi
done

# 取得結束時間 (毫秒)
END_TIME=$(get_timestamp_ms)

# 計算耗時 (毫秒)
DURATION=$((END_TIME - START_TIME))

echo -e "\n-----------------------------------------------------"
echo "🎉 發送完畢！"
echo "⏱️  Publisher (Gateway) 總耗時: ${DURATION} ms"
echo "👉 現在請檢查 Worker Log，看 Consumer 是否正在後台慢慢處理..."
