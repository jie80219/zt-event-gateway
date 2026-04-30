# SPIFFE/SPIRE 自救能力升級與 2026-04-28 救援紀錄

## 起因

當天遇到的兩個問題：

1. **Per-service spire-agent crash loop**：`order-spire-agent` / `production-spire-agent` / `user-spire-agent` 反覆重啟，log 一致是 `x509svid: could not verify leaf certificate: x509: certificate signed by unknown authority`；三個 helper exit 137。
2. **`POST /api/orders` 回 500 `Identity token creation failed`**：gateway 鑄 LSVID L0 失敗，原因是 SHM 內的 SVID 已過期（`spiffe-watcher` stream 卡住但仍標 healthy）。

## 根因

### 為什麼 agent 會撞「unknown authority」

- `zt-spire-server` 的 `data_dir = /tmp/spire-server/data` 沒掛 volume → 容器只要被 recreate，整個 datastore + CA 重生。
- 各 agent 的 `data_dir` 也沒持久化，但**仍會在 container fs 裡寫 `bundle.der`**；server CA 一變，agent 拿到的 bundle 立刻變廢。
- 因為 bundle 不空，`insecure_bootstrap = true` 不會再去抓新 bundle → 永久卡死。

### 為什麼 watcher 卡住但還是「healthy」

- watcher 與 `zt-spire-agent` 共用 PID namespace；當 SPIFFE Workload API 的 gRPC stream 因任何原因（agent restart、network blip）斷掉並 reconnect 後，會回到 `ready` 狀態，但**內部 coroutine 不再實際收 update**。
- SHM 的 `updated_at` 停止前進；docker healthcheck 雖然會跑成 `unhealthy`，但 `restart: unless-stopped` 只在 process exit 時才重啟 — **`unhealthy` 不觸發 restart**。
- SVID TTL 600s，gateway 鑄 LSVID 用過期的 cert → "Identity token creation failed"。

### 為什麼新 named volume 又造成新災難

修復過程加 5 個 named volume 後，`docker compose down + up` 直接 crash：

- `ghcr.io/spiffe/spire-server:1.12.0` default user = `1000:1000`，但 docker 預設新建的 named volume 是 `root:root 0755` → spire-server 沒寫權限 → sqlite3 `unable to open database file: no such file or directory`。
- spire-server 起不來 → 整條 attestation chain 倒。

## 救援步驟（已執行）

### 1. 救活 per-service spire-agent（清 stale bundle）

```bash
docker rm -f order-helper production-helper user-helper \
              order-spire-agent production-spire-agent user-spire-agent

cd Services/Order_service       && docker compose up -d spire-agent order-helper
cd Services/Production_service  && docker compose up -d spire-agent production-helper
cd Services/User_service        && docker compose up -d spire-agent user-helper
```

### 2. 救活 watcher 卡死

```bash
docker restart zt-spiffe-watcher zt-gateway zt-php-worker
```

### 3. 套用 volume + restart policy 後的 ownership 修復

加完 volume 之後 `compose up` 撞 sqlite3 寫不了：

```bash
# 一次性 chown 5 個 volume 給 1000:1000
for v in zt-event-gateway_spire-server-data \
         zt-event-gateway_spire-agent-data \
         order_service_order-spire-data \
         production_service_production-spire-data \
         user_service_user-spire-data; do
    docker run --rm -v "$v":/d --user 0:0 busybox chown -R 1000:1000 /d
done
```

### 4. 清掉所有 agent 在過程中累積的 stale bundle

```bash
docker stop zt-spire-agent order-spire-agent production-spire-agent user-spire-agent
for v in zt-event-gateway_spire-agent-data order_service_order-spire-data \
         production_service_production-spire-data user_service_user-spire-data; do
    docker run --rm -v "$v":/d --user 0:0 busybox sh -c 'rm -f /d/* && chown 1000:1000 /d'
done
docker start zt-spire-agent order-spire-agent production-spire-agent user-spire-agent
```

### 5. 啟動 stack

```bash
docker compose up -d                       # 主 stack
cd Services/Order_service       && docker compose up -d
cd Services/Production_service  && docker compose up -d
cd Services/User_service        && docker compose up -d
```

### 6. 驗證

```bash
docker exec zt-spire-server /opt/spire/bin/spire-server agent list   # 4 個 attested agent
curl -X POST http://127.0.0.1:8080/api/orders \
     -H 'Content-Type: application/json' \
     -d '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}'
# → HTTP 202
```

## 已落地的檔案改動

| 檔案 | 改動 |
|---|---|
| `docker-compose.yml` | `zt-spire-server` 加 `spire-server-data:/tmp/spire-server/data` volume + `restart: unless-stopped`；`zt-spire-agent` 加 `spire-agent-data:/opt/spire/data/agent` volume + `restart: unless-stopped`；`zt-workload-registrar` 加 `restart: unless-stopped` |
| `Services/Order_service/docker-compose.yml` | spire-agent 加 `order-spire-data:/opt/spire/data/agent` volume |
| `Services/Production_service/docker-compose.yml` | spire-agent 加 `production-spire-data:/opt/spire/data/agent` volume |
| `Services/User_service/docker-compose.yml` | spire-agent 加 `user-spire-data:/opt/spire/data/agent` volume |
| `spiffe/spire-agent-e2e/agent.conf` | `data_dir → /opt/spire/data/agent`，`KeyManager memory → disk` |
| `Services/Production_service/spiffe/spire-agent-e2e/agent.conf` | 同上 |
| `Services/User_service/spiffe/spire-agent-e2e/agent.conf` | 同上 |

## 自救循環如何閉合

從這次救援起，操作模型是：

```
spire-server 重啟 → datastore.sqlite3 + CA 從 spire-server-data volume 讀回 → CA 不變
agent 重啟      → bundle.der 從 *-spire-data volume 讀回 → 仍指向同一個 CA → 直接 attest 成功
```

只要 server data volume 不被刪，agent 的 disk bundle 永遠有效；單一 container 重啟（包含 server）不會再撞 unknown authority。

## 已知未解問題（下次處理）

### A. 第一次落地需要 ownership 修復

新環境（CI、新 dev 機器）第一次 `docker compose up` 仍會撞 root:root volume → spire-server crash。

**正式做法**：在 compose 加 init container 自動 chown：

```yaml
spire-server-init:
  image: busybox
  command: ["chown", "-R", "1000:1000", "/tmp/spire-server/data", "/opt/spire/data/agent"]
  user: "0:0"
  volumes:
    - spire-server-data:/tmp/spire-server/data
    - spire-agent-data:/opt/spire/data/agent

spire-server:
  depends_on:
    spire-server-init:
      condition: service_completed_successfully
```

每個 service stack 也要對應加一個 init container。

### B. spiffe-watcher stream 卡死不被 docker 殺

docker 原生 `restart: unless-stopped` 不會因 unhealthy 觸發 restart。兩條解決路徑：

1. **加 autoheal sidecar**（如 `willfarrell/autoheal`），對打了 `autoheal=true` label 的 unhealthy container 自動 restart — 最少程式碼改動。
2. **修 watcher**：在 SHM healthcheck 失敗或 stream stuck 偵測到時自殺（exit non-zero），靠 docker restart policy 接手 — 最徹底。

優先做 1，並用 label 圈出所有需要 autoheal 的容器（watcher / agent / helper）。

### C. helper PID namespace 連動問題

`*-helper` 用 `pid: "service:spire-agent"` 共享 spire-agent 的 PID namespace。spire-agent 被 recreate（非 restart）時，helper 的 PID 參照失效，必須一起 recreate。`Services/*/docker-compose.yml` 已有註解提醒，但要把這個動作做進 `restart-helper.sh` / 自動化腳本。

## 重要時間點

- 2026-04-27 16:08～16:14：原始 crash loop（per-service agent + watcher 卡死）
- 16:15～16:18：救活 per-service agent
- 16:18～16:24：watcher restart + API 恢復 → 開始改 compose
- 16:32～16:42：新 volume ownership 撞牆，全 stack 倒
- 16:43～16:46：chown 5 volumes + wipe stale bundle，全 stack 恢復
- 16:46：`POST /api/orders` 回 202 ✅
