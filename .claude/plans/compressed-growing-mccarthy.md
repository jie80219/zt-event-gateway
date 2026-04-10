# ZT Event Gateway — 完整 SPIFFE/LSVID 驗證流程解說

## Context

本文件詳細解說 zt-event-gateway 專案中，從 Client 發送 HTTP 請求到 Gateway，經 RabbitMQ 訊息佇列到 Worker，再到 Worker 呼叫三個下游 Service（OrderService、ProductionService、UserService）的**完整零信任驗證鏈路**。系統實作了兩層安全模型：

- **Transport 層**：SPIFFE mTLS（X.509-SVID 雙向 TLS 認證）
- **Application 層**：LSVID（Lightweight SVID）巢狀 JWS 簽章鏈

---

## 一、系統架構總覽

```
                          ┌─────────────────────────────────────────────┐
                          │              SPIRE Server                    │
                          │        Trust Domain: zt.local               │
                          │   (簽發所有 X.509-SVID 憑證)                │
                          └───────────────┬─────────────────────────────┘
                                          │ 簽發 SVID
                          ┌───────────────▼─────────────────────────────┐
                          │              SPIRE Agent                     │
                          │   (UDS socket: /run/spire/sockets/agent.sock)│
                          └──┬──────┬──────┬──────┬──────┬──────────────┘
                             │      │      │      │      │
                        ┌────▼─┐ ┌──▼──┐ ┌─▼──┐ ┌▼───┐ ┌▼────┐
                        │  GW  │ │ WK  │ │ OS │ │ PS │ │ US  │
                        │      │ │     │ │    │ │    │ │     │
                        │:8080 │ │AMQP │ │:8443│ │:8443│ │:8443│
                        └──┬───┘ └──┬──┘ └─▲──┘ └─▲──┘ └──▲──┘
                           │        │      │      │       │
  Client ─── HTTP POST ───►│        │      │      │       │
                           │  L0    │      │      │       │
                           ├──AMQP─►│      │      │       │
                           │        │  L1  │      │       │
                           │        ├─AMQP►│(event│       │
                           │        │      │queue)│       │
                           │        │      │      │       │
                           │        │─ L2+mTLS ──►│       │
                           │        │─ L2+mTLS ──────────►│
                           │        │─ L2+mTLS ───────────────►│

  GW = Gateway (spiffe://zt.local/php-gateway)
  WK = Worker  (spiffe://zt.local/php-worker)
  OS = OrderService (spiffe://zt.local/order-service)
  PS = ProductionService (spiffe://zt.local/production-service)
  US = UserService (spiffe://zt.local/user-service)
```

---

## 二、SPIFFE 身分基礎設施

### 2.1 SPIRE Workload 註冊

**檔案**：`spiffe/e2e/bootstrap.sh`

在 SPIRE Server 中為每個元件註冊工作負載身分：

| Workload | SPIFFE ID | 用途 |
|----------|-----------|------|
| Gateway | `spiffe://zt.local/php-gateway` | HTTP 入口，鑄造 L0 |
| Worker | `spiffe://zt.local/php-worker` | 訊息消費者，延伸鏈 |
| OrderService | `spiffe://zt.local/order-service` | 訂單微服務 |
| ProductionService | `spiffe://zt.local/production-service` | 商品微服務 |
| UserService | `spiffe://zt.local/user-service` | 使用者微服務 |

### 2.2 SVID 憑證發放方式

系統有兩種取得 SVID 的方式：

| 方式 | 使用者 | 機制 |
|------|--------|------|
| SHM（共享記憶體） | Gateway、Worker | `SpiffeTableReader` 讀取 SHM 表格，由 `spiffe-watcher` 寫入 |
| PEM 檔案 | 三個 Service | `spiffe-helper` sidecar 從 SPIRE Agent 取得 SVID 寫成 PEM |

**spiffe-helper 設定**（`spiffe/helper/*.conf`）：
```
agent_address = "/run/spire/sockets/agent.sock"
cert_dir = "/certs"
svid_file_name = "svid.pem"          # 服務端 X.509 憑證
svid_key_file_name = "svid_key.pem"  # 服務端私鑰
svid_bundle_file_name = "bundle.pem" # 信任域 CA bundle
renew_signal = "SIGHUP"
daemon_mode = true
```

---

## 三、完整驗證流程（Step-by-Step）

### Phase 1：Client → Gateway（HTTP Ingress）

**檔案**：`bin/gateway.php`

```
Client                              Gateway (:8080)
  │                                      │
  │ ── POST /api/orders ────────────────►│
  │    Body: { userKey, productList,      │
  │            total }                    │
  │    Header: X-Correlation-Id (opt)     │
  │                                      │
  │                              ┌───────▼────────┐
  │                              │ 1. 請求驗證      │
  │                              │ normalizeOrder  │
  │                              │ Data()          │
  │                              └───────┬────────┘
  │                              ┌───────▼────────┐
  │                              │ 2. 建構 Cloud   │
  │                              │ Events envelope │
  │                              └───────┬────────┘
  │                              ┌───────▼────────┐
  │                              │ 3. 鑄造 LSVID   │
  │                              │ L0 (createBase) │
  │                              └───────┬────────┘
  │                              ┌───────▼────────┐
  │                              │ 4. 發布到       │
  │                              │ RabbitMQ        │
  │                              └───────┬────────┘
  │                                      │
  │ ◄── 202 Accepted ───────────────────│
```

#### Step 1：請求資料正規化

**檔案**：`src/Ingress/CanonicalOrderRequest.php:16-27`

```php
CanonicalOrderRequest::normalizeOrderData($requestPayload)
```

- 驗證並提取 `userKey`（別名：`user_id`, `customerId`）
- 驗證並提取 `productList`（每項需有 `p_key` + `amount`）
- 驗證並提取 `total`（非負整數）
- 驗證失敗拋出 `\InvalidArgumentException` → 回傳 422

#### Step 2：建構 CloudEvents Envelope

**檔案**：`bin/gateway.php:254-265`

```php
$envelope = [
    'schema_version' => 1,
    'specversion'    => '1.0',
    'type'           => 'gateway.request',
    'route'          => 'OrderCreateRequestedEvent',
    'source'         => '/gateway/order',
    'id'             => $traceId,
    'time'           => date(DATE_RFC3339),
    'spiffe_id'      => 'spiffe://zt.local/php-gateway',  // Gateway 身分
    'spiffe_path'    => ['spiffe://zt.local/php-gateway'], // 身分鏈起點
    'data'           => $normalizedData,
];
```

#### Step 3：鑄造 LSVID L0（身分鏈起點）

**檔案**：`bin/gateway.php:274-284`、`packages/php-lsvid/src/LSVID/LSVIDSigner.php:60-75`

```php
$l0 = $state->lsvidSigner->createBase(
    audience: 'spiffe://zt.local/php-worker',  // 下一跳 = Worker
    subject:  null,                             // 預設 = Gateway 自身
    extraClaims: [
        'traceId' => $traceId,
        'route'   => 'OrderCreateRequestedEvent',
        'level'   => 'L0',
    ],
);
$envelope['lsvid'] = $l0->raw;  // JWS compact serialization
```

**L0 Token 結構**：

```
┌────────────── L0 LSVID (JWS Compact Serialization) ──────────────┐
│                                                                    │
│  Header (JOSE):                                                    │
│  {                                                                 │
│    "alg": "ES256",              // 演算法（由 SVID key type 決定）  │
│    "typ": "LSVID",              // 類型標識                        │
│    "x5c": ["MIIBxTCC..."]       // Gateway 的 X.509 憑證 (DER)     │
│  }                                                                 │
│                                                                    │
│  Payload:                                                          │
│  {                                                                 │
│    "iss": "spiffe://zt.local/php-gateway",  // 簽發者 = Gateway    │
│    "sub": "spiffe://zt.local/php-gateway",  // 主體 = Gateway 自身  │
│    "aud": "spiffe://zt.local/php-worker",   // 受眾 = Worker       │
│    "iat": 1712000000,           // 簽發時間                        │
│    "exp": 1712000300,           // 過期時間 (iat + 300s)           │
│    "jti": "a1b2c3d4...",        // 唯一識別碼 (16 bytes hex)       │
│    "traceId": "txn_001",        // 追蹤 ID                        │
│    "route": "OrderCreateRequestedEvent",                           │
│    "level": "L0"                // 鏈層級                          │
│  }                                                                 │
│                                                                    │
│  Signature: ECDSA-SHA256(header.payload, gateway_private_key)      │
└────────────────────────────────────────────────────────────────────┘
```

**保留宣告保護**（`LSVIDSigner.php:38,156-158`）：
```php
private const RESERVED_CLAIMS = ['iss', 'sub', 'aud', 'iat', 'exp', 'jti', 'nested'];
// 呼叫者傳入的保留宣告欄位會被靜默移除，防止身份偽造
```

**降級處理**：L0 鑄造失敗時 fail-open — 記錄錯誤但允許請求通過（`gateway.php:286-296`）

#### Step 4：發布到 RabbitMQ

**檔案**：`bin/gateway.php:299-307`

- Exchange: `events`（type: `direct`）
- Routing Key: `request.new`
- Queue: `order_queue`
- Delivery Mode: `PERSISTENT`

---

### Phase 2：RabbitMQ → Worker（Request 消費）

**檔案**：`src/Worker/RequestConsumer.php`

```
RabbitMQ (order_queue)
       │
       ▼
┌─────────────────────────────────────────────────────────────┐
│ RequestConsumer::process(AMQPMessage)                        │
│                                                             │
│  ┌─────────────────────────────────────┐                   │
│  │ Step 5: Envelope 解析與驗證          │                   │
│  │ CanonicalOrderRequest::             │                   │
│  │   validateEnvelope($payload)        │                   │
│  │                                     │                   │
│  │ 驗證：                               │                   │
│  │  - schema_version = 1               │                   │
│  │  - type = "gateway.request"         │                   │
│  │  - route 非空                       │                   │
│  │  - id (traceId) 非空               │                   │
│  │  - spiffe_id 非空                   │                   │
│  │  - spiffe_path 是 array             │                   │
│  └──────────────┬──────────────────────┘                   │
│  ┌──────────────▼──────────────────────┐                   │
│  │ Step 6: SPIFFE 來源前綴驗證          │                   │
│  │                                     │                   │
│  │ 允許前綴: spiffe://zt.local/        │                   │
│  │                                     │                   │
│  │ ✗ 不符 → UnrecoverableMessage       │                   │
│  │          Exception (丟棄訊息)        │                   │
│  └──────────────┬──────────────────────┘                   │
│  ┌──────────────▼──────────────────────┐                   │
│  │ Step 7: LSVID L0 驗證               │                   │
│  │                                     │                   │
│  │ $validator->validate(               │                   │
│  │   $rawLsvid,                        │                   │
│  │   expectedAudience: worker_id,      │                   │
│  │   expectedSubject: source_id,       │                   │
│  │ )                                   │                   │
│  │                                     │                   │
│  │ + L0.subject ↔ envelope.spiffe_id   │                   │
│  │   交叉比對 (防止 source 偽造)        │                   │
│  │                                     │                   │
│  │ ✗ 驗證失敗 → UnrecoverableMessage   │                   │
│  │ ✗ LSVID_REQUIRED=1 且無 token       │                   │
│  │   → UnrecoverableMessageException   │                   │
│  │ ○ 無 LSVID 且 REQUIRED=0           │                   │
│  │   → 遷移期放行                      │                   │
│  └──────────────┬──────────────────────┘                   │
│  ┌──────────────▼──────────────────────┐                   │
│  │ Step 8: 發布事件到 MessageBus        │                   │
│  │                                     │                   │
│  │ messageBus->publishEvent(           │                   │
│  │   eventType, eventData,             │                   │
│  │   spiffePath, priorLsvid            │                   │
│  │ )                                   │                   │
│  └─────────────────────────────────────┘                   │
└─────────────────────────────────────────────────────────────┘
```

---

### Phase 3：Worker MessageBus（LSVID 鏈延伸）

**檔案**：`src/MessageQueue/MessageBus.php:73-148`

```
┌─────────────────────────────────────────────────────────────┐
│ MessageBus::publishEvent()                                  │
│                                                             │
│  Step 9: SPIFFE Path 更新                                   │
│  $spiffePath[] = 'spiffe://zt.local/php-worker'            │
│                                                             │
│  Step 10: LSVID 鏈延伸                                      │
│                                                             │
│  ┌─ 有 priorLsvid (正常流程) ──────────────────────────┐   │
│  │                                                      │   │
│  │  $currentLevel = LSVID::parse($priorLsvid)->level()  │   │
│  │  // L0 → currentLevel = 0, newLevel = 1              │   │
│  │                                                      │   │
│  │  $lsvid = $signer->extend(                           │   │
│  │    priorRawToken: $priorLsvid,  // L0 作為 nested    │   │
│  │    audience: $downstreamAudience,                    │   │
│  │    extraClaims: [                                    │   │
│  │      'eventType' => 'App\Events\OrderCreatedEvent',  │   │
│  │      'traceId'   => 'txn_001',                       │   │
│  │      'level'     => 'L1',                            │   │
│  │    ],                                                │   │
│  │  )                                                   │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                             │
│  Step 11: 建構事件 Envelope                                  │
│  {                                                          │
│    "type":        "App\\Events\\OrderCreatedEvent",          │
│    "data":        { ... },                                   │
│    "spiffe_id":   "spiffe://zt.local/php-worker",           │
│    "spiffe_path": ["spiffe://zt.local/php-gateway",          │
│                    "spiffe://zt.local/php-worker"],           │
│    "timestamp":   "2026-04-07T10:00:01+00:00",              │
│    "lsvid":       "eyJhbGc..."  // L1 token (nested: L0)    │
│  }                                                          │
│                                                             │
│  Step 12: 發布到 RabbitMQ event queue                        │
│  exchange: events, routingKey: OrderCreatedEvent             │
└─────────────────────────────────────────────────────────────┘
```

**L1 Token 巢狀結構**：

```
┌───────── L1 LSVID ─────────┐
│ Header:                     │
│   alg: ES256                │
│   typ: LSVID                │
│   x5c: [Worker cert DER]    │
│                             │
│ Payload:                    │
│   iss: spiffe://…/php-worker│  ←── Worker 簽發
│   sub: spiffe://…/php-worker│
│   aud: spiffe://…/php-worker│  ←── 下一跳
│   iat: 1712000001           │
│   exp: 1712000301           │
│   jti: "b2c3d4e5..."       │
│   eventType: "OrderCreated" │
│   level: "L1"               │
│   nested: "eyJhbGc..."     │  ←── L0 raw token (完整嵌入)
│                             │
│ Signature: ECDSA-SHA256(    │
│   header.payload,           │
│   worker_private_key)       │
└─────────────────────────────┘
        │ nested
        ▼
┌───────── L0 LSVID ─────────┐
│ iss: spiffe://…/php-gateway │
│ sub: spiffe://…/php-gateway │
│ aud: spiffe://…/php-worker  │  ←── L0.aud === L1.iss ✓ (鏈連續性)
│ ...                         │
│ Signature: Gateway key      │
└─────────────────────────────┘
```

---

### Phase 4：Worker EventConsumer（事件消費 + LSVID 驗證）

**檔案**：`src/Worker/EventConsumer.php`

```
RabbitMQ (event queue: OrderCreatedEvent)
       │
       ▼
┌─────────────────────────────────────────────────────────────┐
│ EventConsumer::process(AMQPMessage)                          │
│                                                             │
│  Step 13: 解析 Event Envelope                                │
│  提取: type, data, spiffe_id, spiffe_path, lsvid            │
│                                                             │
│  Step 14: SPIFFE 來源前綴驗證                                │
│  允許前綴: spiffe://zt.local/                               │
│                                                             │
│  Step 15: LSVID 巢狀鏈驗證                                   │
│  $validator->validate(                                      │
│    $rawLsvid,              // L1 token                      │
│    expectedAudience: worker_id,                             │
│  )                                                          │
│  → 走訪整條鏈 L0 → L1，逐層驗證                              │
│                                                             │
│  Step 16: 設定 LSVIDContext                                  │
│  LSVIDContext::set($rawLsvid)   // 存入 L1 raw token        │
│  try {                                                      │
│    $this->eventBus->dispatch($event)  // 觸發 Saga          │
│  } finally {                                                │
│    LSVIDContext::clear()                                     │
│  }                                                          │
└─────────────────────────────────────────────────────────────┘
```

---

### Phase 5：Saga → 下游 Service HTTP 呼叫（LSVID 延伸 + mTLS）

**檔案**：`anser-gateway/Filters/SpiffeLsvidFilter.php`、`src/EventBus.php`

```
EventBus::dispatch($event)
       │
       ▼
OrderSaga::onOrderCreated($event)
       │
       ├─ $productionService->productInfoAction()->do()  ── HTTP 呼叫
       ├─ $orderService->createOrderAction()->do()       ── HTTP 呼叫
       └─ $eventBus->publish('WalletChargeEvent', ...)   ── 發布下一事件
              │
              │  每次 .do() 觸發全域 filter:
              ▼
┌─────────────────────────────────────────────────────────────┐
│ SpiffeLsvidFilter::beforeCallService(ActionInterface)        │
│                                                             │
│  Step 17: LSVID 鏈延伸                                       │
│                                                             │
│  $rawLsvid = LSVIDContext::current()  // 取得 L1 token      │
│  $signer   = LSVIDSignerRegistry::get()                     │
│                                                             │
│  // 解析目標 Service 的 SPIFFE ID                            │
│  $url = $action->getRequestSetting()->url                   │
│  //   e.g. "https://zt-order-service:8443"                  │
│  $targetAudience = SpiffeAudienceRegistry::resolve($url)    │
│  //   → "spiffe://zt.local/order-service"                   │
│                                                             │
│  // 延伸鏈：L1(aud=worker) → L2(aud=order-service)          │
│  $extended = $signer->extend(                               │
│    priorRawToken: $rawLsvid,     // L1                      │
│    audience: $targetAudience,     // order-service           │
│    extraClaims: ['level' => 'http-call'],                   │
│  )                                                          │
│                                                             │
│  // 注入 X-LSVID header                                     │
│  headers['X-LSVID'] = $extended->raw  // L2 token           │
│                                                             │
│  Step 18: mTLS 憑證注入                                      │
│                                                             │
│  $tlsCtx = SpiffeMtlsRegistry::get()                       │
│  $guzzleOpts = $tlsCtx->forGuzzle()                        │
│  // → ['cert' => '/path/svid.pem',                         │
│  //    'ssl_key' => '/path/svid_key.pem',                  │
│  //    'verify' => '/path/bundle.pem']                     │
│                                                             │
│  foreach ($guzzleOpts as $key => $value)                    │
│    $action->addOption($key, $value)                        │
│                                                             │
└─────────────────────────────────────────────────────────────┘
       │
       │ HTTPS + mTLS + X-LSVID: L2
       ▼
┌─────────────────────────────────────────────────────────────┐
│ Downstream Service (e.g. OrderService :8443)                 │
└─────────────────────────────────────────────────────────────┘
```

**SpiffeAudienceRegistry 對應表**（`bin/worker.php:139-162`）：

| Base URL | SPIFFE ID | Env Override |
|----------|-----------|-------------|
| `https://zt-order-service:8443` | `spiffe://zt.local/order-service` | `ORDER_SPIFFE_ID` |
| `https://zt-production-service:8443` | `spiffe://zt.local/production-service` | `PRODUCTION_SPIFFE_ID` |
| `https://zt-user-service:8443` | `spiffe://zt.local/user-service` | `USER_SPIFFE_ID` |

**L2 Token 完整巢狀鏈**：

```
L2 (最外層 — Service 驗證此層)
├── iss: spiffe://zt.local/php-worker     ←── Worker 簽發
├── sub: spiffe://zt.local/php-worker
├── aud: spiffe://zt.local/order-service  ←── 目標 Service
├── nested: L1 raw token
│   └── L1
│       ├── iss: spiffe://zt.local/php-worker
│       ├── aud: spiffe://zt.local/php-worker
│       ├── nested: L0 raw token
│       │   └── L0
│       │       ├── iss: spiffe://zt.local/php-gateway  ←── 鏈起點
│       │       ├── sub: spiffe://zt.local/php-gateway
│       │       └── aud: spiffe://zt.local/php-worker
│       │
│       └── 鏈連續性: L0.aud === L1.iss ✓
│
└── 鏈連續性: L1.aud === L2.iss ✓
```

---

### Phase 6：Service 端驗證（mTLS + LSVID）

**檔案**：`Services/*/app/app/Filters/SpiffeLsvidFilter.php`、`Services/*/app/.rr.yaml`

```
Worker                              Service (RoadRunner :8443)
  │                                        │
  │ ──────── TLS ClientHello ─────────────►│
  │                                        │
  │ ◄─── ServerHello + Service SVID cert ──│  Step 19: Server 出示自己的 SVID
  │      (signed by SPIRE CA)              │
  │                                        │
  │ ──── Worker SVID client cert ─────────►│  Step 20: Client 出示 Worker SVID
  │      (signed by SPIRE CA)              │
  │                                        │
  │ ◄─── Verify client cert against ────── │  Step 21: Server 驗證 client cert
  │      /certs/bundle.pem                 │  (openssl_x509_verify against CA bundle)
  │                                        │
  │ ══════ mTLS 連線建立 ═══════════════════│
  │                                        │
  │ ── HTTPS POST /api/v1/order ─────────► │
  │    Header: X-LSVID: eyJhbGc...(L2)     │
  │                                        │
  │                          ┌─────────────▼──────────────┐
  │                          │ CI4 SpiffeLsvidFilter      │
  │                          │                            │
  │                          │ Step 22: 讀取 X-LSVID header│
  │                          │                            │
  │                          │ Step 23: 建立 LSVIDValidator│
  │                          │ $reader = new FileSvidReader(│
  │                          │   certPath: /certs/svid.pem,│
  │                          │   keyPath: /certs/svid_key, │
  │                          │   bundlePath: /certs/bundle │
  │                          │ )                           │
  │                          │                             │
  │                          │ Step 24: 驗證 LSVID 鏈       │
  │                          │ $validator->validate(       │
  │                          │   $rawLsvid,                │
  │                          │   expectedAudience:         │
  │                          │     'spiffe://zt.local/     │
  │                          │      order-service'         │
  │                          │ )                           │
  │                          │                             │
  │                          │ Step 25: 存入 request        │
  │                          │ $request->lsvid = $lsvid    │
  │                          │ $request->lsvidIssuer = ... │
  │                          │ $request->lsvidSubject = ...│
  │                          └─────────────┬──────────────┘
  │                                        │
  │                          ┌─────────────▼──────────────┐
  │                          │ UserFilter (if applicable)  │
  │                          │ 驗證 X-User-Key header      │
  │                          └─────────────┬──────────────┘
  │                                        │
  │                          ┌─────────────▼──────────────┐
  │                          │ Controller 執行業務邏輯      │
  │                          └─────────────┬──────────────┘
  │                                        │
  │ ◄──── 200 OK ─────────────────────────│
```

**RoadRunner mTLS 設定**（`Services/*/app/.rr.yaml`）：

```yaml
http:
  address: "0.0.0.0:8080"        # plain HTTP（內部/health check）
  ssl:
    address: "0.0.0.0:8443"      # mTLS HTTPS
    cert: "/certs/svid.pem"      # Service 的 X.509 憑證
    key: "/certs/svid_key.pem"   # Service 的私鑰
    root_ca: "/certs/bundle.pem" # CA bundle（驗證 client cert）
    client_auth_type: "require_and_verify_client_cert"  # 強制雙向驗證
```

---

## 四、LSVID 7 步驗證詳解

**檔案**：`packages/php-lsvid/src/LSVID/LSVIDValidator.php`

每一層 LSVID（L0、L1、L2…）都經過以下 7 步驗證：

```
┌─────────────────────────────────────────────────────────────────┐
│                    LSVID 7-Step Verification                     │
│                    (per level, root-first)                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Step 1: 必要宣告檢查 (Lines 120-129)                            │
│  ┌─────────────────────────────────────────────────┐            │
│  │ 確認存在且非空: iss, sub, iat, exp, jti          │            │
│  │ ✗ 缺少任一 → LSVIDException                     │            │
│  └─────────────────────────────────────────────────┘            │
│                          │                                      │
│  Step 2: X.509 憑證驗證 (Lines 131-155)                          │
│  ┌─────────────────────────────────────────────────┐            │
│  │ a. 從 JOSE header 的 x5c claim 取出 leaf cert    │            │
│  │ b. 用 openssl_x509_verify() 驗證 leaf cert       │            │
│  │    是否由 trust bundle 中任一 CA 簽發              │            │
│  │ ✗ 未被任何 CA 簽發 → LSVIDException               │            │
│  └─────────────────────────────────────────────────┘            │
│                          │                                      │
│  Step 3: 憑證時效驗證 (Lines 157-169)                            │
│  ┌─────────────────────────────────────────────────┐            │
│  │ 檢查 cert 的 notBefore / notAfter                │            │
│  │ 允許 ±30 秒 clock skew                           │            │
│  │ ✗ 尚未生效 或 已過期 → LSVIDException             │            │
│  └─────────────────────────────────────────────────┘            │
│                          │                                      │
│  Step 4: JWS 簽章驗證 (Lines 171-188)                            │
│  ┌─────────────────────────────────────────────────┐            │
│  │ 用 leaf cert 的 public key 驗證 JWS 簽章          │            │
│  │ 支援: RS256/384/512, ES256/384/512               │            │
│  │ ECDSA: JOSE → DER 格式轉換                       │            │
│  │ ✗ 簽章不符 → LSVIDException                       │            │
│  └─────────────────────────────────────────────────┘            │
│                          │                                      │
│  Step 5: Payload 時效驗證 (Lines 190-199)                        │
│  ┌─────────────────────────────────────────────────┐            │
│  │ 檢查 LSVID payload 的 iat / exp claims           │            │
│  │ 允許 ±30 秒 clock skew                           │            │
│  │ ✗ iat 在未來 或 已過期 → LSVIDException           │            │
│  └─────────────────────────────────────────────────┘            │
│                          │                                      │
│  Step 6: 簽發者 ↔ 憑證 SAN 一致性 (Lines 201-212)                │
│  ┌─────────────────────────────────────────────────┐            │
│  │ 從 leaf cert 的 Subject Alternative Name 取出    │            │
│  │ URI SAN (e.g. spiffe://zt.local/php-worker)     │            │
│  │ 與 payload 的 iss claim 比對                     │            │
│  │ ✗ 不一致 → LSVIDException                       │            │
│  │                                                 │            │
│  │ 此步確保 LSVID 的簽發者身分與 X.509 憑證密碼學    │            │
│  │ 綁定，防止攻擊者用 A 的私鑰偽稱 B 的身分簽章      │            │
│  └─────────────────────────────────────────────────┘            │
│                          │                                      │
│  Step 7: JTI 重放偵測 (Lines 214-225)                            │
│  ┌─────────────────────────────────────────────────┐            │
│  │ 檢查 jti 是否曾出現過 (JtiReplayCache)           │            │
│  │ 記錄 jti + exp 到 in-memory LRU cache (4096)    │            │
│  │ 自動依 exp 過期清除                               │            │
│  │ ✗ 重複 → LSVIDException('replay detected')      │            │
│  └─────────────────────────────────────────────────┘            │
│                                                                 │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  鏈連續性驗證 (Lines 66-85, 對 L1+ 層級)                         │
│  ┌─────────────────────────────────────────────────┐            │
│  │ 每層 extension (i > 0):                          │            │
│  │   nested.aud MUST === enclosing.iss             │            │
│  │                                                 │            │
│  │ 例: L0.aud = "worker"                           │            │
│  │     L1.iss = "worker"  → L0.aud === L1.iss ✓    │            │
│  │                                                 │            │
│  │ ✗ 不一致 → LSVIDException('chain broken')        │            │
│  └─────────────────────────────────────────────────┘            │
│                                                                 │
│  外層驗證 (Lines 89-113)                                         │
│  ┌─────────────────────────────────────────────────┐            │
│  │ a. 最外層 aud 檢查:                               │            │
│  │    outermost.aud === expectedAudience           │            │
│  │    (e.g. L2.aud === order-service)              │            │
│  │                                                 │            │
│  │ b. L0 subject 檢查 (optional):                   │            │
│  │    L0.sub === expectedSubject                   │            │
│  │    (e.g. L0.sub === gateway)                    │            │
│  └─────────────────────────────────────────────────┘            │
└─────────────────────────────────────────────────────────────────┘
```

---

## 五、EventBus 事件發布與 LSVID 傳播

**檔案**：`src/EventBus.php:57-86`

當 Saga handler 需要發布下一個事件時：

```
Saga handler
  │
  │ $this->eventBus->publish('WalletChargeEvent', $data, ...)
  │
  ▼
┌─────────────────────────────────────────────────────────────┐
│ EventBus::publish()                                          │
│                                                             │
│  // 從 LSVIDContext 取得當前 LSVID                            │
│  $priorLsvid = LSVIDContext::current()  // L1 raw token     │
│                                                             │
│  // 傳給 MessageBus — 它會延伸鏈                              │
│  $this->messageBus->publishEvent(                           │
│    eventType: 'App\Events\WalletChargeEvent',               │
│    eventData: $data,                                        │
│    spiffePath: $spiffePath,                                 │
│    priorLsvid: $priorLsvid,  // L1 → 被 extend 成 L2       │
│  )                                                          │
│                                                             │
│  → MessageBus 產生 L2(nested: L1(nested: L0))               │
│  → 發布到 RabbitMQ                                           │
│  → 另一個 EventConsumer 消費時驗證完整鏈 L0→L1→L2             │
└─────────────────────────────────────────────────────────────┘
```

---

## 六、Service 端驗證決策樹

```
收到 HTTPS 請求
│
├─ mTLS 層 (RoadRunner :8443)
│  ├─ Client 有提供憑證？
│  │  └─ NO → TLS 握手失敗（client_auth_type: require_and_verify_client_cert）
│  └─ Client cert 由信任 CA 簽發？
│     └─ NO → TLS 握手失敗（openssl_x509_verify against bundle.pem）
│
├─ Application 層 (CI4 SpiffeLsvidFilter)
│  ├─ X-LSVID header 存在？
│  │  ├─ NO 且 LSVID_REQUIRED=1 → 401 Unauthorized
│  │  ├─ NO 且 LSVID_REQUIRED=0 → 放行（遷移期）
│  │  └─ YES → 繼續驗證
│  │
│  ├─ SVID PEM 檔案可讀？
│  │  ├─ NO 且 LSVID_REQUIRED=1 → 503 Service Unavailable
│  │  ├─ NO 且 LSVID_REQUIRED=0 → 放行
│  │  └─ YES → 繼續驗證
│  │
│  ├─ 解析 LSVID JWS
│  │  ├─ 格式正確（3 段 base64url）？ → NO → 403
│  │  ├─ typ = "LSVID"？ → NO → 403
│  │  └─ 巢狀深度 ≤ 16？ → NO → 403
│  │
│  ├─ 逐層 7 步驗證（L0 → L1 → L2）
│  │  └─ 任一步驟失敗 → 403 LSVID validation failed
│  │
│  ├─ 鏈連續性
│  │  └─ L(i-1).aud ≠ L(i).iss → 403 chain broken
│  │
│  ├─ Audience 驗證
│  │  └─ L2.aud ≠ 本 Service 的 SPIFFE ID → 403 audience mismatch
│  │
│  └─ 驗證通過
│     ├─ $request->lsvid = 完整解析物件
│     ├─ $request->lsvidIssuer = L2.iss（Worker）
│     └─ $request->lsvidSubject = L0.sub（Gateway）
│
└─ 繼續到 Controller
```

---

## 七、完整 Step 總覽表

| Step | Phase | 元件 | 檔案 | 行數 | 驗證內容 |
|------|-------|------|------|------|----------|
| 1 | Ingress | Gateway | `CanonicalOrderRequest.php` | 16-27 | 請求資料正規化 |
| 2 | Ingress | Gateway | `gateway.php` | 254-265 | CloudEvents envelope 建構 |
| 3 | Ingress | Gateway | `LSVIDSigner.php` | 60-75 | L0 鑄造（iss=gw, aud=worker） |
| 4 | Ingress | Gateway | `gateway.php` | 299-307 | 發布到 RabbitMQ |
| 5 | Request | Worker | `RequestConsumer.php` | 36-39 | Envelope schema 驗證 |
| 6 | Request | Worker | `RequestConsumer.php` | 46 | SPIFFE 來源前綴驗證 |
| 7 | Request | Worker | `LSVIDValidator.php` | 50-113 | L0 LSVID 7 步驗證 + audience + subject |
| 8 | Request | Worker | `RequestConsumer.php` | 118-124 | 發布事件到 MessageBus |
| 9 | Publish | MessageBus | `MessageBus.php` | 84-86 | SPIFFE path 更新 |
| 10 | Publish | MessageBus | `LSVIDSigner.php` | 81-99 | L0→L1 鏈延伸 |
| 11 | Publish | MessageBus | `MessageBus.php` | 131-140 | 事件 envelope 建構 |
| 12 | Publish | MessageBus | `MessageBus.php` | 142-147 | 發布到 RabbitMQ event queue |
| 13 | Event | Worker | `EventConsumer.php` | 30-39 | Event envelope 解析 |
| 14 | Event | Worker | `EventConsumer.php` | 47 | SPIFFE 來源前綴驗證 |
| 15 | Event | Worker | `LSVIDValidator.php` | 50-113 | L1 LSVID 完整鏈驗證 (L0→L1) |
| 16 | Event | Worker | `EventConsumer.php` | 105-109 | LSVIDContext::set + dispatch |
| 17 | HTTP | Filter | `SpiffeLsvidFilter.php` | 46-63 | L1→L2 鏈延伸 (aud=service) |
| 18 | HTTP | Filter | `SpiffeLsvidFilter.php` | 93-107 | mTLS 憑證注入 (Guzzle) |
| 19 | TLS | RoadRunner | `.rr.yaml` | ssl | Server 出示 SVID cert |
| 20 | TLS | RoadRunner | `.rr.yaml` | ssl | Client 出示 Worker cert |
| 21 | TLS | RoadRunner | `.rr.yaml` | ssl | 雙向驗證 (bundle.pem) |
| 22 | Service | CI4 Filter | `SpiffeLsvidFilter.php` | 18 | 讀取 X-LSVID header |
| 23 | Service | CI4 Filter | `SpiffeLsvidFilter.php` | 63-78 | 建立 FileSvidReader + Validator |
| 24 | Service | CI4 Filter | `LSVIDValidator.php` | 50-113 | L2 LSVID 完整鏈驗證 (L0→L1→L2) |
| 25 | Service | CI4 Filter | `SpiffeLsvidFilter.php` | 47-49 | 驗證結果存入 $request |

---

## 八、靜態 Registry 架構

```
┌─────────────────────────────────────────────────────────────┐
│                     bin/worker.php (Bootstrap)              │
│                                                             │
│  ┌──────────────────┐  ┌──────────────────┐                 │
│  │ LSVIDSignerRegistry│  │ SpiffeMtlsRegistry│              │
│  │ ::set($signer)    │  │ ::set($tlsCtx)   │                │
│  └────────┬─────────┘  └────────┬─────────┘                 │
│           │                      │                          │
│  ┌────────▼─────────────────────▼─────────┐                 │
│  │ SpiffeAudienceRegistry                  │                │
│  │ ::register(url, spiffeId) × 3           │                │
│  └────────┬────────────────────────────────┘                │
│           │                                                 │
│  ┌────────▼────────────────────────────────┐                │
│  │ ActionFilter::setGlobalFilter(          │                │
│  │   SpiffeLsvidFilter::class              │                │
│  │ )                                       │                │
│  └─────────────────────────────────────────┘                │
└─────────────────────────────────────────────────────────────┘
                    │
                    ▼ (每次 HTTP 呼叫)
┌─────────────────────────────────────────────────────────────┐
│ SpiffeLsvidFilter::beforeCallService()                      │
│                                                             │
│  LSVIDSignerRegistry::get() ──→ $signer                     │
│  LSVIDContext::current()    ──→ $rawLsvid                   │
│  SpiffeAudienceRegistry::resolve($url) ──→ $audience        │
│  SpiffeMtlsRegistry::get() ──→ $tlsContext                  │
│                                                             │
│  → extend LSVID chain                                       │
│  → inject X-LSVID header                                    │
│  → inject mTLS Guzzle options                               │
└─────────────────────────────────────────────────────────────┘
```

---

## 九、環境變數總覽

| 變數 | 預設值 | 使用者 | 用途 |
|------|--------|--------|------|
| `LSVID_ENABLED` | `1` | GW, WK | 啟用/停用 LSVID |
| `LSVID_REQUIRED` | `0` | WK, Services | `1`=fail-closed, `0`=fail-open |
| `SPIFFE_ID` | — | 全部 | 本元件的 SPIFFE ID |
| `SPIFFE_SHM_DIR` | `/tmp/spiffe-shared` | GW, WK, Services | SVID PEM 檔案目錄 |
| `SPIFFE_ENDPOINT_SOCKET` | — | GW | SPIRE Agent UDS socket |
| `SPIFFE_MTLS_ENABLED` | `0` | WK | 切換 HTTP/HTTPS |
| `DOWNSTREAM_SPIFFE_ID` | — | WK | RabbitMQ 訊息的下一跳 SPIFFE ID |
| `WORKER_SPIFFE_ID` | `spiffe://zt.local/php-worker` | GW | L0 的 audience |
| `ORDER_SERVICE_HOST` | `host.docker.internal` | WK | OrderService 主機名 |
| `PRODUCTION_SERVICE_HOST` | `host.docker.internal` | WK | ProductionService 主機名 |
| `USER_SERVICE_HOST` | `host.docker.internal` | WK | UserService 主機名 |
| `ORDER_SPIFFE_ID` | `spiffe://zt.local/order-service` | WK | L2 的 audience |
| `PRODUCTION_SPIFFE_ID` | `spiffe://zt.local/production-service` | WK | L2 的 audience |
| `USER_SPIFFE_ID` | `spiffe://zt.local/user-service` | WK | L2 的 audience |
| `MTLS_PORT` | `8443` | WK | RoadRunner mTLS port |

---

## 十、錯誤處理與降級策略

| 情境 | 行為 | 級別 |
|------|------|------|
| L0 鑄造失敗（Gateway） | Fail-open：記錄錯誤，允許請求通過 | WARNING |
| 無 SVID（SHM 為空） | 降級為 SPIFFE prefix-check，無密碼學驗證 | WARNING |
| LSVID 驗證失敗（Worker） | Fail-closed：丟棄訊息（`UnrecoverableMessageException`） | ERROR |
| LSVID 驗證失敗（Service） | Fail-closed：回傳 403 | ERROR |
| 無 X-LSVID header + REQUIRED=0 | 遷移期放行 | INFO |
| 無 X-LSVID header + REQUIRED=1 | 拒絕：401（Service）/ 丟棄訊息（Worker） | ERROR |
| mTLS 注入失敗 | 記錄錯誤，HTTP 呼叫繼續（可能被 Service 拒絕） | WARNING |
| Audience mapping 找不到 | 轉發 raw LSVID（不延伸），Service 端 audience 不符 | WARNING |
| JTI 重放偵測 | 拒絕：403 / 丟棄訊息 | ERROR |
| 憑證已過期 | 拒絕：403 / 丟棄訊息 | ERROR |
