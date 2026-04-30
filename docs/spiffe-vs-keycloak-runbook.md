# SPIFFE/SPIRE vs Keycloak — 實驗 Runbook

實際執行 7-profile 安全性與效能比較實驗的步驟。配套設計見 `docs/experiment-comparison-targets.md` §2.6/§2.7。

## 7-Profile 對照矩陣

| Profile | SPIFFE | LSVID | mTLS | Keycloak | 描述 |
|---------|:------:|:-----:|:----:|:--------:|------|
| A-baseline | 0 | 0 | 0 | 0 | 純 Saga，無安全層 |
| B-mtls-only | 1 | 0 | 1 | 0 | 量化 mTLS 成本 |
| C-lsvid-only | 1 | 1 | 0 | 0 | 量化 LSVID 鑄造/驗證成本 |
| D-full-zt | 1 | 1 | 1 | 0 | 論文主張的 SPIFFE ZT |
| E-oauth2-bearer | 0 | 0 | 0 | (HS256) | 靜態 JWT，gateway-only — naive OAuth2 baseline |
| **F-keycloak-jwt** | 0 | 0 | 0 | 1 (RS256) | 真 Keycloak realm + 下游 Service JWT 驗證 |
| **G-keycloak-mtls** | 1 | 0 | 1 | 1 (RS256) | Keycloak + SPIFFE mTLS 混合（最強 OAuth2） |

論文主敘事：B/C/D 是 SPIFFE 漸進升級、E/F/G 是 OAuth2 漸進升級，A 共同 baseline，可畫出 trade-off frontier。

## 準備條件（一次性）

### zt-event-gateway 端
```bash
cd /Users/jiezhiyang/zt-event-gateway
git checkout feat/keycloak
docker compose --profile keycloak up -d keycloak keycloak-db   # 等 keycloak 就緒
# 確認 realm 已匯入：http://localhost:8080/admin → realm `zt`
```

### 三個 Service 端
所有 Service 已加上 `KeycloakJwtFilter`（routes 套上 `spiffeLsvid,keycloakJwt,...`）。`KEYCLOAK_ENABLED` 用 `${KEYCLOAK_ENABLED:-0}` env 變數覆寫。

```bash
# 各 Service 已跑過 composer install（firebase/php-jwt 已裝）
ls /Users/jiezhiyang/Order_service-1.1.3/app/vendor/firebase/php-jwt/
ls /Users/jiezhiyang/Production_service-1.0.4/app/vendor/firebase/php-jwt/
ls /Users/jiezhiyang/User_service-1.1.2/app/vendor/firebase/php-jwt/
```

## 執行流程

### 切到 SPIFFE profile（A/B/C/D）— 既有流程
三個 Service 用預設啟動（`KEYCLOAK_ENABLED=0`）：
```bash
for d in Order_service-1.1.3 Production_service-1.0.4 User_service-1.1.2; do
    (cd /Users/jiezhiyang/$d && docker compose up -d)
done
```

zt-event-gateway 跑 perf 或 security 套件：
```bash
cd /Users/jiezhiyang/zt-event-gateway
SEC_PROFILES="A-baseline B-mtls-only C-lsvid-only D-full-zt" \
    bash scripts/security-suite/run-security-suite.sh

bash scripts/perf-suite/zt-cost-matrix.sh   # 已含 A/B/C/D/F/G
```

### 切到 Keycloak profile（F/G）— 新增流程
**重點**：三個 Service 必須以 `KEYCLOAK_ENABLED=1` 重啟才會啟動 JWT 驗證 middleware。

```bash
# 1. 三個 Service 用 Keycloak 模式重啟
for d in Order_service-1.1.3 Production_service-1.0.4 User_service-1.1.2; do
    (cd /Users/jiezhiyang/$d && \
     KEYCLOAK_ENABLED=1 KEYCLOAK_REQUIRED=1 \
     docker compose up -d --force-recreate)
done

# 2. zt-event-gateway 跑 F/G profile
cd /Users/jiezhiyang/zt-event-gateway
SEC_PROFILES="F-keycloak-jwt G-keycloak-mtls" \
    bash scripts/security-suite/run-security-suite.sh

# 3. 跑完恢復 SPIFFE 模式（清掉環境變數）
for d in Order_service-1.1.3 Production_service-1.0.4 User_service-1.1.2; do
    (cd /Users/jiezhiyang/$d && docker compose up -d --force-recreate)
done
```

### 跑全部 7 profile
```bash
cd /Users/jiezhiyang/zt-event-gateway
# 先帶 Keycloak 模式啟動 Service
for d in Order_service-1.1.3 Production_service-1.0.4 User_service-1.1.2; do
    (cd /Users/jiezhiyang/$d && \
     KEYCLOAK_ENABLED=1 KEYCLOAK_REQUIRED=1 \
     docker compose up -d --force-recreate)
done

# 跑全套（用 SEC_PROFILES 預設 — 即所有 7 個）
bash scripts/security-suite/run-security-suite.sh
bash scripts/perf-suite/zt-cost-matrix.sh
```

注意 A/B/C/D profile 在 Service `KEYCLOAK_ENABLED=1` 模式下也能跑 — 因為 LSVID/SPIFFE filters 仍生效，KeycloakJwt filter 在沒收到 Bearer 時會 noop（除非 `KEYCLOAK_REQUIRED=1` — 此時 A/B 會失敗）。

## 故障排除

| 症狀 | 原因 | 解法 |
|------|------|------|
| F profile 下 Service 503 "JWKS unavailable" | Keycloak 還沒就緒或 realm 沒匯入 | 等 `curl http://keycloak:8080/realms/zt/protocol/openid-connect/certs` 回 200 |
| F profile 下 Service 403 "JWT aud does not include..." | gateway 的 service-account token 沒對應 audience | 在 Keycloak admin 把 gateway client 的 `Audience` mapper 加上三個 Service 的 client_id |
| G profile mTLS 失敗 | Service 的 `production-certs` volume 沒裝好 | `docker compose logs production-helper` 確認 SVID 已寫入 |
| 切回 A baseline 但仍 401 | 環境變數還在舊容器內 | `docker compose up -d --force-recreate` 強制重啟 |

## 產出位置

| 套件 | 輸出 |
|------|------|
| security-suite | `artifacts/security-{STAMP}/{A..G}/security-summary.json` |
| perf-suite (zt-cost-matrix) | `artifacts/zt-cost-{STAMP}/cell-*.json` + `matrix-summary.json` |

## 已知限制（要寫進論文 threat to validity）

- Service 端 `KeycloakJwtFilter` 是新實作（lazy JWKS fetch + 5min cache），不是生產級。論文應標註可能高於成熟 library
- F/G profile 的 Keycloak realm `zt` 與 client 配置依 `docker/keycloak/realm-zt.json`，token 過期 5 分鐘 — keycloak-watcher 自動續發
- 22 案例攻擊矩陣套到 F/G 時，chain-attack（C01-C03）會記為 `defense_gap`（Keycloak 無原生鏈式驗證）— 這是 LSVID 的質性優勢，不是實作 bug
- E profile（HS256 模擬）與 F profile（真 Keycloak）效能差距會反映「JWKS 拉取 + RS256 驗證」的開銷，論文應分開呈現
