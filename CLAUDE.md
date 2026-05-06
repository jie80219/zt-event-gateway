# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**zt-event-gateway** 是一個基於 **SPIFFE/SPIRE 零信任架構** 與 **事件驅動框架** 的分散式交易 API Gateway。

### 系統目標

在微服務架構中，透過 Saga 模式協調跨服務的分散式交易（建立訂單→扣庫存→扣款→完成），並使用 SPIFFE/SPIRE 為每個請求建立加密身份鏈（LSVID），確保服務間通訊的零信任安全。

### 部署架構（4 台獨立 Docker）

每個微服務皆為獨立執行的 Docker 容器。

```
┌── Docker 1 ───────────────────────────────────────────────┐
│  Gateway (OpenSwoole :8080)   ← HTTP 入口                 │
│  php-worker (AMQP consumer)   ← 事件消費 + Saga 編排       │
│  RabbitMQ (:5672/:15672)      ← 訊息佇列                   │
│  SPIRE Server + Agent         ← 身份授權中心                │
│  spiffe-watcher               ← SVID 輪換 + SHM 寫入       │
└───────────────────────────────────────────────────────────┘
         │ mTLS (X.509-SVID) + X-LSVID header
         ▼
┌── Docker 2  ─────────┐  ┌── Docker 3 ─────────┐  ┌── Docker 4 ─────────┐
│ Order-Service :8082  │  │ Production-Svc :8083│  │ User-Service :8084  │
│ SPIRE Agent          │  │ SPIRE Agent         │  │ SPIRE Agent         │
│ spiffe://zt.local    │  │ spiffe://zt.local   │  │ spiffe://zt.local   │
│ /order-service       │  │ /production-service │  │ /user-service       │
└──────────────────────┘  └─────────────────────┘  └─────────────────────┘
```

### 請求完整生命週期

```
Client POST /api/orders
  → Gateway: 正規化欄位、封裝 envelope、鑄造 LSVID L0、發送到 RabbitMQ
  → order_queue → RequestConsumer: 驗證 envelope + SPIFFE 信任域 + LSVID
  → OrderCreateRequestedEvent queue → EventConsumer → EventBus.dispatch()
  → OrderSaga（Saga 編排）:
      Step 1: 查詢商品價格 → 建立訂單（OrderService）→ OrderCreatedEvent
      Step 2: 並發扣庫存（ProductionService）→ InventoryDeductedEvent
      Step 3: 錢包扣款（UserService）→ PaymentProcessedEvent
      Step 4: 確認訂單狀態（OrderService）→ OrderSagaCompletedEvent
      失敗:  RollbackInventoryEvent → 退款 → 回滾庫存 → RollbackOrderEvent → 取消訂單
```

### LSVID 巢狀簽章鏈

```
Gateway 鑄造 L0 (iss=gateway, aud=worker)
  → Worker 擴展為 L1 (nested=L0, iss=worker, aud=worker)
  → SpiffeLsvidFilter 擴展為 L2 (nested=L1, iss=worker, aud=downstream-service)
  → 下游服務驗證完整鏈: L0 → L1 → L2
```

### SHM（共享記憶體）資料流

```
SPIRE Agent (gRPC stream)
  → bin/spiffe-watcher.php (SpiffeWorkloadWatcher + SpiffeTableStore)
  → /tmp/spiffe-shared/x509/0.json  (primary SVID)
  → /tmp/spiffe-shared/meta.json     (seqlock version)

Gateway/Worker 啟動時讀取:
  SpiffeTableReader.readX509Primary()
  → 初始化 LSVIDSigner, LSVIDValidator, SpiffeTlsContext
```

### 關鍵元件職責

| 元件 | 路徑 | 職責 |
|------|------|------|
| Gateway | `bin/gateway.php` | OpenSwoole HTTP 伺服器、LSVID L0 鑄造、AMQP 發佈 |
| Worker | `bin/worker.php` | AMQP 消費者、LSVID 驗證、mTLS 註冊、Saga 分派 |
| Spiffe-Watcher | `bin/spiffe-watcher.php` | 從 SPIRE Agent 獲取 SVID 並寫入 SHM |
| EventBus | `src/EventBus.php` | 事件分派與發佈（帶 SPIFFE 路徑追蹤） |
| Saga | `src/Saga.php` | Saga 基底類別（publish/compensate/isSuccess） |
| OrderSaga | `Sagas/OrderSaga.php` | 訂單 Saga 編排（7 個 EventHandler） |
| HandlerScanner | `src/HandlerScanner.php` | 掃描 `#[EventHandler]` 並註冊到 EventBus |
| RequestConsumer | `src/Worker/RequestConsumer.php` | 驗證 canonical envelope + SPIFFE + LSVID |
| EventConsumer | `src/Worker/EventConsumer.php` | 驗證事件身份、設定 LSVIDContext、分派事件 |
| MessageBus | `src/MessageQueue/MessageBus.php` | AMQP 發佈（LSVID 簽署/擴展） |
| SpiffeLsvidFilter | `anser-gateway/Filters/SpiffeLsvidFilter.php` | 下游 HTTP 呼叫的 LSVID 擴展 + mTLS 注入 |
| SpiffeTlsContext | `src/Spiffe/TLS/SpiffeTlsContext.php` | SVID → Guzzle/cURL mTLS 參數轉換 |

## Stack

- **Language / Runtime**: PHP `^8.3`
- **HTTP Gateway**: OpenSwoole (`bin/gateway.php`)
- **Messaging**: RabbitMQ via `php-amqplib/php-amqplib ^3.7.4`
- **Identity**: SPIRE Server + Agent, trust domain `zt.local`
- **LSVID**: Nested lightweight SVID tokens (`packages/php-lsvid`)
- **SPIFFE Client**: Workload API gRPC client (`packages/php-spiffe`)
- **Event store / Saga**: `prooph/event-store ^7.9`, `prooph/pdo-event-store ^1.15`
- **Service framework**: `sdpmlab/anser`, `sdpmlab/anser-action`
- **Supporting**: `ramsey/uuid`, `monolog/monolog`, `vlucas/phpdotenv`, `guzzlehttp/guzzle`
- **Tests**: `phpunit/phpunit ^10.5`

## Running Tests

```bash
# Unit tests
composer test:unit

# SPIFFE E2E tests
composer spiffe:e2e

# stress tests
bash scripts/stress_test.sh

# Gateway E2E (lightweight — no SPIRE stack)
bash scripts/e2e-gateway.sh

# Full-architecture E2E — 7 phases: SPIRE + LSVID + Saga + security + perf
COMPOSE_PROFILES=zt bash scripts/e2e-full-architecture.sh

# Standalone SPIRE trust-plane integrity probe
COMPOSE_PROFILES=zt composer spiffe:verify

# CI wrappers
composer ci:baseline    # SPIFFE_ENABLED=0 (no SPIRE, no LSVID)
composer ci:zt          # COMPOSE_PROFILES=zt full-architecture E2E
composer ci:verify      # default: gateway-only E2E loop
```

## CI/CD Topology

Pipeline in `.github/workflows/ci.yml` fans out into three jobs:

| Job | Mode | Script | What it verifies |
|---|---|---|---|
| `unit-tests` | — | `vendor/bin/phpunit` | Envelope, consumers, Saga, LSVID, SHM unit suite |
| `e2e-baseline` | `SPIFFE_ENABLED=0` | `scripts/e2e-gateway.sh` | Canonical envelope + Saga without any SPIFFE layer |
| `e2e-full-zt` | `COMPOSE_PROFILES=zt` | `verify-spire-integrity.sh` → `e2e-full-architecture.sh` | SPIRE server/agent/registrar/watcher integrity, LSVID chain, Saga lifecycle, security, resilience |

`scripts/ci-verify.sh` is the local driver — select a scenario via `CI_MODE={gateway|full|baseline}`. In `full` mode it auto-sets `COMPOSE_PROFILES=zt` so the SPIRE stack comes up.

## Running Experiments

Dual-mode (SPIFFE+Keycloak) perf 實驗一律透過 SSH 從本機 Mac 驅動四台主機。**任何一步失敗都必須停下排查，不要硬跑**。

### 1. 受測拓撲（SSH aliases）

| Alias | 角色 | 容器 |
|---|---|---|
| `zt-gateway` | Gateway 主機 | `zt-gateway`, `zt-php-worker`, `zt-rabbitmq`, `zt-spire-server`, `zt-spire-agent`, `zt-spiffe-watcher`, `zt-keycloak-watcher` |
| `zt-order` | 訂單服務 | Order_service + SPIRE Agent |
| `zt-prod` | 商品服務 | Production_service + SPIRE Agent |
| `zt-user` | 使用者服務 | User_service + SPIRE Agent |

> `scripts/experiments/run-dualmode-distributed.sh` 內部使用 `-lan` 後綴別名（`zt-gateway-lan`、`zt-order-lan`…）走低延遲 LAN，此處列出的 WAN 別名僅用於人工 preflight。

### 2. Preflight：分支同步 + Healthy 檢查

從本機 Mac 執行，照順序完成 (a)→(d)：

```bash
# (a) 將要測的分支同步到四台 host
BR=feat/spiffe-keycloak   # 換成你要測的分支：main / feat/* / ablation 分支
for h in zt-gateway zt-prod zt-order zt-user; do
  ssh "$h" "cd ~/zt-event-gateway && git fetch --all --prune && git checkout $BR && git pull --ff-only"
done

# (b) 起各 host 的 compose stack
ssh zt-gateway 'cd ~/zt-event-gateway && COMPOSE_PROFILES=zt docker compose up -d'
ssh zt-order   'cd ~/zt-event-gateway/Services/Order_service      && docker compose up -d'
ssh zt-prod    'cd ~/zt-event-gateway/Services/Production_service && docker compose up -d'
ssh zt-user    'cd ~/zt-event-gateway/Services/User_service       && docker compose up -d'

# (c) 健康檢查 — 四個都要 200 才繼續
ssh zt-gateway 'curl -fsS http://127.0.0.1:8080/api/health' && echo " gateway OK"
ssh zt-order   'curl -fsS http://127.0.0.1:8082/api/health' && echo " order OK"
ssh zt-prod    'curl -fsS http://127.0.0.1:8083/api/health' && echo " production OK"
ssh zt-user    'curl -fsS http://127.0.0.1:8084/api/health' && echo " user OK"

# (d) SPIRE / watcher SHM 狀態（在 gateway host 上）
ssh zt-gateway 'docker exec zt-spiffe-watcher cat /tmp/spiffe-shared/meta.json | jq .x509_state'
ssh zt-gateway 'docker exec zt-keycloak-watcher cat /tmp/keycloak-shared/meta.json | jq .token_state' # KEYCLOAK_ENABLED=1 才需要
```

任一步失敗就 **停下排查**，不要進入下一步。

### 3. Smoke test：先跑一張完整訂單

跑大量負載前必須確認單筆 saga 走完 Step 1→4，避免在已經壞掉的 build 上浪費數小時：

```bash
TRACE=smoke-$(date +%s)
ssh zt-gateway "curl -sS -X POST http://127.0.0.1:8080/api/orders \
  -H 'Content-Type: application/json' -H 'X-Correlation-Id: $TRACE' \
  -d '{\"userKey\":\"1\",\"productList\":[{\"p_key\":1,\"amount\":1}],\"total\":100}'"
# 預期：HTTP 202 + JSON 含 trace_id

sleep 5
ssh zt-gateway "docker logs --tail 200 zt-php-worker 2>&1 | grep -E '✅ Saga Step 4|RollbackSaga' | tail -5"
# Pass 條件：看到 '✅ Saga Step 4: 訂單完成！'，且該 trace 沒有 RollbackSaga
```

只有 smoke 訂單成功完成才能進入第 4 步。若走到 RollbackSaga，常見原因：下游服務 build 過舊、SPIRE 還沒 ready、DB seed 缺資料。

### 4. 執行實驗數據

兩個 runner 擇一：

**A. 分散式 runner（驅動隔離；正式量測一律用這個）** — `scripts/experiments/run-dualmode-distributed.sh`
從本機 Mac 執行（內部用 `-lan` 別名）：

```bash
OUT=artifacts/$(date +%Y%m%d-%H%M%S)-${BR##*/}
SCALES="5000 10000 20000" ROUNDS="warm cold" \
  bash scripts/experiments/run-dualmode-distributed.sh "$OUT"
```

輸出在 `$OUT/raw/`：`load_<round>_<scale>.csv`、`worker_<round>_<scale>.log`、`mtls_<round>_<scale>.err`。Cold round 會在切換時對 `zt-gateway-lan` 上的 `zt-gateway` + `zt-php-worker` 做 docker restart。

**B. 單機 runner（僅 debug 用）** — `scripts/experiments/run-dualmode-experiment.sh`
驅動與 gateway 共用同一台 Mac，數值不可發表。

### 5. 分析輸出

```bash
python3 scripts/experiments/analyze-dualmode-experiment.py --in "$OUT" --scales 5000,10000,20000
# 在 $OUT/ 產出：
#   Gateway接收請求時間.{xlsx,png}
#   訂單完成時間.{xlsx,png}
#   未完成交易率.{xlsx,png}
#   mTLS花費時間.{xlsx,png}
#   summary.xlsx, README.md
```

## File Structure

```
bin/
  gateway.php              → OpenSwoole HTTP server (ingress, L0 LSVID minting)
  worker.php               → RabbitMQ consumer (LSVID validation, Saga dispatch)
  spiffe-watcher.php       → SPIFFE SVID rotation daemon + SHM writer

src/
  EventBus.php             → 事件分派 + SPIFFE 路徑追蹤
  Saga.php                 → Saga 基底類別
  HandlerScanner.php       → #[EventHandler] 反射掃描器
  QueueTopology.php        → RabbitMQ exchange/queue/binding 宣告
  MessageQueue/            → MessageBus, Consumer, RabbitMQConnection
  Worker/                  → RequestConsumer, EventConsumer
  Ingress/                 → CanonicalOrderRequest（envelope 驗證）
  EventStore/              → Prooph event sourcing
  Spiffe/                  → 完整 SPIFFE/SPIRE 整合
    SharedMemory/          →   SHM seqlock reader/writer
    TLS/                   →   mTLS context adapters (Guzzle, cURL, stream)
    Source/                →   X509Source, JwtSource, SpiffeWorkloadWatcher
    LSVID/                 →   SpiffeTableSvidReader

anser-gateway/
  app/HTTP/Controllers/    → Order, Product, HeartBeat controllers
  Filters/                 → SpiffeLsvidFilter, JsonDoneHandler, FailHandler
  system/                  → Router, Adapter, GatewaySpiffeState
  config/                  → Routes, Filters, ServiceDiscovery

Sagas/OrderSaga.php        → 訂單 Saga（7 個 EventHandler）
Event-Driven/Events/       → 10 個事件類別
Services/                  → OrderService, ProductionService, UserService
  Models/                  → OrderProductDetail, ModifyProduct

packages/
  php-spiffe/              → SPIFFE Workload API client (symlink)
  php-lsvid/               → LSVID signer/validator (symlink)

spiffe/
  spire-server-e2e/        → SPIRE Server 設定
  spire-agent-e2e/         → SPIRE Agent 設定
  scripts/                 → register-workloads.sh
  certs/                   → Agent CA/cert/key

docker/
  php-openswoole/          → Gateway + Worker Dockerfile
  php-spiffe/              → SPIFFE client Dockerfile (Swow runtime)
  rabbitmq/            ls -la ~/.ssh/    → RabbitMQ config + definitions
```

## Docker Topology

```bash
# All-in-one: SPIRE Server/Agent + RabbitMQ + Gateway + Worker
docker compose up -d

# SPIFFE E2E tests only
docker compose -f docker-compose.spiffe.yml up -d
```

## Key Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `SPIFFE_ENABLED` | `1` | 主開關：設為 `0` 時整體關閉 SPIFFE/LSVID/mTLS 並跳過 SPIRE 基礎設施（搭配 compose profile `zt`）。覆蓋下方三個子開關 |
| `SPIFFE_ID` | `''` | 本服務的 SPIFFE ID |
| `SPIFFE_ENDPOINT_SOCKET` | `''` | SPIRE Agent UDS socket |
| `SPIFFE_SHM_DIR` | `/tmp/spiffe-shared` | SHM 目錄 |
| `LSVID_ENABLED` | `1` | 啟用 LSVID |
| `LSVID_REQUIRED` | `0` | 強制要求 LSVID（fail-closed） |
| `SPIFFE_MTLS_ENABLED` | `0` | 啟用 mTLS |
| `DOWNSTREAM_SPIFFE_ID` | `''` | LSVID audience |
| `ORDER_SERVICE_HOST/PORT` | `localhost:8082` | 訂單服務（獨立 Docker） |
| `PRODUCTION_SERVICE_HOST/PORT` | `localhost:8083` | 商品服務（獨立 Docker） |
| `USER_SERVICE_HOST/PORT` | `localhost:8084` | 使用者服務（獨立 Docker） |

## Key Patterns

- **Saga pattern**: OrderSaga with compensating transactions (inventory rollback, wallet refund, order cancellation)
- **LSVID chain**: Gateway mints L0 → Worker extends to L1 → SpiffeLsvidFilter extends to L2 for downstream HTTP calls
- **mTLS**: Worker → downstream services via SPIFFE X.509-SVID (SpiffeTlsContext, RoadRunner client_auth_type: require_and_verify_client_cert)
- **Coroutine safety**: OpenSwoole coroutines with per-coroutine LSVIDContext and mutex-protected AMQP channel
- **SHM seqlock**: Cross-process SVID sharing via filesystem + version-based consistency protocol
- **Canonical envelope**: schema_version=1, type=gateway.request, strict SPIFFE identity validation

## Skills

Use the following skills when working on related files:

| File(s) | Skill |
|---------|-------|
| `README.md` | `/readme` |
| `.github/workflows/*.yml` | `/ci-workflow` |