# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**zt-event-gateway** 是一個基於 **事件驅動框架** 與 **Saga 模式** 的分散式交易 API Gateway。

### 系統目標

在微服務架構中，透過 Saga 模式協調跨服務的分散式交易（建立訂單→扣庫存→扣款→完成），並以 **Linkerd 1.x**（Docker-native service mesh）作為觀測層與服務發現。

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

## Running Experiments

> **此流程適用於所有分支**。每換到一個分支量測（main / feat/Linkerd1 / ablation 分支 / …），都必須照下方順序走完 §1→§5 才能執行 `scripts/experiments/` 下的負載腳本。**任一步失敗都要停下排查，不要硬跑**。

### 1. 受測拓撲（SSH aliases）

| Alias | 角色 | 容器位置 |
|---|---|---|
| `zt-gateway` | Gateway 主機 | `~/zt-event-gateway/` 主 stack（gateway + worker + RabbitMQ + EventStoreDB；branch-specific sidecar 另計） |
| `zt-order`   | 訂單服務     | `~/zt-event-gateway/Services/Order_service/` |
| `zt-prod`    | 商品服務     | `~/zt-event-gateway/Services/Production_service/` |
| `zt-user`    | 使用者服務   | `~/zt-event-gateway/Services/User_service/` |

> 分散式 runner 內部使用 `-lan` 後綴別名（`zt-gateway-lan`、`zt-order-lan`…）走低延遲 LAN，本節列出的 WAN 別名只用於人工 preflight。

### 2. Preflight：分支同步 + Healthy 檢查

從本機 Mac 執行，照順序完成 (a)→(c)：

```bash
# (a) 將要測的分支同步到四台 host
BR=main   # 換成你要測的分支：main / feat/Linkerd1 / ablation 分支
for h in zt-gateway zt-prod zt-order zt-user; do
  ssh "$h" "cd ~/zt-event-gateway && git fetch --all --prune && git checkout $BR && git pull --ff-only"
done

# (b) 起各 host 的 compose stack（profile 由分支 docker-compose 決定）
ssh zt-gateway 'cd ~/zt-event-gateway && docker compose up -d'
ssh zt-order   'cd ~/zt-event-gateway/Services/Order_service      && docker compose up -d'
ssh zt-prod    'cd ~/zt-event-gateway/Services/Production_service && docker compose up -d'
ssh zt-user    'cd ~/zt-event-gateway/Services/User_service       && docker compose up -d'

# (c) 健康檢查 — 四個都要 200 才繼續
ssh zt-gateway 'curl -fsS http://127.0.0.1:8080/api/health' && echo " gateway OK"
ssh zt-order   'curl -fsS http://127.0.0.1:8082/api/health' && echo " order OK"
ssh zt-prod    'curl -fsS http://127.0.0.1:8083/api/health' && echo " production OK"
ssh zt-user    'curl -fsS http://127.0.0.1:8084/api/health' && echo " user OK"
```

> **Branch-specific 額外檢查**：若該分支引入 service mesh（例：Linkerd），請在進入 §3 之前另外確認對應 sidecar ready（Linkerd 1.x 用 `curl :9990/admin/ping` 應回 `pong`、`curl :9411/health` 應回 `up`）。具體檢查項由各分支自己的 README / 註解決定。

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

只有 smoke 訂單成功完成才能進入 §4。若走到 RollbackSaga，常見原因：下游服務 build 過舊、DB seed 缺資料、branch-specific 身份/網路層未 ready。

### 4. 執行實驗數據

兩個 runner 擇一：

**A. 分散式 runner（驅動隔離；正式量測一律用這個）** — `scripts/experiments/run-perf-multihost.sh`
從本機 Mac 執行（內部用 `-lan` 別名）：

```bash
OUT=artifacts/$(date +%Y%m%d-%H%M%S)-${BR##*/}
SCALES="5000 10000 20000" ROUNDS="warm cold" \
  bash scripts/experiments/run-perf-multihost.sh "$OUT"
```

輸出在 `$OUT/raw/`：`load_<round>_<scale>.csv`、`worker_<round>_<scale>.log`、`mtls_<round>_<scale>.err`。Cold round 會在切換時對 `zt-gateway-lan` 上的 `zt-gateway` + `zt-php-worker` 做 docker restart。

> `mtls_*.err` 命名是腳本歷史殘留；對 baseline / linkerd-only 等沒有 mTLS 的分支，檔案仍會產出但內容可忽略。

**B. 單機 runner（僅 debug 用）** — `scripts/experiments/run-perf-experiment.sh`
驅動與 gateway 共用同一台 Mac，數值不可發表。

### 5. 分析輸出

```bash
python3 scripts/experiments/analyze-perf-experiment.py --in "$OUT" --scales 5000,10000,20000
# 在 $OUT/ 產出：
#   Gateway接收請求時間.{xlsx,png}
#   訂單完成時間.{xlsx,png}
#   未完成交易率.{xlsx,png}
#   mTLS花費時間.{xlsx,png}
#   summary.xlsx, README.md
```

跨分支比較時，把每個分支跑出來的 `summary.xlsx` 並排彙整即可（建議 `$OUT` 目錄名稱含分支名以利後續對齊）。

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
