#!/usr/bin/env bash
# ============================================================================
# ZT Event Gateway — 全面健康檢查腳本
#
# 一鍵檢查整個系統的運行狀態，包含：
#   1. Docker 容器狀態
#   2. Gateway / Worker / RabbitMQ / 下游服務 HTTP 健康
#   3. RabbitMQ 佇列深度、消費者數量、unacked 警示
#   4. SPIFFE SHM 新鮮度 + SVID 有效期
#   5. LSVID 配置一致性檢查
#   6. Worker 最近錯誤摘要
#
# 使用方式:
#   bash scripts/analyze-health.sh
#   bash scripts/analyze-health.sh --json    # JSON 輸出（適合 CI）
#
# 環境變數:
#   GATEWAY_URL         (default: http://127.0.0.1:8080)
#   RABBIT_API          (default: http://127.0.0.1:15672/api)
#   RABBIT_USER/PASS    (default: zt / ztpass)
#   ORDER_URL           (default: http://127.0.0.1:8082)
#   PRODUCTION_URL      (default: http://127.0.0.1:8083)
#   USER_URL            (default: http://127.0.0.1:8084)
#   SHM_DIR             (default: /tmp/spiffe-shared)
# ============================================================================
set -uo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

GATEWAY_URL="${GATEWAY_URL:-http://127.0.0.1:8080}"
RABBIT_API="${RABBIT_API:-http://127.0.0.1:15672/api}"
RABBIT_USER="${RABBIT_USER:-zt}"
RABBIT_PASS="${RABBIT_PASS:-ztpass}"
ORDER_URL="${ORDER_URL:-http://127.0.0.1:8082}"
PRODUCTION_URL="${PRODUCTION_URL:-http://127.0.0.1:8083}"
USER_URL="${USER_URL:-http://127.0.0.1:8084}"
SHM_DIR="${SHM_DIR:-/tmp/spiffe-shared}"

JSON_MODE=false
[[ "${1:-}" == "--json" ]] && JSON_MODE=true

# ── Colors ──────────────────────────────────────────────────────────────────
if [[ -t 1 && "$JSON_MODE" == false ]]; then
    RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'
    CYAN=$'\033[0;36m'; BOLD=$'\033[1m'; DIM=$'\033[2m'; NC=$'\033[0m'
else
    RED=""; GREEN=""; YELLOW=""; CYAN=""; BOLD=""; DIM=""; NC=""
fi

PASS=0; WARN=0; FAIL=0
declare -a JSON_RESULTS=()

section() { [[ "$JSON_MODE" == false ]] && printf "\n${BOLD}${CYAN}━━ %s ━━${NC}\n" "$*"; }
ok()      { PASS=$((PASS+1)); [[ "$JSON_MODE" == false ]] && printf "  ${GREEN}✓${NC} %s\n" "$*"; JSON_RESULTS+=("$(printf '{"status":"ok","check":"%s"}' "$*")"); }
warn()    { WARN=$((WARN+1)); [[ "$JSON_MODE" == false ]] && printf "  ${YELLOW}⚠${NC} %s\n" "$*"; JSON_RESULTS+=("$(printf '{"status":"warn","check":"%s"}' "$*")"); }
err()     { FAIL=$((FAIL+1)); [[ "$JSON_MODE" == false ]] && printf "  ${RED}✗${NC} %s\n" "$*"; JSON_RESULTS+=("$(printf '{"status":"fail","check":"%s"}' "$*")"); }

http_status() {
    curl -sS -o /dev/null -w '%{http_code}' --max-time 3 "$1" 2>/dev/null || echo "000"
}

# ════════════════════════════════════════════════════════════════════════════
# 1. Docker 容器狀態
# ════════════════════════════════════════════════════════════════════════════
section "Docker 容器狀態"

EXPECTED_CONTAINERS=(zt-gateway zt-php-worker zt-rabbitmq)
OPTIONAL_CONTAINERS=(zt-spiffe-watcher zt-spire-server zt-spire-agent zt-workload-registrar)

for name in "${EXPECTED_CONTAINERS[@]}"; do
    state=$(docker inspect -f '{{.State.Status}}' "$name" 2>/dev/null || echo "not found")
    if [[ "$state" == "running" ]]; then
        ok "$name: running"
    else
        err "$name: $state"
    fi
done

for name in "${OPTIONAL_CONTAINERS[@]}"; do
    state=$(docker inspect -f '{{.State.Status}}' "$name" 2>/dev/null || echo "not found")
    if [[ "$state" == "running" ]]; then
        ok "$name: running"
    elif [[ "$state" == "not found" ]]; then
        printf "  ${DIM}  %s: 未部署（SPIFFE 未啟用時正常）${NC}\n" "$name" 2>/dev/null || true
    else
        warn "$name: $state"
    fi
done

# ════════════════════════════════════════════════════════════════════════════
# 2. HTTP 服務健康
# ════════════════════════════════════════════════════════════════════════════
section "HTTP 服務健康"

check_service() {
    local label="$1" url="$2" expect_2xx="${3:-0}"
    local code
    code=$(http_status "$url")
    if [[ "$expect_2xx" == "1" ]]; then
        [[ "$code" =~ ^2 ]] && ok "$label: HTTP $code" || err "$label: HTTP $code (expected 2xx)"
    else
        [[ "$code" != "000" ]] && ok "$label: reachable (HTTP $code)" || err "$label: unreachable"
    fi
}

check_service "Gateway health"       "$GATEWAY_URL/api/health" 1
check_service "RabbitMQ mgmt API"    "$RABBIT_API/overview"
check_service "Order Service"        "$ORDER_URL"
check_service "Production Service"   "$PRODUCTION_URL"
check_service "User Service"         "$USER_URL"

# ════════════════════════════════════════════════════════════════════════════
# 3. RabbitMQ 佇列狀態
# ════════════════════════════════════════════════════════════════════════════
section "RabbitMQ 佇列狀態"

QUEUES=(
    order_queue
    OrderCreateRequestedEvent
    OrderCreatedEvent
    InventoryDeductedEvent
    PaymentProcessedEvent
    OrderSagaCompletedEvent
    RollbackInventoryEvent
    RollbackOrderEvent
)

rabbit_ok=$(http_status "$RABBIT_API/overview")
if [[ "$rabbit_ok" != "000" ]]; then
    printf "  ${BOLD}%-35s %6s %8s %6s %10s${NC}\n" "QUEUE" "READY" "UNACKED" "TOTAL" "CONSUMERS"
    total_stuck=0
    for q in "${QUEUES[@]}"; do
        json=$(curl -sS -u "${RABBIT_USER}:${RABBIT_PASS}" "${RABBIT_API}/queues/%2F/${q}" 2>/dev/null || echo '{}')
        ready=$(echo "$json" | jq -r '.messages_ready // 0' 2>/dev/null || echo "?")
        unacked=$(echo "$json" | jq -r '.messages_unacknowledged // 0' 2>/dev/null || echo "?")
        total=$(echo "$json" | jq -r '.messages // 0' 2>/dev/null || echo "?")
        consumers=$(echo "$json" | jq -r '.consumers // 0' 2>/dev/null || echo "?")

        marker=""
        if [[ "$ready" =~ ^[0-9]+$ && "$ready" -gt 10 ]]; then
            marker="${YELLOW} (堆積)${NC}"
            total_stuck=$((total_stuck + ready))
        fi
        if [[ "$unacked" =~ ^[0-9]+$ && "$unacked" -gt 0 ]]; then
            marker="${YELLOW} (unacked)${NC}"
        fi
        if [[ "$consumers" == "0" ]]; then
            marker="${RED} (無消費者)${NC}"
        fi

        printf "  %-35s %6s %8s %6s %10s%b\n" "$q" "$ready" "$unacked" "$total" "$consumers" "$marker"
    done

    if [[ $total_stuck -gt 20 ]]; then
        warn "RabbitMQ 佇列堆積 $total_stuck 筆訊息"
    else
        ok "RabbitMQ 佇列深度正常"
    fi
else
    err "RabbitMQ API 不可達，跳過佇列檢查"
fi

# ════════════════════════════════════════════════════════════════════════════
# 4. SPIFFE SHM 狀態
# ════════════════════════════════════════════════════════════════════════════
section "SPIFFE SHM 狀態"

META_FILE="$SHM_DIR/meta.json"
if [[ -f "$META_FILE" ]]; then
    meta=$(cat "$META_FILE" 2>/dev/null || echo '{}')
    version=$(echo "$meta" | jq -r '.version // "?"' 2>/dev/null || echo "?")
    x509_state=$(echo "$meta" | jq -r '.x509_state // "?"' 2>/dev/null || echo "?")
    updated_at=$(echo "$meta" | jq -r '.updated_at // "?"' 2>/dev/null || echo "?")

    # 檢查 seqlock：偶數 = 正常，奇數 = 寫入中
    if [[ "$version" =~ ^[0-9]+$ ]]; then
        if (( version % 2 == 0 )); then
            ok "SHM seqlock version=$version (偶數=穩定)"
        else
            warn "SHM seqlock version=$version (奇數=寫入中或 watcher 崩潰)"
        fi
    fi

    if [[ "$x509_state" == "ready" ]]; then
        ok "SHM x509_state=ready"
    else
        err "SHM x509_state=$x509_state (預期 ready)"
    fi

    # 檢查新鮮度
    if [[ "$updated_at" != "?" ]]; then
        updated_epoch=$(date -j -f "%Y-%m-%dT%H:%M:%S" "${updated_at%%.*}" "+%s" 2>/dev/null \
            || date -d "${updated_at}" "+%s" 2>/dev/null || echo 0)
        now_epoch=$(date "+%s")
        age=$((now_epoch - updated_epoch))
        if (( age < 7200 )); then
            ok "SHM 更新於 ${age} 秒前 (< 2h 閾值)"
        else
            warn "SHM 更新於 ${age} 秒前 (超過 2h 閾值，watcher 可能已停止)"
        fi
    fi

    # 檢查 SVID 檔案
    SVID_FILE="$SHM_DIR/x509/0.json"
    if [[ -f "$SVID_FILE" ]]; then
        svid_size=$(wc -c < "$SVID_FILE" | tr -d ' ')
        if (( svid_size > 100 )); then
            ok "SVID 檔案存在 ($svid_size bytes)"
        else
            warn "SVID 檔案過小 ($svid_size bytes)"
        fi
    else
        warn "SVID 檔案不存在: $SVID_FILE"
    fi
else
    if docker inspect zt-spiffe-watcher >/dev/null 2>&1; then
        err "SHM meta.json 不存在但 watcher 已部署"
    else
        printf "  ${DIM}  SHM 未初始化（SPIFFE 未啟用時正常）${NC}\n" 2>/dev/null || true
    fi
fi

# ════════════════════════════════════════════════════════════════════════════
# 5. LSVID 配置一致性
# ════════════════════════════════════════════════════════════════════════════
section "LSVID 配置一致性"

# 從容器環境變數中讀取配置
read_env() {
    docker exec "$1" printenv "$2" 2>/dev/null || echo ""
}

gw_spiffe_enabled=$(read_env zt-gateway SPIFFE_ENABLED)
gw_lsvid_enabled=$(read_env zt-gateway LSVID_ENABLED)
wk_spiffe_enabled=$(read_env zt-php-worker SPIFFE_ENABLED)
wk_lsvid_required=$(read_env zt-php-worker LSVID_REQUIRED)
gw_spiffe_id=$(read_env zt-gateway SPIFFE_ID)
wk_spiffe_id=$(read_env zt-php-worker SPIFFE_ID)
wk_downstream=$(read_env zt-php-worker DOWNSTREAM_SPIFFE_ID)

if [[ -n "$gw_spiffe_enabled" ]]; then
    if [[ "$gw_spiffe_enabled" == "$wk_spiffe_enabled" ]]; then
        ok "SPIFFE_ENABLED 一致: gateway=$gw_spiffe_enabled worker=$wk_spiffe_enabled"
    else
        err "SPIFFE_ENABLED 不一致: gateway=$gw_spiffe_enabled worker=$wk_spiffe_enabled"
    fi

    if [[ "$gw_lsvid_enabled" == "1" && "$wk_lsvid_required" == "1" ]]; then
        ok "LSVID 配置: gateway 鑄造 + worker 強制驗證 (生產推薦)"
    elif [[ "$gw_lsvid_enabled" == "1" && "$wk_lsvid_required" != "1" ]]; then
        warn "LSVID 配置: gateway 鑄造但 worker 不強制驗證 (遷移模式)"
    elif [[ "$gw_lsvid_enabled" != "1" && "$wk_lsvid_required" == "1" ]]; then
        err "LSVID 配置衝突: gateway 不鑄造但 worker 強制要求"
    fi

    if [[ -n "$gw_spiffe_id" ]]; then
        ok "Gateway SPIFFE_ID: $gw_spiffe_id"
    fi
    if [[ -n "$wk_spiffe_id" ]]; then
        ok "Worker  SPIFFE_ID: $wk_spiffe_id"
    fi
else
    printf "  ${DIM}  無法讀取容器環境變數（容器可能未運行）${NC}\n" 2>/dev/null || true
fi

# ════════════════════════════════════════════════════════════════════════════
# 6. Worker 最近錯誤摘要
# ════════════════════════════════════════════════════════════════════════════
section "Worker 最近錯誤摘要（最近 500 行）"

worker_running=$(docker inspect -f '{{.State.Status}}' zt-php-worker 2>/dev/null || echo "")
if [[ "$worker_running" == "running" ]]; then
    worker_logs=$(docker logs zt-php-worker --tail 500 2>&1 || true)

    handler_errors=$(echo "$worker_logs" | grep -c '\[event-bus\] handler' || true)
    requeue_count=$(echo "$worker_logs" | grep -c '\[consumer\] requeue' || true)
    exhausted_count=$(echo "$worker_logs" | grep -c '\[consumer\] exhausted' || true)
    dropped_count=$(echo "$worker_logs" | grep -c '\[consumer\] dropped' || true)
    lsvid_errors=$(echo "$worker_logs" | grep -c 'LSVID' | head -1 || true)

    printf "  %-30s %s\n" "Handler 例外" "$handler_errors"
    printf "  %-30s %s\n" "Requeue (重試中)" "$requeue_count"
    printf "  %-30s %s\n" "Exhausted (重試耗盡)" "$exhausted_count"
    printf "  %-30s %s\n" "Dropped (不可恢復)" "$dropped_count"
    printf "  %-30s %s\n" "LSVID 相關" "$lsvid_errors"

    if (( handler_errors + exhausted_count + dropped_count == 0 )); then
        ok "Worker 最近無錯誤"
    elif (( exhausted_count > 0 || dropped_count > 0 )); then
        err "Worker 有 $((exhausted_count + dropped_count)) 筆訊息被丟棄"
        echo ""
        echo "  最近的錯誤訊息:"
        echo "$worker_logs" | grep -E '\[consumer\] (exhausted|dropped)' | tail -5 | sed 's/^/    /'
    else
        warn "Worker 有 $handler_errors 個 handler 例外"
    fi
else
    warn "Worker 容器未運行，跳過錯誤檢查"
fi

# ════════════════════════════════════════════════════════════════════════════
# 結果摘要
# ════════════════════════════════════════════════════════════════════════════
if [[ "$JSON_MODE" == true ]]; then
    printf '{"pass":%d,"warn":%d,"fail":%d,"checks":[%s]}\n' \
        "$PASS" "$WARN" "$FAIL" "$(IFS=,; echo "${JSON_RESULTS[*]}")"
else
    section "摘要"
    printf "  ${GREEN}通過: %d${NC}  ${YELLOW}警告: %d${NC}  ${RED}失敗: %d${NC}\n" "$PASS" "$WARN" "$FAIL"

    if (( FAIL > 0 )); then
        printf "\n  ${RED}${BOLD}系統存在 %d 個失敗項目，需要立即處理。${NC}\n" "$FAIL"
        exit 1
    elif (( WARN > 0 )); then
        printf "\n  ${YELLOW}系統基本正常，但有 %d 個警告需要關注。${NC}\n" "$WARN"
        exit 0
    else
        printf "\n  ${GREEN}${BOLD}系統運行正常。${NC}\n"
        exit 0
    fi
fi
