# Vault 整合驗證指南

本文件提供 zt-event-gateway 的 Vault 整合**從 bootstrap 到端對端**的驗證流程。
每一節有「驗證指令」「預期結果」與「失敗時的判讀」。

執行前提：

```bash
bash scripts/vault-bootstrap.sh
( cd Services/Order_service     && docker compose up -d )
( cd Services/User_service      && docker compose up -d )
( cd Services/Production_service && docker compose up -d )
```

驗證共有 7 層：

1. [Bootstrap 結果驗證](#1-bootstrap-結果驗證)
2. [Sidecar 健康驗證](#2-sidecar-健康驗證)
3. [Policy 隔離驗證](#3-policy-隔離驗證)
4. [主應用取值驗證](#4-主應用取值驗證)
5. [端對端事件流驗證](#5-端對端事件流驗證)
6. [Secret 旋轉驗證](#6-secret-旋轉驗證)
7. [完全重置驗證](#7-完全重置驗證)

> 通用前綴：以下指令大量使用 root token 透過 `zt-vault` 容器查詢。
> 為了簡潔，先在外層 shell 設定別名：
>
> ```bash
> v() { docker exec -e VAULT_ADDR=http://127.0.0.1:8200 -e VAULT_TOKEN=zt-root zt-vault vault "$@"; }
> ```

---

## 1. Bootstrap 結果驗證

確認 vault-init 跑完之後，**5 個 secret + 5 個 AppRole + 5 對 creds 檔**全部就位。

### 1.1 Vault Server 健康

```bash
docker inspect -f '{{.State.Health.Status}}' zt-vault
v status
```

**預期**：第一行 `healthy`；`vault status` 顯示 `Sealed: false`、`Initialized: true`。

### 1.2 KV v2 + AppRole 已啟用

```bash
v secrets list -format=json | grep '"secret/"'
v auth list   -format=json | grep '"approle/"'
```

**預期**：兩行都有命中（`grep` 退出碼 0）。

### 1.3 5 條 secret path 寫入正確

```bash
v kv list secret/zt-event-gateway/
v kv list secret/zt-event-gateway/db/
v kv get  secret/zt-event-gateway/rabbitmq
```

**預期**：第一條列出 `db/`、`jwt`、`rabbitmq`；第二條列出 `order`、`production`、`user`；第三條
看到 `user=zt`、`pass=ztpass`。

### 1.4 5 個 AppRole 都建立、policy 對齊

```bash
v list auth/approle/role
for r in php-worker anser-gateway order-svc user-svc production-svc; do
  echo "=== $r ==="
  v read -field=token_policies "auth/approle/role/$r"
done
```

**預期**：5 個 role 都列出，每個 role 的 `token_policies` 等於自己名字（最小權限原則）。

### 1.5 creds 檔已寫入共用掛載

```bash
ls -l docker/vault/creds/*/
for r in php-worker anser-gateway order-svc user-svc production-svc; do
  test -s "docker/vault/creds/$r/role_id"   || echo "[!] $r role_id 缺檔"
  test -s "docker/vault/creds/$r/secret_id" || echo "[!] $r secret_id 缺檔"
done
```

**預期**：每個資料夾都有 `role_id`、`secret_id` 兩檔（非空、權限 0640），無 `[!]` 輸出。

> **失敗判讀**：如果 1.5 缺檔但 1.4 看得到 role，代表 vault-init 中途失敗。
> 重跑：`docker compose run --rm vault-init`，再重看 1.5。

---

## 2. Sidecar 健康驗證

每個主容器都配對一個 vault-agent sidecar。Sidecar 必須做到三件事：
**(a) AppRole login 成功** → **(b) 拿到 token** → **(c) render template 出檔**。

### 2.1 所有 sidecar 容器 Running

```bash
docker ps --filter "name=vault-agent" --format 'table {{.Names}}\t{{.Status}}'
```

**預期**：以下 container 全部 `Up (healthy)` 或 `Up`：

```
zt-vault-agent-php-worker
zt-vault-agent-anser-gateway
zt-vault-agent-app
zt-vault-agent-monitor
zt-vault-agent-recalc
zt-vault-agent-order        # Services/Order_service compose
zt-vault-agent-user         # Services/User_service compose
zt-vault-agent-production   # Services/Production_service compose
```

> **失敗判讀**：若某 sidecar `Restarting`，多半是 creds 缺檔或 AppRole 不存在
> （見 1.4 / 1.5）。

### 2.2 AppRole login 成功（看 log）

```bash
for c in zt-vault-agent-php-worker zt-vault-agent-anser-gateway \
         zt-vault-agent-order zt-vault-agent-user zt-vault-agent-production; do
  echo "=== $c ==="
  docker logs --tail 20 "$c" 2>&1 | grep -E "auth handler|renewed auth token|template server received"
done
```

**預期**：每個 sidecar 都看到類似：

```
auth.handler: authenticating
auth.handler: authentication successful, sending token to sinks
template.server: template server received new token
```

> **失敗判讀**：log 出現 `authentication failed: invalid role or secret ID`
> 表示對應 role 已被刪掉或 creds 過期，重跑 vault-init。

### 2.3 Token 寫入 sink

```bash
docker exec zt-vault-agent-order test -s /vault/agent.token && echo OK
```

**預期**：印出 `OK`（檔存在且非空）。

### 2.4 Template 已 render 出檔

```bash
# 主 stack 的兩種 sink（runtime.env / out/.env）
docker exec zt-vault-agent-php-worker     cat /vault/runtime/runtime.env
docker exec zt-vault-agent-anser-gateway  cat /vault/runtime/runtime.env

# 三個 CI4 服務的完整 .env
docker exec zt-vault-agent-order      cat /vault/out/.env
docker exec zt-vault-agent-user       cat /vault/out/.env
docker exec zt-vault-agent-production cat /vault/out/.env
```

**預期**：

- `php-worker`、`anser-gateway` 的 `runtime.env` 至少包含：

  ```
  RABBITMQ_USER=zt
  RABBITMQ_PASS=ztpass
  AMQP_USER=zt
  AMQP_PASSWORD=ztpass
  ```

- `order` 的 `.env` 看到 `database.default.hostname = order_DB`、`database.default.port = 5432`
- `user` 的 `.env` 額外有 `JWT_SECRET=LcNs...`
- 沒有未替換的 `{{ ... }}` template tag

---

## 3. Policy 隔離驗證

每個 role 的 policy 只允許讀**自己這條** secret path。確認跨界讀取會被擋。
（見 `docker/vault/policies/<role>.hcl`）

### 3.1 用 role 自己的 token 試讀允許 vs 禁止路徑

以 `order-svc` 為例（policy = 只能讀 `secret/data/zt-event-gateway/db/order`）：

```bash
ROLE_ID=$(cat docker/vault/creds/order-svc/role_id)
SECRET_ID=$(cat docker/vault/creds/order-svc/secret_id)

# 1) 用 AppRole 換 client token
TOK=$(v write -field=token auth/approle/login \
        role_id="$ROLE_ID" secret_id="$SECRET_ID")

# 2) 用該 token 讀「自己的」DB secret —— 應該成功
docker exec -e VAULT_ADDR=http://127.0.0.1:8200 -e VAULT_TOKEN="$TOK" \
  zt-vault vault kv get secret/zt-event-gateway/db/order

# 3) 用該 token 讀「別人的」DB secret —— 應該 403
docker exec -e VAULT_ADDR=http://127.0.0.1:8200 -e VAULT_TOKEN="$TOK" \
  zt-vault vault kv get secret/zt-event-gateway/db/user || echo "[OK] denied"

# 4) 用該 token 讀 RabbitMQ —— 應該 403
docker exec -e VAULT_ADDR=http://127.0.0.1:8200 -e VAULT_TOKEN="$TOK" \
  zt-vault vault kv get secret/zt-event-gateway/rabbitmq || echo "[OK] denied"
```

**預期**：

- 步驟 2 印出完整 secret 內容
- 步驟 3、4 都印 `permission denied`，並接著看到 `[OK] denied`

> **失敗判讀**：如果步驟 3 / 4 拿到值，policy 太寬，立刻檢查
> `docker/vault/policies/order-svc.hcl` 是否被誤改。

對 `php-worker`（只能讀 rabbitmq）做同樣測試：用其 creds login，
讀 `db/order` 應 403、讀 `rabbitmq` 應 200。

---

## 4. 主應用取值驗證

確認 sidecar render 出來的 env 真的進入主容器 process。

### 4.1 php-worker process env

```bash
docker exec zt-php-worker sh -c \
  'echo "RABBITMQ_USER=$RABBITMQ_USER  AMQP_USER=$AMQP_USER  AMQP_PASSWORD=$AMQP_PASSWORD"'
```

**預期**：`RABBITMQ_USER=zt  AMQP_USER=zt  AMQP_PASSWORD=ztpass`，**沒有空字串**。

### 4.2 anser-gateway process env

```bash
docker exec zt-anser-gateway sh -c 'env | grep -E "^(RABBITMQ_|AMQP_|LOAD_BALANCE_AMQP_)"'
```

**預期**：列出所有 RabbitMQ / load balance AMQP 變數，值不為空。

### 4.3 CI4 服務 .env 與 DB 連線

```bash
# .env 檔內容
docker exec order-service      grep -E '^(database\.default\.(host|database|user|pass|port))' /app/.env
docker exec user-service       grep -E '^(database\.default\.|JWT_SECRET)' /app/.env
docker exec production-service grep -E '^(database\.default\.)' /app/.env

# 真的可以連到 PG
docker exec order-service php -r '
  $pdo = new PDO("pgsql:host=order_DB;port=5432;dbname=order", "root", "root");
  echo $pdo->query("select 1")->fetchColumn(), "\n";
'
```

**預期**：`.env` 有正確 host（`order_DB` / `user_DB` / `production_DB`）、port `5432`；
PHP 連線測試印出 `1`。

### 4.4 確認 docker-compose 沒留下 plaintext secret

```bash
grep -nE 'RABBITMQ_(USER|PASS)|AMQP_(USER|PASSWORD)|database\.default\.password' \
  docker-compose.yml Services/*/docker-compose.yml || echo "[OK] no plaintext"
```

**預期**：印 `[OK] no plaintext`（搜尋無命中）。**這是整個整合的「未破壞契約」核心驗證**：
業務 PHP 還是 `getenv()`，但 docker-compose 不再寫死任何 secret。

---

## 5. 端對端事件流驗證

確認從 Vault 拿到的 RabbitMQ creds 真的能驅動完整 Saga。

### 5.1 RabbitMQ 連線就緒

```bash
docker exec zt-rabbitmq rabbitmqctl list_connections name user state | grep zt
```

**預期**：看到 `php-worker`、`anser-gateway` 等以 `user=zt` 建立的 `running` 連線。

### 5.2 觸發訂單 Saga

```bash
# 由 anser-gateway 對外開放的下單入口（依實際 route 調整 path）
curl -i -X POST http://localhost:8080/order \
  -H 'Content-Type: application/json' \
  -d '{
    "userKey": "1",
    "productList": [{ "p_key": 1, "amount": 1 }]
  }'
```

### 5.3 觀察 Saga log

```bash
docker logs -f zt-php-worker --tail 50
```

**預期依序看到**（對應 `Sagas/OrderSaga.php`）：

```
Saga Step 1: 收到訂單建立請求
[x] 訂單建立成功
Saga Step 2: 訂單建立，開始扣庫存
[x] 扣減庫存成功
Saga Step 3: 開始支付
[x] 支付成功
✅ Saga Step 4: 訂單完成！
```

### 5.4 DB 落地驗證

```bash
docker exec order-service php -r '
  $pdo = new PDO("pgsql:host=order_DB;port=5432;dbname=order", "root", "root");
  $r = $pdo->query("select count(*) from orders")->fetchColumn();
  echo "orders rows: $r\n";
'
```

**預期**：rows ≥ 1。

### 5.5 EventStoreDB 旁路（若有啟）

`EventBus::publish()` 在 `eventStoreDB` 非 null 時會 append 一份事件流。若該 stack 未起，
跳過此項。

> **失敗判讀**：若 5.3 卡在 Step 1 → 表示 anser-gateway / order-service DB 連不上
> （回到 4.3）；卡在 Step 2 → production-service 不通；卡在 Step 3 → user-service 或
> JWT 錯（檢查 user-svc 的 `.env` 是否有 `JWT_SECRET`）。

---

## 6. Secret 旋轉驗證

驗證「改 Vault 值 → 10 秒內 sidecar 重 render → 重啟主應用即生效」這條鏈完整。
（render interval 寫死在 `docker/vault/agent/*.hcl` 第 9 行 `static_secret_render_interval = "10s"`）

```bash
# 改 RabbitMQ pass
NEW="newpass-$(date +%s)"
v kv put secret/zt-event-gateway/rabbitmq user=zt pass="$NEW"

# 等 sidecar 偵測 + render
sleep 12

# 看 sidecar render 出的檔案是否變了
docker exec zt-vault-agent-php-worker cat /vault/runtime/runtime.env | grep -E "RABBITMQ_PASS|AMQP_PASSWORD"
# 預期: 兩條都顯示 = $NEW

# 主應用 process env「還沒變」（既有連線常駐）
docker exec zt-php-worker sh -c 'echo $AMQP_PASSWORD'
# 預期: 仍是舊值

# 重啟以重讀
docker compose restart php-worker
sleep 3
docker exec zt-php-worker sh -c 'echo $AMQP_PASSWORD'
# 預期: 顯示 $NEW
```

**通過條件**：
- `runtime.env` 在 ≤ 12 秒內反映新值
- Restart 後 process env 同步新值
- RabbitMQ 端 `rabbitmqctl list_connections` 看得到新連線（用新密碼）

> **失敗判讀**：sidecar `runtime.env` 沒變 → 看 `docker logs zt-vault-agent-php-worker`
> 是否有 `template.server: rendered`；token 過期會印 `403 permission denied`，
> 此時 agent 會自動重新 AppRole login，等 1 個 cycle 即可。

---

## 7. 完全重置驗證

確認「砍掉重練」流程可重現乾淨環境。

```bash
# 1. 停掉所有 stack（含 vault data volume）
docker compose down -v
( cd Services/Order_service     && docker compose down -v )
( cd Services/User_service      && docker compose down -v )
( cd Services/Production_service && docker compose down -v )

# 2. 砍 creds 檔（讓下次 vault-init 重新簽發 secret_id）
rm -f docker/vault/creds/*/role_id docker/vault/creds/*/secret_id

# 3. 一鍵重啟
bash scripts/vault-bootstrap.sh
( cd Services/Order_service     && docker compose up -d )
( cd Services/User_service      && docker compose up -d )
( cd Services/Production_service && docker compose up -d )

# 4. 從第 1 節開始重跑全部驗證
```

**通過條件**：所有 7 節驗證都重新通過、且第 5 節能再次完成一次 Saga。

---

## 附：一鍵 smoke test 腳本範例

把上述驗證濃縮成一支 shell（建議放 `scripts/vault-smoke.sh`，**本文件不負責產生**）：

```bash
#!/usr/bin/env bash
set -e
v() { docker exec -e VAULT_ADDR=http://127.0.0.1:8200 -e VAULT_TOKEN=zt-root zt-vault vault "$@"; }

echo "[1/4] Vault server"
v status >/dev/null

echo "[2/4] Secrets seeded"
v kv get -field=user secret/zt-event-gateway/rabbitmq | grep -q '^zt$'

echo "[3/4] Sidecar rendered envs"
docker exec zt-vault-agent-php-worker grep -q '^RABBITMQ_USER=zt$' /vault/runtime/runtime.env
docker exec zt-vault-agent-order      grep -q 'database.default.hostname = order_DB' /vault/out/.env

echo "[4/4] Process env reaches main container"
docker exec zt-php-worker  sh -c '[ -n "$AMQP_USER" ]'
docker exec order-service  test -s /app/.env

echo "[OK] Vault integration smoke test passed."
```

---

## 對照論文

本驗證流程對應 `.claude/plans/end-to-end-robust-sundae.md` §5.3.7 的「SVID 遞送
機制對照」實驗：以 7 節驗證確認 Vault Agent + AppRole 是「**可重現、權限隔離、
可旋轉**」的對照組基準。
