# Linkerd 1.x 流程圖（feat/Linkerd1 分支）

> 本文針對 `feat/Linkerd1` 分支。SVID / SPIFFE / SPIRE 在此分支**不存在**，僅在
> `feat/spiffe`、`feat/go-spiffe`、`feat/spiffe-keycloak` 三條分支才有完整 SPIFFE
> 棧；本文最後一節對照那三條分支標出 SVID 發放路徑供整合規劃用。

## 1. 拓撲總覽

```
                                   ┌──────────────────────────────────────────────────────┐
                                   │                  Telemetry plane                     │
                                   │   io.l5d.prometheus  +  io.l5d.zipkin → zt-zipkin    │
                                   │                       :9411                          │
                                   └──────────────────────────────────────────────────────┘
                                                        ▲ traces (sampleRate=1.0)
                                                        │
┌── Host A  (zt-gateway, 10.1.1.209) ─────────────────────────────────────────┐
│                                                                              │
│   Client ──HTTP──▶  zt-gateway :8080  ──▶ RabbitMQ ──▶ zt-php-worker         │
│                                                              │               │
│                                                              │ Saga step     │
│                                                              ▼ HTTP          │
│                                                  ┌─────────────────────────┐ │
│                                                  │  zt-linkerd  (outgoing) │ │
│                                                  │   router :4140          │ │
│                                                  │   identifier: Host hdr  │ │
│                                                  │   dtab: /svc/Order…    │ │
│                                                  │         /svc/Production │ │
│                                                  │         /svc/User…      │ │
│                                                  │   namer: io.l5d.fs      │ │
│                                                  │   ↓ /var/linkerd/disco  │ │
│                                                  │   OrderService→210:4141│ │
│                                                  │   ProductionSvc→207   │ │
│                                                  │   UserService →214    │ │
│                                                  │   retry budget / 5s TO  │ │
│                                                  │   admin :9990           │ │
│                                                  └────────────┬────────────┘ │
│                                                  ⚠️ 無 TLS / 無 SVID         │
│                                                  Phase 3 mTLS 區塊全部註解中 │
└──────────────────────────────────────────────────────────────────────────────┘
                                                                │
                ┌───────────────────────────────────────────────┼──────────────────────────────────────┐
                │ HTTP :4141                       HTTP :4141   │                       HTTP :4141     │
                ▼                                  ▼            ▼                                  ▼
   ┌── Host B (10.1.1.210) ────┐    ┌── Host C (10.1.1.207) ────┐    ┌── Host D (10.1.1.214) ────┐
   │ zt-linkerd-order          │    │ zt-linkerd-production     │    │ zt-linkerd-user           │
   │   incoming router :4141   │    │   incoming router :4141   │    │   incoming router :4141   │
   │   dtab: /svc/Order…→fs    │    │   dtab: /svc/Production…  │    │   dtab: /svc/User…        │
   │   io.l5d.fs disco ↓       │    │   io.l5d.fs disco ↓       │    │   io.l5d.fs disco ↓       │
   │   order-service:8080      │    │   production-service:8080 │    │   user-service:8080       │
   │            │              │    │            │              │    │            │              │
   │            ▼              │    │            ▼              │    │            ▼              │
   │   order-service           │    │   production-service      │    │   user-service            │
   │   (OpenSwoole, CI4)       │    │   (OpenSwoole, CI4)       │    │   (OpenSwoole, CI4)       │
   │   :8082 host-bind         │    │   :8083 host-bind         │    │   :8084 host-bind         │
   │            │              │    │            │              │    │            │              │
   │   order_DB (Postgres)     │    │   production_DB (PG)      │    │   user_DB (PG)            │
   └───────────────────────────┘    └───────────────────────────┘    └───────────────────────────┘
```

## 2. 單筆 Saga step 的 hop 序列（以 `OrderSaga.handleStep1` 為例）

```
client ─POST /api/orders──▶ Gateway:8080
                                │ 1) AMQP publish (canonical envelope)
                                ▼
                          RabbitMQ (order_queue → request → event chain)
                                │
                                ▼
                          php-worker (OrderSaga.handleStep1)
                                │ 2) HTTP POST /order
                                │    Host: OrderService     ← identifier 用這個 header 解析
                                ▼
                       Host A linkerd outgoing :4140
                                │ 3) dtab /svc/OrderService → /#/io.l5d.fs/OrderService
                                │    io.l5d.fs 查 disco/host-a/OrderService = "10.1.1.210 4141"
                                │    Zipkin span 1 開
                                │    ⚠️ 此處無 SVID 附帶；plain HTTP
                                ▼
                       Host B linkerd incoming :4141
                                │ 4) 同樣 dtab + io.l5d.fs/OrderService
                                │    查 disco/host-b/OrderService = "order-service 8080"
                                │    Zipkin span 2 開（child of span 1）
                                │    ⚠️ 此處無 SVID 驗證
                                ▼
                       order-service :8080  →  order_DB
                                │
                          ◀──── 同一條鏈反向回 worker
                                │
                          worker emit OrderCreatedEvent → 觸發 Step 2/3/4
```

## 3. Linkerd 在這條鏈上做的事

| 元件 | 機制 |
|---|---|
| identifier | `io.l5d.header.token` on `Host` → 把 `Host: OrderService` 解析成 logical name `/svc/OrderService` |
| dtab 重寫 | `/svc/X => /#/io.l5d.fs/X`（兩端 host 各有自己的 dtab） |
| 服務發現 | `io.l5d.fs` 從 `/var/linkerd/disco/host-X/<svc>` 平面檔讀 IP+port，hot-reload |
| retry/timeout | `io.l5d.http.retryableRead5XX`、retry budget 20% × min 5/s × ttl 10s、backoff 100–2000ms jittered、totalTimeout 5s |
| telemetry | 每個 router 同時推 Prometheus 指標 + Zipkin span（sampleRate=1.0） |
| admin | `:9990/admin/ping` 健康、`/admin/metrics.json` 歷史指標 |
| TLS / mTLS | **未啟用** — 所有 4 個 yaml 的 `tls:` 區塊都註解著「Phase 3 — uncomment once certs are distributed」 |
| **SVID** | **不存在於本分支**（見 §5） |

關鍵資料路徑：每個 hop 經過 **2 個 Linkerd**（host A outgoing → host B/C/D incoming），所以一筆 saga step 會在 Zipkin 看到兩段 router span 串起來。

## 4. SVID 在本分支的位置

**不發放、不消費、不存在。**

`grep -rlnE "SPIFFE|SVID|spiffe|spire" .` 在 `feat/Linkerd1` 工作樹零命中。Linkerd 1.x 的 Phase 3
mTLS 規劃使用**靜態憑證**（由 `tls/gen-certs.sh` + `scripts/distribute-certs.sh` 在
gateway/worker container 內鋪 PEM 檔），不是 SPIFFE Workload API。也沒有 spire-server /
spire-agent / workload-registrar / spiffe-watcher 任何元件。

對應到實驗：CLAUDE.md §5 `mTLS花費時間.{xlsx,png}` 對 baseline / linkerd-only 等沒有
mTLS 的分支「檔案仍會產出但內容可忽略」。Linkerd1 屬於後者。

## 5. SPIFFE 分支的 SVID 發放路徑（對照用）

下圖為 `feat/spiffe`（同樣概念出現在 `feat/go-spiffe`、`feat/spiffe-keycloak`）。
**本分支不存在這些元件**；保留是為了之後若把 Linkerd1 與 SPIFFE 合併時知道 SVID 從哪入鏈。

```
┌── Host A (zt-gateway 同位置) ─────────────────────────────────────────────────┐
│                                                                                │
│   ┌─────────────────────┐    attest    ┌─────────────────────┐                 │
│   │   spire-server      │◀─────────────│   spire-agent       │                 │
│   │  ghcr/spire-server  │   Node API    │  ghcr/spire-agent  │                 │
│   │  CA, registry       │──────────────▶│  Workload API on   │                 │
│   │  socket: server.sock│   trust       │  /run/spire/.../   │                 │
│   └─────────────────────┘   bundle      │  agent.sock (UDS)  │                 │
│              ▲                          └──────────┬──────────┘                 │
│              │ register entries                    │ ① workload attest         │
│              │                                     │   (selectors: pid/uid)    │
│   ┌──────────┴──────────┐                          │                           │
│   │ workload-registrar  │                          │ ② issue X.509-SVID +     │
│   │ register-workloads- │                          │   trust bundle (gRPC)     │
│   │ internal.sh         │                          │                           │
│   └─────────────────────┘                          ▼                           │
│                                          ┌─────────────────────┐                │
│                                          │ spiffe-watcher (PHP)│                │
│                                          │ bin/spiffe-watcher  │                │
│                                          │ pid:service:agent   │                │
│                                          │                     │                │
│                                          │ ③ stream X.509SVIDs │                │
│                                          │   寫 PEM 到 vol     │                │
│                                          └──────────┬──────────┘                │
│                                                     │                           │
│                                                     ▼                           │
│                                          shared volume: spiffe-certs/           │
│                                          shared volume: spiffe-shared/meta.json │
│                                                     │                           │
│                                                     │ ④ gateway/worker mount    │
│                                                     │   reads cert+key+ca       │
│                                                     ▼                           │
│   gateway / php-worker  ──HTTPS w/ client cert──▶ outbound to 服務端 :8443     │
│                            (X.509-SVID = client identity)                       │
│                                                                                │
│   每個下游服務同樣跑 spire-agent + watcher，                                    │
│   inbound 用 SpiffeLsvidFilter 驗 peer cert SAN URI 是否匹配                    │
│   spiffe://<trust-domain>/<expected-svid>                                       │
└────────────────────────────────────────────────────────────────────────────────┘
```

關鍵發放點（在 SPIFFE 分支）：

| 步驟 | 元件 | 動作 |
|---|---|---|
| ① | `spire-agent` | 連 `spire-server` 的 Node API attest 自己（用 agent.crt+key） |
| ② | `spire-server` → `spire-agent` | 下發本 agent 可代理的 workload entries + trust bundle |
| ③ | `spiffe-watcher` (PHP) | 透過 UDS `agent.sock` 呼叫 Workload API，stream 收 X.509-SVID + JWT-SVID，寫成 PEM 到 `spiffe-certs/`，更新 `meta.json` 內 `x509_state=ready` 與 `updated_at` |
| ④ | `gateway` / `php-worker` | mount 同個 volume 讀 PEM，用 client cert 對下游 mTLS；PHP filter `SpiffeLsvidFilter` 在下游做驗證 |

整合到 Linkerd 的計畫（Phase 3）：把上面 ③ 寫出的 PEM mount 進 zt-linkerd 容器的
`/var/linkerd/tls/`，並把 yaml 裡 `tls:` 區塊解註解 — 但**現行 SPIFFE 分支與 Linkerd1 分支
是分離的**，尚未有合併分支實際做這件事。

## 6. 端口與 socket 一覽

| Port / Socket | 作用 | 出現在 |
|---|---|---|
| `:8080` | Gateway HTTP | 所有分支 |
| `:8082/:8083/:8084` | order/production/user host-bind | 所有分支 |
| `:4140` | Linkerd outgoing router (Host A) | feat/Linkerd1 |
| `:4141` | Linkerd incoming router (Host B/C/D) | feat/Linkerd1 |
| `:9990` | Linkerd admin | feat/Linkerd1 |
| `:9411` | Zipkin collector | feat/Linkerd1 |
| `unix:/run/spire/sockets/agent.sock` | SPIRE Workload API | feat/spiffe* |
| `:9901` | spiffe-watcher health | feat/spiffe* |
