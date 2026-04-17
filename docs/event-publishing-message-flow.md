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
│  ② 鑄造 LSVID L0 (iss=gateway, aud=worker)                      │
│  ③ 組裝 Request Envelope                                        │
│  ④ 發佈到 RabbitMQ: exchange=events, routing_key=request.new    │
│  ⑤ 回傳 HTTP 202 Accepted                                       │
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

**接收的 Envelope 結構** (即 MessageBus 在 Phase 2 發佈的 Event Envelope):
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

**處理步驟**:

```
① JSON 解析 → 檢查 type (string) 和 data (array)
② SPIFFE 驗證 → spiffe_id 前綴白名單 spiffe://zt.local/
③ LSVID 驗證:
   $parsed = $lsvidValidator->validate($rawLsvid, expectedAudience=worker_spiffe_id)
   → 驗證 L1 的簽章 + 過期 + audience
   → 遞迴驗證 nested claim 中的 L0
   → log: "[event-consumer] LSVID chain L0..L1 verified: gateway → worker"
④ buildEventInstance():
   - OrderCreateRequestedEvent: new $class($payload, $traceId)  ← 特殊處理
   - 其他事件: Reflection 逐一對應 constructor 參數名稱 → payload key
⑤ LSVIDContext::set($rawLsvid)  ← 存入 L1 raw token (coroutine-local)
⑥ EventBus::dispatch($event)   ← 分派到 Saga handler
⑦ LSVIDContext::clear()         ← finally 區塊，無論成功或失敗都清除
```

**LSVID Chain 驗證 Log 輸出** (LSVID_LOG_PAYLOAD=1 時):
```
[event-consumer] LSVID L0 payload={"iss":"spiffe://zt.local/php-gateway","aud":"spiffe://zt.local/php-worker","traceId":"txn_...","route":"OrderCreateRequestedEvent","level":"L0"}
[event-consumer] LSVID L1 payload={"iss":"spiffe://zt.local/php-worker","aud":"spiffe://zt.local/php-worker","eventType":"App\\Events\\OrderCreateRequestedEvent","traceId":"txn_...","level":"L1"}
```

---

### 3.4 Phase 4: Saga 各步驟的 Event Publish

每個 Saga handler 透過以下呼叫鏈發佈事件：

```
Saga.publish(EventClass, payload)
  → EventBus.publish(eventType, eventData, streamName='Streams', spiffePath=[])
    → $priorLsvid = LSVIDContext::current()  // 取得當前 LSVID (Ln)
    → EventStoreDB.appendEvent()             // 可選，寫入 EventStore
    → MessageBus.publishEvent(eventType, eventData, exchange=null, spiffePath=[], priorLsvid=Ln)
      → spiffePath[] = $this->spiffeId       // append worker SPIFFE ID → ["spiffe://zt.local/php-worker"]
      → $currentLevel = LSVID::parse($priorLsvid)->level() + 1
      → lsvidSigner.extend(priorLsvid=Ln, audience, extraClaims={level: "L(n+1)"})
      → 組裝 Envelope → AMQP basic_publish
```

> **注意**: `EventBus.publish()` 呼叫 `MessageBus.publishEvent()` 時 `spiffePath=[]`（硬編碼），
> MessageBus 再 append 自身 SPIFFE ID，因此所有 Saga 發佈的事件 `spiffe_path` 都是 `["spiffe://zt.local/php-worker"]`。

#### SpiffeLsvidFilter: HTTP 下游呼叫的 LSVID 擴展

每次 Saga handler 透過 Anser Service 呼叫下游 HTTP 服務時，全域 Filter `SpiffeLsvidFilter` 會自動注入身份：

```
SpiffeLsvidFilter.beforeCallService($action):
  ① $rawLsvid = LSVIDContext::current()         // 取得當前 LSVID (Ln)
  ② $validator->validate($rawLsvid, aud=worker)  // 防禦性重新驗證
  ③ $targetAudience = SpiffeAudienceRegistry::resolve($action->url)
     // 例: http://host.docker.internal:8082 → spiffe://zt.local/order-service
  ④ $extended = $signer->extend(
       priorRawToken: $rawLsvid,                  // Ln
       audience: $targetAudience,                  // 目標服務 SPIFFE ID
       extraClaims: ['level' => 'http-call']       // 標記為 HTTP 呼叫層
     )
  ⑤ 注入 Header: X-LSVID: $extended->raw
  ⑥ 注入 mTLS: cert/ssl_key/verify (若 SpiffeMtlsRegistry 有設定)
```

**SPIFFE Audience 對應表** (bin/worker.php:166-196):
| Service URL | SPIFFE ID |
|-------------|-----------|
| `http://host.docker.internal:8082` | `spiffe://zt.local/order-service` |
| `http://host.docker.internal:8081` | `spiffe://zt.local/production-service` |
| `http://host.docker.internal:8083` | `spiffe://zt.local/user-service` |

---

#### Step 1: `onOrderCreateRequested` → 發佈 `OrderCreatedEvent`

**① EventConsumer 接收的 Envelope** (LSVID=L1):
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
  "lsvid": "<L1 token (nested=L0)>"
}
```
- LSVID 驗證: chain L0→L1 OK
- `LSVIDContext::set(L1)`
- `buildEventInstance()`: `new OrderCreateRequestedEvent($payload, $traceId)`

**② HTTP 下游呼叫** (SpiffeLsvidFilter extend L1 → L_http):
| 服務 | Method | Path | Headers | Body/Params | X-LSVID |
|------|--------|------|---------|-------------|---------|
| ProductionService | GET | `/api/v1/products/{p_key}` | — | — | extend(L1, aud=`spiffe://zt.local/production-service`, level="http-call") |
| OrderService | POST | `/api/v1/order` | `X-User-Key: 1` | `{"o_key": "{orderId}", "product_detail": [{"p_key":1,"price":100,"amount":5}]}` | extend(L1, aud=`spiffe://zt.local/order-service`, level="http-call") |

> **注意**: `userKey` 在 OrderSaga 中硬編碼為 `'1'`（`private string $userKey = '1'`），
> 未從 event 的 `orderData['userKey']` 提取。

**③ Saga.publish() 的 payload** (OrderSaga:60-65):
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

> 注: `productList` 元素已被 `generateProductList()` 轉為 `OrderProductDetail` 物件，
> JSON 序列化後會包含 `p_key`, `price`, `amount` 三個欄位。
> `total` 來自 OrderService 回傳的 `$info['total']`，若無則預設 `1000`。

**④ MessageBus 發佈的完整 RabbitMQ Envelope** (LSVID=L2):
```json
{
  "type": "App\\Events\\OrderCreatedEvent",
  "data": {
    "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "userKey": "1",
    "productList": [
      { "p_key": 1, "price": 100, "amount": 5 },
      { "p_key": 2, "price": 200, "amount": 10 }
    ],
    "total": 1000
  },
  "spiffe_id": "spiffe://zt.local/php-worker",
  "spiffe_path": ["spiffe://zt.local/php-worker"],
  "timestamp": "2026-04-16T10:30:46+08:00",
  "lsvid": "<L2 token (nested=L1(nested=L0))>"
}
```

**LSVID L2 Extra Claims** (MessageBus extend):
```json
{
  "eventType": "App\\Events\\OrderCreatedEvent",
  "traceId": null,
  "level": "L2"
}
```
- `iss` = `spiffe://zt.local/php-worker`
- `aud` = `DOWNSTREAM_SPIFFE_ID`
- `nested` = L1 token
- `traceId` = null (publish payload 中無 `traceId` key)

**Routing Key**: `OrderCreatedEvent`
**Queue**: `OrderCreatedEvent`
**AMQP Properties**: `delivery_mode=2` (PERSISTENT)

**EventStore metadata** (若啟用):
```json
{
  "eventId": "event_680005e3a1b2c.9876543210",
  "eventType": "OrderCreatedEvent",
  "data": {
    "orderId": "a1b2c3d4-...",
    "userKey": "1",
    "productList": [...],
    "total": 1000
  },
  "metadata": {
    "spiffe_id": "spiffe://zt.local/php-worker",
    "spiffe_path": [],
    "lsvid_prior": "<L1 raw token (from LSVIDContext)>"
  }
}
```

---

#### Step 2: `onOrderCreated` → 發佈 `InventoryDeductedEvent`

**① EventConsumer 接收的 Envelope** (LSVID=L2):
```json
{
  "type": "App\\Events\\OrderCreatedEvent",
  "data": {
    "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "userKey": "1",
    "productList": [
      { "p_key": 1, "price": 100, "amount": 5 },
      { "p_key": 2, "price": 200, "amount": 10 }
    ],
    "total": 1000
  },
  "spiffe_id": "spiffe://zt.local/php-worker",
  "spiffe_path": ["spiffe://zt.local/php-worker"],
  "timestamp": "2026-04-16T10:30:46+08:00",
  "lsvid": "<L2 token (nested=L1(nested=L0))>"
}
```
- LSVID 驗證: chain L0→L1→L2 OK
- `LSVIDContext::set(L2)`
- `buildEventInstance()`: Reflection → `new OrderCreatedEvent($orderId, $userKey, $productList, $total)`

**② HTTP 下游呼叫** (ConcurrentAction 並發, SpiffeLsvidFilter extend L2 → L_http):
| 服務 | Method | Path | Headers | Form Params | X-LSVID |
|------|--------|------|---------|-------------|---------|
| ProductionService (x N) | POST | `/api/v1/inventory/reduceInventory` | — | `p_key={p_key}&o_key={orderId}&reduceAmount={amount}` | extend(L2, aud=`spiffe://zt.local/production-service`, level="http-call") |

**③ Saga.publish() 的 payload** (OrderSaga:107-112):
```json
{
  "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "userKey": "1",
  "productList": [],
  "total": 1000
}
```

> **注意**: `productList` 為空陣列 `[]`。原因：`$successfulDeductions` 初始化為 `[]`，
> 而追蹤每筆扣減結果的邏輯（OrderSaga:85-106）已被註解，所以直接傳入空陣列。

**④ MessageBus 發佈的完整 RabbitMQ Envelope** (LSVID=L3):
```json
{
  "type": "App\\Events\\InventoryDeductedEvent",
  "data": {
    "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "userKey": "1",
    "productList": [],
    "total": 1000
  },
  "spiffe_id": "spiffe://zt.local/php-worker",
  "spiffe_path": ["spiffe://zt.local/php-worker"],
  "timestamp": "2026-04-16T10:30:47+08:00",
  "lsvid": "<L3 token (nested=L2(nested=L1(nested=L0)))>"
}
```

**LSVID L3 Extra Claims**:
```json
{
  "eventType": "App\\Events\\InventoryDeductedEvent",
  "traceId": null,
  "level": "L3"
}
```

**Routing Key**: `InventoryDeductedEvent`
**Queue**: `InventoryDeductedEvent`

**EventStore metadata** (若啟用):
```json
{
  "eventId": "event_680005e4b2c3d.1234567890",
  "eventType": "InventoryDeductedEvent",
  "data": { "orderId": "...", "userKey": "1", "productList": [], "total": 1000 },
  "metadata": {
    "spiffe_id": "spiffe://zt.local/php-worker",
    "spiffe_path": [],
    "lsvid_prior": "<L2 raw token (from LSVIDContext)>"
  }
}
```

---

#### Step 3: `onInventoryDeducted` → 發佈 `PaymentProcessedEvent` 或 `RollbackInventoryEvent`

**① EventConsumer 接收的 Envelope** (LSVID=L3):
```json
{
  "type": "App\\Events\\InventoryDeductedEvent",
  "data": {
    "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "userKey": "1",
    "productList": [],
    "total": 1000
  },
  "spiffe_id": "spiffe://zt.local/php-worker",
  "spiffe_path": ["spiffe://zt.local/php-worker"],
  "timestamp": "2026-04-16T10:30:47+08:00",
  "lsvid": "<L3 token (nested=L2(nested=L1(nested=L0)))>"
}
```
- LSVID 驗證: chain L0→L1→L2→L3 OK
- `LSVIDContext::set(L3)`
- `buildEventInstance()`: Reflection → `new InventoryDeductedEvent($orderId, $userKey, $productList, $total)`

**② HTTP 下游呼叫** (SpiffeLsvidFilter extend L3 → L_http):
| 服務 | Method | Path | Headers | Form Params | X-LSVID |
|------|--------|------|---------|-------------|---------|
| UserService | POST | `/api/v1/wallet/charge` | `X-User-Key: {userKey}` | `o_key={orderId}&total={total}` | extend(L3, aud=`spiffe://zt.local/user-service`, level="http-call") |

**③-A 成功路徑 → Saga.publish() payload** (OrderSaga:135-141):
```json
{
  "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "success": true,
  "userKey": "1",
  "total": 1000,
  "productList": []
}
```

**③-A MessageBus 發佈的完整 RabbitMQ Envelope** (LSVID=L4):
```json
{
  "type": "App\\Events\\PaymentProcessedEvent",
  "data": {
    "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "success": true,
    "userKey": "1",
    "total": 1000,
    "productList": []
  },
  "spiffe_id": "spiffe://zt.local/php-worker",
  "spiffe_path": ["spiffe://zt.local/php-worker"],
  "timestamp": "2026-04-16T10:30:48+08:00",
  "lsvid": "<L4 token (nested=L3(...))>"
}
```

**LSVID L4 Extra Claims**:
```json
{
  "eventType": "App\\Events\\PaymentProcessedEvent",
  "traceId": null,
  "level": "L4"
}
```

**Routing Key**: `PaymentProcessedEvent`
**Queue**: `PaymentProcessedEvent`

**③-B 失敗路徑 → Saga.compensate() payload** (OrderSaga:125-131):
```json
{
  "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "userKey": "1",
  "successfulDeductions": [],
  "paymentCompleted": false,
  "total": 0
}
```

> `successfulDeductions` 來自 `$event->productList`，此處為 `[]`（Step 2 傳入的空陣列）。
> `total` 為 `0`（失敗時不需扣款資訊）。

**③-B MessageBus 發佈的完整 RabbitMQ Envelope** (LSVID=L4):
```json
{
  "type": "App\\Events\\RollbackInventoryEvent",
  "data": {
    "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "userKey": "1",
    "successfulDeductions": [],
    "paymentCompleted": false,
    "total": 0
  },
  "spiffe_id": "spiffe://zt.local/php-worker",
  "spiffe_path": ["spiffe://zt.local/php-worker"],
  "timestamp": "2026-04-16T10:30:48+08:00",
  "lsvid": "<L4 token (nested=L3(...))>"
}
```

**LSVID L4 Extra Claims** (compensate 路徑):
```json
{
  "eventType": "App\\Events\\RollbackInventoryEvent",
  "traceId": null,
  "level": "L4"
}
```

**Routing Key**: `RollbackInventoryEvent`
**Queue**: `RollbackInventoryEvent`

---

#### Step 4: `onPaymentProcessed` → 發佈 `OrderSagaCompletedEvent` 或 `RollbackInventoryEvent`

**① EventConsumer 接收的 Envelope** (LSVID=L4):
```json
{
  "type": "App\\Events\\PaymentProcessedEvent",
  "data": {
    "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "success": true,
    "userKey": "1",
    "total": 1000,
    "productList": []
  },
  "spiffe_id": "spiffe://zt.local/php-worker",
  "spiffe_path": ["spiffe://zt.local/php-worker"],
  "timestamp": "2026-04-16T10:30:48+08:00",
  "lsvid": "<L4 token (nested=L3(nested=L2(nested=L1(nested=L0))))>"
}
```
- LSVID 驗證: chain L0→L1→L2→L3→L4 OK
- `LSVIDContext::set(L4)`
- `buildEventInstance()`: Reflection → `new PaymentProcessedEvent($orderId, $success, $userKey, $total, $productList)`

**② HTTP 下游呼叫** (SpiffeLsvidFilter extend L4 → L_http):
| 服務 | Method | Path | Headers | Body | X-LSVID |
|------|--------|------|---------|------|---------|
| OrderService | PUT | `/api/v1/order/{orderId}` | `X-User-Key: {userKey}` | — | extend(L4, aud=`spiffe://zt.local/order-service`, level="http-call") |

> 注: 只有 `$event->success === true` 時才會呼叫 confirmOrderAction。
> 若 `success === false`，直接走 compensate 路徑，不做 HTTP 呼叫。

**③-A 成功路徑 → Saga.publish() payload** (OrderSaga:174-179):
```json
{
  "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "userKey": "1",
  "total": 1000,
  "status": "completed"
}
```

**③-A MessageBus 發佈的完整 RabbitMQ Envelope** (LSVID=L5):
```json
{
  "type": "App\\Events\\OrderSagaCompletedEvent",
  "data": {
    "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "userKey": "1",
    "total": 1000,
    "status": "completed"
  },
  "spiffe_id": "spiffe://zt.local/php-worker",
  "spiffe_path": ["spiffe://zt.local/php-worker"],
  "timestamp": "2026-04-16T10:30:49+08:00",
  "lsvid": "<L5 token (nested=L4(...))>"
}
```

**LSVID L5 Extra Claims**:
```json
{
  "eventType": "App\\Events\\OrderSagaCompletedEvent",
  "traceId": null,
  "level": "L5"
}
```

**Routing Key**: `OrderSagaCompletedEvent`
**Queue**: `OrderSagaCompletedEvent`

**③-B 失敗路徑 (success=false 或 confirmOrder 失敗) → Saga.compensate() payload**:

當 `$event->success === false` 時 (OrderSaga:148-155):
```json
{
  "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "userKey": "1",
  "successfulDeductions": [],
  "paymentCompleted": false,
  "total": 0
}
```

當 confirmOrder 失敗時 (OrderSaga:163-170):
```json
{
  "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "userKey": "1",
  "successfulDeductions": [],
  "paymentCompleted": true,
  "total": 1000
}
```

**③-B MessageBus 發佈的完整 RabbitMQ Envelope** (LSVID=L5):
```json
{
  "type": "App\\Events\\RollbackInventoryEvent",
  "data": {
    "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "userKey": "1",
    "successfulDeductions": [],
    "paymentCompleted": true,
    "total": 1000
  },
  "spiffe_id": "spiffe://zt.local/php-worker",
  "spiffe_path": ["spiffe://zt.local/php-worker"],
  "timestamp": "2026-04-16T10:30:49+08:00",
  "lsvid": "<L5 token (nested=L4(...))>"
}
```

**LSVID L5 Extra Claims** (compensate 路徑):
```json
{
  "eventType": "App\\Events\\RollbackInventoryEvent",
  "traceId": null,
  "level": "L5"
}
```

**Routing Key**: `RollbackInventoryEvent`
**Queue**: `RollbackInventoryEvent`

---

#### Step 5: `onOrderSagaCompleted` → 僅 log，不發佈事件

**① EventConsumer 接收的 Envelope** (LSVID=L5):
```json
{
  "type": "App\\Events\\OrderSagaCompletedEvent",
  "data": {
    "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "userKey": "1",
    "total": 1000,
    "status": "completed"
  },
  "spiffe_id": "spiffe://zt.local/php-worker",
  "spiffe_path": ["spiffe://zt.local/php-worker"],
  "timestamp": "2026-04-16T10:30:49+08:00",
  "lsvid": "<L5 token (nested=L4(nested=L3(nested=L2(nested=L1(nested=L0)))))>"
}
```
- LSVID 驗證: chain L0→L1→L2→L3→L4→L5 OK
- `LSVIDContext::set(L5)`

**② 處理**: 僅 log，不呼叫下游服務，不發佈事件
```
log("Saga 完成: orderId=a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d")
```

---

#### Compensation Step 1: `onRollbackInventory` → 發佈 `RollbackOrderEvent`

**① EventConsumer 接收的 Envelope** (LSVID=L4 或 L5，依觸發來源):

從 Step 3 失敗觸發時 (LSVID=L4):
```json
{
  "type": "App\\Events\\RollbackInventoryEvent",
  "data": {
    "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "userKey": "1",
    "successfulDeductions": [],
    "paymentCompleted": false,
    "total": 0
  },
  "spiffe_id": "spiffe://zt.local/php-worker",
  "spiffe_path": ["spiffe://zt.local/php-worker"],
  "timestamp": "2026-04-16T10:30:48+08:00",
  "lsvid": "<L4 token>"
}
```

從 Step 4 失敗觸發時 (LSVID=L5):
```json
{
  "type": "App\\Events\\RollbackInventoryEvent",
  "data": {
    "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "userKey": "1",
    "successfulDeductions": [],
    "paymentCompleted": true,
    "total": 1000
  },
  "spiffe_id": "spiffe://zt.local/php-worker",
  "spiffe_path": ["spiffe://zt.local/php-worker"],
  "timestamp": "2026-04-16T10:30:49+08:00",
  "lsvid": "<L5 token>"
}
```
- LSVID 驗證: chain OK
- `LSVIDContext::set(L4 或 L5)`
- `buildEventInstance()`: Reflection → `new RollbackInventoryEvent($orderId, $userKey, $successfulDeductions, $paymentCompleted, $total)`

**② HTTP 下游呼叫** (SpiffeLsvidFilter extend Ln → L_http):
| 條件 | 服務 | Method | Path | Headers | Form Params | X-LSVID |
|------|------|--------|------|---------|-------------|---------|
| if paymentCompleted | UserService | POST | `/api/v1/wallet/compensate` | `X-User-Key: {userKey}` | `o_key={orderId}&addAmount={total}` | extend(Ln, aud=`spiffe://zt.local/user-service`, level="http-call") |
| foreach product | ProductionService | POST | `/api/v1/inventory/addInventory` | — | `p_key={p_key}&o_key={orderId}&addAmount={amount}&type=compensate` | extend(Ln, aud=`spiffe://zt.local/production-service`, level="http-call") |

> 注: 目前 `successfulDeductions` 為空陣列 `[]`（因 Step 2 追蹤邏輯被註解），
> 所以 foreach 迴圈實際上不會執行任何庫存回補。

**③ Saga.publish() payload** (OrderSaga:205-208):
```json
{
  "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "userKey": "1"
}
```

**④ MessageBus 發佈的完整 RabbitMQ Envelope** (LSVID=L5 或 L6):
```json
{
  "type": "App\\Events\\RollbackOrderEvent",
  "data": {
    "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "userKey": "1"
  },
  "spiffe_id": "spiffe://zt.local/php-worker",
  "spiffe_path": ["spiffe://zt.local/php-worker"],
  "timestamp": "2026-04-16T10:30:50+08:00",
  "lsvid": "<L(n+1) token>"
}
```

**LSVID L(n+1) Extra Claims**:
```json
{
  "eventType": "App\\Events\\RollbackOrderEvent",
  "traceId": null,
  "level": "L5 或 L6"
}
```

**Routing Key**: `RollbackOrderEvent`
**Queue**: `RollbackOrderEvent`

---

#### Compensation Step 2: `onRollbackOrder` → 僅 HTTP 呼叫，不發佈事件

**① EventConsumer 接收的 Envelope** (LSVID=L5 或 L6):
```json
{
  "type": "App\\Events\\RollbackOrderEvent",
  "data": {
    "orderId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "userKey": "1"
  },
  "spiffe_id": "spiffe://zt.local/php-worker",
  "spiffe_path": ["spiffe://zt.local/php-worker"],
  "timestamp": "2026-04-16T10:30:50+08:00",
  "lsvid": "<L(n) token>"
}
```
- LSVID 驗證: chain OK
- `LSVIDContext::set(Ln)`
- `buildEventInstance()`: Reflection → `new RollbackOrderEvent($orderId, $userKey)`

**② HTTP 下游呼叫** (SpiffeLsvidFilter extend Ln → L_http):
| 服務 | Method | Path | Headers | X-LSVID |
|------|--------|------|---------|---------|
| OrderService | DELETE | `/api/v1/order/{orderId}` | `X-User-Key: {userKey}` | extend(Ln, aud=`spiffe://zt.local/order-service`, level="http-call") |

**③ 不發佈事件** — Saga 流程結束

---

## 四、LSVID 巢狀簽章鏈演化

### 4.1 完整 LSVID 鏈 (Happy Path: L0 → L5)

```
L0 ─ Gateway 鑄造 (Order.php:82-90)
│  iss = spiffe://zt.local/php-gateway
│  aud = spiffe://zt.local/php-worker
│  claims = { traceId: "txn_...", route: "OrderCreateRequestedEvent", level: "L0" }
│  nested = null
│
▼ RequestConsumer 驗證 L0 (aud=worker, sub=gateway)
│
L1 ─ MessageBus extend (RequestConsumer → OrderCreateRequestedEvent queue)
│  iss = spiffe://zt.local/php-worker
│  aud = spiffe://zt.local/php-worker
│  claims = { eventType: "App\\Events\\OrderCreateRequestedEvent", traceId: "txn_...", level: "L1" }
│  nested = L0
│
▼ EventConsumer 驗證 L1 chain (L0→L1) → LSVIDContext::set(L1) → Saga Step 1
│  ├─ HTTP calls: SpiffeLsvidFilter.extend(L1, aud=service, level="http-call")
│  └─ Saga.publish(OrderCreatedEvent)
│
L2 ─ MessageBus extend (Step 1 → OrderCreatedEvent queue)
│  iss = spiffe://zt.local/php-worker
│  aud = spiffe://zt.local/php-worker
│  claims = { eventType: "App\\Events\\OrderCreatedEvent", traceId: null, level: "L2" }
│  nested = L1 (nested = L0)
│
▼ EventConsumer 驗證 L2 chain (L0→L1→L2) → LSVIDContext::set(L2) → Saga Step 2
│  ├─ HTTP calls: SpiffeLsvidFilter.extend(L2, aud=production-service, level="http-call")
│  └─ Saga.publish(InventoryDeductedEvent)
│
L3 ─ MessageBus extend (Step 2 → InventoryDeductedEvent queue)
│  iss = spiffe://zt.local/php-worker
│  aud = spiffe://zt.local/php-worker
│  claims = { eventType: "App\\Events\\InventoryDeductedEvent", traceId: null, level: "L3" }
│  nested = L2 (nested = L1 (nested = L0))
│
▼ EventConsumer 驗證 L3 chain (L0→L1→L2→L3) → LSVIDContext::set(L3) → Saga Step 3
│  ├─ HTTP call: SpiffeLsvidFilter.extend(L3, aud=user-service, level="http-call")
│  └─ Saga.publish(PaymentProcessedEvent)
│
L4 ─ MessageBus extend (Step 3 → PaymentProcessedEvent queue)
│  iss = spiffe://zt.local/php-worker
│  aud = spiffe://zt.local/php-worker
│  claims = { eventType: "App\\Events\\PaymentProcessedEvent", traceId: null, level: "L4" }
│  nested = L3 (nested = L2 (nested = L1 (nested = L0)))
│
▼ EventConsumer 驗證 L4 chain (L0→L1→L2→L3→L4) → LSVIDContext::set(L4) → Saga Step 4
│  ├─ HTTP call: SpiffeLsvidFilter.extend(L4, aud=order-service, level="http-call")
│  └─ Saga.publish(OrderSagaCompletedEvent)
│
L5 ─ MessageBus extend (Step 4 → OrderSagaCompletedEvent queue)
   iss = spiffe://zt.local/php-worker
   aud = spiffe://zt.local/php-worker
   claims = { eventType: "App\\Events\\OrderSagaCompletedEvent", traceId: null, level: "L5" }
   nested = L4 (nested = L3 (nested = L2 (nested = L1 (nested = L0))))
   │
   ▼ EventConsumer 驗證 L5 chain (L0→L1→L2→L3→L4→L5) → log 完成
```

### 4.2 HTTP 下游呼叫的 LSVID 分支 (SpiffeLsvidFilter)

```
每個 Saga Step 的 HTTP 呼叫都會從當前 LSVIDContext 產生一個「分支」LSVID：

                   L1 (LSVIDContext)
                   ├── extend(aud=production-service) → L_http  ← GET /products/{id}
                   ├── extend(aud=order-service)      → L_http  ← POST /order
                   │
                   L2 (LSVIDContext)
                   ├── extend(aud=production-service) → L_http  ← POST /reduceInventory (x N)
                   │
                   L3 (LSVIDContext)
                   ├── extend(aud=user-service)       → L_http  ← POST /wallet/charge
                   │
                   L4 (LSVIDContext)
                   ├── extend(aud=order-service)      → L_http  ← PUT /order/{id}

注意: HTTP 分支的 LSVID 不會回寫到 LSVIDContext，不影響主鏈的層級遞增。
      HTTP LSVID 的 extraClaims 中 level="http-call"（非數字），
      而主鏈的 level 為 "L0", "L1", "L2"...
```

### 4.3 補償路徑的 LSVID 鏈

```
補償路徑從主鏈的某一層分叉，繼續遞增：

從 Step 3 失敗觸發:
  L3 (LSVIDContext) → compensate(RollbackInventoryEvent) → L4
  L4 → EventConsumer → LSVIDContext::set(L4) → onRollbackInventory
  L4 → publish(RollbackOrderEvent) → L5
  L5 → EventConsumer → LSVIDContext::set(L5) → onRollbackOrder → HTTP(extend L5)

從 Step 4 失敗觸發:
  L4 (LSVIDContext) → compensate(RollbackInventoryEvent) → L5
  L5 → EventConsumer → LSVIDContext::set(L5) → onRollbackInventory
  L5 → publish(RollbackOrderEvent) → L6
  L6 → EventConsumer → LSVIDContext::set(L6) → onRollbackOrder → HTTP(extend L6)
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
