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

# Gateway E2E tests
bash scripts/e2e-gateway.sh

# CI verification (unit + E2E)
bash scripts/ci-verify.sh
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
  rabbitmq/                → RabbitMQ config + definitions
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