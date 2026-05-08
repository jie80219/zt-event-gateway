# EXPERIMENTS.md

`zt-event-gateway` 的實驗總手冊。本文件涵蓋 **四種實驗類別**：

1. 壓力／效能實驗（5,000 / 10,000 / 20,000 訂單規模）
2. 安全性實驗（跨 stack 攻擊矩陣）
3. **無 LSVID vs 有 LSVID 的容量比較**（僅 `feat/spiffe-keycloak`）
4. **巢狀 Token 成長大小與成長比**（僅 `feat/spiffe-keycloak`）

> **每次跑實驗前必走 §0 核對清單**：先確認分支、確認本次實驗類別、再對照下面對應章節走流程。任一步沒過就停下排查，不要硬跑。

---

## 0. 開跑前核對清單（每一次都要跑）

```bash
# ── (a) 確認本機 branch ─────────────────────────────────
git rev-parse --abbrev-ref HEAD          # 本機現在在哪個 branch
git rev-parse --short HEAD               # 本機現在的 commit

# ── (b) 確認 4 台 host 同步 ─────────────────────────────
for h in zt-gateway zt-order zt-prod zt-user; do
  printf '%-13s ' "$h"
  ssh "$h" 'cd ~/zt-event-gateway && git rev-parse --abbrev-ref HEAD && git rev-parse --short HEAD' | xargs
done
# 預期：四台都在你要測的 branch、同一個 short HEAD
```

對照下表確認 **本次要跑哪一類實驗 × 是否符合 branch 限制 × 必要的 worker / 環境配置**：

| 實驗類別 | 章節 | 適用 branch | 是否需要先改 worker 配置？ | 額外前置 |
|---|---|---|---|---|
| 壓力 / 效能 | §2 | 任何 branch（`main` / `feat/Linkerd1` / `feat/spiffe-keycloak` / ablation） | **是**（§2.1 指定） | — |
| 安全性 | §3 | 任何 branch（用該 branch 的 `probe-<stack>.sh`） | 否 | — |
| 無 LSVID vs 有 LSVID 容量 | §4 | **僅 `feat/spiffe-keycloak`** | 是（§4.1） | §1.4.3 SPIRE/Keycloak/LSVID 驗證項全綠 |
| 巢狀 Token 成長 | §5 | **僅 `feat/spiffe-keycloak`** | 否 | 同上 |

切錯 branch / 沒改 worker config 的後果：

- 在 `main` / `feat/Linkerd1` 上跑 §4 §5 — 沒有 LSVID 邏輯，量到的數值無意義。
- 跑 §2 但 `gateway.workerCount` 還停在 default `1` — 5k/10k 已經會被 Gateway thread 卡死，整輪資料失準。
- 跑 §3 用了不同 stack 的 probe — case_id 命中錯誤，aggregator 對不齊。

---

## 1. 基本測試（§2 §3 §4 §5 共用的前置門檻）

每次實驗開跑前必跑這 4 項，**全通過** 才能進入後面任一個實驗章節。任一項失敗就停下排查，不要硬跑。

### 1.1 確認 branch 位置（本機 + 4 host commit 一致）

```bash
BR=$(git rev-parse --abbrev-ref HEAD)
LOCAL_HEAD=$(git rev-parse --short HEAD)
echo "local: $BR @ $LOCAL_HEAD"

for h in zt-gateway zt-order zt-prod zt-user; do
  REMOTE=$(ssh "$h" 'cd ~/zt-event-gateway && git rev-parse --abbrev-ref HEAD && git rev-parse --short HEAD' | xargs)
  printf '%-13s %s\n' "$h" "$REMOTE"
done
```

**Pass 條件**：本機與 4 台 host 全部都在同一個 `branch @ short HEAD`。
**Fail 處理**：差幾個 commit → 對 host 跑 `git fetch + git checkout $BR + git pull --ff-only` 補齊；分支對不上 → 重看 §0 的對照表，確認本次實驗本來就該在哪個 branch。

### 1.2 4 台 host 都 up 且跑的是對應 branch 的服務

```bash
# (a) compose stack 都拉起來
ssh zt-gateway 'cd ~/zt-event-gateway && docker compose up -d'
ssh zt-order   'cd ~/zt-event-gateway/Services/Order_service      && docker compose up -d'
ssh zt-prod    'cd ~/zt-event-gateway/Services/Production_service && docker compose up -d'
ssh zt-user    'cd ~/zt-event-gateway/Services/User_service       && docker compose up -d'

# (b) 容器都 healthy
for h in zt-gateway zt-order zt-prod zt-user; do
  printf '=== %-13s ===\n' "$h"
  ssh "$h" 'docker ps --format "{{.Names}}\t{{.Status}}" | grep -v "^$"'
done

# (c) Gateway 自身健康（其他 service 沒有 /api/health，看 docker (healthy) 標記為準）
ssh zt-gateway 'curl -fsS --max-time 5 http://127.0.0.1:8080/api/health' && echo " gateway OK"

# (d) 跑的 image 是當前 branch build 的 — 確認容器掛載的 source 跟 host repo 同 commit
ssh zt-gateway 'docker exec zt-php-worker cat /var/www/html/.git/HEAD 2>/dev/null || docker exec zt-php-worker git -C /var/www/html rev-parse --short HEAD 2>/dev/null'
```

**Pass 條件**：所有容器 `Up ... (healthy)`、gateway `/api/health` 200、worker 容器掛載的 repo commit 跟 host 一致（避免 image 還是上次切 branch 前的舊版）。
**Fail 處理**：某容器 unhealthy → 看 `docker logs <name> 2>&1 | tail` 找原因；commit 對不上 → `docker compose up -d --force-recreate`。

### 1.3 `/api/orders` 能完成完整訂單

```bash
TRACE="smoke-$(date +%s)"
echo "trace: $TRACE"

ssh zt-gateway "curl -sS -o /dev/null -w 'HTTP %{http_code}\n' -X POST http://127.0.0.1:8080/api/orders \
  -H 'Content-Type: application/json' -H 'X-Correlation-Id: $TRACE' \
  -d '{\"userKey\":\"1\",\"productList\":[{\"p_key\":1,\"amount\":1}],\"total\":100}'"

sleep 6

# 條件 1：HTTP 必須 202
# 條件 2：worker log 該 trace 必須出現 Saga Step 4 完成
ssh zt-gateway "docker logs --tail 300 zt-php-worker 2>&1 | grep -E 'Saga Step 4: 訂單完成|RollbackSaga' | tail -10"
```

**Pass 條件**：HTTP `202` + worker log 看到 `✅ Saga Step 4: 訂單完成！` + 該 trace 沒有 `RollbackSaga`。
**Fail 處理**：

- 202 但 Saga 在 Step 1/2/3 卡住 → 對應下游 service 沒接通；`docker logs` 看 order/prod/user 容器
- 走到 RollbackSaga → 通常是下游 build 過舊 / DB seed 缺資料 / branch-specific 身份驗證沒過（後者請先過 §1.4 再回頭）
- HTTP 不是 202 → Gateway envelope schema check 把它擋掉了，看 1.4 是不是配置改錯

### 1.4 對應 zero-trust 架構各自的驗證方法

不同 branch 的「零信任」放在不同層，對應的驗證手法也不同。下表列出每個 branch **必須** 跑通的驗證項；**其他 branch 上的驗證項不適用，跑了反而會失敗**。

| Branch | Zero-trust 模型 | 驗證項（全部要過）|
|---|---|---|
| `main` | 無 zero-trust（純粹的 baseline）| §1.4.1 |
| `feat/Linkerd1` | Linkerd 1.x L7 mesh（mTLS off Phase 2，僅做 routing/observability）| §1.4.2 |
| `feat/spiffe-keycloak` | SPIRE workload SVID + Keycloak end-user JWT + LSVID nested token | §1.4.3 |

#### 1.4.1 `main`（baseline）

baseline 沒有零信任層；但要 **明確驗證它確實「沒有」**，避免跑出來的數值其實混到舊配置殘留。

```bash
# (a) Gateway 不該回傳任何 SPIFFE / WWW-Authenticate header
ssh zt-gateway "curl -sI http://127.0.0.1:8080/api/orders" | grep -iE 'spiffe|www-authenticate|x-svid' && echo "FAIL: 殘留" || echo "OK: 沒有身份層 header"

# (b) 直連下游 :8084 偽造 X-User-key 應該 200（baseline 預期可被攻破）
ssh zt-user "curl -sS -o /dev/null -w '%{http_code}\n' --max-time 5 -H 'X-User-key: 2' http://127.0.0.1:8084/api/v1/wallet"
# Pass：200（沒有任何身份檢查；這正是 baseline 應該有的行為）

# (c) RabbitMQ envelope 不該帶 spiffe_path
ssh zt-gateway 'docker logs --tail 100 zt-php-worker 2>&1 | grep -oE "spiffe_path\":\[[^]]*\]" | head -3'
# Pass：要嘛沒看到 spiffe_path、要嘛全是 []
```

#### 1.4.2 `feat/Linkerd1`

Linkerd 1.x 不做使用者認證，只負責 service-to-service routing + observability。**必須驗證 routing 真的在動**，否則「跑了 Linkerd」實際只是名字。

```bash
# (a) Linkerd admin/zipkin ready
ssh zt-gateway 'curl -fsS http://127.0.0.1:9990/admin/ping' && echo " admin OK"
ssh zt-gateway 'curl -fsS http://127.0.0.1:9411/health' | head -1
# Pass：admin pong、zipkin status:UP

# (b) Linkerd 真的有路由 — 對 outgoing :4140 用 Host header 走完一輪 service discovery
ssh zt-gateway "curl -sS --max-time 5 -H 'Host: OrderService' http://127.0.0.1:4140/api/health" 
# 預期至少能到 OrderService（200/404/任何來自下游的回應，不是 connection refused）

# (c) Saga 訊息真的經過 Linkerd — 跑一張 smoke 後從 zipkin 看 trace
ssh zt-gateway 'curl -sS http://127.0.0.1:9411/api/v2/services' | head
# Pass：services 裡看得到 OrderService / ProductionService / UserService

# (d) namerd / linkerd disco 設定正確 — 切記 host-port 順序（refer to commit c775059）
ssh zt-gateway 'docker exec zt-linkerd-gateway cat /etc/linkerd/config.yaml 2>/dev/null | grep -A2 disco' || true
```

**Pass 條件**：(a)(b)(c) 全綠；(d) 用來在數據異常時排查 disco 配置。

#### 1.4.3 `feat/spiffe-keycloak`（恢復後才有）

這個 branch 把零信任堆到底：**SPIRE 發 SVID（workload identity）+ Keycloak 發 JWT（user identity）+ LSVID nested token 在 Saga 各 hop 鏈起來**。三個層每一層都要單獨驗證在動。

```bash
# (a) SPIRE：workload 真的拿到 SVID
ssh zt-gateway 'docker exec zt-php-worker /opt/spire/bin/spire-agent api fetch x509 -socketPath /tmp/spire-agent/public/api.sock 2>&1 | head'
# Pass：fetched 1 x509 svid 並印出有效期 + spiffe_id

# (b) Keycloak：realm 與 OIDC discovery 通
ssh zt-gateway 'curl -fsS http://127.0.0.1:8180/realms/zt/.well-known/openid-configuration | head -1'
# Pass：JSON 含 issuer/authorization_endpoint/token_endpoint

# (c) Keycloak token 驗證真的在攔：用無 token / 過期 / 換 realm 的 token 各打一次
GW="http://127.0.0.1:8080/api/orders"
ORDER_BODY='{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}'

ssh zt-gateway "curl -sS -o /dev/null -w 'no-token=%{http_code}\n'         -X POST '$GW' -H 'Content-Type: application/json' -d '$ORDER_BODY'"
ssh zt-gateway "curl -sS -o /dev/null -w 'fake-token=%{http_code}\n'       -X POST '$GW' -H 'Authorization: Bearer FAKE.JWT.TOKEN' -H 'Content-Type: application/json' -d '$ORDER_BODY'"
ssh zt-gateway "curl -sS -o /dev/null -w 'alg-none=%{http_code}\n'         -X POST '$GW' -H 'Authorization: Bearer eyJhbGciOiJub25lIn0.e30.' -H 'Content-Type: application/json' -d '$ORDER_BODY'"
# Pass：三個都 401 / 403（不是 202）

# (d) LSVID nested token 在 envelope 裡確實追加：smoke 一張後撈 envelope 看 spiffe_path
TRACE="zt-check-$(date +%s)"
# （取得 valid Keycloak token 的方式由該 branch README 提供，例如 client_credentials grant）
TOK=$(ssh zt-gateway 'curl -sS -X POST http://127.0.0.1:8180/realms/zt/protocol/openid-connect/token \
    -d "grant_type=client_credentials" -d "client_id=zt-test" -d "client_secret=ztsecret" | jq -r .access_token')
ssh zt-gateway "curl -sS -o /dev/null -X POST '$GW' \
    -H 'Authorization: Bearer $TOK' -H 'Content-Type: application/json' \
    -H 'X-Correlation-Id: $TRACE' -d '$ORDER_BODY'"
sleep 6
ssh zt-gateway "docker logs --tail 300 zt-php-worker 2>&1 | grep '$TRACE' -A2 | grep -oE 'spiffe_path\":\[[^]]+\]'"
# Pass：spiffe_path 長度 = 走完 saga 的 hop 數（Gateway → Order → Production → User → Order = 4–5 個 entry）
```

**Pass 條件**：(a)(b)(c)(d) 全綠。任一缺失就停下排查，**不要進 §4 §5**，會量到誤導性數據。

> §3 安全性實驗的 probe driver（`scripts/security/probe-<stack>.sh`）會把 1.4 的核心驗證也包進 attack matrix（見 `scripts/security/README.md`）。但 1.4 是「實驗開跑前」的快速 sanity，probe 是「實驗本身」，不要相互取代。

---

## 2. 壓力 / 效能實驗（5k / 10k / 20k）

量訂單建立到 Saga Step 4 完成的端到端 latency 與失敗率。

### 2.1 必須先設定的 worker / consumer 配置

| 設定 | 位置 | 預設 | 實驗值 |
|---|---|---|---|
| Gateway Workerman `$worker->count` | `anser-gateway/env` 解註 `gateway.workerCount` <br>（落地到 `anser-gateway/system/Worker/GatewayWorker.php:157`）| `1` | **10** |
| Worker `numprocs`（supervisord）| `anser-gateway/supervisord.recalc.conf` / `supervisord.monitor.conf` | （未指定 → `1`）| **初始 1** |
| 20,000 規模時的 Gateway worker | 同上 `gateway.workerCount` | 10 | **臨時改 100** |
| 動態 numprocs 調整 | `recalc_worker.php` + `monitor_trigger.php`（compose 內路徑 `/app/worker/recalc_worker.php`、`/app/monitor/monitor_trigger.php`）| autostart | 偵測 RabbitMQ queue 深度後自動寫入 supervisord conf 並 `supervisorctl update` |

把 `anser-gateway/env` 中 `gateway.workerCount` 解註成你要的值之後，4 host 全部 sync + rebuild + restart：

```bash
# 改完 env 後
git add anser-gateway/env && git commit -m "experiment: bump gateway.workerCount to 10"
git push origin "$BR"

for h in zt-gateway zt-prod zt-order zt-user; do
  ssh "$h" "cd ~/zt-event-gateway && git pull --ff-only origin $BR"
done
ssh zt-gateway 'cd ~/zt-event-gateway && docker compose up -d --force-recreate gateway'
```

跑 20k 時，臨時把 `workerCount` 改 100、跑完改回 10，不要常駐 100（會搶其他實驗的 baseline）。

> **動態 numprocs 機制**：`monitor_trigger.php` 每 N 秒讀 RabbitMQ `/api/queues/%2F/order_queue` 抓 `messages_ready`；超過閾值就改寫 `[program:php_worker]` 的 `numprocs`，呼叫 `supervisorctl reread && supervisorctl update`，把 worker process 數加上去。queue drain 後再縮回 1。`recalc_worker.php` 是被觸發後負責計算「目前負載要拉到幾個 worker」的腳本。詳細策略由各 branch 自己的實作決定，以實機 `/app/worker/` 與 `/app/monitor/` 為準。

### 2.2 跑壓力實驗

```bash
# 先確認 §1 通過
BR=$(git rev-parse --abbrev-ref HEAD)

# 多 host 分散式 runner（驅動隔離；正式量測一律用這個，內部走 -lan）
PERF_SCALES="5000 10000 20000" PASSES=2 \
  bash scripts/experiments/run-perf-multihost.sh
# → 輸出 rsync 回本機 ./artifacts/<TS>_Experimental/（PASSES=2 會產出兩個 timestamped 目錄）
```

20k 那一輪開跑前再次確認 `workerCount=100`，跑完立刻改回 10。

> **跨分支命名差異**（待 §6.4 follow-up 統一）：在 `feat/spiffe-keycloak` 上目前還沒 `run-perf-multihost.sh`，分散式 runner 仍叫 `run-dualmode-distributed.sh`，env 用 `SCALES` + `ROUNDS` 而非 `PERF_SCALES`。在那個分支跑時把上面命令替換成：`OUT=artifacts/$(date +%Y%m%d-%H%M%S)-${BR##*/}; SCALES="5000 10000 20000" ROUNDS="warm cold" bash scripts/experiments/run-dualmode-distributed.sh "$OUT"`。

### 2.3 輸出

```
$OUT/raw/load_<round>_<scale>.csv          每筆 request 的 lat / status
$OUT/raw/worker_<round>_<scale>.log        worker 訊息（Saga Step 4 / Rollback）
$OUT/raw/mtls_<round>_<scale>.err          mTLS handshake 計時（在沒 mTLS 的 stack 上仍會產出，內容可忽略）
```

### 2.4 分析

```bash
python3 scripts/experiments/analyze-perf-experiment.py --in "$OUT" --scales 5000,10000,20000
# 產出：
#   $OUT/Gateway接收請求時間.{xlsx,png}
#   $OUT/訂單完成時間.{xlsx,png}
#   $OUT/未完成交易率.{xlsx,png}
#   $OUT/mTLS花費時間.{xlsx,png}
#   $OUT/summary.xlsx, $OUT/README.md
```

跨 branch 比較：把每個 branch 的 `summary.xlsx` 並排彙整。

---

## 3. 安全性實驗

詳細的 case 矩陣（A/B/C/D/E + 規劃中的 F/G/H/I）見 [`scripts/security/README.md`](scripts/security/README.md)。本節只列從 §0 走到 aggregator 的最短指令路徑。

```bash
# Single stack — 對齊本機 branch 跑對應的 driver
BR=$(git rev-parse --abbrev-ref HEAD)
case "$BR" in
  main)                  PROBE=probe-baseline.sh ;;
  feat/Linkerd1)         PROBE=probe-linkerd.sh ;;
  feat/spiffe-keycloak)  PROBE=probe-keycloak-spiffe.sh ;;  # §6.1 cherry-pick 後才有
  *)                     echo "no probe for $BR"; exit 1 ;;
esac

# 從本機 Mac 跑（內部 SSH 走 -lan；若 LAN 不通可覆寫成 WAN）
GATEWAY_ALIAS=zt-gateway ORDER_ALIAS=zt-order \
PROD_ALIAS=zt-prod USER_ALIAS=zt-user \
bash "scripts/security/$PROBE"
```

跨 stack 對比：每個 branch 走 §1 + §3，把多個 `artifacts/sec-<stack>-<ts>/` 餵給 aggregator：

```bash
python3 scripts/security/aggregate-security.py \
  --in artifacts/sec-baseline-<ts> \
  --in artifacts/sec-linkerd-<ts> \
  --in artifacts/sec-keycloak-spiffe-<ts> \
  --out artifacts/sec-compare-$(date +%Y%m%d)
```

輸出位置與檔案結構照 `scripts/security/README.md` §4.5。

---

## 4. 無 LSVID vs 有 LSVID 容量比較

> **僅 `feat/spiffe-keycloak`**。其他 branch 沒有 LSVID 編解碼路徑，量到的數值不具意義。

### 4.1 必要前置

1. 把 SPIFFE/SPIRE 與 LSVID 編解碼層復活（§6.1）
2. `anser-gateway/env` 的 `gateway.workerCount = 10`（同 §2.1）
3. 確認 SPIRE agent socket（在 gateway / order / prod / user 容器內）正常 issue SVID
4. Smoke 測一張帶 LSVID 的訂單，確認 `spiffe_path` 在 RabbitMQ envelope 上有完整鏈

### 4.2 量什麼

「容量」= 系統能在 **未完成交易率（rollback rate）≤ 5%** 與 **p99 訂單完成時間 ≤ X ms** 約束下，吃下的並發訂單規模。

對同一台拓撲，在 §1 通過後跑兩輪：

| 模式 | LSVID env | Saga envelope | 量點 |
|---|---|---|---|
| OFF | `LSVID_ENABLED=0` | `spiffe_id=""`、`spiffe_path=[]` | 容量上限 N₀ |
| ON  | `LSVID_ENABLED=1` | 每跳追加 LSVID 的 nested token | 容量上限 N₁ |

### 4.3 跑法

```bash
# 4.3.1 OFF 路徑
ssh zt-gateway 'cd ~/zt-event-gateway && LSVID_ENABLED=0 docker compose up -d --force-recreate'
# 走 §1 通用 preflight + smoke
OUT=artifacts/lsvid-off-$(date +%Y%m%d-%H%M%S)
SCALES="5000 10000 20000 30000 50000" ROUNDS="warm" \
  bash scripts/experiments/run-perf-multihost.sh "$OUT"

# 4.3.2 ON 路徑
ssh zt-gateway 'cd ~/zt-event-gateway && LSVID_ENABLED=1 docker compose up -d --force-recreate'
# 重新 §1 preflight
OUT=artifacts/lsvid-on-$(date +%Y%m%d-%H%M%S)
SCALES="5000 10000 20000 30000 50000" ROUNDS="warm" \
  bash scripts/experiments/run-perf-multihost.sh "$OUT"

# 4.3.3 雙路徑分析
python3 scripts/experiments/analyze-perf-experiment.py --in artifacts/lsvid-off-<ts> --scales 5000,10000,20000,30000,50000
python3 scripts/experiments/analyze-perf-experiment.py --in artifacts/lsvid-on-<ts>  --scales 5000,10000,20000,30000,50000

# 4.3.4 並排對比（沿用 perf 的對比腳本）
python3 scripts/experiments/compare-linkerd-vs-dualmode.py \
  --baseline artifacts/lsvid-off-<ts>/summary.xlsx \
  --variant  artifacts/lsvid-on-<ts>/summary.xlsx \
  --out      artifacts/lsvid-compare-$(date +%Y%m%d)
```

> 把 SCALES 拉到 50k 是為了找出 ON 模式真正的容量上限；OFF 模式可能在 30k 還沒爆，要持續拉高到 rollback rate 開始顯著上升才停。

### 4.4 預期輸出

`artifacts/lsvid-compare-<date>/`：

- `容量曲線.{xlsx,png}` — X 軸 scale，Y 軸 rollback rate（雙線：OFF / ON），交叉點 = 容量差距
- `p99_對比.{xlsx,png}` — 同 scale 下 ON 比 OFF 多花多少時間（LSVID 驗章 + nested token 解包成本）
- `summary.md` — N₀ vs N₁ 的數字、容量損失百分比、建議的 LSVID 開關策略

### 4.5 已知陷阱

- 切換 LSVID 模式時，**RabbitMQ queue 必須清空**，不然 OFF 模式會吃到上一輪 ON 模式留下的帶 LSVID 訊息（RequestConsumer 可能 reject 也可能跑掉，污染容量數字）。每次切換前：`ssh zt-gateway 'docker exec zt-rabbitmq rabbitmqctl purge_queue order_queue'`。
- LSVID 私鑰若是每次 compose up 都重簽，OFF→ON 切換要等 SPIRE agent 第一輪 SVID rotation 完成才開始量；通常等 60s。

---

## 5. 巢狀 Token 成長大小與成長比

> **僅 `feat/spiffe-keycloak`**。

### 5.1 量什麼

Saga 鏈：Gateway → Order → Production → User → Order。每一跳都把自己的 SVID（或 LSVID）追加到 `spiffe_path`，token / envelope 隨之長大。量：

- **每一跳的 envelope 大小**（bytes，可從 RabbitMQ 訊息內容直接量）
- **成長比**：`size_n / size_{n-1}`（理想是常數，反映每跳追加的 LSVID 大小相當）
- **複合成長率**：`size_final / size_initial`（saga 完整走完後，envelope 比初始膨脹幾倍）

### 5.2 量法

兩種途徑，擇一即可：

**A. 寫一支 instrumentation script（推薦）**

在 Saga 每個 publish 點之前 hook 一段 `strlen($body)` 寫到專屬 log。

```bash
# 預期會新增到 feat/spiffe-keycloak：
# scripts/experiments/measure-nested-token.sh
#   - 跑 1 張 saga
#   - 從 zt-php-worker log 抓每跳的 envelope size
#   - 算成長比
#
# 輸出：artifacts/nested-token-<ts>/
#   per_hop.csv      hop_idx, hop_name, size_bytes
#   growth.{xlsx,png} 成長曲線 + 對應的線性／指數擬合
```

**B. 用 RabbitMQ shovel + size 統計**

對 `events` exchange 加一個 `firehose`-style binding，把每則訊息 mirror 到 audit queue，讀出後 `wc -c`。比 A 麻煩但對 saga 流程零侵入。

### 5.3 抽樣策略

- 每個 scale (1k / 5k / 10k) 各抽 30 張完整走完 Step 4 的 saga
- 算每張的 per-hop sizes，取平均
- 跨 scale 確認成長比不會因為負載升高而漂移（健康狀況下 hop 大小應該與負載無關）

### 5.4 預期輸出

`artifacts/nested-token-<ts>/`：

- `per_hop.csv` — 每張 saga 每跳的大小
- `成長曲線.{xlsx,png}` — Y 軸 size，X 軸 hop index，多條線（每張 saga 一條）+ 平均線
- `成長比.{xlsx,png}` — Y 軸 size_n / size_{n-1}，X 軸 hop index，理論線 vs 實測線
- `summary.md` — 平均成長比、複合成長率、是否有非線性成長（發現非線性 = 該跳上有 bug，例如 LSVID 雙重夾帶）

---

## 6. 補充

### 6.1 `feat/spiffe-keycloak` 分支現況

`feat/spiffe-keycloak` 已經存在於 origin 與 4 台 host（截至本文件寫成時），不需要從歷史 commit cherry-pick 復活。它和 `main` 從某個分岔點開始已經分歧 49 個 commit，最近的大頭包括：

```
f22f7a3 feat(experiments): Keycloak token minting + drain 180s + bash 3.2 compat
0ab4665 feat(experiments): add fixed-load wrapper + analyze metadata sheet
82ce517 feat(keycloak): wire user-level ingress JWT validation end-to-end
30245a3 update SPIFFE & Keycloak
2011fa1 feat(auth): wire CompositeAuthFilter; bin/{gateway,worker}.php dual-bootstrap
```

要在這個 branch 上跑 §4 §5：
```bash
git checkout feat/spiffe-keycloak
git pull --ff-only
# 對 4 host 走 §1（branch sync + compose up + §1.4.3 SPIRE/Keycloak/LSVID 驗證）
# 跑 §4 / §5 章節的命令
```

若 §1.4.3 驗證項有缺（例如 SPIRE agent socket 沒掛、Keycloak realm 名稱不是 `zt`、LSVID 編解碼不在 envelope 上），請先看該分支的 README / commit log 找對應補位點，**修好再進實驗**，不要把驗證失敗硬跳過。

如果未來要把這個分支重新從歷史拼回去（例如不小心刪掉），按時間正序的關鍵 commit：

```
1081ebf feat(keycloak): vendor src/Keycloak/, watcher daemon, filters, realm config
a2f73b0 feat(keycloak): add KEYCLOAK_* env vars, compose profile, firebase/php-jwt
0f277e9 feat(auth): extend MessageBus/EventBus/Consumers/CanonicalOrderRequest to dual-mode
2011fa1 feat(auth): wire CompositeAuthFilter; bin/{gateway,worker}.php dual-bootstrap
6ed5800 feat(experiments): multi-host perf runner + security xlsx exporter
30245a3 update SPIFFE & Keycloak
82ce517 feat(keycloak): wire user-level ingress JWT validation end-to-end
0ab4665 feat(experiments): add fixed-load wrapper + analyze metadata sheet
f22f7a3 feat(experiments): Keycloak token minting + drain 180s + bash 3.2 compat
```

### 6.2 實驗紀錄表（建議每跑一輪手動填一列）

存在 `artifacts/EXPERIMENT_LOG.md`（不進 git，純本機歸檔）：

```markdown
| 日期       | branch              | commit  | 實驗類別 | scale         | workerCount | 配置備註            | 輸出位置                                | 結論 |
|------------|---------------------|---------|----------|---------------|-------------|---------------------|-----------------------------------------|------|
| 2026-05-08 | feat/Linkerd1       | 684ccc3 | §3 安全  | n/a           | 10          | C-cat 改 mgmt API   | artifacts/sec-linkerd-20260508-165842   | 6/14 攻擊未擋 |
| ...        |                     |         |          |               |             |                     |                                         |      |
```

### 6.3 實驗一致性原則

- **同一份手冊跨所有 branch 通用**。本檔在 `main` 與所有 feature branch 上應該維持同步（必要時 cherry-pick）；不要在某個 branch 上分岔。
- **每次跑實驗 §0 都要重走**。即使 5 分鐘前才剛跑過另一輪，也要重看 branch / commit / 配置是否還對。
- **每次切換 branch 都要 §1 重 smoke**。compose 重起後 Saga 第一張單常常會慢；smoke 不過絕對不要進 §2 §3 §4 §5。

### 6.4 跨分支腳本命名 follow-up（pending）

本手冊把分散式 perf runner 跟分析器都寫成 `run-perf-multihost.sh` / `analyze-perf-experiment.py`，這對應 `feat/Linkerd1` 上的實際檔名。`feat/spiffe-keycloak` 分支上對應的檔名仍是歷史的 `run-dualmode-distributed.sh` / `analyze-dualmode-experiment.py`，env scheme 也不同（`SCALES` + `ROUNDS` vs `PERF_SCALES`）。

統一動作（待後續另開 PR 在 `feat/spiffe-keycloak` 上做）：

```bash
git checkout feat/spiffe-keycloak
git mv scripts/experiments/run-dualmode-distributed.sh   scripts/experiments/run-perf-multihost.sh
git mv scripts/experiments/run-dualmode-experiment.sh    scripts/experiments/run-perf-experiment.sh   # 若無同名衝突
git mv scripts/experiments/analyze-dualmode-experiment.py scripts/experiments/analyze-perf-experiment.py
# 更新該分支內部交叉引用（grep -rn dualmode scripts/ docs/）
# 連同 compare-linkerd-vs-dualmode.py 一起評估是否更名為 compare-perf-cross-branch.py
```

完成後可把本節跟 §2.2 的「跨分支命名差異」、§4.3 的舊 dualmode 註記一起拿掉。
