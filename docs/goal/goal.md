# 實驗一（壓測效能）+ 實驗四（故障恢復對照）完善方案

## Context

論文要證明 SPIFFE/SPIRE + Keycloak(+LSVID) 的零信任架構在效能與韌性上可與 Linkerd 1.x 抗衡。目前兩個實驗有缺口：

- **實驗一**：`SPIFFE+Keycloak` 與 `SPIFFE+Keycloak+LSVID` 兩模式壓測下成功率偏低、延遲過高，落後 Linkerd 1.x。經程式碼追蹤，瓶頸全部落在**身分／安全層**，不在 Saga 業務邏輯。
- **實驗四**：尚無「純 SPIFFE/SPIRE 注入故障」vs「Linkerd 1.x Proxy 注入故障」的恢復時間對照 harness。

### 使用者敲定的前提
1. 故障注入層級 = **下游服務故障**（兩套環境對同一下游容器做 pause/kill，apples-to-apples）。
2. 實驗一只做**零安全損失**優化 — 不移除/弱化任何 LSVID/JWT 驗證（保留三重驗證）。
3. Linkerd 環境已存在於 `feat/Linkerd1` 分支；跑該側時使用者會清空 Docker 重新部署 Linkerd1 容器。
4. **不可改 `Sagas/OrderSaga.php`**；訂單須能完整跑完 Step 1→4；**每個 Run 用 git commit 紀錄、commit 在對應分支**。

### 拓撲事實（已驗證）
- 本機 `k8s-pve-01` = **10.1.1.209 = gateway host 本身**。
- `~/.ssh/config` 有 `zt-order`(10.1.1.210)、`zt-prod`(10.1.1.207)、`zt-user`(10.1.1.214)，三台皆通且都在 `feat/spiffe-keycloak`。
- **無 `zt-gateway` 別名、無 `-lan` 別名**（CLAUDE.md 的正式量測是從 Mac 驅動）。
- Worker（`bin/worker.php`）是**單進程同步 AMQP consumer**，非協程 → 「worker 併發」只能用 container replica 擴展，不可在進程內並行（會破壞 saga 順序與 jtiCache）；Gateway 是 OpenSwoole 協程。

---

## 實驗一 — 零安全損失效能優化（逐 Run commit 於 `feat/spiffe-keycloak`）

每個 Run = 一個獨立 commit + 一次獨立量測（`run-dualmode-distributed.sh` → `analyze-dualmode-experiment.py`）。量測時其他新增 env toggle 一律保持預設，確保 delta 可歸因於單一改動。輸出目錄名嵌入 `git rev-parse --short HEAD`。每個 Run 跑完先用 smoke test 確認單筆訂單走完 Step 1→4，再進場壓測。

### Run 1 —（最高影響）消除 Keycloak token 同步抓取
- **檔案/位置**：`src/Keycloak/TokenProvider.php:27-34`。快取 miss/stale 時同步打 Keycloak（`KeycloakClient::fetchClientCredentialsToken` `src/Keycloak/KeycloakClient.php:36-58`，10s timeout）。呼叫點：`KeycloakBearerFilter.php:36`、`MessageBus.php:192`、`Order.php:162`，每筆 saga 多次。
- **改法**：加 process 內 memo（`exp-skew>time()` 直接回傳，避免每跳重讀 SHM）；新增 env `KEYCLOAK_SYNC_FETCH_FALLBACK`（預設 `1`=現狀；`0`=miss 直接擲例外、fail-fast、倚賴已暖 watcher）。watcher 暖度沿用 `KEYCLOAK_TOKEN_REFRESH_SKEW`(`bin/keycloak-watcher.php:66`)。
- **零安全損失**：token 同源（SHM）、memo TTL 受 `exp-skew` 約束不送過期；下游 `JwtValidator` 仍驗 iss/sig/aud/exp。只移除「抓取路徑」，未移除任何「檢查」。
- **量測**：gateway 503(`Order.php:177`) 歸零、`gw_proc_ms` p99 下降。
- **commit**：`perf(keycloak): per-process token memo + optional sync-fetch fail-fast`

### Run 2 — JwtValidator JWKS keymap 記憶化
- **檔案**：`src/Keycloak/JwtValidator.php:33-40`，每次 `validate()` 重跑 `JWK::parseKeySet()`（`RequestConsumer.php:184`、`EventConsumer.php:257`）。以 JWKS 原文 hash 為 key 快取解析結果，輪換才重解析。
- **零安全損失**：簽章仍逐 token 驗，只略過未變更金鑰的重複解析。
- **commit**：`perf(keycloak): memoize parsed JWKS keymap by content hash`

### Run 3 — 下游 HTTP keep-alive（worker → order/prod/user）
- **檔案**：worker bootstrap `bin/worker.php:~166-230`，經 `ServiceList::setGlobalHandlerStack()`（gateway 已用 `GatewayWorker.php:89`）設 `Connection: keep-alive`+`CURLOPT_TCP_KEEPALIVE=1`+`CURLOPT_FORBID_REUSE=0`。共享 client `ServiceList::getHttpClient()`。
- **env**：`DOWNSTREAM_HTTP_KEEPALIVE`（預設 `1`）。**必須 gate `SPIFFE_MTLS_ENABLED=0`**（兩模式 mTLS 關、下游純 HTTP，逐請求帶 Bearer+X-LSVID header；socket 重用不影響身分驗證；mTLS 模式禁用，避免跨不同 client cert 重用 socket）。
- **commit**：`perf(downstream): enable HTTP keep-alive on worker Guzzle client (mTLS-off modes)`

### Run 4 — KeycloakClient keep-alive（watcher 刷新路徑）
- **檔案**：`src/Keycloak/KeycloakClient.php:26-30` 加 `Connection: keep-alive`+`CURLOPT_TCP_KEEPALIVE`。env `KEYCLOAK_HTTP_KEEPALIVE`（預設 `1`）。
- **commit**：`perf(keycloak): keep-alive on KeycloakClient for watcher refresh`

### Run 5 — 可調 AMQP prefetch（吞吐 vs p99 取捨）
- **檔案**：`bin/worker.php:392` `basic_qos(null,1,null)` 改讀 env `AMQP_PREFETCH`（**預設仍 1**，保留 `:388-391` p99 理由）。worker 併發用 replica 擴展。
- **零安全損失**：純流控；每訊息仍走完整三重驗證。
- **量測**：`AMQP_PREFETCH=1` vs `8` @20k，比 saga-complete 吞吐與 p99(`perf-saga-complete−perf-saga-step1`)，取捨寫進 run notes。
- **commit**：`perf(amqp): make basic_qos prefetch tunable via AMQP_PREFETCH (default 1)`

### Run 6 — 可調 SHM seqlock spin
- **檔案**：`packages/php-spiffe/src/Spiffe/SharedMemory/SpiffeTableReader.php:21-22,271-287`，`MAX_SPIN/SPIN_SLEEP_US` 改讀 env（`SPIFFE_SHM_MAX_SPIN`=200、`SPIFFE_SHM_SPIN_SLEEP_US`=500），seqlock 正確性（`v1===v2` 或 `null`）不變。
- **commit**：`perf(spiffe): make SHM seqlock spin budget tunable via env`

### Run 7 — EventConsumer 反射快取
- **檔案**：`src/Worker/EventConsumer.php:333-377` 加 `static $reflCache`（key=eventClass）快取 ReflectionClass+建構子參數，行為不變。
- **commit**：`perf(worker): cache ReflectionClass + ctor metadata per event type`

### Run 8 —（驗證型）確認 LSVID signer/validator 單例重用
- **檔案**：`bin/worker.php:92-106,166-176`、`packages/php-lsvid/src/LSVID/LSVIDSigner.php:162-192`、`SpiffeLsvidFilter.php:46-47`。**已確認無逐請求重建**；加 `LSVID_PREP_DEBUG`(預設0) 觀測 prep-cache 命中率，若日後發現重建即在此修。
- **commit**：`perf(lsvid): assert signer/validator singleton reuse + prep-cache hit debug`

**排序理由**：Run 1 唯一會造成請求期網路 stall 與 503，列第一；Run 2/3 是穩態 CPU/RTT；Run 5 是最大吞吐槓桿但犧牲 p99，放在延遲優化落地後再測；Run 6–8 為低風險收尾。

---

## 實驗四 — 下游服務故障的恢復時間對照（SPIRE vs Linkerd 1.x）

故障注入在**下游服務容器**（兩套環境注同一容器，唯一差別是傳輸層 SPIRE mTLS/LSVID vs Linkerd 1.x sidecar @`:4140`）。**完全不改 OrderSaga**，僅用既有 log 標記與健康/queue 輪詢量測。

### 既有可複用（read-only）
- 注入/偵測樣板 `scripts/e2e-failure-modes.sh`（`pause/unpause_container` `:71-92`、`wait_for_worker_log` `:113-123`、`post_order` `:100-107`、`cleanup` trap `:136-144`，Phase1-3 場景）。
- 健康/queue：`run-dualmode-distributed.sh` 的 `wait_health` `:81`、`purge_queues` `:71`、`rabbitmqctl list_queues` 深度輪詢 `:154`。
- OrderSaga 既有標記（凍結、勿改，且 `feat/Linkerd1` 必須有同樣標記）：`[perf-saga-step1]`(:72)、`[perf-saga-complete]`(:220)、`RollbackSaga Step 2`(:242)、`❌ RollbackSaga Step 1`(:266)、`商品資訊查詢失敗`(:58)。皆受 `PERF_METRIC_ENABLED=1` 控制。

### 新增腳本
1. `scripts/experiments/fault-recovery-common.sh`（**commit 至兩分支**）：移植上述 helper + `STACK`(spire|linkerd) 切換 + 容器名 env 參數化（單機 `php-worker`/`production-service`… 與分散式 `zt-php-worker`/`zt-*` 皆支援）+ `record_csv` + `measure_recovery()`。commit：`feat(exp4): shared fault-recovery harness lib`
2. `scripts/experiments/fault-recovery-spire.sh`（`feat/spiffe-keycloak`）：`STACK=spire`、`COMPOSE_FILE=docker-compose.yml`、寫 `recovery_spire.csv`。commit：`feat(exp4): SPIRE-side fault recovery runner`
3. `scripts/experiments/fault-recovery-linkerd.sh`（`feat/Linkerd1`）：`STACK=linkerd`、`COMPOSE_FILE` 疊加 Linkerd compose、寫 `recovery_linkerd.csv`。commit：`feat(exp4): Linkerd 1.x fault recovery runner`
4. `scripts/experiments/analyze-fault-recovery.py`（`feat/spiffe-keycloak`）：沿用 `analyze-dualmode-experiment.py` pandas 樣式，讀兩份 CSV，依 `(fault_type, fault_duration)` 比 mean/median/p95 `recovery_sec`、`saga_completed` 率、`rollback_count`，產 SPIRE-vs-Linkerd delta 表/圖。commit：`feat(exp4): fault-recovery comparison analyzer`

### 恢復時間量測（兩套環境定義一致）
- `t_fault_clear` = `docker unpause/start` 回傳當下時戳。
- 清除後立刻送唯一 `X-Correlation-Id` probe 訂單；`t_recovered` = 該 since 之後第一筆 `[perf-saga-complete]`。
- `recovery_sec = t_recovered − t_fault_clear`。
- 補償場景額外記 `rollback_count`（故障窗內 rollback 標記數），且須有後續成功 `[perf-saga-complete]` 才設 `saga_completed=1`。
- 僅用凍結 saga 標記 + docker/rabbitmqctl/health，零 OrderSaga 改動，兩分支標記一致。

### 故障場景矩陣（每場景結束前都要證明一筆訂單成功完成）
1. Step1 暫時性：pause `production-service`→abort(`商品資訊查詢失敗`)→等 `fault_duration`(5s/30s)→unpause→probe→量恢復。
2. 強制補償後恢復：pause `user-service`→Step3 付款失敗→`RollbackSaga Step 2`→記 rollback→unpause→probe。
3. 付款後 confirm 失敗（完整 rollback）：送單→等 `Saga Step 3`→pause `order-service`→Step4 失敗(paymentCompleted=true)→unpause→probe。
4. `kill`+`start` 變體（重啟恢復，凸顯 Linkerd sidecar 重解析 vs SPIRE SVID 重抓差異）。
5. `fault_duration` sweep（短 ~5s / 長 ~30–60s）暴露兩套重試/熔斷差異。

### CSV schema（`recovery_<stack>.csv`）
```
stack,scenario,fault_type,fault_target,fault_duration_sec,t_fault_clear,recovery_sec,saga_completed,rollback_count,trace_id
```

### 執行流程
1. `feat/spiffe-keycloak`：起 SPIRE stack（`PERF_METRIC_ENABLED=1`）→ `fault-recovery-spire.sh <out>`。
2. 使用者清 Docker、checkout `feat/Linkerd1`、重部署 Linkerd（`docker compose -f docker-compose.yml -f docker-compose.linkerd.yml up -d`，`PERF_METRIC_ENABLED=1`）→ `fault-recovery-linkerd.sh <out>`。
3. 回 `feat/spiffe-keycloak`：`python3 scripts/experiments/analyze-fault-recovery.py --spire recovery_spire.csv --linkerd recovery_linkerd.csv --out artifacts/fault-recovery-<date>`。

### 公平性把關
Linkerd 1.x 可能用自身重試遮蔽暫時性故障 → 先確認故障確實傳達到 saga（看到 abort/rollback 標記）再採信 `recovery_sec`，由 `saga_completed`/`rollback_count` 欄位把關。先 `git show feat/Linkerd1:Sagas/OrderSaga.php | grep perf-saga-complete` 確認該分支標記一致。

---

## 風險與啟動前查核

1. **Worker 單進程同步** → 不得進程內並行；併發用 replica。確認 queue 非 exclusive（競爭式 consumer 才安全）。
2. **keep-alive × mTLS**：Run 3 必須 gate `SPIFFE_MTLS_ENABLED=0`；確認 `bin/worker.php:178-181` 在這兩模式回傳 null（mTLS 關）。
3. **Run 1 memo TTL**：須在 `exp-skew` 失效，絕不送輪換/過期 token；保留 `KEYCLOAK_SYNC_FETCH_FALLBACK=1` 預設讓冷啟動自癒。
4. **量測隔離**：每 Run 一 commit + 一次量測 + 其他 toggle 全預設；輸出目錄嵌 `HEAD`。
5. **三重驗證仍在線**：每 Run 後確認 `RequestConsumer.php:184`(JWT)、LSVID 鏈驗證、`SpiffeLsvidFilter.php:59-80`(extend 前 re-validate) 皆執行。
6. **OrderSaga 凍結**：兩實驗所需標記皆已存在，無需改。
7. **容器名漂移**：Exp-4 腳本容器名以 env 參數化；每次執行前 `docker ps` 核對實際名稱。
8. **從 gateway host 驅動壓測**：本機無 `zt-gateway`/`-lan` 別名。若在本機直接跑 `run-dualmode-distributed.sh`，需設 `GATEWAY_HOST` 指本機、`DRIVER_HOST=zt-order`；正式可發表數據建議從 Mac 用 `-lan` 別名驅動。

---

## 驗證方式（end-to-end）

- **每 Run（實驗一）**：smoke 一筆訂單看到 `✅ Saga Step 4` → `SCALES="5000 10000 20000" ROUNDS="warm cold" bash scripts/experiments/run-dualmode-distributed.sh $OUT` → `analyze-dualmode-experiment.py --in $OUT`，比對 `未完成交易率`、saga-complete 吞吐、`gw_proc_ms`/saga span p50/p99 的 delta。
- **實驗四**：兩 stack 各跑 `fault-recovery-*.sh`，每場景結束都看到後續成功 `[perf-saga-complete]`（業務完整），最後 `analyze-fault-recovery.py` 出對照表/圖。
- **回歸**：`composer test:unit` 與 `bash scripts/e2e-failure-modes.sh` 維持綠燈。
