# 安全性實驗手冊（Security Probe Suite）

本目錄是 **跨 stack 安全性比較實驗** 的工具集。對同一個 Saga 拓撲，把不同的安全層配置（baseline / Linkerd / Keycloak / SPIFFE / 疊加）擺到同一張 case×stack 對比表上，量化每一種「擋下了什麼、放過了什麼」。

> **適用範圍**：本手冊與 `CLAUDE.md` 的「Running Experiments」§1–§5 共用同一組 SSH alias 與 host 拓撲。建議先把 `CLAUDE.md` §1–§3 走熟再來看本文件。

---

## 1. 為什麼要做這個實驗

`zt-event-gateway` 同時被 **網路層**（Linkerd 1.x mesh）與 **身份層**（Keycloak end-user JWT、SPIFFE workload SVID）兩種安全方案各自整合過。在切換或疊加方案時，光看效能曲線無法判斷「哪個方案值得它的 overhead」——需要一組 **可重跑、結果可量化** 的攻擊矩陣，從同一拓撲對每個 stack 各打一輪，再把結果並排對比。

研究問題：

- 同一組攻擊，**身份層** vs **網路層** 各自能擋下多少？
- 哪些攻擊只有身份層能擋（如下游身份繞過、AMQP 偽造 envelope）？
- 哪些攻擊不管哪一層都擋不住（如 replay、應用層輸入污染）→ 必須在 Gateway envelope 或 Saga 邏輯補位？
- 疊加身份層 + 網路層的成本與效益如何？

---

## 2. 架構

```
scripts/security/
├── lib.sh                        共用：emit JSONL / log / 4 個 stack-agnostic 類目
├── probe-linkerd.sh              Linkerd1 driver（A/B/C/E + 自訂 D：4140/4141/9990 暴露）
├── probe-baseline.sh             main driver（A/B/C/E + 自訂 D：服務裸 port + 確認沒 mesh 殘留）
├── probe-<stack>.sh              （未來）keycloak / keycloak-spiffe / mesh+identity
└── aggregate-security.py         讀 N 個 results.jsonl → 比較矩陣 xlsx + png + md
```

每支 driver 產出一份 `artifacts/sec-<stack>-<ts>/results.jsonl`，每行一個 case：

```json
{
  "stack":     "linkerd",                     // 哪個 stack 跑出來的
  "case_id":   "B5",                          // 唯一識別
  "category":  "id-bypass-wallet",            // 類目
  "target":    "user:8084/api/v1/wallet",     // 探測目標
  "status":    "200",                         // HTTP 狀態
  "verdict":   "accepted",                    // 對齊欄位：accepted / rejected / reachable / blocked / accepted-by-broker / broker-rejected
  "notes":     "X-User-key=2 — read user 2 wallet (cross-user) — body=...",
  "is_attack": 1                              // 1=攻擊案（reject 才算擋下），0=控制案（accept 才符合預期）
}
```

`is_attack=0` 是 sanity check（happy path、自家 health 端點、replay 第一次發），用來：
1. 驗證實驗環境本身能正常運作（A9 必須 accept，否則整個 stack 不健康，不該採信任何結果）
2. 不污染 reject_rate 統計（aggregator 自動排除）

---

## 3. 攻擊矩陣（類目）

### A. HTTP ingress 驗證（12 案，stack-agnostic）

對 Gateway `:8080/api/orders` 打各種畸形 request，測 envelope schema 檢查強度。

| ID | Body | 攻擊類別 |
|---|---|---|
| A1 | `{}` | 空 body |
| A2 | 缺 userKey | 缺欄位 |
| A3 | userKey 為空字串 | 空欄位 |
| A4 | productList 為空陣列 | 空陣列 |
| A5 | amount 負值 | 邊界 |
| A6 | total 負值 | 邊界 |
| A7 | amount = 99999999 | 異常上限 |
| A8 | userKey = `"abc"` | 型別 |
| **A9** | 正常 body | **控制案** (`is_attack=0`) |
| A10 | malformed JSON | parser |
| A11 | non-JSON body | content-type |
| A12 | userKey = `"1 OR 1=1--"` | SQL-style |

### B. 下游身份繞過（6 案）

直接 SSH 到 service host，繞過 Gateway，對下游 service 直接 POST/GET 並偽造 `X-User-key`。

| ID | Target | 動作 |
|---|---|---|
| B1 | order:8082 | `X-User-key=1` 列訂單 |
| B2 | order:8082 | `X-User-key=2` 列訂單（跨使用者）|
| B3 | order:8082 | `X-User-key=999` 列訂單（不存在使用者）|
| B4 | user:8084 | `X-User-key=1` 讀錢包 |
| B5 | user:8084 | `X-User-key=2` 讀錢包（跨使用者）|
| B6 | production:8083 | 直接呼叫 reduceInventory |

> **判讀注意**：B2/B3 若回 404，要先確認是「auth 擋下」還是「資料層查不到」。`baseline` 與 `linkerd` 沒裝 auth，所以 404 多半是後者；`keycloak+spiffe` 應該是 401/403，verdict=`rejected` 才算真正擋下。

### C. AMQP envelope 注入（6 案）

完全跳過 Gateway，從 RabbitMQ management API（:15672）直接 publish 到 `order_queue`。

| ID | Envelope | 攻擊類別 |
|---|---|---|
| C1 | `schema_version=99` | bad schema |
| C2 | `type="malicious"` | bad type |
| C3 | 缺 `data` 欄位 | 缺必填 |
| C4 | `spiffe_id="spiffe://attacker.example/svc"` | **偽造身份** |
| C5 | `spiffe_id=""`（無身份）| 無身份冒充合法 |
| C6 | `spiffe_id="spiffe://zt.local/order"` 但無簽章 | replay-style |

> **C1–C3** broker 都會收下（broker 不檢 schema），但 RequestConsumer 拉出來時應該會擋。
> **C4–C6** 是 **stack 真正分歧的點**：在 baseline / linkerd 上 RequestConsumer 沒 SPIFFE 驗證 → 偽造 envelope 會被處理；在 SPIFFE stack 上 → reject。

### D. Port 暴露（stack-specific）

每個 driver 自帶不同的 D 子集：

- **`probe-linkerd.sh`**：D1 admin :9990（sanity）、D2 outgoing :4140、D3 incoming :4141、D4/D5 跨 host 4141 探測
- **`probe-baseline.sh`**：D1–D4 服務裸 port（sanity）、D5–D7 確認沒 Linkerd 殘留、D8/D9 跨 host 8083/8084 直連

### E. Replay（3 案）

對 Gateway 連送同 `X-Correlation-Id` 三次。

| ID | Action | is_attack |
|---|---|---|
| E1 | 第一次送 | 0（控制）|
| E2 | 重送 | 1 |
| E3 | 再重送 | 1 |

> 沒有 idempotency key 機制下，E2/E3 都會 202 accepted。**任何 stack 都擋不住** 是預期結果——這是給 Saga 應用層該補的洞，不是身份/網路層的責任。

### F–I（規劃中，未實作）

- F. JWT 操弄（alg=none / 過期 / aud 錯 / 跨 realm）→ Keycloak stack
- G. SVID 偽造（自簽 / 換 SPIFFE ID）→ SPIFFE stack
- H. 跨服務未授權跳轉 → SPIFFE authz policy
- I. Token 跨主機重用 → 區分 user-id vs workload-id

---

## 4. 執行流程

### 4.1 Preflight（每次跑一個 stack 都要走）

對齊 `CLAUDE.md` 「Running Experiments」§1–§3，外加本實驗特有檢查。

```bash
# 設定要測的分支
BR=feat/Linkerd1   # 或 main / feat/keycloak / feat/keycloak-spiffe / ...

# (a) 把該分支同步到四台 host
for h in zt-gateway zt-prod zt-order zt-user; do
  ssh "$h" "cd ~/zt-event-gateway && git fetch --all --prune && git checkout $BR && git pull --ff-only"
done

# (b) 確保各 host compose stack 起來
ssh zt-gateway 'cd ~/zt-event-gateway && docker compose up -d'
ssh zt-order   'cd ~/zt-event-gateway/Services/Order_service      && docker compose up -d'
ssh zt-prod    'cd ~/zt-event-gateway/Services/Production_service && docker compose up -d'
ssh zt-user    'cd ~/zt-event-gateway/Services/User_service       && docker compose up -d'

# (c) Gateway 健康檢查
ssh zt-gateway 'curl -fsS http://127.0.0.1:8080/api/health' && echo " gateway OK"

# (d) 三個下游 service container Docker-healthy（注意：服務本身沒有 /api/health，看 docker ps 的 (healthy) 標記）
for h in zt-order zt-prod zt-user; do
  ssh "$h" 'docker ps --filter "health=healthy" --format "{{.Names}}"'
done

# (e) 分支特有檢查 — Linkerd 1.x 才需要
ssh zt-gateway 'curl -fsS http://127.0.0.1:9990/admin/ping; curl -fsS http://127.0.0.1:9411/health'
# 預期：pong + zipkin status:UP
```

任一步失敗就 **停下排查**。

### 4.2 Smoke test（必跑）

在跑 30 秒探針前，先確認單筆 Saga 真的能走完——否則所有 case 的 verdict 都是「下游壞掉」造成的偽 reject。

```bash
TRACE="smoke-$(date +%s)"
ssh zt-gateway "curl -sS -X POST http://127.0.0.1:8080/api/orders \
  -H 'Content-Type: application/json' -H 'X-Correlation-Id: $TRACE' \
  -d '{\"userKey\":\"1\",\"productList\":[{\"p_key\":1,\"amount\":1}],\"total\":100}'"
# 預期：HTTP 202 + JSON 含 trace_id

sleep 6
ssh zt-gateway "docker logs --tail 200 zt-php-worker 2>&1 | grep -E 'Saga Step 4|RollbackSaga' | tail -5"
# Pass 條件：看到 '✅ Saga Step 4: 訂單完成！'，且該 trace 沒有 RollbackSaga
```

### 4.3 跑 probe（單 stack）

腳本預設用 `-lan` SSH alias（10.1.1.x）。如果本機不在 LAN 段（或 LAN tunnel 不通），用 WAN alias 覆寫：

```bash
# 預設（在 LAN 上時）
bash scripts/security/probe-linkerd.sh

# 從 WAN 跑（覆寫 alias）
GATEWAY_ALIAS=zt-gateway \
ORDER_ALIAS=zt-order \
PROD_ALIAS=zt-prod \
USER_ALIAS=zt-user \
bash scripts/security/probe-linkerd.sh

# 自訂輸出位置
OUT=artifacts/sec-linkerd-$(date +%Y%m%d-%H%M%S) bash scripts/security/probe-linkerd.sh
```

跑完印出 case-by-case 表格，並產生：

```
artifacts/sec-<stack>-<ts>/
├── results.jsonl    每行一個 case，可被 aggregator 吃進去
└── .body            內部臨時檔（B 類目 curl 抓回的 response body 暫存）
```

> **注意 SSH 中斷的偽資料**：probe 進行中若 SSH 斷線，每個 case 會 fallback 成 `status=000 / verdict=rejected`，notes 裡的 `body=` 會殘留 `ssh: connect to host ... port 22: Connection refused`。看到這種就把該批 dir 改名成 `.bad-ssh-failed` 之類丟掉，重跑。

### 4.4 跑 probe（多 stack 對比）

順序：**每換一個 stack 都要重跑 §4.1 + §4.2**，再跑 §4.3。

```bash
# 1. main → baseline
git checkout main && (對 4 host 做 §4.1)
# 確認 §4.2 smoke 過
bash scripts/security/probe-baseline.sh
# → artifacts/sec-baseline-<ts>/

# 2. feat/Linkerd1 → linkerd
git checkout feat/Linkerd1 && (對 4 host 做 §4.1)
bash scripts/security/probe-linkerd.sh
# → artifacts/sec-linkerd-<ts>/

# 3. （未來）keycloak / spiffe / mesh+id...

# 4. 彙整
python3 scripts/security/aggregate-security.py \
  --in artifacts/sec-baseline-<ts> \
  --in artifacts/sec-linkerd-<ts> \
  --out artifacts/sec-compare-$(date +%Y%m%d)
```

### 4.5 解讀彙整結果

`artifacts/sec-compare-<ts>/` 會有：

- **`security_matrix.xlsx`** — 4 個 sheet：
  - `by_case` — case_id × stack 的 verdict 表（看哪些 case 哪個 stack 不一樣）
  - `by_case_status` — 同上但顯示 HTTP status
  - `by_category` — 類目層級的 REJECT / ACCEPT / total / reject_rate
  - `raw` — 全部 JSONL 攤平
- **`block_rate.png`** — 攻擊型 case 的 reject_rate 分組長條圖（每 stack 一條，每類目一組）
- **`summary.md`** — 文字摘要：
  - 各 stack 各類目的 reject_rate
  - **控制案異常告警**（is_attack=0 但居然 REJECT 的）
  - **Differential cases** — 兩 stack 對同一 case 的 verdict 不一致的全部列出來，這是研究的核心輸出

---

## 5. 已知限制與 probe 端踩過的坑

| 問題 | 表現 | 處理 |
|---|---|---|
| `rabbitmqadmin` 3.13.7 publish KeyError | C 類目全噴 Python traceback、broker_response 空 | 已改走 management API（`POST /api/exchanges/%2F//publish`），lib.sh 修好了 |
| Linkerd admin/proxy 對 `/` 會 redirect | D2/D3 出現 `status=000000`（curl `-w '%{http_code}'` 跑了三次重導向）| Cosmetic glitch，verdict=`reachable` 仍正確 |
| 服務沒有 `/api/health` 路由 | order/prod/user 的 `/api/health` 回 404 | 改看 `docker ps` 的 `(healthy)` 標記（基於各服務 Dockerfile 內建 healthcheck） |
| WAN SSH 比 LAN 慢 5–10 倍 | 整輪 probe 從 ~30s 拉到 ~2 分鐘 | 沒問題，probe 量的是「會不會擋」不是「擋多快」 |
| B6 路徑 404 | route 在 `Routes.php` 有，但實機回 404 | 實驗紀錄保留，當作「該介面當前部署不對外」的事實 |
| A8/A12 被接受 | userKey 是 `"abc"` 或 `"1 OR 1=1--"` 都過 | **真實 finding**：Gateway envelope 沒驗證 userKey 型別 |

---

## 6. 加新 stack 時要做的事

1. 在 `scripts/security/probe-<stack>.sh` 裡：
   - `STACK="<name>"` + `source lib.sh`
   - 呼叫 `run_category_a` `run_category_b` `run_category_c` `run_category_e`
   - 自寫 D 類目（與 stack 暴露的服務 / sidecar / control-plane port 對應）
   - 若該 stack 帶 JWT/SVID 等身份層，加 F/G/H/I 類目（lib 之後會陸續加 helper）
   - 結尾呼叫 `print_summary`
2. 對 4 host 切到該分支、`docker compose up -d`、確保 §4.1 §4.2 都過
3. `bash scripts/security/probe-<stack>.sh`
4. 把新的 `artifacts/sec-<stack>-<ts>/` 加進 §4.4 的 aggregator `--in` 列表

---

## 7. Phase 2 規劃

把過去 revert 掉的 commit cherry-pick 到新分支：

```
8b5d9d3 chore(spiffe): remove SPIFFE/SPIRE/LSVID identity layer  ← revert 來源
33f9176 revert(keycloak): drop CompositeAuthFilter + dual-mode auth
aa16657 chore(deploy): drop SPIRE/Keycloak compose, simplify CI + docs
b870936 chore(linkerd): purge SPIFFE/SPIRE/LSVID residue
```

→ 新分支 `feat/keycloak-spiffe` + 新 driver `probe-keycloak-spiffe.sh`（含 F/G/H/I）

最終比較目標：5 個 stack（baseline / linkerd / keycloak / keycloak-spiffe / mesh+identity）一起進 aggregator，產出 case×stack 全表 + reject_rate 趨勢圖 + Pareto 散佈圖（reject_rate vs p99 latency overhead）。

---

## 8. 與效能實驗的關聯

| | 效能實驗 | 安全實驗 |
|---|---|---|
| 觸發方式 | `scripts/experiments/run-perf-multihost.sh` | `scripts/security/probe-<stack>.sh` |
| Driver | 多 host AMQP 壓測（5k/10k/20k）| 單機 SSH 對 4 host 打 ~30 case |
| 跑一次時間 | 數十分鐘到數小時 | 30 秒（LAN）/ 2 分鐘（WAN）|
| 輸出 | `artifacts/<ts>_Experimental/`（CSV + xlsx + png）| `artifacts/sec-<stack>-<ts>/results.jsonl` |
| 彙整工具 | `analyze-perf-experiment.py` | `aggregate-security.py` |

兩者用 **同一個** preflight + smoke 流程（§4.1 + §4.2），可在同一輪 stack 切換中先跑安全 probe（快），再跑效能（慢），最大化每次拓撲切換的產出。
