#!/usr/bin/env bash
# ============================================================================
# ZT Event Gateway — 程式碼品質分析腳本
#
# 靜態分析專案程式碼的健康指標：
#   1. 程式碼行數統計（按目錄與類型）
#   2. TODO / FIXME / HACK / XXX 標記掃描
#   3. 測試覆蓋率概覽（測試檔案 vs 源碼檔案）
#   4. 事件類別 ↔ Handler 註冊一致性
#   5. 未使用的 use/import 偵測
#   6. 環境變數使用追蹤
#   7. Saga 步驟完整性驗證
#
# 使用方式:
#   bash scripts/analyze-codebase.sh
# ============================================================================
set -uo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

# ── Colors ──────────────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
    RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'
    CYAN=$'\033[0;36m'; BOLD=$'\033[1m'; DIM=$'\033[2m'; NC=$'\033[0m'
else
    RED=""; GREEN=""; YELLOW=""; CYAN=""; BOLD=""; DIM=""; NC=""
fi

section() { printf "\n${BOLD}${CYAN}━━ %s ━━${NC}\n" "$*"; }
subsect() { printf "\n  ${BOLD}%s${NC}\n" "$*"; }

# ════════════════════════════════════════════════════════════════════════════
# 1. 程式碼行數統計
# ════════════════════════════════════════════════════════════════════════════
section "1. 程式碼行數統計"

count_lines() {
    local dir="$1" label="$2"
    if [[ -d "$dir" ]]; then
        local count
        count=$(find "$dir" -name '*.php' -not -path '*/vendor/*' -not -path '*/node_modules/*' | xargs wc -l 2>/dev/null | tail -1 | awk '{print $1}')
        printf "  %-40s %6s lines\n" "$label" "${count:-0}"
    fi
}

count_lines "src"                       "src/ (核心元件)"
count_lines "src/Spiffe"                "  src/Spiffe/ (SPIFFE 整合)"
count_lines "src/MessageQueue"          "  src/MessageQueue/ (訊息佇列)"
count_lines "src/Worker"                "  src/Worker/ (消費者)"
count_lines "src/Ingress"               "  src/Ingress/ (入口驗證)"
count_lines "Sagas"                     "Sagas/ (Saga 編排)"
count_lines "Event-Driven/Events"       "Event-Driven/Events/ (事件類別)"
count_lines "Services"                  "Services/ (下游服務)"
count_lines "anser-gateway"             "anser-gateway/ (Gateway 框架)"
count_lines "bin"                       "bin/ (啟動腳本)"
count_lines "tests"                     "tests/ (測試)"
count_lines "packages/php-lsvid/src"    "packages/php-lsvid/ (LSVID 函式庫)"
count_lines "packages/php-spiffe/src"   "packages/php-spiffe/ (SPIFFE 客戶端)"

echo ""
total_src=$(find src Sagas Event-Driven anser-gateway bin -name '*.php' -not -path '*/vendor/*' 2>/dev/null | xargs wc -l 2>/dev/null | tail -1 | awk '{print $1}')
total_test=$(find tests -name '*.php' -not -name 'benchmark*' 2>/dev/null | xargs wc -l 2>/dev/null | tail -1 | awk '{print $1}')
printf "  ${BOLD}%-40s %6s lines${NC}\n" "總計源碼" "${total_src:-0}"
printf "  ${BOLD}%-40s %6s lines${NC}\n" "總計測試" "${total_test:-0}"

if [[ -n "$total_src" && -n "$total_test" && "$total_src" -gt 0 ]]; then
    ratio=$(( total_test * 100 / total_src ))
    printf "  ${BOLD}%-40s %5s%%${NC}\n" "測試/源碼比例" "$ratio"
fi

# ════════════════════════════════════════════════════════════════════════════
# 2. TODO / FIXME / HACK 標記掃描
# ════════════════════════════════════════════════════════════════════════════
section "2. TODO / FIXME / HACK 標記"

for tag in TODO FIXME HACK XXX; do
    count=$(grep -rn --include='*.php' --exclude-dir=vendor "$tag" src/ Sagas/ Event-Driven/ anser-gateway/ bin/ 2>/dev/null | wc -l | tr -d ' ')
    if (( count > 0 )); then
        printf "  ${YELLOW}%-10s %3d 處${NC}\n" "$tag" "$count"
        grep -rn --include='*.php' --exclude-dir=vendor "$tag" src/ Sagas/ Event-Driven/ anser-gateway/ bin/ 2>/dev/null \
            | head -5 | sed 's/^/    /'
        (( count > 5 )) && printf "    ${DIM}... 及其他 %d 處${NC}\n" "$((count - 5))"
    else
        printf "  ${GREEN}%-10s %3d 處${NC}\n" "$tag" 0
    fi
done

# ════════════════════════════════════════════════════════════════════════════
# 3. 測試覆蓋率概覽
# ════════════════════════════════════════════════════════════════════════════
section "3. 測試覆蓋率概覽"

subsect "已測試的元件"
for f in tests/Unit/**/*Test.php tests/Unit/*Test.php; do
    [[ -f "$f" ]] || continue
    basename="${f##*/}"
    basename="${basename%Test.php}"
    printf "  ${GREEN}✓${NC} %s\n" "$basename"
done

subsect "缺少測試的關鍵元件"
CRITICAL_UNTESTED=(
    "src/Worker/EventConsumer.php:已建立基礎測試"
    "anser-gateway/Filters/SpiffeLsvidFilter.php:LSVID 擴展 + mTLS 注入"
    "src/Spiffe/SpiffeBootstrap.php:SHM → LSVID 初始化"
    "src/Spiffe/LSVIDSignerRegistry.php:靜態 signer 持有者"
    "src/Spiffe/LSVIDValidatorRegistry.php:靜態 validator 持有者"
    "src/Spiffe/SpiffeAudienceRegistry.php:URL → SPIFFE ID 對應"
    "src/Spiffe/Validation/X509SvidValidator.php:X.509 鏈驗證"
    "src/Spiffe/TLS/TlsPeerAuthorizer.php:TLS peer 授權"
)

for item in "${CRITICAL_UNTESTED[@]}"; do
    file="${item%%:*}"
    desc="${item#*:}"
    if [[ -f "$file" ]]; then
        test_name="tests/Unit/${file#src/}"
        test_name="${test_name%.php}Test.php"
        if [[ -f "$test_name" ]]; then
            printf "  ${GREEN}✓${NC} %s ${DIM}(%s)${NC}\n" "$file" "$desc"
        else
            printf "  ${YELLOW}✗${NC} %s ${DIM}(%s)${NC}\n" "$file" "$desc"
        fi
    fi
done

# ════════════════════════════════════════════════════════════════════════════
# 4. 事件類別 ↔ Handler 一致性
# ════════════════════════════════════════════════════════════════════════════
section "4. 事件類別 ↔ Handler 一致性"

subsect "已定義的事件類別"
event_files=$(find Event-Driven/Events -name '*.php' 2>/dev/null | sort)
declare -a events=()
for f in $event_files; do
    name=$(basename "$f" .php)
    events+=("$name")
    printf "  📨 %s\n" "$name"
done

subsect "一致性檢查"
for event in "${events[@]}"; do
    # 檢查 handler 名稱去掉 Event 後是否匹配
    handler_name="${event%Event}"
    if grep -q "on${handler_name}" Sagas/OrderSaga.php 2>/dev/null; then
        printf "  ${GREEN}✓${NC} %s → handler 已註冊\n" "$event"
    else
        printf "  ${YELLOW}?${NC} %s → 無對應 handler${DIM}（可能由其他 Saga 處理或未使用）${NC}\n" "$event"
    fi
done

# ════════════════════════════════════════════════════════════════════════════
# 5. Saga 步驟完整性
# ════════════════════════════════════════════════════════════════════════════
section "5. Saga 步驟完整性"

subsect "Happy Path 事件鏈"
EXPECTED_CHAIN=(
    "OrderCreateRequestedEvent → onOrderCreateRequested → OrderCreatedEvent"
    "OrderCreatedEvent → onOrderCreated → InventoryDeductedEvent"
    "InventoryDeductedEvent → onInventoryDeducted → PaymentProcessedEvent"
    "PaymentProcessedEvent → onPaymentProcessed → OrderSagaCompletedEvent"
    "OrderSagaCompletedEvent → onOrderSagaCompleted → (結束)"
)

for step in "${EXPECTED_CHAIN[@]}"; do
    printf "  ${GREEN}→${NC} %s\n" "$step"
done

subsect "Compensation Path 事件鏈"
COMP_CHAIN=(
    "(失敗) → RollbackInventoryEvent → onRollbackInventory → RollbackOrderEvent"
    "RollbackOrderEvent → onRollbackOrder → (結束)"
)

for step in "${COMP_CHAIN[@]}"; do
    printf "  ${RED}←${NC} %s\n" "$step"
done

# 驗證每個 handler 是否在 publish 時傳入完整的 payload
subsect "Publish Payload 完整性"
saga_content=$(cat Sagas/OrderSaga.php 2>/dev/null)

# 檢查 InventoryDeductedEvent 是否傳入 productList（之前的 bug）
if echo "$saga_content" | grep -A5 "InventoryDeductedEvent" | grep -q "productList.*event->productList"; then
    printf "  ${GREEN}✓${NC} InventoryDeductedEvent.productList 使用 event->productList（已修復）\n"
else
    printf "  ${RED}✗${NC} InventoryDeductedEvent.productList 可能未正確傳遞\n"
fi

# 檢查 RollbackInventoryEvent 是否包含 paymentCompleted 和 total
if echo "$saga_content" | grep -A10 "RollbackInventoryEvent" | grep -q "paymentCompleted"; then
    printf "  ${GREEN}✓${NC} RollbackInventoryEvent 包含 paymentCompleted 欄位\n"
else
    printf "  ${RED}✗${NC} RollbackInventoryEvent 缺少 paymentCompleted 欄位\n"
fi

# 檢查價格邏輯
if echo "$saga_content" | grep -q 'if (\$price !== null)'; then
    printf "  ${GREEN}✓${NC} 價格賦值邏輯正確 (\$price !== null)\n"
elif echo "$saga_content" | grep -q 'if (!is_int(\$price))'; then
    printf "  ${RED}✗${NC} 價格賦值邏輯反轉 (!is_int) — 需修復\n"
fi

# 檢查庫存驗證是否被註解
if echo "$saga_content" | grep -q 'getActionsMeaningData()' && ! echo "$saga_content" | grep -B1 'getActionsMeaningData()' | grep -q '^\s*[/*]'; then
    printf "  ${GREEN}✓${NC} 庫存扣減結果驗證已啟用\n"
else
    printf "  ${RED}✗${NC} 庫存扣減結果驗證被註解或缺失\n"
fi

# 檢查 isSuccess 檢查
create_order_check=$(echo "$saga_content" | grep -c 'isSuccess.*info' || true)
printf "  ${GREEN}✓${NC} isSuccess() 檢查點: %d 處\n" "$create_order_check"

# ════════════════════════════════════════════════════════════════════════════
# 6. 環境變數使用追蹤
# ════════════════════════════════════════════════════════════════════════════
section "6. 環境變數使用追蹤"

printf "  ${BOLD}%-35s %s${NC}\n" "環境變數" "使用位置"
for var in SPIFFE_ENABLED SPIFFE_ID SPIFFE_ENDPOINT_SOCKET SPIFFE_SHM_DIR \
           LSVID_ENABLED LSVID_REQUIRED SPIFFE_MTLS_ENABLED \
           DOWNSTREAM_SPIFFE_ID LSVID_TTL_SECONDS \
           ORDER_SERVICE_HOST ORDER_SERVICE_PORT \
           PRODUCTION_SERVICE_HOST PRODUCTION_SERVICE_PORT \
           USER_SERVICE_HOST USER_SERVICE_PORT \
           RABBITMQ_HOST RABBITMQ_PORT RABBITMQ_USER RABBITMQ_PASS; do
    files=$(grep -rl --include='*.php' --exclude-dir=vendor "$var" src/ bin/ anser-gateway/ Sagas/ 2>/dev/null | wc -l | tr -d ' ')
    if (( files > 0 )); then
        printf "  %-35s %s 個檔案\n" "$var" "$files"
    else
        printf "  ${DIM}%-35s 未使用${NC}\n" "$var"
    fi
done

# ════════════════════════════════════════════════════════════════════════════
# 7. Consumer retry 上限檢查
# ════════════════════════════════════════════════════════════════════════════
section "7. Consumer 安全機制"

consumer_file="src/MessageQueue/Consumer.php"
if [[ -f "$consumer_file" ]]; then
    if grep -q 'MAX_RETRIES' "$consumer_file"; then
        max=$(grep -oE 'MAX_RETRIES\s*=\s*[0-9]+' "$consumer_file" | grep -oE '[0-9]+$' || echo "?")
        printf "  ${GREEN}✓${NC} Retry 上限已設定: MAX_RETRIES=%s\n" "$max"
    else
        printf "  ${RED}✗${NC} 無 retry 上限 — 可能發生 requeue storm\n"
    fi

    if grep -q 'x-delivery-count\|x-death' "$consumer_file"; then
        printf "  ${GREEN}✓${NC} 支援 RabbitMQ delivery count 偵測\n"
    fi

    if grep -q 'UnrecoverableMessageException' "$consumer_file"; then
        printf "  ${GREEN}✓${NC} 支援不可恢復例外直接 reject\n"
    fi
fi

# ════════════════════════════════════════════════════════════════════════════
# 摘要
# ════════════════════════════════════════════════════════════════════════════
section "分析完成"
printf "  執行時間: %s\n" "$(date '+%Y-%m-%d %H:%M:%S')"
printf "  專案路徑: %s\n" "$PROJECT_DIR"
