#!/usr/bin/env bash
# ============================================================================
# ZT Event Gateway — SPIFFE/LSVID 安全審計腳本
#
# 審計 SPIFFE/LSVID 配置與安全性：
#   1. 環境變數配置矩陣
#   2. SHM 檔案完整性
#   3. SVID 憑證有效期
#   4. Trust domain 一致性
#   5. LSVID 鏈路完整性檢查
#   6. mTLS 配置檢查
#   7. 安全建議
#
# 使用方式:
#   bash scripts/analyze-spiffe.sh
#   COMPOSE_PROFILES=zt bash scripts/analyze-spiffe.sh
#
# 環境變數:
#   SHM_DIR             (default: /tmp/spiffe-shared)
#   GATEWAY_CONTAINER   (default: zt-gateway)
#   WORKER_CONTAINER    (default: zt-php-worker)
#   WATCHER_CONTAINER   (default: zt-spiffe-watcher)
# ============================================================================
set -uo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

SHM_DIR="${SHM_DIR:-/tmp/spiffe-shared}"
GATEWAY_CONTAINER="${GATEWAY_CONTAINER:-zt-gateway}"
WORKER_CONTAINER="${WORKER_CONTAINER:-zt-php-worker}"
WATCHER_CONTAINER="${WATCHER_CONTAINER:-zt-spiffe-watcher}"

# ── Colors ──────────────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
    RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'
    CYAN=$'\033[0;36m'; BOLD=$'\033[1m'; DIM=$'\033[2m'; NC=$'\033[0m'
else
    RED=""; GREEN=""; YELLOW=""; CYAN=""; BOLD=""; DIM=""; NC=""
fi

PASS=0; WARN=0; FAIL=0

section() { printf "\n${BOLD}${CYAN}━━ %s ━━${NC}\n" "$*"; }
ok()      { PASS=$((PASS+1)); printf "  ${GREEN}✓${NC} %s\n" "$*"; }
warn()    { WARN=$((WARN+1)); printf "  ${YELLOW}⚠${NC} %s\n" "$*"; }
err()     { FAIL=$((FAIL+1)); printf "  ${RED}✗${NC} %s\n" "$*"; }
info()    { printf "  ${DIM}ℹ %s${NC}\n" "$*"; }

read_env() {
    docker exec "$1" printenv "$2" 2>/dev/null || echo ""
}

# ════════════════════════════════════════════════════════════════════════════
# 1. 配置矩陣
# ════════════════════════════════════════════════════════════════════════════
section "1. SPIFFE/LSVID 配置矩陣"

VARS=(SPIFFE_ENABLED LSVID_ENABLED LSVID_REQUIRED SPIFFE_MTLS_ENABLED SPIFFE_ID DOWNSTREAM_SPIFFE_ID SPIFFE_SHM_DIR LSVID_TTL_SECONDS)
CONTAINERS=($GATEWAY_CONTAINER $WORKER_CONTAINER $WATCHER_CONTAINER)

# Header
printf "  ${BOLD}%-30s" "變數"
for c in "${CONTAINERS[@]}"; do
    short="${c#zt-}"
    printf " %-20s" "$short"
done
printf "${NC}\n"

# Rows
for var in "${VARS[@]}"; do
    printf "  %-30s" "$var"
    for c in "${CONTAINERS[@]}"; do
        val=$(read_env "$c" "$var")
        if [[ -n "$val" ]]; then
            printf " %-20s" "$val"
        else
            printf " ${DIM}%-20s${NC}" "(unset)"
        fi
    done
    printf "\n"
done

# ════════════════════════════════════════════════════════════════════════════
# 2. 配置一致性驗證
# ════════════════════════════════════════════════════════════════════════════
section "2. 配置一致性驗證"

gw_enabled=$(read_env "$GATEWAY_CONTAINER" SPIFFE_ENABLED)
wk_enabled=$(read_env "$WORKER_CONTAINER" SPIFFE_ENABLED)
gw_lsvid=$(read_env "$GATEWAY_CONTAINER" LSVID_ENABLED)
wk_required=$(read_env "$WORKER_CONTAINER" LSVID_REQUIRED)
gw_id=$(read_env "$GATEWAY_CONTAINER" SPIFFE_ID)
wk_id=$(read_env "$WORKER_CONTAINER" SPIFFE_ID)
wk_downstream=$(read_env "$WORKER_CONTAINER" DOWNSTREAM_SPIFFE_ID)

# SPIFFE_ENABLED 一致性
if [[ "$gw_enabled" == "$wk_enabled" ]]; then
    ok "SPIFFE_ENABLED 一致 (gateway=$gw_enabled, worker=$wk_enabled)"
elif [[ -z "$gw_enabled" && -z "$wk_enabled" ]]; then
    info "容器可能未運行，無法讀取環境變數"
else
    err "SPIFFE_ENABLED 不一致 (gateway=$gw_enabled, worker=$wk_enabled)"
fi

# LSVID 配置矩陣
if [[ -n "$gw_lsvid" || -n "$wk_required" ]]; then
    printf "\n  ${BOLD}LSVID 配置矩陣:${NC}\n"
    printf "  ┌──────────────────────┬────────────────────────┬───────────────────────┐\n"
    printf "  │                      │ LSVID_REQUIRED=0       │ LSVID_REQUIRED=1      │\n"
    printf "  ├──────────────────────┼────────────────────────┼───────────────────────┤\n"
    printf "  │ LSVID_ENABLED=1      │ 遷移模式（降級放行）   │ 生產模式（推薦）      │\n"
    printf "  │ LSVID_ENABLED=0      │ 無 LSVID               │ ⚠ 衝突（會拒絕全部）   │\n"
    printf "  └──────────────────────┴────────────────────────┴───────────────────────┘\n"

    current="LSVID_ENABLED=${gw_lsvid:-?}, LSVID_REQUIRED=${wk_required:-?}"
    if [[ "$gw_lsvid" == "1" && "$wk_required" == "1" ]]; then
        ok "當前: $current → 生產模式"
    elif [[ "$gw_lsvid" == "1" && "$wk_required" != "1" ]]; then
        warn "當前: $current → 遷移模式"
    elif [[ "$gw_lsvid" != "1" && "$wk_required" == "1" ]]; then
        err "當前: $current → 衝突！Gateway 不鑄造但 Worker 要求"
    else
        info "當前: $current → 無 LSVID"
    fi
fi

# Audience 鏈路
if [[ -n "$gw_id" && -n "$wk_id" ]]; then
    gw_downstream=$(read_env "$GATEWAY_CONTAINER" DOWNSTREAM_SPIFFE_ID)
    if [[ "$gw_downstream" == "$wk_id" ]]; then
        ok "Gateway DOWNSTREAM_SPIFFE_ID ($gw_downstream) 匹配 Worker SPIFFE_ID"
    elif [[ -n "$gw_downstream" ]]; then
        warn "Gateway DOWNSTREAM_SPIFFE_ID ($gw_downstream) ≠ Worker SPIFFE_ID ($wk_id)"
    fi
fi

# Trust domain 一致性
if [[ -n "$gw_id" && -n "$wk_id" ]]; then
    gw_domain=$(echo "$gw_id" | grep -oP 'spiffe://[^/]+' || echo "")
    wk_domain=$(echo "$wk_id" | grep -oP 'spiffe://[^/]+' || echo "")
    if [[ "$gw_domain" == "$wk_domain" && -n "$gw_domain" ]]; then
        ok "Trust domain 一致: $gw_domain"
    elif [[ -n "$gw_domain" && -n "$wk_domain" ]]; then
        err "Trust domain 不一致: gateway=$gw_domain, worker=$wk_domain"
    fi
fi

# ════════════════════════════════════════════════════════════════════════════
# 3. SHM 檔案完整性
# ════════════════════════════════════════════════════════════════════════════
section "3. SHM 檔案完整性"

if [[ -d "$SHM_DIR" ]]; then
    ok "SHM 目錄存在: $SHM_DIR"

    # meta.json
    META="$SHM_DIR/meta.json"
    if [[ -f "$META" ]]; then
        meta_content=$(cat "$META" 2>/dev/null)
        if echo "$meta_content" | jq empty 2>/dev/null; then
            ok "meta.json 合法 JSON"

            version=$(echo "$meta_content" | jq -r '.version // "?"')
            x509_state=$(echo "$meta_content" | jq -r '.x509_state // "?"')
            updated_at=$(echo "$meta_content" | jq -r '.updated_at // "?"')
            error_count=$(echo "$meta_content" | jq -r '.error_count // 0')

            printf "    version:     %s\n" "$version"
            printf "    x509_state:  %s\n" "$x509_state"
            printf "    updated_at:  %s\n" "$updated_at"
            printf "    error_count: %s\n" "$error_count"

            # Seqlock 檢查
            if [[ "$version" =~ ^[0-9]+$ ]] && (( version % 2 != 0 )); then
                err "seqlock version 為奇數 ($version) — watcher 可能在寫入中崩潰"
            fi

            # 錯誤計數
            if [[ "$error_count" =~ ^[0-9]+$ ]] && (( error_count > 0 )); then
                warn "SHM 記錄了 $error_count 個錯誤"
                last_error=$(echo "$meta_content" | jq -r '.last_error // "none"')
                [[ "$last_error" != "none" ]] && printf "    last_error:  %s\n" "$last_error"
            fi
        else
            err "meta.json 不是合法 JSON"
        fi
    else
        err "meta.json 不存在"
    fi

    # x509/0.json
    SVID="$SHM_DIR/x509/0.json"
    if [[ -f "$SVID" ]]; then
        svid_content=$(cat "$SVID" 2>/dev/null)
        if echo "$svid_content" | jq empty 2>/dev/null; then
            ok "x509/0.json 合法 JSON"

            spiffe_id=$(echo "$svid_content" | jq -r '.spiffe_id // "?"')
            has_cert=$(echo "$svid_content" | jq -r 'has("cert_pem") // false')
            has_key=$(echo "$svid_content" | jq -r 'has("key_pem") // false')
            has_bundle=$(echo "$svid_content" | jq -r 'has("bundle_pem") // false')

            printf "    spiffe_id:   %s\n" "$spiffe_id"
            [[ "$has_cert" == "true" ]] && ok "包含 cert_pem" || err "缺少 cert_pem"
            [[ "$has_key" == "true" ]] && ok "包含 key_pem" || err "缺少 key_pem"
            [[ "$has_bundle" == "true" ]] && ok "包含 bundle_pem" || err "缺少 bundle_pem"

            # 嘗試解析憑證有效期
            cert_pem=$(echo "$svid_content" | jq -r '.cert_pem // ""')
            if [[ -n "$cert_pem" && "$cert_pem" != "null" ]]; then
                not_after=$(echo "$cert_pem" | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2 || echo "")
                if [[ -n "$not_after" ]]; then
                    printf "    有效至:      %s\n" "$not_after"
                    # 檢查是否即將過期（1 小時內）
                    if openssl x509 -checkend 3600 <<< "$cert_pem" >/dev/null 2>&1; then
                        ok "憑證有效（> 1 小時）"
                    else
                        warn "憑證將在 1 小時內過期"
                    fi
                fi
            fi
        else
            err "x509/0.json 不是合法 JSON"
        fi
    else
        err "x509/0.json 不存在"
    fi
else
    watcher_state=$(docker inspect -f '{{.State.Status}}' "$WATCHER_CONTAINER" 2>/dev/null || echo "not found")
    if [[ "$watcher_state" == "running" ]]; then
        err "SHM 目錄不存在但 watcher 正在運行 — 可能是掛載問題"
    else
        info "SHM 目錄不存在（SPIFFE 未啟用時正常）"
    fi
fi

# ════════════════════════════════════════════════════════════════════════════
# 4. Watcher 健康
# ════════════════════════════════════════════════════════════════════════════
section "4. Watcher 健康"

watcher_state=$(docker inspect -f '{{.State.Status}}' "$WATCHER_CONTAINER" 2>/dev/null || echo "not found")
if [[ "$watcher_state" == "running" ]]; then
    ok "Watcher 容器運行中"

    # 檢查 watcher 最近 log
    watcher_errors=$(docker logs "$WATCHER_CONTAINER" --tail 100 2>&1 | grep -ci 'error\|exception\|fatal' || true)
    if (( watcher_errors > 0 )); then
        warn "Watcher 最近 100 行有 $watcher_errors 個錯誤"
        docker logs "$WATCHER_CONTAINER" --tail 100 2>&1 | grep -i 'error\|exception\|fatal' | tail -3 | sed 's/^/    /'
    else
        ok "Watcher 最近無錯誤"
    fi

    # 檢查 health endpoint
    watcher_health_port=$(read_env "$WATCHER_CONTAINER" SPIFFE_WATCHER_HEALTH_PORT)
    if [[ -n "$watcher_health_port" ]]; then
        health_code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 3 "http://127.0.0.1:${watcher_health_port}/health" 2>/dev/null || echo "000")
        if [[ "$health_code" =~ ^2 ]]; then
            ok "Watcher health endpoint: HTTP $health_code"
        else
            warn "Watcher health endpoint: HTTP $health_code"
        fi
    fi
elif [[ "$watcher_state" == "not found" ]]; then
    info "Watcher 未部署（SPIFFE 未啟用時正常）"
else
    err "Watcher 容器狀態: $watcher_state"
fi

# ════════════════════════════════════════════════════════════════════════════
# 5. 原始碼安全掃描
# ════════════════════════════════════════════════════════════════════════════
section "5. 原始碼安全掃描"

# 檢查 ALLOWED_SOURCES 設定
for consumer_file in src/Worker/RequestConsumer.php src/Worker/EventConsumer.php; do
    if [[ -f "$consumer_file" ]]; then
        sources=$(grep -oP "spiffe://[^']+" "$consumer_file" 2>/dev/null | sort -u)
        if [[ -n "$sources" ]]; then
            printf "  %s ALLOWED_SOURCES:\n" "$(basename "$consumer_file")"
            echo "$sources" | sed 's/^/    /'
        fi
    fi
done

# 檢查是否有 exec/system/shell_exec 呼叫
unsafe_calls=$(grep -rn --include='*.php' -E '\b(exec|system|shell_exec|passthru|proc_open)\s*\(' src/ bin/ anser-gateway/ Sagas/ 2>/dev/null | grep -v 'vendor/' || true)
if [[ -n "$unsafe_calls" ]]; then
    warn "發現外部命令呼叫（需人工審查）:"
    echo "$unsafe_calls" | head -5 | sed 's/^/    /'
else
    ok "源碼中無 exec/system/shell_exec 呼叫"
fi

# 檢查是否有硬編碼密碼
hardcoded=$(grep -rn --include='*.php' -iE '(password|secret|token)\s*=\s*["\x27][^"\x27]{3,}' src/ bin/ anser-gateway/ Sagas/ 2>/dev/null | grep -v 'getenv\|->get\|env(' || true)
if [[ -n "$hardcoded" ]]; then
    warn "可能有硬編碼密碼/token:"
    echo "$hardcoded" | head -3 | sed 's/^/    /'
else
    ok "未發現硬編碼密碼"
fi

# ════════════════════════════════════════════════════════════════════════════
# 6. 安全建議
# ════════════════════════════════════════════════════════════════════════════
section "6. 安全建議"

recommendations=()

if [[ "$wk_required" != "1" ]]; then
    recommendations+=("將 LSVID_REQUIRED 設為 1 以啟用 fail-closed 模式")
fi

mtls_enabled=$(read_env "$WORKER_CONTAINER" SPIFFE_MTLS_ENABLED)
if [[ "$mtls_enabled" != "1" ]]; then
    recommendations+=("啟用 SPIFFE_MTLS_ENABLED=1 以加密服務間通訊")
fi

if [[ -z "$wk_downstream" ]]; then
    recommendations+=("設定 DOWNSTREAM_SPIFFE_ID 以確保 LSVID audience 正確")
fi

# 檢查 Worker 是否有 SVID 輪換監聽
if ! grep -q 'watchVersion\|rotation' bin/worker.php 2>/dev/null; then
    recommendations+=("Worker 缺少 SVID 輪換監聽（Gateway 有但 Worker 沒有）")
fi

# 檢查 JTI replay cache 是否為分散式
if ! grep -q 'redis\|memcache\|distributed' src/ bin/ 2>/dev/null; then
    recommendations+=("JTI replay cache 為 per-process，考慮使用分散式 cache")
fi

if (( ${#recommendations[@]} > 0 )); then
    for rec in "${recommendations[@]}"; do
        printf "  ${YELLOW}→${NC} %s\n" "$rec"
    done
else
    ok "目前配置符合最佳實踐"
fi

# ════════════════════════════════════════════════════════════════════════════
# 摘要
# ════════════════════════════════════════════════════════════════════════════
section "審計摘要"
printf "  ${GREEN}通過: %d${NC}  ${YELLOW}警告: %d${NC}  ${RED}失敗: %d${NC}\n" "$PASS" "$WARN" "$FAIL"

if (( FAIL > 0 )); then
    printf "\n  ${RED}${BOLD}發現 %d 個安全問題需要立即處理。${NC}\n" "$FAIL"
    exit 1
elif (( WARN > 0 )); then
    printf "\n  ${YELLOW}安全配置基本正常，有 %d 個建議需要關注。${NC}\n" "$WARN"
    exit 0
else
    printf "\n  ${GREEN}${BOLD}SPIFFE/LSVID 安全配置通過審計。${NC}\n"
    exit 0
fi
