# Dual-mode 固定負載實驗規格

本規格定義 `scripts/experiments/run-experimental-full.sh` 的固定參數、量測指標、執行流程與輸出結構。每次跑 5000 / 10000 / 20000 筆訂單時的「可比較數據」都依此產出。

通用拓撲與方案中立規範請見 `docs/zt-perf-experiment-spec.md`（本機文件，`docs/` 在 `.gitignore` 內）；本文檔只描述本實驗的**特化條件**。

---

## 1. 固定測試參數（每次執行都一樣）

| 參數 | 值 | 對應位置 | 套用方式 |
|---|---|---|---|
| Gateway worker 數（`$worker->count`） | **記錄實際值**（compose 預設 32，可由 `GATEWAY_WORKERS` env 覆寫） | OpenSwoole `worker_num`，由 `GATEWAY_WORKERS` env 注入（`bin/gateway.php`） | `export GATEWAY_WORKERS=<N>` 後 `docker compose up -d --force-recreate gateway` |
| php-worker 容器內 fork 出的 process 數（`WORKER_PROCESSES`） | **記錄實際值**（compose 預設 4） | `docker/php-openswoole/zt-worker-entrypoint.sh` 依 `WORKER_PROCESSES` env fork N 個 `php bin/worker.php` | `export WORKER_PROCESSES=<N>` 後 `docker compose up -d --force-recreate php-worker` |
| AMQP `prefetch_count` | **1** | `bin/worker.php:388` 寫死（`basic_qos(null,1,null)`） | 本實驗不改，僅紀錄 |
| 受測分支 | `feat/spiffe-keycloak`（可由 `EXPECTED_BRANCH` 覆寫） | 四台 host 同步 | wrapper §A.1 強制檢查 |
| 請求量級 | 5000、10000、20000 | 由 `SCALES` env 控制 | `run-dualmode-distributed.sh` 內部循環 |
| 輪次 | warm（暖機後）+ cold（restart gateway/php-worker 後第一波） | 由 `ROUNDS` env 控制 | cold 由 runner 自動 `docker restart` |

> wrapper `§A.4` 在落 metadata 時只 sanity check `gateway_workers >= 1` 和 `worker_consumer_processes >= 1`。若要 strict pin（例如不同 run 都用同樣的 worker 數），設 `EXPECTED_GW_WORKERS=<N>` / `EXPECTED_NUMPROCS=<N>` 顯式指定，不符就 fail。同一批要比較的 run 必須使用相同 worker 數。

---

## 2. 量測指標

四個指標皆沿用既有 log marker，不修改 PHP 程式：

| # | 指標 | log marker | 計算 | 來源檔（行） |
|---|---|---|---|---|
| 1 | **Gateway 接收請求時間** | `[perf-request-in] ts_in ts_out gw_proc_ms traceId` | `gw_proc_ms` 直接取 — 等同 `(ts_out − ts_in) × 1000`，含 publish 到 `order_queue` 的耗時 | `anser-gateway/.../Order.php` ~L189 |
| 2 | **訂單完成時間** | `[perf-saga-step1] ts orderId traceId` ↔ `[perf-saga-complete] ts orderId` | `(complete_ts − publish_ts) × 1000` ms（以 trace_id → orderId 對齊） | `Sagas/OrderSaga.php` L70 / L218 |
| 3 | **未完成交易率** | step1 與 complete 的計數差 | `(step1_fired − completed) / total × 100 %`<br>分母 = 「Saga step1 觸發數」（= 真正進入 worker pipeline 的訂單） | OrderSaga log 聚合 |
| 4 | **mTLS 花費時間** | `[perf-mtls] handshake_ms connect_ms total_ms` | 直接取 `handshake_ms`，過濾 `<= 0`（失敗握手） | `scripts/experiments/mtls-probe.sh` |

每指標各自輸出 `<指標名>.xlsx`（long-form raw + summary）與 `<指標名>.png`（boxplot 或 barplot）。

---

## 3. 執行流程

由 `scripts/experiments/run-experimental-full.sh` 一次性串起。任一步失敗就 `exit 1`，不允許繼續。

### §A.1 分支同步檢查
- 對 `zt-gateway / zt-order / zt-prod / zt-user` 四台執行：
  ```bash
  git fetch --all --prune && git checkout "$EXPECTED_BRANCH" && git pull --ff-only
  ```
- 比對 `git rev-parse --abbrev-ref HEAD` 必須等於 `$EXPECTED_BRANCH`。
- `SKIP_BRANCH_SYNC=1` 可跳過 fetch/pull（debug only）。

### §A.2 Stack health probe
- `:8080/api/health`、`:8082/api/health`、`:8083/api/health`、`:8084/api/health` 都必須 200。
- 額外讀 `zt-spiffe-watcher` 的 `/tmp/spiffe-shared/meta.json` 與 `zt-keycloak-watcher` 的 `/tmp/keycloak-shared/meta.json` 顯示 `x509_state` / `token_state`（best-effort，不阻擋）。

### §A.3 Smoke 訂單（必須完成 Saga Step 4）
```bash
TRACE=smoke-<stamp>
curl -X POST http://127.0.0.1:8080/api/orders \
  -H 'Content-Type: application/json' -H "X-Correlation-Id: $TRACE" \
  -d '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}'
# 預期 HTTP 202
sleep 5
docker logs --tail 500 zt-php-worker 2>&1 | grep $TRACE | grep -E 'Saga Step 4|RollbackSaga'
```
- Pass 條件：看到 `✅ Saga Step 4: 訂單完成！`，且該 trace 沒有 `RollbackSaga`。
- 結果落 `$OUT/smoke.log`。失敗常見原因：下游服務 build 過舊、SPIRE 還沒 ready、DB seed 缺資料。
- `SKIP_SMOKE=1` 可跳過（debug only）。

### §A.4 Metadata 落檔到 `$OUT/metadata.json`
- 從 `docker inspect zt-gateway` 抓 `GATEWAY_WORKERS`，**驗證為正整數**（≥ 1）；若 `EXPECTED_GW_WORKERS` 有設值再做 strict pin。
- 從 `docker exec zt-php-worker pgrep -fc 'php bin/worker.php'` 抓 `numprocs`（= 容器內 fork 出來的 `php bin/worker.php` 數量），**驗證為正整數**；若 `EXPECTED_NUMPROCS` 有設值再做 strict pin。
- 抓四台 host 的 `git rev-parse HEAD` 寫入 `commit_sha`。
- 抓 profile flag（`SPIFFE_ENABLED`、`LSVID_REQUIRED`、`SPIFFE_MTLS_ENABLED`、`KEYCLOAK_ENABLED`）。

`metadata.json` 範例（數字隨實際 compose 設定而異）：
```json
{
  "stamp": "20260508-153422",
  "branch": "feat/spiffe-keycloak",
  "commit_sha": {
    "zt-gateway": "<sha>", "zt-order": "<sha>",
    "zt-prod": "<sha>",    "zt-user": "<sha>"
  },
  "gateway_workers": 32,
  "worker_consumer_processes": 4,
  "amqp_prefetch_count": 1,
  "spiffe_enabled": 1,
  "lsvid_required": 1,
  "mtls_enabled": 1,
  "keycloak_enabled": 1,
  "scales": [5000, 10000, 20000],
  "rounds": ["warm", "cold"],
  "started_at": "2026-05-08T07:34:22Z"
}
```

### §B 主測試（`run-dualmode-distributed.sh`）
- 對每個 `(round, scale)`：
  1. purge 所有 RabbitMQ queue
  2. 在 `zt-order-lan` 容器內跑 `load-driver.py`，併發 = scale，計時 → CSV
  3. drain 90 秒讓 saga 走完
  4. 從 `zt-gateway` / `zt-php-worker` log 撈 `[perf-request-in]` / `[perf-saga-*]`
  5. 在 `zt-php-worker` 內跑 `mtls-probe.sh`（200 次 mTLS 握手）
- cold round 啟動前自動 `docker restart zt-gateway zt-php-worker`。

### §B.3 視覺化（`analyze-dualmode-experiment.py`）
依 §2 公式對 `$OUT/raw/` 做聚合。

---

## 4. 輸出結構

```
artifacts/<YYYYmmdd-HHMMSS>_Experimental/
├── metadata.json                       # §A.4 落檔的固定條件
├── smoke.log                           # §A.3 smoke 訂單 log
├── raw/
│   ├── load_warm_5000.csv              # load-driver 客戶端視角
│   ├── load_warm_10000.csv
│   ├── load_warm_20000.csv
│   ├── load_cold_5000.csv
│   ├── load_cold_10000.csv
│   ├── load_cold_20000.csv
│   ├── worker_<round>_<scale>.log      # gateway/worker 的 [perf-*] 行
│   └── mtls_<round>_<scale>.err        # mTLS probe 的 [perf-mtls]
├── Gateway接收請求時間.xlsx
├── Gateway接收請求時間.png
├── 訂單完成時間.xlsx
├── 訂單完成時間.png
├── 未完成交易率.xlsx
├── 未完成交易率.png
├── mTLS花費時間.xlsx
├── mTLS花費時間.png
├── summary.xlsx                        # sheets: metadata, 4 個指標
└── README.md                           # metadata + 統計表 markdown
```

### `summary.xlsx` 工作表

| Sheet | 內容 |
|---|---|
| `metadata` | 從 `metadata.json` 攤平的 key/value（branch、worker 配置、profile flag、commit_sha 等） |
| `Gateway接收請求時間` | 每個 (scale, round) 的 count / mean / p50 / p90 / p95 / p99 / max |
| `訂單完成時間` | 同上 |
| `未完成交易率` | total / step1_fired / saga_completed / incomplete / rate(%) |
| `mTLS花費時間` | handshake_ms 統計 |

---

## 5. 通過條件（建議用於報告驗收）

- 每個 (scale, round) 的三類 raw 檔（csv / log / err）都非空。
- `[perf-saga-step1]` 與 `[perf-saga-complete]` 的 orderId 對得上 ≥ 99%。
- D-full-zt 在 5000 scale 的未完成交易率 < 0.5%；20000 scale 容忍 < 2%。
- 任一 scale 的 `Gateway接收請求時間` p50 不可比 baseline（A-baseline 同 scale）退化超過 50%。

> baseline 比較需另跑 `SPIFFE_ENABLED=0 LSVID_ENABLED=0 SPIFFE_MTLS_ENABLED=0` 的 A-baseline 配置；本實驗本身只跑 D-full-zt。

---

## 6. 執行範例

```bash
# 預設：feat/spiffe-keycloak 分支、5000/10000/20000、warm+cold
bash scripts/experiments/run-experimental-full.sh

# 換分支
EXPECTED_BRANCH=main bash scripts/experiments/run-experimental-full.sh

# 只跑 warm + 5000（debug）
SCALES=5000 ROUNDS=warm bash scripts/experiments/run-experimental-full.sh

# 跳過 git pull 與 smoke（已手動驗證過時）
SKIP_BRANCH_SYNC=1 SKIP_SMOKE=1 bash scripts/experiments/run-experimental-full.sh
```

完成後 artifacts 路徑會印在最後一行；用 `python3 -c "import openpyxl; ..."` 或 LibreOffice 開 `summary.xlsx` 即可比對。

---

## 7. 相關檔案

| 檔案 | 角色 |
|---|---|
| `scripts/experiments/run-experimental-full.sh` | 一次性整合 wrapper |
| `scripts/experiments/run-dualmode-distributed.sh` | 分散式 runner（被 wrapper 呼叫） |
| `scripts/experiments/load-driver.py` | aiohttp 客戶端 |
| `scripts/experiments/mtls-probe.sh` | mTLS handshake probe |
| `scripts/experiments/analyze-dualmode-experiment.py` | 聚合 + xlsx/png/README |
| `bin/gateway.php` | Gateway worker 數來源（`GATEWAY_WORKERS`） |
| `bin/worker.php` | AMQP prefetch（寫死於 L388） |
| `Sagas/OrderSaga.php` | `[perf-saga-step1]` / `[perf-saga-complete]` 寫 log |
| `anser-gateway/.../Order.php` | `[perf-request-in]` 寫 log |
| `docs/zt-perf-experiment-spec.md` | 通用版實驗規格（多方案比較，本機文件） |
