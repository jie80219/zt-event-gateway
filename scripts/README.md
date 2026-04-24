# Scripts

## Quick usage

```bash
chmod +x scripts/*.sh
./scripts/e2e-gateway.sh
./scripts/ci-verify.sh
```

---

## 腳本總覽

本目錄共含 19 支腳本 + 3 個 sub-suite，依用途分為六類：

### 一、E2E 驗證套件（End-to-End Verification）

| 腳本 | 主要驗證內容 | 測試 Phase / Case 數 | 產出 | 執行前提 |
|---|---|---|---|---|
| `e2e-gateway.sh` | Gateway 骨幹：健康檢查、Happy-path 訂單、欄位別名、envelope schema、Invalid input 拒絕、Worker 消費、SPIFFE 身份傳遞、X-Correlation-Id 追蹤、不受信任 SPIFFE 拒絕、並發 fan-in | 4 Phases / 15 cases | stdout 日誌 | `docker-compose.yml`, curl, jq, php |
| `e2e-full-architecture.sh` | 完整 ZT 堆疊：SPIRE 健康、Registrar、watcher SHM ready、L0 鑄造、L1 擴展、EventConsumer 鏈、Saga 四步、偽造 SPIFFE 拒絕、LSVID 重放偵測、Worker 重啟恢復、並發、延遲（P50/P95/P99）、壓力吞吐、Saga 端到端延遲 | 7 Phases / 15 cases | stdout 日誌 | `COMPOSE_PROFILES=zt`, EventStoreDB:2113 |
| `e2e-full-stack.sh` | 單一 compose 之完整 ZT 管線：Infra bootstrap、SPIFFE 身份與信任域、Gateway L0、Worker LSVID 鏈、Saga + 下游 mTLS、LSVID 安全（竄改／缺失／重放）、鏈完整性、補償流程、ZT 並發 | 9 Phases (0–8) | stdout 日誌 | 單一 `docker-compose.yml`, openssl |
| `e2e-suite/full-microservice.sh` | 完整微服務拓撲：MS-HAPPY（Saga 1→4）、MS-PAYMENT-FAIL（補償）、MS-LSVID-CHAIN（L0→L1→L2）、MS-MTLS-REJECT（TLS 失敗） | 4 cases | 每案 JSON + summary | 3 下游服務 + `SPIFFE_MTLS_ENABLED=1` |

### 二、安全驗證套件（Security Verification）

| 腳本 | 主要驗證內容 | 測試項 | 產出 | 執行前提 |
|---|---|---|---|---|
| `verify-spire-integrity.sh` | SPIRE 信任平面閘：server 健康、agent 健康、Workload API socket、5 個預期 SPIFFE ID 已註冊、spiffe-watcher 健康、SHM `x509_state=ready` 且新鮮（<120s）、主 SVID slot 有效、`/metrics` 可達 | 8 checks | PASS/FAIL 計數 | `COMPOSE_PROFILES=zt` |
| `security-suite/run-security-suite.sh` | 安全評估驅動：4 stages（unit / http / amqp / rotation）× 4 profiles（A–D） | 4 × 4 = 16 格 | `security-summary.json` | 4 profiles 啟動 |

### 三、效能 / 實驗套件（Performance & Experiment）

| 腳本 | 主要驗證內容 | 測試設計 | 產出 | 執行前提 |
|---|---|---|---|---|
| `stress_test.sh` | 並發壓力產生器：成功率、HTTP 代碼、延遲百分位（P50/P95/P99/P99.9）、吞吐（req/sec）、微秒計時、變動 payload 大小 | 參數化 `TOTAL`、`CONC`、`PRODUCT_COUNT`、`LSVID_MODE` | `STRESS_JSON_OUT` JSON | Gateway 可達 |
| `lsvid-experiment.sh` | LSVID 四 profile 消融：A-baseline off、B-minting only、C-fail-closed、D-fail-closed + re-validate；每 profile 重啟 + 抽乾 queue | `TOTAL=500`, `CONC=10` × 4 profiles | `docs/data/stress-{A,B,C,D}-{STAMP}.json` | `COMPOSE_PROFILES=zt`, python3 |
| `perf-suite/zt-cost-matrix.sh` | Zero-Trust Cost Matrix（B1）：4 profiles × 3 payload sizes（1/5/20）× 3 concurrency（1/10/50） | 4 × 3 × 3 = 36 格 | 每 cell JSON + 聚合矩陣 | ZT profile 啟動 |
| `collect-experiment-data.sh` | 統一資料收集（6 stages, 28 metrics）：LSVID micro-perf、SPIFFE SHM micro-perf、4-profile 消融 + 壓力、Saga e2e 延遲分解、Envelope size（有/無 LSVID）、Summary | 6 stages | `artifacts/experiment-{STAMP}/stage-*-*.json` + `summary.md` | `COMPOSE_PROFILES=zt` |
| `run-thesis-experiment.sh` | 論文資料編排總控：(1) collect-experiment-data、(2) run-security-suite、(3) aggregate-thesis-data | 3 steps | `artifacts/experiment-{STAMP}/`、`docs/data/thesis-experiment-{STAMP}.json` + latest symlink | `COMPOSE_PROFILES=zt` 預設 |

### 四、CI / Orchestration

| 腳本 | 主要內容 | 支援模式 | 產出 | 執行前提 |
|---|---|---|---|---|
| `ci-verify.sh` | CI 驗證編排：phpunit + N 次重複 E2E 穩定性、每次收集產物 | `CI_MODE=gateway / full / baseline`、`E2E_RUNS=3`、`CI_PREBUILD_IMAGES=1` | `artifacts/ci/unit.log`、`artifacts/ci/e2e-run-{N}.log` | docker, docker-compose |

### 五、基礎設施 / 工具（Utility）

| 腳本 | 用途 | 動作 | 產出 | 備註 |
|---|---|---|---|---|
| `start-spiffe.sh` | SPIRE 啟動 | server → agent → 註冊 5 workload → spiffe-helper → 驗證 PEM → 啟動 app | Docker 容器 + PEM volume | x509pop 認證、3600s TTL、冪等 |
| `stop_all.sh` | 停止 consumers | 依 `tmp/pids/*.pid` kill process group | 移除 stale PID | 冪等 |
| `check_status.sh` | 行程監看 | 列出 `tmp/pids/*.pid` RUNNING/STOPPED | stdout | 非破壞 |
| `run_all_events.sh` | 啟動事件 consumer | nohup 4 個 PHP consumer（4 個 saga queue） | `tmp/pids/*.pid`, `tmp/logs/*.log` | PHP, nohup |
| `diagnose-order-saga.sh` | Saga 卡住診斷 | 送 1 筆訂單 → 快照 queue depth 前/中/後 → 比對 worker log → 定位失敗點 | 診斷 stdout | `WATCH_SECONDS=30` |

### 六、後處理 / 聚合（Post-processing）

| 腳本 | 用途 | 輸入 | 輸出 |
|---|---|---|---|
| `summarize-stress.php` | LSVID 壓力報告產生器 | `stress-{A,B,C,D}-{STAMP}.json` | `docs/data/stress-summary-{STAMP}.md`（含 P50/P95/P99、吞吐、錯誤率，對 baseline A 計差值） |
| `aggregate-thesis-data.php` | 論文資料聚合器 | experiment/ + security/ + lsvid-bench | `docs/data/thesis-experiment-{STAMP}.json` + `thesis-experiment-latest.json`（symlink） |

---

## 類別分布統計

| 類別 | 數量 | 主要目的 |
|---|---|---|
| E2E 驗證 | 4 | 功能正確性 |
| 安全驗證 | 2 | 威脅模型 / 信任平面 |
| 效能 / 實驗 | 5 | 資料量化 |
| CI 編排 | 1 | 自動化 |
| 基礎設施工具 | 5 | 啟停 / 診斷 |
| 後處理 | 2 | 資料聚合 |
| **合計** | **19** | — |

---

## 關鍵腳本詳解

### e2e-gateway.sh

Validates the gateway+worker backbone flow:

- `GET /api/health` returns `200`
- `POST /api/orders` returns `202` and `trace_id`
- `order_queue` contains canonical ingress envelope (`schema_version=1`) with SPIFFE metadata
- `php-worker` verifies source and republishes downstream event
- invalid input does not enter queue
- forged untrusted `spiffe_id` is rejected without retry storm

Main env vars:

- `E2E_QUEUE_CHECK_MODE=requeue|consume` (default: `requeue`)
- `E2E_DIAG_LEVEL=none|full` (default: `full`)
- `E2E_KEEP_ON_FAIL=1` keeps containers on failure
- `E2E_BUILD_IMAGES=0|1` (default: `1`)
- `E2E_WAIT_TIMEOUT=<seconds>`

### ci-verify.sh

CI-oriented single entry:

1. run Unit tests
2. run E2E repeatedly (`E2E_RUNS`, default `20`)
3. fail fast and collect logs under `artifacts/ci/e2e-run-N/`
4. prebuild images once by default (`CI_PREBUILD_IMAGES=1`)

Example:

```bash
E2E_RUNS=1 ./scripts/ci-verify.sh
```
