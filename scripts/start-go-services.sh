#!/usr/bin/env bash
# ============================================================================
# ZT Event Gateway — Go 服務一鍵啟動腳本
#
# 按正確順序啟動整個堆疊：
#   1. 建立 Docker 外部網路
#   2. 啟動 Gateway 堆疊（SPIRE Server + RabbitMQ + EventStoreDB + Gateway + Worker）
#   3. 啟動三個 Go 下游服務（DB + SPIRE Agent + Go Service）
#   4. 健康檢查 + 狀態摘要
#
# 使用方式:
#   bash scripts/start-go-services.sh
#   bash scripts/start-go-services.sh --skip-gateway   # 只啟動下游服務
#   bash scripts/start-go-services.sh --build           # 強制重建 image
#
# 停止:
#   bash scripts/stop-go-services.sh
# ============================================================================
set -uo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

SKIP_GATEWAY=false
BUILD_FLAG=""
COMPOSE_PROFILES="${COMPOSE_PROFILES:-}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --skip-gateway) SKIP_GATEWAY=true; shift ;;
        --build) BUILD_FLAG="--build"; shift ;;
        -h|--help) grep -E '^# ' "$0" | sed 's/^# //'; exit 0 ;;
        *) echo "Unknown flag: $1" >&2; exit 2 ;;
    esac
done

# ── Colors ──────────────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
    GREEN=$'\033[0;32m'; RED=$'\033[0;31m'; YELLOW=$'\033[1;33m'
    CYAN=$'\033[0;36m'; BOLD=$'\033[1m'; NC=$'\033[0m'
else
    GREEN=""; RED=""; YELLOW=""; CYAN=""; BOLD=""; NC=""
fi

section() { printf "\n${BOLD}${CYAN}━━ %s ━━${NC}\n" "$*"; }
ok()      { printf "  ${GREEN}✓${NC} %s\n" "$*"; }
err()     { printf "  ${RED}✗${NC} %s\n" "$*" >&2; }
info()    { printf "  ${CYAN}ℹ${NC} %s\n" "$*"; }
warn()    { printf "  ${YELLOW}⚠${NC} %s\n" "$*"; }

wait_for_http() {
    local label="$1" url="$2" timeout="${3:-60}" elapsed=0
    while (( elapsed < timeout )); do
        local code
        code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 3 "$url" 2>/dev/null || echo "000")
        if [[ "$code" =~ ^2 ]]; then
            ok "$label: HTTP $code"
            return 0
        fi
        sleep 2
        elapsed=$((elapsed + 2))
    done
    err "$label: 未就緒 (${timeout}s timeout)"
    return 1
}

# ════════════════════════════════════════════════════════════════════════════
# Step 1: 建立外部網路
# ════════════════════════════════════════════════════════════════════════════
section "Step 1: Docker 網路"

if docker network inspect anser_project_network >/dev/null 2>&1; then
    ok "anser_project_network 已存在"
else
    docker network create anser_project_network
    ok "anser_project_network 已建立"
fi

# ════════════════════════════════════════════════════════════════════════════
# Step 2: 啟動 Gateway 堆疊（SPIRE Server 必須先於下游 Agent）
# ════════════════════════════════════════════════════════════════════════════
if [[ "$SKIP_GATEWAY" == true ]]; then
    info "跳過 Gateway 堆疊 (--skip-gateway)，假設 SPIRE Server 已在運行"
else
    section "Step 2: 啟動 Gateway 堆疊"

    COMPOSE_CMD="docker compose -f $PROJECT_DIR/docker-compose.yml"
    if [[ -n "$COMPOSE_PROFILES" ]]; then
        export COMPOSE_PROFILES
        info "使用 COMPOSE_PROFILES=$COMPOSE_PROFILES"
    fi

    $COMPOSE_CMD up -d $BUILD_FLAG 2>&1 | grep -v '^$' | sed 's/^/    /'

    # 等待 SPIRE Server 健康（下游 Agent 需要它才能完成 attestation）
    info "等待 SPIRE Server..."
    SPIRE_READY=false
    for i in $(seq 1 30); do
        if docker exec zt-spire-server /opt/spire/bin/spire-server healthcheck >/dev/null 2>&1; then
            ok "SPIRE Server 已就緒"
            SPIRE_READY=true
            break
        fi
        sleep 2
    done
    if [[ "$SPIRE_READY" != true ]]; then
        err "SPIRE Server 未就緒 (60s timeout)"
        docker logs zt-spire-server --tail 10 2>&1 | sed 's/^/    /'
        exit 1
    fi

    info "等待 RabbitMQ..."
    wait_for_http "RabbitMQ API" "http://127.0.0.1:15672/api/overview" 90 || true

    info "等待 Gateway..."
    wait_for_http "Gateway" "http://127.0.0.1:8080/api/health" 90 || true
fi

# ════════════════════════════════════════════════════════════════════════════
# Step 3: 啟動下游 Go 服務
# ════════════════════════════════════════════════════════════════════════════
section "Step 3: 啟動 Go 下游服務"

SERVICES=(
    "Order Service|Services/Order_service|8082"
    "Production Service|Services/Production_service|8083"
    "User Service|Services/User_service|8084"
)

for entry in "${SERVICES[@]}"; do
    IFS='|' read -r label dir port <<< "$entry"
    info "啟動 $label ($dir)..."

    docker compose -f "$PROJECT_DIR/$dir/docker-compose.yml" up -d $BUILD_FLAG 2>&1 | \
        grep -v '^$' | sed 's/^/    /'

    ok "$label 容器已啟動"
done

# 等待所有 Go 服務就緒
section "Step 3b: 等待 Go 服務健康"

ALL_HEALTHY=true
for entry in "${SERVICES[@]}"; do
    IFS='|' read -r label dir port <<< "$entry"
    if ! wait_for_http "$label" "http://127.0.0.1:${port}/health" 90; then
        ALL_HEALTHY=false
    fi
done

if [[ "$ALL_HEALTHY" != true ]]; then
    err "部分 Go 服務未就緒，請檢查 docker logs"
    echo ""
    for entry in "${SERVICES[@]}"; do
        IFS='|' read -r label dir port <<< "$entry"
        code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 3 "http://127.0.0.1:${port}/health" 2>/dev/null || echo "000")
        if [[ ! "$code" =~ ^2 ]]; then
            warn "$label (port $port) 最近日誌:"
            docker compose -f "$PROJECT_DIR/$dir/docker-compose.yml" logs --tail 20 2>&1 | sed 's/^/    /'
        fi
    done
    exit 1
fi

# ════════════════════════════════════════════════════════════════════════════
# Step 4: 狀態摘要
# ════════════════════════════════════════════════════════════════════════════
section "服務狀態摘要"

printf "  ${BOLD}%-25s %-8s %-10s${NC}\n" "SERVICE" "PORT" "STATUS"

check_status() {
    local label="$1" port="$2"
    local code
    code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 3 "http://127.0.0.1:${port}${3:-/health}" 2>/dev/null || echo "000")
    if [[ "$code" =~ ^2 ]]; then
        printf "  %-25s %-8s ${GREEN}%-10s${NC}\n" "$label" ":$port" "OK ($code)"
    else
        printf "  %-25s %-8s ${RED}%-10s${NC}\n" "$label" ":$port" "FAIL ($code)"
    fi
}

check_status "Order Service (Go)"      8082
check_status "Production Service (Go)" 8083
check_status "User Service (Go)"       8084

if [[ "$SKIP_GATEWAY" != true ]]; then
    check_status "RabbitMQ"             15672 "/api/overview"
    check_status "Gateway"              8080  "/api/health"
fi

echo ""
ok "啟動完成"
echo ""
info "測試訂單:"
info "  curl -X POST http://127.0.0.1:8080/api/orders \\"
info "    -H 'Content-Type: application/json' \\"
info "    -d '{\"userKey\":\"1\",\"productList\":[{\"p_key\":1,\"amount\":2}],\"total\":200}'"
echo ""
info "停止所有服務:"
info "  bash scripts/stop-go-services.sh"
