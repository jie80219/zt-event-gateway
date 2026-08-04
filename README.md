# zt-event-gateway

> A zero-trust, event-driven distributed transaction API gateway built on
> **SPIFFE/SPIRE** workload identity, **nested LSVID** identity chains, and a
> **Saga**-orchestrated RabbitMQ pipeline.
>
> 基於 **SPIFFE/SPIRE 工作負載身份**、**巢狀 LSVID 身份鏈** 與 **Saga 編排**
> 的零信任事件驅動分散式交易 API Gateway。

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](./LICENSE)
![PHP](https://img.shields.io/badge/PHP-%5E8.3-777bb4)
![SPIRE](https://img.shields.io/badge/SPIRE-1.12-blue)

This repository is the reference implementation accompanying the master's thesis
comparing **SPIFFE + Keycloak + LSVID** against a **HashiCorp Vault PKI** baseline
for service-to-service identity in an event-driven micro-service gateway.

本 repo 為碩士論文之參考實作，主題為在事件驅動微服務閘道器中，比較
**SPIFFE + Keycloak + LSVID** 與 **HashiCorp Vault PKI** 兩種服務間身份方案。

---

## Table of Contents / 目錄

- [Architecture / 系統架構](#architecture--系統架構)
- [Repository layout / 專案結構](#repository-layout--專案結構)
- [Prerequisites / 環境需求](#prerequisites--環境需求)
- [Quick start / 快速開始](#quick-start--快速開始)
- [Configuration / 環境設定](#configuration--環境設定)
- [Testing / 測試](#testing--測試)
- [Reproducing the research / 重現研究實驗](#reproducing-the-research--重現研究實驗)
- [Troubleshooting / 疑難排解](#troubleshooting--疑難排解)
- [Key environment variables / 主要環境變數](#key-environment-variables--主要環境變數)
- [License & citation / 授權與引用](#license--citation--授權與引用)

---

## Architecture / 系統架構

A single ingress gateway accepts orders over HTTP, wraps them in a canonical
envelope stamped with a SPIFFE identity + freshly minted LSVID, and publishes to
RabbitMQ. A worker consumes the events and drives a distributed Saga across three
downstream services (create order → deduct inventory → charge wallet → confirm),
with compensating transactions on failure.

單一入口 Gateway 以 HTTP 收單，封裝成帶 SPIFFE 身份 + 新鑄 LSVID 的
canonical envelope 發送到 RabbitMQ；Worker 消費事件並跨三個下游服務
編排分散式 Saga（建立訂單 → 扣庫存 → 扣款 → 完成），失敗時執行補償交易。

```
Client ──HTTP──▶ Gateway (OpenSwoole :8080)
                   │  mint LSVID L0, publish canonical envelope
                   ▼
              RabbitMQ (events exchange ─▶ order_queue)
                   │
                   ▼
              php-worker ── RequestConsumer → EventConsumer → OrderSaga
                   │  validate envelope + SPIFFE trust domain + LSVID chain
                   │  extend LSVID L1 → L2, inject mTLS
                   ├──▶ Order-Service      (:8082)
                   ├──▶ Production-Service (:8083)
                   └──▶ User-Service       (:8084)

Identity plane:  SPIRE Server ─▶ SPIRE Agent ─▶ spiffe-watcher ─▶ SHM (seqlock)
                                                                  ▲
                                          Gateway / Worker read SVID + bundle here
```

**LSVID nested chain / LSVID 巢狀簽章鏈**

```
Gateway mints L0 (iss=gateway, aud=worker)
  → Worker extends to L1 (nested=L0, iss=worker, aud=worker)
    → SpiffeLsvidFilter extends to L2 (nested=L1, aud=downstream-service)
      → downstream verifies the full chain L0 → L1 → L2
```

For the full component responsibility table and request lifecycle, see
[`CLAUDE.md`](./CLAUDE.md).
完整的元件職責表與請求生命週期請見 [`CLAUDE.md`](./CLAUDE.md)。

---

## Repository layout / 專案結構

```
bin/
  gateway.php            OpenSwoole HTTP server — ingress, L0 LSVID minting
  worker.php             RabbitMQ consumer — LSVID validation, Saga dispatch
  spiffe-watcher.php     SPIFFE SVID rotation daemon + SHM writer
  keycloak-watcher.php   Keycloak service-account token + JWKS warmer (optional)
src/
  EventBus.php  Saga.php  HandlerScanner.php  QueueTopology.php
  MessageQueue/  Worker/  Ingress/  EventStore/
  Spiffe/                SPIFFE/SPIRE integration (SHM, TLS, Source, LSVID)
  Keycloak/              OAuth2 client-credentials layer (optional)
anser-gateway/           HTTP controllers, filters (SpiffeLsvidFilter), routing
Sagas/OrderSaga.php      order Saga (7 event handlers)
Event-Driven/Events/     10 event classes
Services/                Order / Production / User services (in-tree)
  Order_service/         each ships its own docker-compose.yml + SPIRE Agent
  Production_service/
  User_service/
packages/
  php-spiffe/            SPIFFE Workload API client (path symlink)
  php-lsvid/             LSVID signer / validator (path symlink)
spiffe/                  SPIRE Server / Agent configs, workload registration
docker/                  Dockerfiles: php-openswoole, php-spiffe, rabbitmq, keycloak, …
scripts/                 test, CI, security, and experiment drivers
docs/                    design notes, data-flow diagrams, thesis material
EXPERIMENTS.md           full experiment manual (multi-host lab)
```

---

## Prerequisites / 環境需求

| Tool | Version | Purpose |
|---|---|---|
| Docker + Compose v2 | recent | run the whole stack / 執行整個堆疊 |
| PHP | `^8.3` | local tooling / composer scripts |
| Composer | 2.x | PHP dependencies |
| Python | `>=3.9` | experiment analysis (`scripts/experiments/requirements.txt`) |
| `jq`, `curl` | any | health checks & smoke tests |

Everything runs in containers, so **Docker alone is enough to bring the stack up**.
PHP/Composer/Python are only needed to run tests and the analysis scripts on the host.

所有服務皆容器化，**只要有 Docker 就能把整套堆疊跑起來**；
PHP/Composer/Python 僅在本機跑測試與分析腳本時才需要。

---

## Quick start / 快速開始

### 1. Clone & install / 取得原始碼與安裝依賴

```bash
git clone <your-fork-url> zt-event-gateway
cd zt-event-gateway
cp .env.example .env          # then adjust as needed / 視需要調整
composer install              # optional — only for host-side tooling
```

> `packages/php-spiffe` and `packages/php-lsvid` are path-symlinked Composer
> packages; they are pulled in automatically by `composer install`.
> 這兩個套件以 path symlink 方式引入，`composer install` 會自動處理。

### 2a. Baseline mode (no SPIFFE) / 基線模式（不啟用 SPIFFE）

The fastest way to see the gateway + Saga working, with no identity plane.
最快看到 Gateway + Saga 運作、不啟用身份層的方式：

```bash
SPIFFE_ENABLED=0 docker compose up -d rabbitmq eventstoredb gateway php-worker
curl -fsS http://localhost:8080/api/health && echo "  gateway OK"
```

### 2b. Full zero-trust stack / 完整零信任堆疊

Brings up RabbitMQ, EventStoreDB, the **SPIRE Server/Agent + workload-registrar +
spiffe-watcher**, and the gateway + worker.
啟動 RabbitMQ、EventStoreDB、**SPIRE Server/Agent + registrar + spiffe-watcher**
以及 gateway + worker：

```bash
docker compose up -d
# wait for SPIRE + watcher to become healthy / 等 SPIRE 與 watcher healthy
docker compose ps
docker exec zt-spiffe-watcher cat /tmp/spiffe-shared/meta.json | jq .x509_state
```

Then start the three downstream services (each is a self-contained compose stack
with its own SPIRE Agent):
接著啟動三個下游服務（各自是含獨立 SPIRE Agent 的 compose stack）：

```bash
docker compose -f Services/Order_service/docker-compose.yml      up -d
docker compose -f Services/Production_service/docker-compose.yml up -d
docker compose -f Services/User_service/docker-compose.yml       up -d
```

### 2c. Add the Keycloak layer (optional) / 加上 Keycloak 層（選用）

```bash
# Enable the OAuth2 client-credentials east-west layer + north-south JWT ingress
KEYCLOAK_ENABLED=1 COMPOSE_PROFILES=keycloak docker compose up -d
```

Keycloak listens on host port **8180**; the seeded realm is `zt`
(see `docker/keycloak/`).
Keycloak 對外埠為 **8180**，預載 realm 為 `zt`。

### 3. Smoke test — one full order / 冒煙測試：跑一張完整訂單

```bash
TRACE=smoke-$(date +%s)
curl -sS -X POST http://localhost:8080/api/orders \
  -H 'Content-Type: application/json' -H "X-Correlation-Id: $TRACE" \
  -d '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}'
# expect / 預期：HTTP 202 + JSON containing trace_id

sleep 5
docker logs --tail 200 zt-php-worker 2>&1 | grep -E '✅ Saga Step 4|RollbackSaga' | tail -5
# pass / 通過條件：看到 "✅ Saga Step 4: 訂單完成！" 且該 trace 無 RollbackSaga
```

**Service endpoints / 服務端點**

| Service | URL | |
|---|---|---|
| Gateway | `http://localhost:8080/api/health` · `POST /api/orders` | ingress |
| Order-Service | `http://localhost:8082/api/health` | |
| Production-Service | `http://localhost:8083/api/health` | |
| User-Service | `http://localhost:8084/api/health` | |
| RabbitMQ management | `http://localhost:15672` | |
| EventStoreDB | `http://localhost:2113` | |
| Keycloak | `http://localhost:8180` (profile `keycloak`) | |

**Canonical ingress contract / 標準入口契約 (`schema_version=1`)** — the gateway
publishes a fixed envelope and accepts common field aliases, normalizing them:
Gateway 發送固定 envelope，並接受常見欄位別名後正規化：

```json
{
  "schema_version": 1,
  "type": "gateway.request",
  "route": "OrderCreateRequestedEvent",
  "id": "trace-demo-001",
  "spiffe_id": "spiffe://zt.local/php-gateway",
  "spiffe_path": ["spiffe://zt.local/php-gateway"],
  "data": {
    "userKey": "1",
    "productList": [{ "p_key": 1, "amount": 2 }],
    "total": 100
  }
}
```

- `userKey` ← `user_id`, `customerId`, `customer_id`
- `productList` ← `product_list`; product `p_key` ← `productId`, `product_id`; `amount` ← `qty`, `quantity`
- `total` ← `amount`
- invalid JSON → `400` (no queue message); missing required fields → `422` (no queue message); untrusted `spiffe_id` → rejected without requeue

---

## Configuration / 環境設定

All runtime knobs live in [`.env.example`](./.env.example) — copy it to `.env`.
The file is heavily commented; the most important switches:

所有執行期開關都在 [`.env.example`](./.env.example)，複製成 `.env` 使用。
檔內註解詳盡，最關鍵的開關如下：

| Variable | Default | Meaning |
|---|---|---|
| `SPIFFE_ENABLED` | `1` | Master switch. `0` disables SPIFFE/LSVID/mTLS entirely. / 主開關 |
| `LSVID_ENABLED` | `1` | Enable LSVID minting/validation. |
| `LSVID_REQUIRED` | `0` | Fail-closed: require a valid LSVID. |
| `SPIFFE_MTLS_ENABLED` | `0` | mTLS on downstream calls. |
| `KEYCLOAK_ENABLED` | `0` | OAuth2 client-credentials east-west layer. |
| `KEYCLOAK_INGRESS_ENABLED` | follows `KEYCLOAK_ENABLED` | North-south JWT validation (fail-closed). |

**Ablation combinations / 消融組合** (used by the thesis experiments):

| Configuration | Flags |
|---|---|
| Baseline | `SPIFFE_ENABLED=0` |
| LSVID only | `SPIFFE_ENABLED=1 LSVID_ENABLED=1 SPIFFE_MTLS_ENABLED=0` |
| mTLS only | `SPIFFE_ENABLED=1 LSVID_ENABLED=0 SPIFFE_MTLS_ENABLED=1` |
| Full zero-trust | `SPIFFE_ENABLED=1 LSVID_ENABLED=1 SPIFFE_MTLS_ENABLED=1` |
| + Keycloak | add `KEYCLOAK_ENABLED=1` |

> **Never commit `.env`.** It is git-ignored. Secrets (Keycloak client secrets,
> etc.) belong only in your local `.env`.
> **切勿把 `.env` commit 進 repo**（已在 `.gitignore`），密鑰只放本機 `.env`。

---

## Testing / 測試

```bash
# Unit tests (envelope, consumers, Saga, LSVID, SHM) / 單元測試
composer test:unit

# Gateway E2E — lightweight, no SPIRE stack / 輕量 E2E（不含 SPIRE）
composer gateway:e2e

# SPIFFE E2E + LSVID chain / SPIFFE 端到端 + LSVID 鏈
composer spiffe:e2e
composer lsvid:e2e

# Full-architecture E2E: SPIRE + LSVID + Saga + security + perf
COMPOSE_PROFILES=zt composer arch:e2e

# SPIRE trust-plane integrity probe / SPIRE 信任平面完整性探針
COMPOSE_PROFILES=zt composer spiffe:verify

# CI drivers / CI 驅動
composer ci:verify      # unit + gateway E2E loop (default)
composer ci:zt          # full zero-trust E2E
composer ci:baseline    # SPIFFE_ENABLED=0 baseline
composer ci:keycloak    # Keycloak profile E2E
```

The GitHub Actions pipeline (`.github/workflows/ci.yml`) fans out into
`unit-tests`, `e2e-baseline`, and `e2e-full-zt`.
CI 管線分成 `unit-tests`、`e2e-baseline`、`e2e-full-zt` 三個 job。

---

## Reproducing the research / 重現研究實驗

The thesis reports **four experiments**. There are two levels of reproduction:

論文包含 **四項實驗**，重現分兩個層級：

- **Functional reproduction (single host)** — anyone can run the security
  matrix, the LSVID component-layer benchmark, and a functional Saga on one
  machine with Docker. Numbers will differ from the paper (single-host, shared
  CPU) but the behaviour is identical.
  **功能重現（單機）**：任何人用一台有 Docker 的機器即可跑安全矩陣、LSVID
  元件層量測與功能性 Saga；數值會與論文不同（單機、共享 CPU）但行為一致。
- **Performance reproduction (4-host lab)** — the published latency/throughput
  figures require the four-host topology (gateway + 3 services on separate
  hosts) described in [`EXPERIMENTS.md`](./EXPERIMENTS.md).
  **效能重現（四台主機）**：論文的延遲/吞吐數據需要
  [`EXPERIMENTS.md`](./EXPERIMENTS.md) 所述的四台主機拓撲。

Install analysis dependencies once / 安裝分析相依套件（一次即可）：

```bash
python3 -m pip install -r scripts/experiments/requirements.txt
```

| # | Experiment / 實驗 | Driver | Analysis |
|---|---|---|---|
| 1 | Performance, 5k/10k/20k orders / 效能壓測 | `scripts/experiments/run-dualmode-distributed.sh` (multi-host) | `analyze-dualmode-experiment.py` |
| 2 | LSVID component-layer latency & size / LSVID 元件層 | `composer bench:lsvid-size` | `scripts/experiments/` analyzers |
| 3 | CVE attack matrix (17 categories) / CVE 攻擊矩陣 | `scripts/security/run-zt-cve-experiment.sh` | `scripts/security/compare-stacks.py` |
| 4 | Fault-injection recovery / 故障注入恢復 | `scripts/experiments/chaos/` drivers | `scripts/experiments/compare-fault-recovery.py` |

Example — the security CVE matrix on a single host:
範例——單機跑安全 CVE 矩陣：

```bash
docker compose up -d                              # bring up the zero-trust stack
bash scripts/security/run-zt-cve-experiment.sh    # 17 categories, 50 variants
# results land in artifacts/sec-zt-cve-<timestamp>/
```

See [`EXPERIMENTS.md`](./EXPERIMENTS.md) for the full preflight checklist,
host↔service mapping, and step-by-step run/analyze flow for every experiment.
每項實驗的 preflight 核對清單、主機對應與完整 run/analyze 流程請見
[`EXPERIMENTS.md`](./EXPERIMENTS.md)。

---

## Troubleshooting / 疑難排解

**SPIRE Agent / PostgreSQL unhealthy on some hosts (AppArmor blocks UDS bind)**
某些主機上 SPIRE Agent / PostgreSQL 起不來（AppArmor 擋 unix socket bind）

The SPIRE Agent and PostgreSQL bind a unix domain socket; a restrictive host
AppArmor profile makes them crash-loop. Add a compose override:
兩者都會 bind UDS，主機預設 AppArmor 會讓它們 crashloop。加一個 override：

```yaml
# docker-compose.override.yml
services:
  spire-agent:
    security_opt: ["apparmor:unconfined"]
```

**SPIRE Agent crash-loops with `unknown authority` after restarting the server**
重啟 SPIRE Server 後 Agent 一直 `unknown authority` crashloop

A server CA rotation (or a `docker compose down` that recreates the server)
invalidates the agent's cached trust bundle. Either wipe the agent data volume,
or restart the server without tearing it down:
Server CA 輪換（或 `down` 重建 server）會讓 Agent 快取的 bundle 對不上。
清掉 agent data volume，或用不拆除的方式重啟 server：

```bash
# restart the server without invalidating agents
docker compose up -d --no-deps --force-recreate spire-server
# or, wipe the agent's cached bundle
docker compose rm -sf spire-agent && docker volume rm <project>_spire-agent-data
```

**Stale containers after switching branches** / 切分支後容器跑到舊 code

After `git checkout` + `git pull`, the **first** docker command on each host must
be `docker compose down` before `up -d`; otherwise Compose reuses old containers
running stale code/env.
切分支 pull 後，每台第一個 docker 指令必須是 `docker compose down` 再 `up -d`，
否則會重用舊容器跑到舊 code / 舊 env。

**Smoke order stalls at Saga Step 1** / 冒煙訂單卡在 Saga Step 1

Usually a stale downstream build, SPIRE not yet ready, or missing DB seed data.
Confirm all four `/api/health` endpoints return `200` and the watcher SHM is
populated before load-testing.
常見原因：下游 build 過舊、SPIRE 尚未 ready、DB seed 缺資料。壓測前先確認
四個 `/api/health` 都回 200 且 watcher SHM 已寫入。

**Keep RabbitMQ consumer prefetch at 1** / RabbitMQ 消費者 prefetch 維持 1

`bin/worker.php` sets `basic_qos(prefetch=1)` deliberately — raising it inflates
tail latency and skews the p99 comparisons the thesis relies on.
`bin/worker.php` 的 `basic_qos` 刻意設為 1；調高會放大尾端延遲、扭曲論文的 p99 對比。

**Inspect live state** / 檢查即時狀態

```bash
docker compose ps                                  # health of every service
docker logs -f zt-php-worker                       # Saga step-by-step trace
docker exec zt-spiffe-watcher cat /tmp/spiffe-shared/meta.json | jq   # SVID freshness
docker exec zt-rabbitmq rabbitmqctl list_queues name messages consumers
```

---

## Key environment variables / 主要環境變數

| Variable | Default | Description |
|---|---|---|
| `GATEWAY_HOST` / `GATEWAY_PORT` | `0.0.0.0` / `8080` | OpenSwoole HTTP bind |
| `AMQP_HOST` … `REQUEST_ROUTING_KEY` | see `.env.example` | RabbitMQ connection + topology |
| `SPIFFE_ENABLED` | `1` | master SPIFFE/LSVID/mTLS switch |
| `SPIFFE_ID` | `spiffe://zt.local/php-gateway` | this workload's SPIFFE ID |
| `SPIFFE_ENDPOINT_SOCKET` | `unix:/run/spire/sockets/agent.sock` | SPIRE Agent UDS |
| `SPIFFE_SHM_DIR` | `/tmp/spiffe-shared` | SHM directory (seqlock) |
| `LSVID_ENABLED` / `LSVID_REQUIRED` | `1` / `0` | LSVID enable / fail-closed |
| `SPIFFE_MTLS_ENABLED` | `0` | downstream mTLS |
| `DOWNSTREAM_SPIFFE_ID` | `''` | LSVID audience |
| `KEYCLOAK_ENABLED` | `0` | OAuth2 layer master switch |
| `ORDER/PRODUCTION/USER_SERVICE_HOST/PORT` | `localhost:8082/8083/8084` | downstream services |

The full annotated list (Keycloak, watcher tuning, E2E/CI knobs) is in
[`.env.example`](./.env.example).
完整且附註解的清單見 [`.env.example`](./.env.example)。

---

## License & citation / 授權與引用

Licensed under the [MIT License](./LICENSE).
本專案採 [MIT 授權](./LICENSE)。

> The copyright holder in `LICENSE` is set to *SDPM Lab, National Kaohsiung
> Normal University* — edit it to your name/institution before publishing.
> `LICENSE` 的著作權人目前填為 *SDPM Lab, National Kaohsiung Normal University*，
> 發佈前請視需要改成你的姓名／單位。

If you use this work in academic research, please cite the accompanying thesis
on zero-trust event-driven gateways with SPIFFE + Keycloak + LSVID.
若用於學術研究，請引用本專案對應之碩士論文（SPIFFE + Keycloak + LSVID
零信任事件驅動閘道器）。
