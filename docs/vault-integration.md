# Vault 整合 Runbook

zt-event-gateway 透過 **HashiCorp Vault Agent + AppRole** 把所有 RabbitMQ /
DB / JWT secret 從 plaintext 配置（`docker-compose.yml` env 區、`Services/*/app/.env`）
轉為 Vault 中央管理。**PHP 業務邏輯完全沒改**：sidecar 把 secret render 成主應
用原本就在讀的 env 變數 / `.env` 檔案，`getenv()` 拿到的內容跟以前一致。

## 拓撲

```
vault (dev) ── kv ── secret/zt-event-gateway/{rabbitmq,jwt,db/{order,user,production}}
   │
   │ AppRole role_id + secret_id (寫入 docker/vault/creds/<role>/)
   │
   ▼ vault-init (one-shot)
   ▼
vault-agent-* sidecars ─ render template ─▶ /vault/runtime/runtime.env
                                            /vault/out/.env
                                              │
   主應用 entrypoint: source 那檔，exec 原本的 CMD
```

主 compose 包含的角色：

| 主容器          | 對應 sidecar                | AppRole role     | 拿到的 env           |
| --------------- | --------------------------- | ---------------- | -------------------- |
| `php-worker`    | `vault-agent-php-worker`    | `php-worker`     | RABBITMQ_*, AMQP_*   |
| `anser-gateway` | `vault-agent-anser-gateway` | `anser-gateway`  | RABBITMQ_*, AMQP_*, LOAD_BALANCE_AMQP_* |
| `app`           | `vault-agent-app`           | `anser-gateway`  | 同上                 |
| `monitor`       | `vault-agent-monitor`       | `anser-gateway`  | 同上                 |
| `recalc-worker` | `vault-agent-recalc`        | `anser-gateway`  | 同上                 |

各 CI4 service compose（`Services/*/docker-compose.yml`）：

| 主容器              | 對應 sidecar             | AppRole role     | render 結果                              |
| ------------------- | ------------------------ | ---------------- | ---------------------------------------- |
| `order-service`     | `vault-agent-order`      | `order-svc`      | `/vault/out/.env` (CI4 完整 .env)         |
| `user-service`      | `vault-agent-user`       | `user-svc`       | `/vault/out/.env` (含 JWT_SECRET)         |
| `production-service`| `vault-agent-production` | `production-svc` | `/vault/out/.env`                         |

CI4 服務 entrypoint：`cp /vault/out/.env /app/.env && ./start_service.sh`。
原本的 `start_service.sh` 完全沒動。

## 一鍵啟動

```bash
# 主 compose（自動建 anser_project_network、起 vault、跑 vault-init、啟動全部 sidecar+app）
bash scripts/vault-bootstrap.sh

# 三個 CI4 service compose（要分別起）
( cd Services/Order_service     && docker compose up -d )
( cd Services/User_service      && docker compose up -d )
( cd Services/Production_service && docker compose up -d )
```

## 驗證 secret 真的從 Vault 流到應用

```bash
# 1) Vault 中能讀到 5 條 path
docker exec zt-vault env VAULT_ADDR=http://127.0.0.1:8200 VAULT_TOKEN=zt-root \
  vault kv list secret/zt-event-gateway/

# 2) Sidecar render 出來的檔案
docker exec zt-vault-agent-php-worker cat /vault/runtime/runtime.env
# 預期看到:
#   RABBITMQ_USER=zt
#   RABBITMQ_PASS=ztpass
#   AMQP_USER=zt
#   AMQP_PASSWORD=ztpass

# 3) 主應用 process env
docker exec zt-php-worker sh -c 'echo "RMQ_USER=$RABBITMQ_USER  AMQP_USER=$AMQP_USER"'

# 4) CI4 服務拿到的 .env
docker exec user-service grep -E '^(database\.default\.password|JWT_SECRET)' /app/.env
```

## 旋轉 secret

Agent 對 KV v2 的 `static_secret_render_interval` 設為 `10s`（見
`docker/vault/agent/*.hcl`），所以新 secret 會在 ~10 秒內 propagate 到
sidecar 的 render 檔案。

```bash
# 改 RabbitMQ pass
docker exec -e VAULT_ADDR=http://127.0.0.1:8200 -e VAULT_TOKEN=zt-root zt-vault \
  vault kv put secret/zt-event-gateway/rabbitmq user=zt pass=newpass-$(date +%s)

# Agent 在 10 秒內偵測變更並重新 render
sleep 12
docker exec zt-vault-agent-php-worker cat /vault/runtime/runtime.env

# 既有 RabbitMQ 連線是常駐的，不會 hot-reload；下次重啟 worker 取新值
docker compose restart php-worker
```

## Debug

| 症狀                                    | 排查                                                                                                                                              |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sidecar 一直 restart / log "approle" 失敗 | `docker exec zt-vault-agent-php-worker ls /vault/creds/php-worker/` 該有 `role_id` `secret_id` 兩檔。沒有就 `docker compose run --rm vault-init` 重跑 |
| 主應用卡在 "until [ -s ... ]" loop      | sidecar 還沒 render；看 `docker logs zt-vault-agent-php-worker`                                                                                   |
| CI4 service 啟動但 DB 連不上              | `docker exec user-service cat /app/.env` 確認 hostname 是 `user_DB`、port 是 5432                                                                   |
| vault-init 跑完但 sidecar 起不來         | `docker compose logs vault-init`、確認 5 個 role 全寫進去                                                                                          |
| 重新 bootstrap 後 sidecar 401             | `docker compose down vault-agent-*` 後重跑 `bash scripts/vault-bootstrap.sh`；secret_id 會保留                                                     |

## 完全重置

```bash
docker compose down -v        # 含 vault data
rm -f docker/vault/creds/*/role_id docker/vault/creds/*/secret_id
bash scripts/vault-bootstrap.sh
```

## 不修改的 PHP 程式碼（驗證契約）

切到 Vault 之後以下檔案完全沒動，是這次整合「不破壞業務邏輯」的核心保證：

- `bin/worker.php` — 仍 `getenv('AMQP_USER')`、`getenv('AMQP_PASSWORD')`
- `src/MessageQueue/RabbitMQConnection.php::fromEnv` — 仍讀 `RABBITMQ_USER` / `RABBITMQ_PASS`
- `init.php`, `initialization.php`, `consumer.php`, `publisher.php`
- `Sagas/OrderSaga.php`, `Services/{Order,Production,User}Service.php`
- `Services/*/app/start_service.sh`, `Services/*/app/app/Config/*`
- `Event-Driven/Events/*`
- `composer.json`（不需新套件）

## 對照論文

本部署即為 `.claude/plans/end-to-end-robust-sundae.md` A.4.3 規劃的
「HashiCorp Vault Agent (auto-auth + template)」對照組；可作為「SVID 遞送機制
對照」（§5.3.7）量測 Vault 風格 credential delivery 的 rotation latency。
