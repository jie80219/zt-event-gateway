#!/usr/bin/env bash
# ============================================================================
# ZT Event Gateway — Go 服務停止腳本
#
# 按相反順序停止所有服務：
#   1. 停止 Gateway 堆疊
#   2. 停止三個 Go 下游服務
#
# 使用方式:
#   bash scripts/stop-go-services.sh
#   bash scripts/stop-go-services.sh --volumes    # 同時移除 volumes（含 DB 資料）
#   bash scripts/stop-go-services.sh --skip-gateway
# ============================================================================
set -uo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

VOLUME_FLAG=""
SKIP_GATEWAY=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --volumes|-v) VOLUME_FLAG="-v"; shift ;;
        --skip-gateway) SKIP_GATEWAY=true; shift ;;
        -h|--help) grep -E '^# ' "$0" | sed 's/^# //'; exit 0 ;;
        *) echo "Unknown flag: $1" >&2; exit 2 ;;
    esac
done

# ── Colors ──────────────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
    GREEN=$'\033[0;32m'; CYAN=$'\033[0;36m'; BOLD=$'\033[1m'; NC=$'\033[0m'
else
    GREEN=""; CYAN=""; BOLD=""; NC=""
fi

section() { printf "\n${BOLD}${CYAN}━━ %s ━━${NC}\n" "$*"; }
ok()      { printf "  ${GREEN}✓${NC} %s\n" "$*"; }
info()    { printf "  ${CYAN}ℹ${NC} %s\n" "$*"; }

# ── Step 1: 停止 Gateway ───────────────────────────────────────────────────
if [[ "$SKIP_GATEWAY" != true ]]; then
    section "Step 1: 停止 Gateway 堆疊"
    docker compose -f "$PROJECT_DIR/docker-compose.yml" down $VOLUME_FLAG --remove-orphans 2>&1 | \
        grep -v '^$' | sed 's/^/    /'
    ok "Gateway 堆疊已停止"
fi

# ── Step 2: 停止 Go 下游服務 ───────────────────────────────────────────────
section "Step 2: 停止 Go 下游服務"

for dir in Services/User_service Services/Production_service Services/Order_service; do
    compose_file="$PROJECT_DIR/$dir/docker-compose.go.yml"
    if [[ -f "$compose_file" ]]; then
        label=$(basename "$dir")
        docker compose -f "$compose_file" down $VOLUME_FLAG --remove-orphans 2>&1 | \
            grep -v '^$' | sed 's/^/    /'
        ok "$label 已停止"
    fi
done

echo ""
ok "所有服務已停止"

if [[ -n "$VOLUME_FLAG" ]]; then
    info "已移除 volumes（DB 資料將在下次啟動時重新初始化）"
fi
