# 論文實驗 Goal — SPIFFE/SPIRE + Keycloak (+LSVID) vs Linkerd 1.x

## 論文目標

論文要證明 SPIFFE/SPIRE + Keycloak(+LSVID) 的零信任架構在效能與韌性上可與 Linkerd 1.x 抗衡。目前兩個實驗有缺口：

- **實驗一**：SPIFFE+Keycloak 與 SPIFFE+Keycloak+LSVID 兩模式壓測下成功率偏低、延遲過高，落後 Linkerd 1.x。經程式碼追蹤，瓶頸全部落在身分／安全層，不在 Saga 業務邏輯。
- **實驗四**：尚無「純 SPIFFE/SPIRE 注入故障」vs「Linkerd 1.x Proxy 注入故障」的恢復時間對照 harness。

## 四個前提（已敲定）

1. **故障注入層級 = 下游服務故障**（兩套環境對同一下游容器做 pause/kill，apples-to-apples）。
2. **實驗一只做零安全損失優化** — 不移除/弱化任何 LSVID/JWT 驗證（保留三重驗證）。
3. **Linkerd 環境已存在於 `feat/Linkerd1` 分支**；跑該側時使用者會清空 Docker 重新部署 Linkerd1 容器。
4. **不可改 `Sagas/OrderSaga.php`**；訂單須能完整跑完 Step 1→4；每個 Run 用 git commit 紀錄、commit 在對應分支。

## 拓撲事實（已驗證）

- 本機 `k8s-pve-01` = `10.1.1.209` = gateway host 本身。
- `~/.ssh/config` 有 `zt-order` (10.1.1.210)、`zt-prod` (10.1.1.207)、`zt-user` (10.1.1.214)，三台皆通且都在 `feat/spiffe-keycloak`。
- 無 `zt-gateway` 別名、無 `-lan` 別名（CLAUDE.md 的正式量測是從 Mac 驅動）。
- Worker (`bin/worker.php`) 是單進程同步 AMQP consumer，非協程；Gateway 是 OpenSwoole 協程。→「worker 併發」只能用 container replica 擴展，不可在進程內並行（會破壞 saga 順序與 `jtiCache`）。

---

## 量測 stack 與執行順序（總綱）

**本輪實測 stack（共 4 個，皆要實跑壓測 + 故障注入）：**

| Stack | 壓測（5k/10k/20k） | 故障注入恢復 | 備註 |
|---|---|---|---|
| **Vault** | ✅ 實跑 | ✅ 實跑 | **最先跑一輪** |
| **SPIFFE+Keycloak（B，無 LSVID）** | ✅ 實跑 | ✅ 實跑（SPIRE 側 split） | Vault 跑完後才回調微調 |
| **SPIFFE+Keycloak+LSVID（A）** | ✅ 實跑 | ✅ 實跑（SPIRE 側 split） | Vault 跑完後才回調微調 |
| **Linkerd 1.x** | ❌ 不重跑（沿用 `artifacts/linkerd-reference/`） | ✅ 實跑（`feat/Linkerd1`，使用者重部署） | — |

**執行順序（硬性）：**

1. **先跑一輪 Vault**（壓測 5k/10k/20k + 故障注入恢復），把結果存檔（見下「Vault 必存數據」）。
2. **Vault 一輪跑完後**，才開始**回調 SPIFFE 兩個 stack（A/B）的 Run 微調**（實驗一 Run 1–8）。
3. SPIFFE 側微調完成、過 gate 後，再做三方/四方圖表合併。
4. Linkerd 1.x：壓測沿用既有參考；故障注入由使用者在 `feat/Linkerd1` 實跑。

**Vault 必存數據（每 scale）：**

- **Gateway 處理時間**（gateway ingress / `gw_proc_ms`，p50/p99，ms）。
- **訂單完成時間**（saga span / order completion，p50/p99，ms）。
- **故障注入恢復時間**（`recovery_sec`，依實驗四 CSV schema）。

> ⚠️ Vault 的部署與分支尚未確立（類似 `feat/Linkerd1` 需獨立環境）。開跑前須先確認：Vault 用哪個分支/compose、是否有等價的 saga 標記（`[perf-saga-step1]`/`[perf-saga-complete]` 等）以便沿用同一量測 harness。

---

## 實驗一 — 零安全損失效能優化（逐 Run commit 於 `feat/spiffe-keycloak`）

每個 Run = 一個獨立 commit + 一次獨立量測（`run-dualmode-distributed.sh` → `analyze-dualmode-experiment.py`）。量測時其他新增 env toggle 一律保持預設，確保 delta 可歸因於單一改動。輸出目錄名嵌入 `git rev-parse --short HEAD`。

> 每個 Run 跑完，先用第 3 步 smoke test 確認單筆訂單能走完 Step 1→4，再進場壓測。

### Run 1 —（最高影響）消除 Keycloak token 同步抓取

- **檔案/位置**：`src/Keycloak/TokenProvider.php:27-34`。`getAccessToken()` 快取 miss/stale 時會同步打 Keycloak（`KeycloakClient::fetchClientCredentialsToken` `src/Keycloak/KeycloakClient.php:36-58`，10s timeout）。呼叫點：`KeycloakBearerFilter::beforeCallService` (`anser-gateway/Filters/KeycloakBearerFilter.php:36`)、`MessageBus::publish` (`src/MessageQueue/MessageBus.php:192`)、`Order.php:162`，每筆 saga 多次。
- **改法**：加 process 內 memo（`?array $memo`），`exp - skew > time()` 直接回傳，避免每跳重讀 SHM；新增 env `KEYCLOAK_SYNC_FETCH_FALLBACK`（預設 `1` = 現狀；設 `0` 時 miss 直接擲例外、fail-fast，完全倚賴已暖的 watcher）。watcher 暖度沿用既有 `KEYCLOAK_TOKEN_REFRESH_SKEW` (`bin/keycloak-watcher.php:66`) 可調大。
- **零安全損失**：token 同源（SHM）、memo TTL 受 exp-skew 約束不會送過期 token；下游 JwtValidator 仍驗 iss/sig/aud/exp。只移除「抓取路徑」，未移除任何「檢查」。
- **量測**：gateway 503（`Order.php:177`）歸零、`gw_proc_ms` p99 下降。
- **commit**：`perf(keycloak): per-process token memo + optional sync-fetch fail-fast`

### Run 2 — JwtValidator JWKS keymap 記憶化

- **檔案**：`src/Keycloak/JwtValidator.php:33-40`，每次 `validate()` 都重跑 `JWK::parseKeySet()`（`RequestConsumer.php:184`、`EventConsumer.php:257`）。以 JWKS 原文 hash 為 key 快取解析結果，內容變更（輪換）才重解析。
- **零安全損失**：簽章仍逐 token 驗證，只略過「未變更金鑰的重複解析」。
- **commit**：`perf(keycloak): memoize parsed JWKS keymap by content hash`

### Run 3 — 下游 HTTP keep-alive（worker → order/prod/user）

- **檔案**：worker bootstrap（`bin/worker.php` 全域 filter/ServiceList 接線處 ~:166-230），透過 `ServiceList::setGlobalHandlerStack()`（gateway 已用 `anser-gateway/system/Worker/GatewayWorker.php:89`）設定預設 `Connection: keep-alive` + `CURLOPT_TCP_KEEPALIVE=1`、`CURLOPT_FORBID_REUSE=0`。共享 client：`ServiceList::getHttpClient()`。
- **env**：`DOWNSTREAM_HTTP_KEEPALIVE`（預設 `1`）。**必須 gate 在 `SPIFFE_MTLS_ENABLED=0`**（這兩模式 mTLS 關閉、下游走純 HTTP，逐請求帶各自 Bearer + `X-LSVID` header；socket 重用不影響身分驗證）。mTLS 模式禁止套用（避免跨不同 client cert 重用 socket）。
- **commit**：`perf(downstream): enable HTTP keep-alive on worker Guzzle client (mTLS-off modes)`

### Run 4 — KeycloakClient keep-alive（watcher 刷新路徑）

- **檔案**：`src/Keycloak/KeycloakClient.php:26-30` 加 `Connection: keep-alive` + `CURLOPT_TCP_KEEPALIVE`。env `KEYCLOAK_HTTP_KEEPALIVE`（預設 `1`）。
- **commit**：`perf(keycloak): keep-alive on KeycloakClient for watcher refresh`

### Run 5 — 可調 AMQP prefetch（吞吐 vs p99 取捨）

- **檔案**：`bin/worker.php:392` 把 `basic_qos(null, 1, null)` 改成讀 env `AMQP_PREFETCH`（預設仍 `1`，保留 `:388-391` 的 p99 理由）。worker 併發改用 replica 擴展（`docker compose up --scale php-worker=N`），不在進程內並行。
- **零安全損失**：純流控；每筆訊息仍走完整三重驗證。
- **量測**：`AMQP_PREFETCH=1` vs `8` @20k，比 saga-complete 吞吐與 p99(`perf-saga-complete.ts − perf-saga-step1.ts`)，把取捨寫進 run notes。
- **commit**：`perf(amqp): make basic_qos prefetch tunable via AMQP_PREFETCH (default 1)`

### Run 6 — 可調 SHM seqlock spin

- **檔案**：`packages/php-spiffe/src/Spiffe/SharedMemory/SpiffeTableReader.php:21-22,271-287`，`MAX_SPIN`/`SPIN_SLEEP_US` 改讀 env（`SPIFFE_SHM_MAX_SPIN` 預設 `200`、`SPIFFE_SHM_SPIN_SLEEP_US` 預設 `500`），seqlock 正確性（`v1===v2` 或 `null`）不變。
- **commit**：`perf(spiffe): make SHM seqlock spin budget tunable via env`

### Run 7 — EventConsumer 反射快取

- **檔案**：`src/Worker/EventConsumer.php:333-377`，加 `static array $reflCache`（key=eventClass）快取 `ReflectionClass`+建構子參數，行為不變。
- **commit**：`perf(worker): cache ReflectionClass + ctor metadata per event type`

### Run 8 —（驗證型）確認 LSVID signer/validator 單例重用

- **檔案**：`bin/worker.php:92-106,166-176`（build-once + registry）、`packages/php-lsvid/src/LSVID/LSVIDSigner.php:162-192`（key-hash prep cache）、`anser-gateway/Filters/SpiffeLsvidFilter.php:46-47`。已確認無逐請求重建；本 Run 只加 `LSVID_PREP_DEBUG`（預設 `0`）觀測 prep-cache 命中率，若日後發現重建即在此修。
- **commit**：`perf(lsvid): assert signer/validator singleton reuse + prep-cache hit debug`

### 排序理由

Run 1 唯一會造成請求期網路 stall 與 503，列第一；Run 2/3 是穩態 CPU/RTT；Run 5 是最大吞吐槓桿但犧牲 p99，放在延遲優化落地後再測；Run 6–8 為低風險收尾。

### 圖表交付 gate（實驗一）

> **只有通過下列 gate 才生成圖表。** gate 未過 → 停下排查，不出圖（避免把未達標數據畫成正式圖）。

**生成條件（5000 / 10000 / 20000 三個 scale 都要滿足）：**

1. **完成率 100%**（`incomplete_rate_pct=0`）於 5000、10000、20000 三個 scale，**且**
2. **延遲達標**：`gateway_latency_ms` / `saga_span_ms`（p50,p99）**低於 Linkerd 1.x**（`artifacts/linkerd-reference/`），**或**低於先前數據（baseline / 上一輪量測）的延遲。

> 兩模式的「延遲達標」依預期排序分別判定（見下）：
> - **SPIFFE+Keycloak（B 組，無 LSVID）**：預期最快 → gate = **延遲 < Linkerd 1.x**。
> - **SPIFFE+Keycloak+LSVID（A 組）**：預期比 Linkerd 慢（多一層 LSVID 鏈）→ gate = **延遲 < 先前數據**（較自身 baseline 進步即可，不要求贏 Linkerd）。

**預期延遲排序（論文假設）：**

```
SPIFFE+Keycloak+LSVID  >  Linkerd 1.x  >  SPIFFE+Keycloak
（最慢，多一層 LSVID 巢狀簽章鏈）        （最快，純 Bearer + mTLS-off）
```

**圖表規格：**

- **主圖型 = Line Chart**（折線圖），X 軸 = scale（5000/10000/20000），Y 軸 = 延遲（ms）。
- 每張圖**四條序列**，固定對照四者：`SPIFFE+Keycloak`、`SPIFFE+Keycloak+LSVID`、`Linkerd 1.x`、`Vault`。
- 至少產出：**Gateway 接收/處理時間、訂單完成時間**（p50 與 p99 各一組線）。
- 單位統一為 **ms**（Linkerd `gateway_ingress_linkerd.csv` 為秒，合併前 ×1000；`order_completion_linkerd.csv` 僅 p50/p99、無 mean，排序 gate 以 **p50** 為準）。Vault 量測直接以 ms 輸出 Gateway 處理時間與訂單完成時間。
- 合併產出：現有 `compare-three-stacks.py`（`--lsvid-on <A> --lsvid-off <B> --linkerd artifacts/linkerd-reference/`）**只支援三方**；加入 Vault 需擴成四方（新增 `--vault <Vault的OUT>`）或另出一張含 Vault 的對照圖。Linkerd 沿用既有參考、不重跑。
- 圖中標註各 stack 相對位置；SPIFFE/Linkerd 預期排序見下（Vault 位置以實測為準，不預設）。

---

## 實驗四 — 下游服務故障的恢復時間對照（SPIRE vs Linkerd 1.x）

故障注入在下游服務容器（兩套環境注同一容器，唯一差別是傳輸層 SPIRE mTLS/LSVID vs Linkerd 1.x sidecar @:4140）。完全不改 OrderSaga，僅用既有 log 標記與健康/queue 輪詢量測。

### 既有可複用（read-only）

- **注入/偵測樣板**：`scripts/e2e-failure-modes.sh`（`pause_container`/`unpause_container` `:71-92`、`wait_for_worker_log` `:113-123`、`post_order` `:100-107`、cleanup trap `:136-144`，三階段場景 Phase1-3）。
- **健康/queue**：`run-dualmode-distributed.sh` 的 `wait_health :81`、`purge_queues :71`、`rabbitmqctl list_queues` 深度輪詢 `:154`。
- **OrderSaga 既有標記**（凍結、勿改，且 `feat/Linkerd1` 必須有同樣標記）：`[perf-saga-step1](:72)`、`[perf-saga-complete](:220)`、`RollbackSaga Step 2(:242)`、`❌ RollbackSaga Step 1(:266)`、`商品資訊查詢失敗(:58)`。皆受 `PERF_METRIC_ENABLED=1` 控制。

### 新增腳本

1. **`scripts/experiments/fault-recovery-common.sh`**（commit 至兩分支）：移植上述 helper + `STACK(spire|linkerd)` 切換 + 容器名以 env 參數化（單機 `php-worker`/`production-service`… 與分散式 `zt-php-worker`/`zt-*-lan` 都支援）+ `record_csv` + `measure_recovery()`。
2. **`scripts/experiments/fault-recovery-spire.sh`**（commit 至 `feat/spiffe-keycloak`）：`STACK=spire`、`COMPOSE_FILE=docker-compose.yml`、寫 `recovery_spire.csv`。
3. **`scripts/experiments/fault-recovery-linkerd.sh`**（commit 至 `feat/Linkerd1`）：`STACK=linkerd`、`COMPOSE_FILE` 疊加 Linkerd compose、寫 `recovery_linkerd.csv`。
4. **`scripts/experiments/analyze-fault-recovery.py`**（commit 至 `feat/spiffe-keycloak`）：沿用 `analyze-dualmode-experiment.py` 的 pandas 樣式，讀兩份 CSV，依 `(fault_type, fault_duration)` 比 `mean/median/p95 recovery_sec`、`saga_completed` 率、`rollback_count`，產 SPIRE-vs-Linkerd delta 表。

### 恢復時間量測（兩套環境定義一致）

- **`t_fault_clear`** = `docker unpause`/`start` 回傳當下時戳。
- 清除後立刻送一筆唯一 `X-Correlation-Id` 的 probe 訂單，**`t_recovered`** = 該 since 之後第一筆 `[perf-saga-complete]`。
- **`recovery_sec`** = `t_recovered − t_fault_clear`。
- 補償場景額外記 `rollback_count`（故障窗內 rollback 標記數），且須有後續成功 `[perf-saga-complete]` 才把 `saga_completed=1`。
- 僅用凍結 saga 標記 + docker/rabbitmqctl/health，零 OrderSaga 改動，兩分支標記一致。

### 故障場景矩陣（每個場景結束前都要證明一筆訂單成功完成）

1. **Step 1 暫時性故障**：`pause production-service` → 訂單 abort（商品資訊查詢失敗）→ 等 `fault_duration`（5s/30s）→ unpause → probe → 量恢復。
2. **強制補償後恢復**：`pause user-service` → Step 3 付款失敗 → `RollbackSaga Step 2` → 記 `rollback_count` → unpause → probe → 量恢復。
3. **付款後 confirm 失敗（完整 rollback）**：送單 → 等 Saga Step 3 → `pause order-service` → Step 4 失敗（`paymentCompleted=true` 補償）→ unpause → probe。
4. **kill+start 變體**（重啟恢復，凸顯 Linkerd sidecar 重解析 vs SPIRE SVID 重抓差異）。
5. **`fault_duration` sweep**（短 ~5s / 長 ~30–60s）暴露兩套重試/熔斷差異。

### CSV schema (`recovery_<stack>.csv`)

```
stack,scenario,fault_type,fault_target,fault_duration_sec,t_fault_clear,recovery_sec,saga_completed,rollback_count,trace_id
```

### 執行流程

1. `feat/spiffe-keycloak`：起 SPIRE stack（`PERF_METRIC_ENABLED=1`）→ `fault-recovery-spire.sh <out>`。
2. 使用者清 Docker、checkout `feat/Linkerd1`、重部署 Linkerd → `fault-recovery-linkerd.sh <out>`。
3. 回 `feat/spiffe-keycloak`：`analyze-fault-recovery.py --spire ... --linkerd ... --out artifacts/fault-recovery-<date>`。

### commits

- `feat(exp4): shared fault-recovery harness lib`（兩分支）
- `feat(exp4): SPIRE-side fault recovery runner`（`feat/spiffe-keycloak`）
- `feat(exp4): Linkerd 1.x fault recovery runner`（`feat/Linkerd1`）
- `feat(exp4): fault-recovery comparison analyzer`（`feat/spiffe-keycloak`）

---

## 風險與啟動前查核

1. **Worker 單進程同步** → 不得進程內並行；併發用 replica。確認 queue 非 exclusive（競爭式 consumer 才安全）。
2. **keep-alive × mTLS**：Run 3 必須 gate `SPIFFE_MTLS_ENABLED=0`；確認 `bin/worker.php:178-181` 在這兩模式回傳 null（mTLS 關）。
3. **Run 1 memo TTL**：須在 exp-skew 失效，絕不送輪換/過期 token；保留 `KEYCLOAK_SYNC_FETCH_FALLBACK=1` 預設讓冷啟動自癒。
4. **量測隔離**：每 Run 一 commit + 一次量測 + 其他 toggle 全預設；輸出目錄嵌 HEAD。
5. **三重驗證仍在線**：每 Run 後確認 `RequestConsumer.php:184`(JWT)、LSVID 鏈驗證、`SpiffeLsvidFilter.php:59-80`(extend 前 re-validate) 皆執行（本方案皆不碰這些檢查點）。
6. **OrderSaga 凍結**：兩實驗所需標記皆已存在，無需改。先 `git show feat/Linkerd1:Sagas/OrderSaga.php | grep perf-saga-complete` 確認該分支標記一致。
7. **容器名漂移**：Exp-4 腳本容器名以 env 參數化；每次執行前 `docker ps` 核對實際名稱。
8. **Linkerd 恢復語意**：Linkerd 1.x 可能用自身重試遮蔽暫時性故障 → 先確認故障確實傳達到 saga（看到 abort/rollback 標記）再採信 `recovery_sec`，由 `saga_completed`/`rollback_count` 欄位把關。
9. **從 gateway host 驅動壓測**：本機無 `zt-gateway`/`-lan` 別名。若要在這台直接跑 `run-dualmode-distributed.sh`，需設 `GATEWAY_HOST` 指本機（local docker exec）、`DRIVER_HOST=zt-order`，或改從 Mac 用 `-lan` 別名走正式量測。正式可發表數據建議仍依 CLAUDE.md 從 Mac 驅動。

---

## Vault stack — 實測（壓測 + 故障注入）

> **本輪定位：實際量測 stack，且最先跑一輪。** Vault 一輪壓測 + 故障注入跑完並存檔後，才開始回調 SPIFFE 兩個 stack（A/B）的 Run 微調。

### 範圍

- **壓測**：5000 / 10000 / 20000，warm（cold 視時間補），與 A/B 同條件（同下游、同 saga）。
  - 必存：**Gateway 處理時間**（p50/p99，ms）、**訂單完成時間**（p50/p99，ms）。
  - 輸出沿用 `analyze-dualmode-experiment.py` 樣式或等價 CSV，便於與 A/B/Linkerd 合併成四方 Line Chart。
- **故障注入**：套用實驗四同一場景矩陣（下游服務 pause/kill），對同一下游容器注故障。
  - 必存：**故障注入恢復時間**（`recovery_sec`），CSV schema 同 `recovery_<stack>.csv`（`stack=vault`），寫 `recovery_vault.csv`。

### 執行流程（Vault 一輪）

1. 部署 Vault stack（分支/compose 待確認；須有等價 saga 標記 `[perf-saga-step1]`/`[perf-saga-complete]`）。
2. smoke 一筆訂單看到 `✅ Saga Step 4`。
3. 壓測 5k/10k/20k → 存 Gateway 處理時間 + 訂單完成時間。
4. 故障注入場景 → 存 `recovery_vault.csv`（每場景結束須有後續成功 `[perf-saga-complete]`）。
5. **存檔完成後**，才切回 `feat/spiffe-keycloak` 開始 SPIFFE 的 Run 1–8 微調。

### 對照討論軸線（量測之外的論文 Discussion）

- **身分鑄造**：SPIFFE/SPIRE（workload attestation + SVID 輪換、去中心化）vs Vault（集中式 token/lease、PKI secrets engine 簽發短期憑證）vs Linkerd（sidecar 自動 mTLS）。
- **信任根**：SPIRE trust domain `zt.local` 自管 CA vs Vault PKI / Vault as CA。
- **憑證生命週期**：SPIRE 自動輪換 + SHM 共享 vs Vault lease/renew。
- **與 Keycloak 關係**：Keycloak 提供 OIDC/JWT；Vault 可作後端祕密儲存或獨立 workload 憑證簽發者。

---

## 驗證方式（end-to-end）

- **每 Run（實驗一）**：先 smoke test 一筆訂單看到 `✅ Saga Step 4`，再 `SCALES="5000 10000 20000" ROUNDS="warm cold" bash scripts/experiments/run-dualmode-distributed.sh $OUT` → `analyze-dualmode-experiment.py --in $OUT`，比對該 Run 對未完成交易率、saga-complete 吞吐、`gw_proc_ms`/saga span p50/p99 的 delta。
- **Vault（最先一輪）**：壓測 5k/10k/20k + 故障注入，存 Gateway 處理時間、訂單完成時間、`recovery_vault.csv`，再進 SPIFFE 微調。
- **圖表生成（實驗一）**：A、B 兩組於 5000/10000/20000 量完後，依「圖表交付 gate」判定——完成率 100% 且延遲達標（B 組 < Linkerd；A 組 < 先前數據）才產出 Line Chart 對照，序列含 `SPIFFE+Keycloak`、`SPIFFE+Keycloak+LSVID`、`Linkerd 1.x`、`Vault`（Linkerd 沿用 `artifacts/linkerd-reference/`；Vault 用本輪量測）。gate 未過不出圖。
- **實驗四**：兩 stack 各跑 `fault-recovery-*.sh`，每場景結束都看到後續成功 `[perf-saga-complete]`（證明業務完整），最後 `analyze-fault-recovery.py` 出對照表/圖。
- **回歸**：`composer test:unit` 與 `bash scripts/e2e-failure-modes.sh` 應維持綠燈。
