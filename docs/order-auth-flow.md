# 下游服務憑證取得與驗證流程

## 問題

Order Service（及其他下游服務）本身只做訂單 CRUD，它如何取得 SPIFFE 憑證並驗證來自 Worker 的請求？是透過 Order Service 的 SPIRE Agent 跟主 SPIRE Agent 做驗證嗎？

## 結論

**不是 Agent 之間互相驗證**。所有 SPIRE Agent 都連到**同一台 SPIRE Server**，由 Server 作為唯一的 Root of Trust。Order Service 的 PHP 應用**完全不需要**跟任何 SPIRE 元件通訊，它只是讀取 sidecar 寫好的憑證檔案來驗簽章。

---

## 一、整體架構：三個容器協作

```
┌── Docker 1 (zt-event-gateway) ──────────────────────────────┐
│  zt-spire-server        ← 全域身份授權中心 (trust domain: zt.local)
│  zt-spire-agent         ← Gateway/Worker 用的 Agent
│  zt-workload-registrar  ← 註冊所有 workload 的 SPIFFE ID
│  zt-gateway / zt-worker
└─────────────────────────────────────────────────────────────┘
        │  共享 Docker network: anser_project_network
        │  SPIRE Server 監聽 port 8081
        ▼
┌── Docker 2 (Order Service) ─────────────────────────────────┐
│                                                             │
│  ① order-spire-agent  ──gRPC:8081──▶  zt-spire-server      │
│     (SPIRE Agent)         向 Server 做 node attestation     │
│     ↓ UDS socket                                            │
│     /run/spire/sockets/agent.sock                           │
│                                                             │
│  ② order-helper       ──UDS──▶  order-spire-agent           │
│     (spiffe-helper)       透過 Workload API 取得 SVID       │
│     ↓ 寫入共享 volume                                        │
│     /certs/svid.pem                                         │
│     /certs/svid_key.pem                                     │
│     /certs/bundle.pem                                       │
│                                                             │
│  ③ order-service       ──讀取──▶  /certs/ (read-only)       │
│     (PHP 應用)             直接讀取檔案，不碰 SPIRE          │
│     SpiffeLsvidFilter 用 bundle.pem 驗證 LSVID              │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## 二、逐步詳解

### Step 1: Order Service 的 SPIRE Agent → 主 SPIRE Server 做 Node Attestation

```
order-spire-agent 啟動時:
  ├─ 讀取預分發的憑證: agent.crt.pem / agent.key.pem
  ├─ 連線到 spire-server:8081 (透過 Docker network)
  ├─ 用 x509pop NodeAttestor 做 node attestation
  │   (出示 agent cert，Server 用 agent-ca.crt.pem 驗證)
  └─ attestation 成功 → Agent 被 Server 信任
```

關鍵配置 (`Order_service/spiffe/spire-agent/agent.conf`):
```
server_address = "spire-server"    ← 連到主 SPIRE Server
server_port = "8081"
trust_domain = "zt.local"          ← 同一個 trust domain
```

### Step 2: Workload Registrar 在 SPIRE Server 註冊 Order Service 的身份

`register-workloads-internal.sh` 在主 SPIRE Server 上跑，註冊所有服務：

```bash
register "spiffe://zt.local/order-service"       "unix:uid:0"
register "spiffe://zt.local/production-service"   "unix:uid:0"
register "spiffe://zt.local/user-service"         "unix:uid:0"
```

這告訴 SPIRE Server：「UID=0 的 process，透過已 attest 的 Agent 來要 SVID 時，給它 `spiffe://zt.local/order-service` 這個身份。」

### Step 3: spiffe-helper Sidecar 向 Agent 請求 SVID

```
order-helper (spiffe-helper):
  ├─ 共享 Agent 的 PID namespace (pid: "service:spire-agent")
  ├─ 透過 UDS 連接 Agent: /run/spire/sockets/agent.sock
  ├─ 呼叫 Workload API: FetchX509SVID()
  ├─ Agent 向 Server 確認此 workload 的身份
  ├─ 收到 X.509-SVID (URI SAN = spiffe://zt.local/order-service)
  ├─ 寫入共享 volume:
  │   ├─ /certs/svid.pem       ← 服務憑證
  │   ├─ /certs/svid_key.pem   ← 私鑰
  │   └─ /certs/bundle.pem     ← CA bundle (可驗證同 trust domain 的所有 SVID)
  └─ daemon_mode = true → 持續監聽，SVID 快過期時自動輪換
```

`Order_service/spiffe/helper/order-service.conf`:
```
agent_address = "/run/spire/sockets/agent.sock"
cert_dir = "/certs"
svid_file_name = "svid.pem"
svid_key_file_name = "svid_key.pem"
svid_bundle_file_name = "bundle.pem"
renew_signal = "SIGHUP"
daemon_mode = true
```

### Step 4: Order Service PHP 應用只是「讀檔案」

```
order-service 容器:
  ├─ 掛載 order-certs volume 為 /certs (read-only)
  ├─ 環境變數: SPIFFE_SHM_DIR=/certs
  │
  ├─ SpiffeLsvidFilter 驗證時:
  │   ├─ 讀取 /certs/bundle.pem → 載入 CA 公鑰
  │   ├─ 用 CA 公鑰驗證 X-LSVID token 中 x5c claim 的簽章
  │   └─ 確認 audience = spiffe://zt.local/order-service
  │
  └─ PHP 完全不知道 SPIRE Agent 的存在
     它只是在讀檔案驗簽章而已
```

---

## 三、信任鏈建立的完整流程

```
SPIRE Server (trust domain: zt.local)
    │
    ├─ 簽發 CA 憑證 (Root of Trust)
    │
    ├─ Node Attestation:
    │   ├─ zt-spire-agent     (Gateway Docker) ✅ attested
    │   └─ order-spire-agent  (Order Docker)   ✅ attested
    │
    ├─ Workload Registration:
    │   ├─ spiffe://zt.local/php-gateway    → zt-spire-agent,    uid:0
    │   ├─ spiffe://zt.local/php-worker     → zt-spire-agent,    uid:0
    │   └─ spiffe://zt.local/order-service  → order-spire-agent, uid:0
    │
    └─ SVID 簽發:
        ├─ Gateway 的 SVID → 用來鑄造 LSVID L0
        ├─ Worker 的 SVID  → 用來擴展 LSVID L1, L2
        └─ Order 的 SVID   → 用來驗證收到的 LSVID
            (同一個 CA 簽的，所以 bundle.pem 可以互相驗證)
```

---

## 四、雙層驗證機制

每個進入下游服務的 HTTP 請求經過兩層驗證：

### Layer 1: TLS 傳輸層 (RoadRunner mTLS)

```yaml
# .rr.yaml
http:
  ssl:
    cert: "/certs/svid.pem"
    key: "/certs/svid_key.pem"
    root_ca: "/certs/bundle.pem"
    client_auth_type: "require_and_verify_client_cert"
```

- RoadRunner 要求呼叫方出示客戶端憑證
- 用 bundle.pem (CA bundle) 驗證
- 無效 → TLS handshake 直接拒絕，請求進不到 PHP 層

### Layer 2: 應用層 (SpiffeLsvidFilter)

```
HTTP Request 進入
  ├─ 提取 X-LSVID header
  ├─ 建構 LSVIDValidator (從 /certs 讀取 CA bundle)
  ├─ 遍歷巢狀鏈 L0→L1→L2，每層檢查:
  │   ├─ x5c 憑證對 CA bundle 驗簽
  │   ├─ JWS 簽章驗證
  │   ├─ 時間有效性 (iat/exp ± 30s clock skew)
  │   ├─ issuer 與 cert URI SAN 一致
  │   ├─ trust domain = spiffe://zt.local/
  │   ├─ 鏈連續性 (nested.aud === enclosing.iss)
  │   ├─ audience = 本服務 SPIFFE ID
  │   └─ JTI replay 偵測
  └─ 任一失敗 → 403 Forbidden
```

### 各服務 Filter 套用範圍

| 服務 | mTLS (TLS 層) | SpiffeLsvidFilter 套用路由 | LSVID_REQUIRED |
|------|---------------|---------------------------|----------------|
| **Order Service** | 有條件（可 fallback HTTP） | `api/v1/order`, `api/v1/order/*` | 0（可選） |
| **Production Service** | 強制 mTLS | **未套用 filter** | — |
| **User Service** | 強制 mTLS | `api/v1/wallet`, `api/v1/wallet/*` | 0（可選） |

---

## 五、核心觀念

```
SPIRE Server ←→ Order SPIRE Agent ←→ spiffe-helper → 寫檔 → PHP 讀檔驗證
                                                        ↑
                                              這是唯一的橋樑
```

- **mTLS** 確保「你是誰」（傳輸層身份）
- **LSVID** 確保「你從哪裡來、經過誰」（完整呼叫鏈溯源）
- **Order Service 本身**只負責業務邏輯，身份驗證的重活全部由 sidecar 架構處理

---

## 六、關鍵檔案索引

| 檔案 | 職責 |
|------|------|
| `Services/Order_service/docker-compose.yml` | 定義三個容器的協作關係與 volume 共享 |
| `Services/Order_service/spiffe/spire-agent/agent.conf` | Agent 連接 SPIRE Server 的設定 |
| `Services/Order_service/spiffe/helper/order-service.conf` | spiffe-helper 取得 SVID 並寫入 /certs |
| `Services/Order_service/spiffe/certs/` | 預分發的 Agent attestation 憑證 |
| `Services/Order_service/app/app/Filters/SpiffeLsvidFilter.php` | 應用層 LSVID 驗證 middleware |
| `Services/Order_service/app/app/Config/Filters.php` | Filter 路由設定 |
| `spiffe/scripts/register-workloads-internal.sh` | 在 SPIRE Server 註冊所有 workload 身份 |
| `spiffe/spire-server-e2e/server.conf` | SPIRE Server 設定 (trust domain, CA) |
