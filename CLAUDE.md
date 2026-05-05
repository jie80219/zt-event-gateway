# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**zt-event-gateway** 是一個基於 **事件驅動框架** 與 **Saga 模式** 的分散式交易 API Gateway。

### 系統目標

在微服務架構中，透過 Saga 模式協調跨服務的分散式交易（建立訂單→扣庫存→扣款→完成）。先前的 SPIFFE/SPIRE/LSVID 與 Keycloak 身份層已移除，準備接入 **Linkerd 1.x**（Docker-native service mesh）作為觀測層與服務發現。

### 部署架構（4 台獨立 Docker）

每個微服務皆為獨立執行的 Docker 容器。

```
┌── Docker 1 ───────────────────────────────────────────────┐
│  Gateway (OpenSwoole :8080)   ← HTTP 入口                 │
│  php-worker (AMQP consumer)   ← 事件消費 + Saga 編排       │
│  RabbitMQ (:5672/:15672)      ← 訊息佇列                   │
│  EventStoreDB (:2113)         ← 事件溯源                    │
└───────────────────────────────────────────────────────────┘
         │ HTTP
         ▼
┌── Docker 2  ─────────┐  ┌── Docker 3 ─────────┐  ┌── Docker 4 ─────────┐
│ Order-Service :8082  │  │ Production-Svc :8083│  │ User-Service :8084  │
└──────────────────────┘  └─────────────────────┘  └─────────────────────┘
```

### 請求完整生命週期

```
Client POST /api/orders
  → Gateway: 正規化欄位、封裝 envelope、發送到 RabbitMQ
  → order_queue → RequestConsumer: 驗證 envelope schema
  → OrderCreateRequestedEvent queue → EventConsumer → EventBus.dispatch()
  → OrderSaga（Saga 編排）:
      Step 1: 查詢商品價格 → 建立訂單（OrderService）→ OrderCreatedEvent
      Step 2: 並發扣庫存（ProductionService）→ InventoryDeductedEvent
      Step 3: 錢包扣款（UserService）→ PaymentProcessedEvent
      Step 4: 確認訂單狀態（OrderService）→ OrderSagaCompletedEvent
      失敗:  RollbackInventoryEvent → 退款 → 回滾庫存 → RollbackOrderEvent → 取消訂單
```

### 關鍵元件職責

| 元件 | 路徑 | 職責 |
|------|------|------|
| Gateway | `bin/gateway.php` | OpenSwoole HTTP 伺服器、AMQP 發佈 |
| Worker | `bin/worker.php` | AMQP 消費者、Saga 分派 |
| EventBus | `src/EventBus.php` | 事件分派與發佈 |
| Saga | `src/Saga.php` | Saga 基底類別（publish/compensate/isSuccess） |
| OrderSaga | `Sagas/OrderSaga.php` | 訂單 Saga 編排（7 個 EventHandler） |
| HandlerScanner | `src/HandlerScanner.php` | 掃描 `#[EventHandler]` 並註冊到 EventBus |
| RequestConsumer | `src/Worker/RequestConsumer.php` | 驗證 canonical envelope |
| EventConsumer | `src/Worker/EventConsumer.php` | 反序列化事件並分派 |
| MessageBus | `src/MessageQueue/MessageBus.php` | AMQP 發佈 |

## Stack

- **Language / Runtime**: PHP `^8.3`
- **HTTP Gateway**: OpenSwoole (`bin/gateway.php`)
- **Messaging**: RabbitMQ via `php-amqplib/php-amqplib ^3.7.4`
- **Event store / Saga**: `prooph/event-store ^7.9`, `prooph/pdo-event-store ^1.15`
- **Service framework**: `sdpmlab/anser`, `sdpmlab/anser-action`
- **Supporting**: `ramsey/uuid`, `monolog/monolog`, `vlucas/phpdotenv`, `guzzlehttp/guzzle`
- **Tests**: `phpunit/phpunit ^10.5`

## Running Tests

```bash
# Unit tests
composer test:unit

# Gateway E2E (lightweight)
bash scripts/e2e-gateway.sh

# CI baseline wrapper
composer ci:baseline
```

## CI/CD Topology

Pipeline in `.github/workflows/ci.yml`:

| Job | Script | What it verifies |
|---|---|---|
| `unit-tests` | `vendor/bin/phpunit` | Envelope, consumers, Saga, EventBus unit suite |
| `e2e-baseline` | `scripts/ci-verify.sh` | Canonical envelope + Saga end-to-end |

## File Structure

```
bin/
  gateway.php              → OpenSwoole HTTP server (ingress)
  worker.php               → RabbitMQ consumer (Saga dispatch)

src/
  EventBus.php             → 事件分派
  Saga.php                 → Saga 基底類別
  HandlerScanner.php       → #[EventHandler] 反射掃描器
  QueueTopology.php        → RabbitMQ exchange/queue/binding 宣告
  MessageQueue/            → MessageBus, Consumer, RabbitMQConnection
  Worker/                  → RequestConsumer, EventConsumer
  Ingress/                 → CanonicalOrderRequest（envelope 驗證）
  EventStore/              → Prooph event sourcing

anser-gateway/
  app/HTTP/Controllers/    → Order, Product, HeartBeat controllers
  Filters/                 → JsonDoneHandler, FailHandler
  system/                  → Router, Adapter
  config/                  → Routes, Filters, ServiceDiscovery

Sagas/OrderSaga.php        → 訂單 Saga（7 個 EventHandler）
Event-Driven/Events/       → 10 個事件類別
Services/                  → OrderService, ProductionService, UserService
  Models/                  → OrderProductDetail, ModifyProduct

docker/
  php-openswoole/          → Gateway + Worker Dockerfile
  rabbitmq/                → RabbitMQ config + definitions
```

## Docker Topology

```bash
# Single host: RabbitMQ + EventStoreDB + Gateway + Worker
docker compose up -d
```

## Key Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `ORDER_SERVICE_HOST/PORT` | `10.1.1.210:8082` | 訂單服務 |
| `PRODUCTION_SERVICE_HOST/PORT` | `10.1.1.207:8083` | 商品服務 |
| `USER_SERVICE_HOST/PORT` | `10.1.1.214:8084` | 使用者服務 |
| `EVENTSTOREDB_ENABLED` | `1` | 啟用 EventStoreDB 寫入 |
| `RABBITMQ_HOST/PORT/USER/PASS` | `rabbitmq:5672/zt/ztpass` | AMQP broker |

## Key Patterns

- **Saga pattern**: OrderSaga with compensating transactions (inventory rollback, wallet refund, order cancellation)
- **Coroutine safety**: OpenSwoole coroutines with mutex-protected AMQP channel
- **Canonical envelope**: schema_version=1, type=gateway.request, route + id + data
