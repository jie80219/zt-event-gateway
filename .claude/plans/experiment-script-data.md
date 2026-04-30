# 實驗資料與腳本對照表

本文件彙整 `zt-event-gateway` 的實驗／壓測／基準／安全測試產出，並對應到產生它們的腳本。

- 產出根目錄：`artifacts/`（整段 `.gitignore`）、`docs/data/`（`.gitignore`）
- 時間戳格式：`<STAMP>` = `YYYYMMDD-HHMMSS`
- Profile 代號：A=baseline、B=mTLS only、C=LSVID only、D=full zero-trust

---

## 1. 輸出目錄結構

```
artifacts/
├── ci/                           CI 執行紀錄
│   ├── prebuild.log              Docker 預先建置輸出
│   ├── unit.log                  單元測試輸出
│   └── e2e-run-N/e2e.log         第 N 次 E2E 全紀錄
│
├── experiment-<STAMP>/           單次完整實驗快照
│   ├── experiment-summary.json   六階段彙總
│   ├── lsvid-micro.json          LSVID 13 種操作基準
│   ├── spiffe-micro.json         SPIFFE SDK 基準
│   ├── lsvid-token-sizes.json    L0/L1/L2 token 位元組與成長比
│   ├── saga-latency.json         Saga 各 step 延遲 + p50/p95
│   ├── envelope-size.json        Envelope/LSVID overhead 百分比
│   ├── ablation-summary.json     A~D profile 延遲與吞吐對照
│   ├── lsvid-bench-stdout.txt    LSVID bench 原始 stdout
│   ├── spiffe-bench-stdout.txt   SPIFFE bench 原始 stdout
│   ├── experiment-report.md      人類可讀總覽
│   ├── experiment-png-analysis.md 圖表解讀
│   ├── ablation/
│   │   ├── stress-{A,B,C,D}.json         四 profile 壓測結果
│   │   ├── stress-{A,B,C,D}-stdout.txt   壓測原始 stdout
│   │   └── resource-{A,B,C,D}.json       docker stats (CPU/mem/net)
│   └── experiment-png/           8 張分析圖
│       ├── 01-lsvid-operation-latency.png
│       ├── 02-lsvid-token-size.png
│       ├── 03-ablation-latency-throughput.png
│       ├── 04-ablation-delta-overhead.png
│       ├── 05-saga-latency-breakdown.png
│       ├── 06-lsvid-throughput.png
│       ├── 07-trust-bundle-cache.png
│       └── 08-ablation-latency-distribution.png
│
├── security-<STAMP>/             安全測試矩陣（4 profile × 4 stage）
│   ├── A-baseline/stage{1..3}-results.json
│   ├── B-mtls-only/stage{1..3}-results.json
│   ├── C-lsvid-only/stage{1..3}-results.json
│   └── D-full-zt/stage{1..4}-results.json
│
└── zt-cost-<STAMP>/              Zero-Trust Cost Matrix
    ├── cell-<PROFILE>-p<PAYLOAD>-c<CONC>.json   36 個矩陣單元
    └── matrix-summary.json                       彙總

docs/data/
├── lsvid-bench-<STAMP>.json              LSVID 基準歷史
├── lsvid-bench-latest.json               最新 LSVID 基準
├── thesis-experiment-<STAMP>.json        論文用彙整資料歷史
├── thesis-experiment-latest.json         最新論文彙整
├── lsvid-<PROFILE>-<STAMP>.json          舊版 4-profile 壓測
└── stress-summary.md                     壓測對照 markdown
```

---

## 2. 核心實驗執行器（Core Experiment Runners）

| Script                             | Purpose                                      | Output                                   |
| ---------------------------------- | -------------------------------------------- | ---------------------------------------- |
| scripts/run-thesis-experiment.sh   | 論文實驗總流程（collect + security + aggregate）| docs/data/thesis-experiment-*.json       |
| scripts/collect-experiment-data.sh | 6 階段實驗資料收集                              | artifacts/experiment-<STAMP>/            |
| scripts/aggregate-thesis-data.php  | 合併 experiment + security → thesis JSON      | docs/data/thesis-experiment-<STAMP>.json |
| scripts/stress_test.sh             | 併發壓測與延遲百分位 / 吞吐量統計                  | $STRESS_JSON_OUT（指定時）                |
| scripts/lsvid-experiment.sh        | 舊版 4-profile LSVID 壓測                      | docs/data/lsvid-<PROFILE>-<STAMP>.json   |
| scripts/summarize-stress.php       | 壓測 JSON → markdown 對照表                     | docs/data/stress-summary.md              |

---

## 3. 基準測試（Micro-Benchmark）

| Script                               | Purpose                                                 | Output                              |
| ------------------------------------ | ------------------------------------------------------- | ----------------------------------- |
| tests/benchmark-lsvid.php            | LSVID 13 種操作：create/extend/parse/validate/cache       | docs/data/lsvid-bench-<STAMP>.json  |
| tests/benchmark-spiffe.php           | SPIFFE SDK：SpiffeId / TLS / SHM / HTTP2 frame           | docs/data/spiffe-bench-<STAMP>.json |
| scripts/perf-suite/zt-cost-matrix.sh | Zero-Trust Cost Matrix：4 profile × 3 payload × 3 conc   | artifacts/zt-cost-<STAMP>/          |

---

## 4. 安全測試矩陣（Security Suite）

| Script                                                | Purpose                                    | Output                           |
| ----------------------------------------------------- | ------------------------------------------ | -------------------------------- |
| scripts/security-suite/run-security-suite.sh          | 4 profile × 4 stage 安全測試總入口                 | artifacts/security-<STAMP>/      |
| scripts/security-suite/stages/stage1-unit-matrix.sh   | 單元測試層級安全矩陣                                 | <PROFILE>/stage1-results.json    |
| scripts/security-suite/stages/stage2-e2e-http.sh      | HTTP 注入 / 竄改測試                              | <PROFILE>/stage2-results.json    |
| scripts/security-suite/stages/stage3-amqp-inject.sh   | AMQP 訊息注入攻擊                                 | <PROFILE>/stage3-results.json    |
| scripts/security-suite/stages/stage4-rotation-race.sh | SPIRE 憑證輪換 race condition（僅 profile D）     | D-full-zt/stage4-results.json    |
| scripts/security-suite/plot/plot_security.py          | 以結果繪製 PNG 分析圖                               | artifacts/security-<STAMP>/*.png |

---

## 5. E2E / CI 腳本

| Script                                 | Purpose                                      | Output                      |
| -------------------------------------- | -------------------------------------------- | --------------------------- |
| scripts/ci-verify.sh                   | CI 總驅動：unit + 重複 E2E（可用 CI_MODE 切換）  | artifacts/ci/               |
| scripts/e2e-gateway.sh                 | Gateway + Worker 輕量 E2E（15 phase）          | stdout（由 CI 腳本擷取為 e2e.log）   |
| scripts/e2e-full-architecture.sh       | 完整微服務 E2E：含 SPIRE 信任平面驗證             | stdout                      |
| scripts/e2e-full-stack.sh              | Full-stack E2E                               | stdout                      |
| scripts/e2e-suite/full-microservice.sh | Full microservice E2E 子流程                  | stdout                      |

---

## 6. 基礎設施 / 診斷腳本

| Script                            | Purpose                                       | Output              |
| --------------------------------- | --------------------------------------------- | ------------------- |
| scripts/verify-spire-integrity.sh | SPIRE/SPIFFE 信任平面 8 項健檢                       | stdout（PASS/FAIL）    |
| scripts/start-spiffe.sh           | 啟動 SPIRE server/agent/registrar/watcher       | Docker containers   |
| scripts/diagnose-order-saga.sh    | 追蹤 order saga 執行流程（log 分析）                    | stdout              |
| scripts/check_status.sh           | 系統狀態檢查                                        | stdout              |
| scripts/run_all_events.sh         | 事件 runner helper                              | stdout              |
| scripts/stop_all.sh               | 停止所有 Docker 容器                                | —                   |

---

## 7. 資料流對照（Data ↔ Script）

### 7.1 Thesis pipeline

```
run-thesis-experiment.sh
├─ collect-experiment-data.sh
│  ├─ Stage 1  benchmark-lsvid.php           → lsvid-micro.json + lsvid-bench-<STAMP>.json
│  ├─ Stage 2  benchmark-spiffe.php          → spiffe-micro.json + spiffe-bench-stdout.txt
│  ├─ Stage 3  stress_test.sh × {A,B,C,D}    → ablation/stress-*.json + resource-*.json
│  ├─ Stage 4  saga 量測迴圈                   → saga-latency.json
│  ├─ Stage 5  envelope 擷取                  → envelope-size.json
│  └─ Stage 6  彙總 + 繪圖                     → experiment-summary.json + experiment-png/
│
├─ run-security-suite.sh
│  └─ stage{1..4} × profile{A..D}            → artifacts/security-<STAMP>/
│
└─ aggregate-thesis-data.php                 → docs/data/thesis-experiment-<STAMP>.json
                                               docs/data/thesis-experiment-latest.json
```

### 7.2 直接對應表（Data File → Producing Script）

| Data file (relative)                                       | Produced by                                                      |
| ---------------------------------------------------------- | ---------------------------------------------------------------- |
| docs/data/lsvid-bench-<STAMP>.json                         | tests/benchmark-lsvid.php                                        |
| docs/data/lsvid-bench-latest.json                          | tests/benchmark-lsvid.php（最新複本）                                   |
| docs/data/thesis-experiment-<STAMP>.json                   | scripts/aggregate-thesis-data.php                                |
| docs/data/thesis-experiment-latest.json                    | scripts/aggregate-thesis-data.php                                |
| docs/data/lsvid-<PROFILE>-<STAMP>.json                     | scripts/lsvid-experiment.sh                                      |
| docs/data/stress-summary.md                                | scripts/summarize-stress.php                                     |
| artifacts/experiment-<STAMP>/lsvid-micro.json              | collect-experiment-data.sh → benchmark-lsvid.php                 |
| artifacts/experiment-<STAMP>/spiffe-micro.json             | collect-experiment-data.sh → benchmark-spiffe.php                |
| artifacts/experiment-<STAMP>/lsvid-token-sizes.json        | collect-experiment-data.sh（Stage 1 副產物）                           |
| artifacts/experiment-<STAMP>/saga-latency.json             | collect-experiment-data.sh（Stage 4）                               |
| artifacts/experiment-<STAMP>/envelope-size.json            | collect-experiment-data.sh（Stage 5）                               |
| artifacts/experiment-<STAMP>/ablation-summary.json         | collect-experiment-data.sh（Stage 3 彙總）                            |
| artifacts/experiment-<STAMP>/experiment-summary.json       | collect-experiment-data.sh（Stage 6）                               |
| artifacts/experiment-<STAMP>/ablation/stress-*.json        | collect-experiment-data.sh → stress_test.sh                      |
| artifacts/experiment-<STAMP>/ablation/resource-*.json      | collect-experiment-data.sh（docker stats 取樣）                       |
| artifacts/experiment-<STAMP>/experiment-png/*.png          | collect-experiment-data.sh（Stage 6 繪圖）                            |
| artifacts/security-<STAMP>/<PROFILE>/stage<N>-results.json | scripts/security-suite/stages/stage<N>-*.sh                      |
| artifacts/zt-cost-<STAMP>/cell-*.json                      | scripts/perf-suite/zt-cost-matrix.sh                             |
| artifacts/zt-cost-<STAMP>/matrix-summary.json              | scripts/perf-suite/zt-cost-matrix.sh                             |
| artifacts/ci/unit.log                                      | scripts/ci-verify.sh                                             |
| artifacts/ci/prebuild.log                                  | scripts/ci-verify.sh                                             |
| artifacts/ci/e2e-run-N/e2e.log                             | scripts/ci-verify.sh → e2e-gateway.sh / e2e-full-architecture.sh |

---

## 8. 主要指標（Metrics Reference）

| Dataset         | Key metrics                                                                     |
| --------------- | ------------------------------------------------------------------------------- |
| LSVID micro     | iterations、ops_per_sec、us_per_op（13 個 operation）                                 |
| SPIFFE micro    | SpiffeId parse/equals/memberOf、SHM seqlock、TLS cred、HTTP2 frame                  |
| Stress          | success / failed、rate_per_sec、latency min/avg/max/p50/p90/p95/p99                |
| Saga latency    | samples、avg_ms、p50_ms、p95_ms、request→step1…→step4 間隔                             |
| Envelope size   | envelope_total_bytes、lsvid_token_bytes、lsvid_overhead_pct、spiffe_path_entries    |
| Ablation (A~D)  | rate_rps、p50/p90/p95/p99、delta_p50_pct vs baseline                               |
| Token sizes     | L0_bytes / L1_bytes / L2_bytes、growth L1/L0、L2/L0                                |
| Security matrix | 各 stage 的 pass/fail 數、profile 間對照                                                |
| ZT cost matrix  | 4 profile × 3 payload × 3 concurrency → p50/p95 + rate_rps                       |

---

## 9. 執行範例

```bash
# 一鍵跑完整論文 pipeline
bash scripts/run-thesis-experiment.sh

# 僅跑 micro-benchmark（跳過 stress / security）
MICRO_ONLY=1 bash scripts/run-thesis-experiment.sh

# 獨立跑 LSVID 基準（自訂迭代次數）
ITER=10000 php tests/benchmark-lsvid.php

# 獨立跑壓測並輸出 JSON
STRESS_JSON_OUT=/tmp/stress.json bash scripts/stress_test.sh

# Zero-Trust cost matrix
bash scripts/perf-suite/zt-cost-matrix.sh

# 安全測試矩陣
bash scripts/security-suite/run-security-suite.sh

# CI 本地驗證
CI_MODE=full bash scripts/ci-verify.sh
```

---

## 10. 歷史快照（截至 2026-04-23）

| 類別                 | 最新檔案                                    | 時間                |
| ------------------- | ------------------------------------------ | ------------------ |
| LSVID bench         | docs/data/lsvid-bench-20260423-133602.json | 2026-04-23 13:36   |
| Thesis experiment   | docs/data/thesis-experiment-latest.json    | 2026-04-23 02:54   |
| Experiment snapshot | artifacts/experiment-20260417-223524/      | 2026-04-17 22:35   |

---

## 11. 比較對象（Comparison Structure）

每項實驗的「自變項 / Baseline / 對照組 / Delta 指標」對照。

### 11.1 實驗 ↔ 比較對象總表

| 實驗項目          | 自變項（獨立變數）                  | Baseline（控制組）                  | 對照組（處理-組）                                | Delta 指標                                     |
| ---------------- | -------------------------------- | ---------------------------------- | --------------------------------------------- | --------------------------------------------- |
| LSVID micro      | Token 巢狀深度 / 快取狀態           | L0 / 1-level validate / 冷快取        | L1、L2、L5；2/3/6-level validate；熱快取          | ops_per_sec、us_per_op                         |
| SPIFFE micro     | 操作類別                          | 無（純量測）                          | —                                             | ops_per_sec、us_per_op                         |
| Ablation stress  | SPIFFE / LSVID / mTLS 開關組合     | Profile A（SPIFFE_ENABLED=0）          | B（mint only）、C（fail-closed）、D（full ZT）      | rate_rps、p50/p95/p99、delta_p50_pct_vs_A      |
| Saga latency     | Saga 步驟進度                      | 無（僅 full-ZT，無對照）               | —（step1→step2→step3→step4 內部比較）             | ms per step、total_ms、p50/p95                  |
| Envelope size    | LSVID 是否存在                     | envelope_without_lsvid_bytes         | envelope_total_bytes                          | lsvid_overhead_pct                            |
| Token sizes      | 巢狀層級 L0 / L1 / L2                | L0_bytes                            | L1_bytes、L2_bytes                             | growth_ratio_L1_L0、growth_ratio_L2_L0        |
| Security matrix  | Profile × 攻擊階段                 | A（無 LSVID → 多數攻擊放行）           | C / D（fail-closed → 期望拒絕）                   | pass/fail delta by_profile × by_stage         |
| ZT cost matrix   | Profile × payload × concurrency  | A @ payload=1, conc=1              | 其餘 35 cell                                   | delta_p50_pct_vs_baseline、delta_rps_pct_vs_baseline |
| CI e2e           | —                               | —                                 | —                                             | 回歸成敗（pass/fail）                              |

### 11.2 LSVID micro 內部配對比較（13 operation）

| 比較軸                      | Baseline                          | 對照                                 | 觀察                    |
| -------------------------- | --------------------------------- | ------------------------------------ | ---------------------- |
| 巢狀深度：mint vs extend       | createBase（L0 mint）               | extend L0→L1、extend L1→L2           | 每多一層的簽章成本           |
| 解析深度：parse                | parse L0                           | parse L1、parse L2                   | 巢狀 decode overhead    |
| 驗證深度：validate              | validate 1 level（L0）              | validate 2 / 3 / 6 levels            | 驗證成本線性 vs 指數          |
| LSVID vs 純密碼學原語           | openssl_verify / openssl_x509_verify | LSVIDValidator::validate           | LSVID 的框架開銷           |
| Trust-bundle cache         | 冷啟動新 validator                    | 熱路徑已快取 validator                  | cache hit 節省量         |

### 11.3 Ablation profile 定義（A/B/C/D 四組）

| Profile | SPIFFE_ENABLED | LSVID_ENABLED | LSVID_REQUIRED | SPIFFE_MTLS_ENABLED | 意義                        |
| ------- | -------------- | ------------- | -------------- | ------------------- | -------------------------- |
| A       | 0              | 0             | 0              | 0                   | Baseline（零信任全關）            |
| B       | 1              | 1             | 0              | 0                   | 僅鑄造 LSVID、fail-open      |
| C       | 1              | 1             | 1              | 0                   | 強制驗證 LSVID（fail-closed） |
| D       | 1              | 1             | 1              | 1                   | Full Zero-Trust（含 mTLS）     |

### 11.4 Security matrix 攻擊期望

| Stage   | 攻擊內容                                | 期望行為（A/B）        | 期望行為（C/D）    |
| ------- | -------------------------------------- | -------------------- | --------------- |
| Stage 1 | LSVIDValidator 單元攻擊（forgery/expiry/replay） | 放行（無 LSVID 驗證）     | 拒絕             |
| Stage 2 | HTTP ingress 竄改（malformed / missing field）    | 拒絕（gateway 強制 envelope） | 拒絕         |
| Stage 3 | AMQP 直接注入（F/T/C/E/R/Q/S/D 系列案例）        | 多數放行              | 拒絕             |
| Stage 4 | SVID 輪換 race + mTLS probe（M01/M02/M03）      | —（profile D 專屬）    | 拒絕 / 輪換無縫     |

### 11.5 ZT cost matrix 維度

- Profile：A / B / C / D（同 ablation 定義）
- Payload（`STRESS_PRODUCT_COUNT`）：1 / 5 / 20 個商品
- Concurrency：1 / 10 / 50
- 每 cell 請求數：`TOTAL=1000`（預設）
- Baseline cell：`A_p1_c1`（profile=A, payload=1, conc=1）
- 其餘 35 cell 均以該 cell 計算 `delta_p50_pct_vs_baseline` 與 `delta_rps_pct_vs_baseline`
