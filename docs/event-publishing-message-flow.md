# 完整事件發佈與訊息傳遞流程分析

## Context

本文件為 zt-event-gateway 專案中 **publishEvent 事件發佈** 及 **訊息傳遞** 的完整分析，涵蓋從 Client 發送 HTTP 請求到訂單完成（或回滾）的每一個步驟。包含每個階段的 **payload**、**metadata**、**envelope** 內容以及 LSVID 身份鏈的演化。

---

## 一、總覽流程圖

```
Client POST /api/orders
  │
  ▼
┌─────────────────────────────────────────────────────────────────┐
│  Phase 1: Gateway (Order Controller)                            │
│  ① 正規化 Request Body → CanonicalOrderRequest                  │
│  ② 鑄造 LSVID L0 (iss=gateway, aud=worker)                     │
│  ③ 組裝 Request Envelope                                        │
│  ④ 發佈到 RabbitMQ: exchange=events, routing_key=request.new    │
│  ⑤ 回傳 HTTP 202 Accepted                                      │
└──────────────────────────────┬──────────────────────────────────┘
                               │ AMQP (order_queue)
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│  Phase 2: RequestConsumer                                       │
│  ① 解析 JSON → validateEnvelope()                               │
│  ② 驗證 SPIFFE source (trust domain: zt.local)                  │
│  ③ 驗證 LSVID L0 (aud=worker, sub=gateway)                     │
│  ④ 透過 MessageBus.publishEvent() 擴展 LSVID 為 L1              │
│  ⑤ 發佈 Event Envelope 到 RabbitMQ                              │
│     exchange=events, routing_key=OrderCreateRequestedEvent      │
└──────────────────────────────┬──────────────────────────────────┘
                               │ AMQP (OrderCreateRequestedEvent queue)
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│  Phase 3: EventConsumer                                         │
│  ① 解析 JSON → 檢查 type/data 欄位                               │
│  ② 驗證 SPIFFE source                                           │
│  ③ 驗證 LSVID L1 chain (L0→L1)                                  │
│  ④ 設定 LSVIDContext (coroutine-local)                           │
│  ⑤ buildEventInstance() → EventBus.dispatch()                   │
│  ⑥ 清除 LSVIDContext                                            │
└──────────────────────────────┬──────────────────────────────────┘
                               │ dispatch to Saga handler
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│  Phase 4: OrderSaga (7 個 EventHandler)                         │
│  每個步驟透過 Saga.publish() → EventBus.publish()                │
│  → MessageBus.publishEvent() 發佈下一個事件                       │
│  (詳見下方 Saga 步驟圖)                                          │
└─────────────────────────────────────────────────────────────────┘
```

---

## 二、Saga 完整步驟流程圖

### 成功路徑 (Happy Path)

```
OrderCreateRequestedEvent ─[onOrderCreateRequested]─→ HTTP 呼叫下游服務
  │  ① ProductionService.productInfoAction(p_key) → 取得商品價格
  │  ② OrderService.createOrderAction(userKey, orderId, productList) → 建立訂單
  │  ③ publish(OrderCreatedEvent)
  ▼
OrderCreatedEvent ─[onOrderCreated]─→ HTTP 呼叫下游服務
  │  ① ProductionService.reduceInventory(p_key, orderId, amount) × N (ConcurrentAction 並發)
  │  ② publish(InventoryDeductedEvent)
  ▼
InventoryDeductedEvent ─[onInventoryDeducted]─→ HTTP 呼叫下游服務
  │  ① UserService.walletChargeAction(userKey, orderId, total) → 扣款
  │  ├─ 失敗 → compensate(RollbackInventoryEvent) [進入補償路徑]
  │  └─ 成功 → publish(PaymentProcessedEvent)
  ▼
PaymentProcessedEvent ─[onPaymentProcessed]─→ HTTP 呼叫下游服務
  │  ① 檢查 success 欄位
  │  ② OrderService.confirmOrderAction(userKey, orderId) → 確認訂單
  │  ├─ 失敗 → compensate(RollbackInventoryEvent, paymentCompleted=true)
  │  └─ 成功 → publish(OrderSagaCompletedEvent)
  ▼
OrderSagaCompletedEvent ─[onOrderSagaCompleted]─→ log("Saga 完成")
```

### 補償路徑 (Compensation Path)

```
RollbackInventoryEvent ─[onRollbackInventory]─→ HTTP 呼叫下游服務
  │  ① if paymentCompleted: UserService.walletCompensateAction() → 退款
  │  ② foreach product: ProductionService.addInventoryCompensateAction() → 回補庫存
  │  ③ publish(RollbackOrderEvent)
  ▼
RollbackOrderEvent ─[onRollbackOrder]─→ HTTP 呼叫下游服務
  │  ① OrderService.compensateOrderAction(userKey, orderId) → 取消訂單
  │  ② log 結果
  ▼
  [結束]
```

---

## 三、各階段 Envelope / Payload / Metadata 詳細內容

### 3.1 Phase 1: Gateway → RabbitMQ (Request Envelope)

**來源**: `anser-gateway/app/HTTP/Controllers/Order.php:63-91`
**Exchange**: `events` (direct 類型)
**Routing Key**: `request.new`
**Queue**: `order_queue`

```json
{
  "schema_version": 1,
  "type": "gateway.request",
  "route": "OrderCreateRequestedEvent",
  "id": "txn_680005e3a1b2c.1234567890",
  "spiffe_id": "spiffe://zt.local/php-gateway",
  "spiffe_path": ["spiffe://zt.local/php-gateway"],
  "data": {
    "userKey": "user123",
    "productList": [
      { "p_key": 1, "amount": 5 },
      { "p_key": 2, "amount": 10 }
    ],
    "total": 500
  },
  "lsvid": "<L0 raw JWT token>"
}
```

**LSVID L0 Extra Claims**:
```json
{
  "traceId": "txn_680005e3a1b2c.1234567890",
  "route": "OrderCreateRequestedEvent",
  "level": "L0"
}
```
- `iss` = gateway 的 SPIFFE ID (自動由 SVID 簽名)
- `aud` = `DOWNSTREAM_SPIFFE_ID` (預設: `spiffe://zt.local/php-worker`)
- `sub` = null (由 SVID 推導)

**AMQP Properties**:
- `delivery_mode` = `2` (PERSISTENT)

---

### 3.2 Phase 2: RequestConsumer → MessageBus → RabbitMQ (Event Envelope)

**來源**: `src/Worker/RequestConsumer.php:127-133` → `src/MessageQueue/MessageBus.php:73-148`
**Exchange**: `events`
**Routing Key**: `OrderCreateRequestedEvent` (從 class name 最後一段取得)
**Queue**: `OrderCreateRequestedEvent` (queue name = routing key)

**驗證流程**:
1. `CanonicalOrderRequest::validateEnvelope()` — 驗證 schema_version=1, type=gateway.request
2. `verifySpiffeSource()` — 檢查 `spiffe://zt.local/` 前綴
3. LSVID L0 驗證 — `expectedAudience=worker_spiffe_id`, `expectedSubject=source_spiffe_id`
4. L0.sub 與 envelope source 一致性比對

**發佈的 Event Envelope**:
```json
{
  "type": "App\\Events\\OrderCreateRequestedEvent",
  "data": {
    "userKey": "user123",
    "productList": [
      { "p_key": 1, "amount": 5 },
      { "p_key": 2, "amount": 10 }
    ],
    "total": 500,
    "traceId": "txn_680005e3a1b2c.1234567890"
  },
  "spiffe_id": "spiffe://zt.local/php-worker",
  "spiffe_path": [
    "spiffe://zt.local/php-gateway",
    "spiffe://zt.local/php-worker"
  ],
  "timestamp": "2026-04-16T10:30:45+08:00",
  "lsvid": "<L1 raw JWT token (nested=L0)>"
}
```

**LSVID L1 Extra Claims** (由 MessageBus 簽出):
```json
{
  "eventType": "App\\Events\\OrderCreateRequestedEvent",
  "traceId": "txn_680005e3a1b2c.1234567890",
  "level": "L1"
}
```
- `iss` = worker 的 SPIFFE ID
- `aud` = `DOWNSTREAM_SPIFFE_ID`
- `nested` = L0 raw token (Gateway 鑄造)

---

### 3.3 Phase 3: EventConsumer 處理流程

**來源**: `src/Worker/EventConsumer.php:58-187`

**接收的 Envelope**: 即上方 3.2 的 Event Envelope

**處理步驟**:
1. JSON 解析 → 檢查 `type` (string) 和 `data` (array)
2. SPIFFE 驗證 → `spiffe_id` 前綴白名單 `spiffe://zt.local/`
3. LSVID 驗證 → `validate($rawLsvid, expectedAudience=worker_spiffe_id)`
4. `buildEventInstance()` — 反射建構事件物件
5. `LSVIDContext::set($rawLsvid)` — 儲存到 coroutine-local
6. `EventBus::dispatch($event)` — 分派到 Saga handler
7. `LSVIDContext::clear()` — 清除 context

---

### 3.4 Phase 4: Saga 各步驟的 Event Publish

每個 Saga handler 透過以下呼叫鏈發佈事件：

```
Saga.publish(EventClass, payload)
  → EventBus.publish(eventType, eventData, streamName='Streams', spiffePath=[])
    → LSVIDContext::current()  // 取得當前 LSVID (Ln)
    → EventStoreDB.appendEvent() // 可選，寫入 EventStore
    → MessageBus.publishEvent(eventType, eventData, exchange=null, spiffePath, priorLsvid=Ln)
      → lsvidSigner.extend(priorLsvid=Ln, audience, extraClaims) // LSVID L(n+1)
      → 組裝 Envelope → AMQP basic_publish
```

---

#### Step 1: `onOrderCreateRequested` → 發佈 `OrderCreatedEvent`

**HTTP 下游呼叫**:
| 服務 | Method | Path | Headers | Body/Params |
|------|--------|------|---------|-------------|
| ProductionService | GET | `/api/v1/products/{p_key}` | — | — |
| OrderService | POST | `/api/v1/order` | `X-User-Key: {userKey}` | `{"o_key": "{orderId}", "product_detail": [{"p_key":1,"price":100,"amount":5}]}` |

**下游 HTTP 呼叫的 LSVID 注入** (SpiffeLsvidFilter):
- Header: `X-LSVID: <L2 token>` (extend L1, aud=target-service SPIFFE ID)
- mTLS: cert/key from SVID (via SpiffeMtlsRegistry)

**publish payload**:
```json
{
  "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "userKey": "1",
  "productList": [
    { "p_key": 1, "price": 100, "amount": 5 },
    { "p_key": 2, "price": 200, "amount": 10 }
  ],
  "total": 1000
}
```

**RabbitMQ Envelope** (由 MessageBus 組裝):
```json
{
  "type": "App\\Events\\OrderCreatedEvent",
  "data": {
    "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "userKey": "1",
    "productList": [...],
    "total": 1000
  },
  "spiffe_id": "spiffe://zt.local/php-worker",
  "spiffe_path": ["spiffe://zt.local/php-worker"],
  "timestamp": "2026-04-16T10:30:46+08:00",
  "lsvid": "<L(n+1) token>"
}
```

**Routing Key**: `OrderCreatedEvent`
**Queue**: `OrderCreatedEvent`

**EventStore metadata** (若啟用):
```json
{
  "eventId": "event_680005e3a1b2c.9876543210",
  "eventType": "OrderCreatedEvent",
  "data": { "orderId": "...", "userKey": "1", "productList": [...], "total": 1000 },
  "metadata": {
    "spiffe_id": "spiffe://zt.local/php-worker",
    "spiffe_path": [],
    "lsvid_prior": "<current LSVID from LSVIDContext>"
  }
}
```

---

#### Step 2: `onOrderCreated` → 發佈 `InventoryDeductedEvent`

**HTTP 下游呼叫** (ConcurrentAction 並發):
| 服務 | Method | Path | Headers | Form Params |
|------|--------|------|---------|-------------|
| ProductionService | POST | `/api/v1/inventory/reduceInventory` | — | `p_key={p_key}&o_key={orderId}&reduceAmount={amount}` |

**publish payload**:
```json
{
  "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "userKey": "1",
  "productList": [],
  "total": 1000
}
```

> **注意**: 目前 `successfulDeductions` 的追蹤邏輯被註解，`productList` 傳入為空陣列。

**Routing Key**: `InventoryDeductedEvent`
**Queue**: `InventoryDeductedEvent`

---

#### Step 3: `onInventoryDeducted` → 發佈 `PaymentProcessedEvent` 或 `RollbackInventoryEvent`

**HTTP 下游呼叫**:
| 服務 | Method | Path | Headers | Form Params |
|------|--------|------|---------|-------------|
| UserService | POST | `/api/v1/wallet/charge` | `X-User-Key: {userKey}` | `o_key={orderId}&total={total}` |

**成功 → publish payload**:
```json
{
  "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "success": true,
  "userKey": "1",
  "total": 1000,
  "productList": []
}
```
**Routing Key**: `PaymentProcessedEvent`

**失敗 → compensate payload**:
```json
{
  "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "userKey": "1",
  "successfulDeductions": [],
  "paymentCompleted": false,
  "total": 0
}
```
**Routing Key**: `RollbackInventoryEvent`

---

#### Step 4: `onPaymentProcessed` → 發佈 `OrderSagaCompletedEvent` 或 `RollbackInventoryEvent`

**HTTP 下游呼叫**:
| 服務 | Method | Path | Headers | Body |
|------|--------|------|---------|------|
| OrderService | PUT | `/api/v1/order/{orderId}` | `X-User-Key: {userKey}` | — |

**成功 → publish payload**:
```json
{
  "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "userKey": "1",
  "total": 1000,
  "status": "completed"
}
```
**Routing Key**: `OrderSagaCompletedEvent`

**失敗 → compensate payload** (paymentCompleted=true):
```json
{
  "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "userKey": "1",
  "successfulDeductions": [],
  "paymentCompleted": true,
  "total": 1000
}
```
**Routing Key**: `RollbackInventoryEvent`

---

#### Step 5: `onOrderSagaCompleted` → 僅 log，不發佈事件

```
log("Saga 完成: orderId={orderId}")
```

---

#### Compensation Step 1: `onRollbackInventory` → 發佈 `RollbackOrderEvent`

**HTTP 下游呼叫**:
| 服務 | Method | Path | Headers | Form Params |
|------|--------|------|---------|-------------|
| UserService (if paymentCompleted) | POST | `/api/v1/wallet/compensate` | `X-User-Key: {userKey}` | `o_key={orderId}&addAmount={total}` |
| ProductionService (foreach product) | POST | `/api/v1/inventory/addInventory` | — | `p_key={p_key}&o_key={orderId}&addAmount={amount}&type=compensate` |

**publish payload**:
```json
{
  "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "userKey": "1"
}
```
**Routing Key**: `RollbackOrderEvent`

---

#### Compensation Step 2: `onRollbackOrder` → 僅 HTTP 呼叫，不發佈事件

**HTTP 下游呼叫**:
| 服務 | Method | Path | Headers |
|------|--------|------|---------|
| OrderService | DELETE | `/api/v1/order/{orderId}` | `X-User-Key: {userKey}` |

---

## 四、LSVID 巢狀簽章鏈演化

```
Gateway 鑄造 L0:
  iss = spiffe://zt.local/php-gateway
  aud = spiffe://zt.local/php-worker
  claims = { traceId, route, level: "L0" }
                    │
                    ▼
RequestConsumer 驗證 L0 → MessageBus 擴展為 L1:
  iss = spiffe://zt.local/php-worker
  aud = spiffe://zt.local/php-worker (self, 事件佇列內部)
  claims = { eventType, traceId, level: "L1" }
  nested = L0
                    │
                    ▼
EventConsumer 驗證 L1 → 設定 LSVIDContext
  → Saga handler 呼叫 publish()
  → EventBus.publish() → LSVIDContext::current() = L1
  → MessageBus 擴展為 L2:
    iss = spiffe://zt.local/php-worker
    aud = spiffe://zt.local/php-worker
    claims = { eventType, traceId, level: "L2" }
    nested = L1 (內含 L0)
                    │
                    ▼
SpiffeLsvidFilter 擴展為 L(http-call):
  (下游 HTTP 呼叫時)
  iss = spiffe://zt.local/php-worker
  aud = spiffe://zt.local/order-service (依目標服務決定)
  claims = { level: "http-call" }
  nested = 當前 LSVID (Ln)
                    │
                    ▼
下游服務驗證完整鏈: L0 → L1 → ... → Ln
```

---

## 五、RabbitMQ 拓撲結構

```
Exchange: "events" (direct, durable)
    │
    ├── Routing Key: "request.new"                → Queue: "order_queue"                (Gateway → RequestConsumer)
    │
    ├── Routing Key: "OrderCreateRequestedEvent"  → Queue: "OrderCreateRequestedEvent"  (RequestConsumer → EventConsumer)
    ├── Routing Key: "OrderCreatedEvent"          → Queue: "OrderCreatedEvent"          (Saga Step 1 → Step 2)
    ├── Routing Key: "InventoryDeductedEvent"     → Queue: "InventoryDeductedEvent"     (Saga Step 2 → Step 3)
    ├── Routing Key: "PaymentProcessedEvent"      → Queue: "PaymentProcessedEvent"      (Saga Step 3 → Step 4)
    ├── Routing Key: "OrderSagaCompletedEvent"    → Queue: "OrderSagaCompletedEvent"    (Saga Step 4 → Step 5)
    ├── Routing Key: "RollbackInventoryEvent"     → Queue: "RollbackInventoryEvent"     (補償觸發)
    └── Routing Key: "RollbackOrderEvent"         → Queue: "RollbackOrderEvent"         (補償步驟 2)
```

---

## 六、Event 類別欄位一覽

| Event Class | 建構子參數 | 說明 |
|---|---|---|
| `OrderCreateRequestedEvent` | `array $orderData, ?string $traceId` | 入口事件；`$productList` 從 orderData 中提取 |
| `OrderCreatedEvent` | `string $orderId, string $userKey, array $productList, int $total` | 訂單已建立 |
| `InventoryDeductedEvent` | `string $orderId, string $userKey, array $productList, int $total` | 庫存已扣減 |
| `PaymentProcessedEvent` | `string $orderId, bool $success, string $userKey, int $total, array $productList` | 付款結果 |
| `OrderSagaCompletedEvent` | `string $orderId, string $userKey, int $total, string $status` | Saga 完成 |
| `RollbackInventoryEvent` | `string $orderId, string $userKey, array $successfulDeductions, bool $paymentCompleted, int $total` | 回滾庫存觸發 |
| `RollbackOrderEvent` | `string $orderId, string $userKey` | 取消訂單 |

---

## 七、Envelope 格式統整

### Request Envelope (Gateway → order_queue)
```
{
  schema_version: int(1),
  type: "gateway.request",
  route: string,             // 目標事件類別名稱
  id: string,                // traceId / correlation ID
  spiffe_id: string,         // 發送者 SPIFFE ID
  spiffe_path: string[],     // 身份鏈
  data: { ... },             // 正規化後的訂單資料
  lsvid?: string             // L0 JWT token
}
```

### Event Envelope (MessageBus → event queues)
```
{
  type: string,              // 完整類別名稱 (App\Events\XxxEvent)
  data: { ... },             // 事件 payload
  spiffe_id: string,         // 當前服務 SPIFFE ID
  spiffe_path: string[],     // 累積身份鏈
  timestamp: string,         // RFC 3339 時間戳
  lsvid?: string             // LSVID JWT token (Ln)
}
```

### EventStore Event (可選)
```
{
  eventId: string,           // "event_<uniqid>"
  eventType: string,         // 事件類別短名
  data: { ... },             // 事件 payload
  metadata: {
    spiffe_id: string,
    spiffe_path: string[],
    lsvid_prior: ?string,    // 前一層 LSVID
    timestamp: string        // 自動加入的 UTC 時間戳
  }
}
```

---

## 八、完整端到端 Sequence Diagram

```
Client          Gateway         RabbitMQ        RequestConsumer    MessageBus       EventConsumer     OrderSaga          OrderSvc       ProductionSvc      UserSvc
  │                │                │                │                │                │                │                  │                │                │
  │── POST /api ──▶│                │                │                │                │                │                  │                │                │
  │               │─normalize()──▶│                │                │                │                │                  │                │                │
  │               │─mint LSVID L0─│                │                │                │                │                  │                │                │
  │               │─build envelope│                │                │                │                │                  │                │                │
  │               │───publish────▶│ order_queue    │                │                │                │                  │                │                │
  │◀──202 Accept──│                │                │                │                │                │                  │                │                │
  │                │                │──consume──────▶│                │                │                │                  │                │                │
  │                │                │                │─validate env──│                │                │                  │                │                │
  │                │                │                │─verify SPIFFE─│                │                │                  │                │                │
  │                │                │                │─validate L0───│                │                │                  │                │                │
  │                │                │                │─publishEvent─▶│                │                │                  │                │                │
  │                │                │                │                │─extend L0→L1──│                │                  │                │                │
  │                │                │◀──OrderCreateRequestedEvent────│                │                │                  │                │                │
  │                │                │                │                │                │                │                  │                │                │
  │                │                │──consume───────────────────────────────────────▶│                │                  │                │                │
  │                │                │                │                │                │─validate L1────│                  │                │                │
  │                │                │                │                │                │─set LSVIDCtx───│                  │                │                │
  │                │                │                │                │                │─dispatch()────▶│                  │                │                │
  │                │                │                │                │                │                │                  │                │                │
  │                │                │                │                │                │                │──[Step1]─────────│                │                │
  │                │                │                │                │                │                │  GET /products/{id}──────────────▶│                │
  │                │                │                │                │                │                │◀─────price───────│                │                │
  │                │                │                │                │                │                │  POST /order─────▶│                │                │
  │                │                │                │                │                │                │◀────created──────│                │                │
  │                │                │                │                │                │                │─publish(OrderCreatedEvent)────────▶│                │
  │                │                │◀──OrderCreatedEvent────────────────────────────────────────────────│                │                │
  │                │                │                │                │                │                │                  │                │                │
  │                │                │  ... (EventConsumer consume → dispatch → Saga) ...               │                  │                │                │
  │                │                │                │                │                │                │                  │                │                │
  │                │                │                │                │                │                │──[Step2]─────────│                │                │
  │                │                │                │                │                │                │  POST /reduceInventory (x N)─────▶│                │
  │                │                │                │                │                │                │◀───deducted──────│                │                │
  │                │                │                │                │                │                │─publish(InventoryDeductedEvent)──▶│                │
  │                │                │◀──InventoryDeductedEvent───────────────────────────────────────────│                │                │
  │                │                │                │                │                │                │                  │                │                │
  │                │                │                │                │                │                │──[Step3]─────────│                │                │
  │                │                │                │                │                │                │  POST /wallet/charge──────────────────────────────▶│
  │                │                │                │                │                │                │◀──────charged────────────────────────────────────│
  │                │                │                │                │                │                │─publish(PaymentProcessedEvent)───▶│                │
  │                │                │◀──PaymentProcessedEvent────────────────────────────────────────────│                │                │
  │                │                │                │                │                │                │                  │                │                │
  │                │                │                │                │                │                │──[Step4]─────────│                │                │
  │                │                │                │                │                │                │  PUT /order/{id}─▶│                │                │
  │                │                │                │                │                │                │◀───confirmed─────│                │                │
  │                │                │                │                │                │                │─publish(OrderSagaCompletedEvent)─▶│                │
  │                │                │◀──OrderSagaCompletedEvent──────────────────────────────────────────│                │                │
  │                │                │                │                │                │                │                  │                │                │
  │                │                │                │                │                │                │──[Step5] log ────│                │                │
```

---

## 九、關鍵檔案索引

| 檔案 | 職責 |
|------|------|
| `anser-gateway/app/HTTP/Controllers/Order.php` | Gateway 入口，envelope 組裝，LSVID L0 鑄造 |
| `src/Ingress/CanonicalOrderRequest.php` | 請求正規化與 envelope 驗證 |
| `src/Worker/RequestConsumer.php` | Request envelope 消費、SPIFFE/LSVID 驗證、轉發 |
| `src/Worker/EventConsumer.php` | Event envelope 消費、LSVID 驗證、事件物件建構與分派 |
| `src/MessageQueue/MessageBus.php` | AMQP 發佈、LSVID 擴展簽章、envelope 組裝 |
| `src/EventBus.php` | Handler 註冊與分派、EventStore 寫入、event publish |
| `src/Saga.php` | Saga 基底：publish() / compensate() |
| `Sagas/OrderSaga.php` | 訂單 Saga 7 個 EventHandler |
| `anser-gateway/Filters/SpiffeLsvidFilter.php` | 下游 HTTP 呼叫 LSVID 擴展 + mTLS 注入 |
| `src/QueueTopology.php` | RabbitMQ exchange/queue/binding 宣告 |
| `Event-Driven/Events/*.php` | 10 個事件類別定義 |
