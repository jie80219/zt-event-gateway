# 實作計畫：`docs/goal/goal2.md` + 實驗一圖表轉換、三方對比、實驗四腳手架

> 本檔是「實作計畫」(給 `/goal` 執行用)。最終要**產出**的實驗執行規格文件是 `docs/goal/goal2.md`(見 §A,為本計畫的第 7 個 commit)。

## 達成定義（completion criteria）
下列 7 個 commit 全部完成、且驗證(§F)通過即視為達成：
1. 實驗一圖表 box→bar+line(預設 barline)
2. 三方對比分析器 `compare-three-stacks.py`
3. 實驗四共用函式庫 `fault-recovery-common.sh`
4. 實驗四 SPIRE runner
5. 實驗四 Linkerd runner（本分支建立，後續 cherry-pick 到 `feat/Linkerd1`）
6. 實驗四對比分析器 `analyze-fault-recovery.py`
7. `docs/goal/goal2.md`（最後寫，引用前述已提交腳本）

硬性約束：**不得修改 `Sagas/OrderSaga.php`**；不得弱化三重驗證(`RequestConsumer` JWT、LSVID 鏈、`SpiffeLsvidFilter` re-validate)；新增 env toggle 預設保留現狀；每個改動一個 commit；分支 `feat/spiffe-keycloak`；`packages/php-lsvid`(巢狀 gitignore repo)不動；所有圖表**禁用 box，一律 Bar(對比)+Line(趨勢)**。

## Context

`docs/goal/goal.md` 已定義實驗一(壓測效能優化 Runs 1–8，已提交)與實驗四(下游故障注入恢復，harness 尚未建立)。本計畫產出 `docs/goal/goal2.md`：兩個實驗的**執行 + 分析規格**，以及支援用的 harness/圖表程式。

使用者敲定前提：
1. **目標敘事**：訂單完成時間排序 **SPIFFE+Keycloak+LSVID < Linkerd1.x < SPIFFE+Keycloak(無 LSVID)** — 最完整的 LSVID 模式必須**最快**。若實測不符，啟動**微調迴圈**：依 goal.md 風格新增優化 Run(Run 9、10…)，各自一個 commit，直到排序成立。
2. **實驗四**：下游服務故障注入；**SPIRE 側恢復時間必須贏過 Linkerd 1.x**。
3. **圖表**：每次量測 → grouped **Bar**(對比) + **Line**(趨勢)。**禁用 box plot。**
4. 每個程式改動皆 **git commit**。
5. **範圍 = 只建置**。我寫 goal2.md、轉圖表、建三方對比分析器、建實驗四腳手架 — 全部可提交可執行。**實際量測由使用者**從 Mac driver 跑(可發表)並自行在 `feat/Linkerd1` 重部署 Linkerd。本計畫不依賴我親自跑實驗。

## 已驗證事實
- 實驗一分析器 `scripts/experiments/analyze-dualmode-experiment.py` 對 訂單完成時間 / Gateway接收請求時間 / mTLS花費時間 輸出 box plot（`plot_box_by_scale_round`，呼叫點 ~L530/L550/L596），未完成交易率為 bar（`plot_bar_incomplete` ~L344）。metric 欄位於 `assemble_one` ~L122-160 計算（`order_completion_ms`、`gw_proc_ms`、`step1_fired`、`completed`）；`stat_dict` ~L169-183 給 mean/p50/p90/p95/p99/max。`ROUNDS=("warm","cold")`、`ROUND_COLORS` ~L45。
- raw 檔名為 **round 後綴**：`raw/load_<round>_<scale>.csv` / `worker_…` / `mtls_…`（`run-dualmode-distributed.sh:105-107`）。**既有 `artifacts/*` 用舊式 `load_<scale>.csv`** → round 後綴分析器無法對它們驗證；三方分析器(讀 `summary.xlsx`)可。
- 三條序列 = 三次 run：Group A(LSVID on，compose 預設) / Group B(LSVID off，`docker-compose.override.yml`) / Linkerd(`feat/Linkerd1`，使用者重部署)。`feat/Linkerd1` 已有 `compare-linkerd-vs-dualmode.py`(2 方 grouped bar)，含多 sheet(SPIFFE) vs 單 sheet(Linkerd) `summary.xlsx` 對帳邏輯可重用。
- 實驗四：4 個規劃檔皆不存在。可重用 helper：`scripts/e2e-failure-modes.sh`（`pause_container` L71-80、`unpause_container` L82-92、`wait_for_worker_log` L113-123、`post_order` L100-107、cleanup trap L136-144、container env 變數 L45-47）；`run-dualmode-distributed.sh`（`wait_health` L81-92、`purge_queues` L71-79、queue 深度輪詢 L154）。OrderSaga 標記（`[perf-saga-step1]`、`[perf-saga-complete]`、`RollbackSaga Step 2`、`❌ RollbackSaga Step 1`、`商品資訊查詢失敗`，受 `PERF_METRIC_ENABLED=1` 控制）**兩分支一致** — apples-to-apples OK。**不得改 `Sagas/OrderSaga.php`。**

## A. `docs/goal/goal2.md`（新文件；引用 goal.md，不複製 Run 理由）
章節：§0 範圍與 goal.md 關係(建置 vs 使用者執行分工)；§1 假設(LSVID 最快排序；「較快」= 較低 `order_completion_ms` mean 為 gate + p99 並列，warm 為頭條)；§2 三組與切換(A 預設 / B `-f docker-compose.override.yml` / Linkerd overlay；皆 `PERF_METRIC_ENABLED=1`)；§3 實驗一執行流程(先 smoke 看 `✅ Saga Step 4`，再 `SCALES="5000 10000 20000" ROUNDS="warm cold" run-dualmode-distributed.sh <OUT>`，輸出目錄 `<date>_<sha>_{lsvidON,lsvidOFF,linkerd}`，再逐組 `analyze-dualmode-experiment.py`；說明 round 後綴 raw 契約)；§4 三方對比 + 圖表規格(Bar+Line，禁 box)；§5 **微調迴圈**(PASS = 每 scale warm `oc_mean(A)<oc_mean(LK)<oc_mean(B)`；FAIL 時依 goal.md 風格新增 Run N + 一個 commit，重測、重比，循環；候選槓桿：`AMQP_PREFETCH`、worker replicas、SHM spin、`LSVID_PREP_DEBUG` 導向的快取工作；設停止條件避免無限迴圈)；§6 實驗四流程(5 場景矩陣、`recovery_sec = t_recovered − t_fault_clear`、`saga_completed`/`rollback_count` 語義、SPIRE→Linkerd 順序、公平性把關)；§7 實驗四圖表規格(每場景 Bar SPIRE-vs-Linkerd + fault_duration sweep Line + saga_completed 率 bar + rollback_count bar)；§8 驗證；§9 commit/分支矩陣；§10 風險。

## B. 圖表轉換 — `analyze-dualmode-experiment.py`（commit 1）
- 新增 `plot_bar_line_by_scale(df, value_col, title, ylabel, out_png_bar, out_png_line)`：**Bar** = 每 scale 群組(warm/cold)，各顯示 **p50(實心) + p99(斜線填充)**；**Line** = x=scales，繪 warm-p50/warm-p99/cold-p50/cold-p99（`ROUND_COLORS`，實線=p50/虛線=p99）。統計用既有 `stat_dict`。
- 檔名：保留既有 `<metric>.png`(現為 bar) + 新增 `<metric>_trend.png`(line)，套用於 訂單完成時間 / Gateway接收請求時間 / mTLS花費時間。`.xlsx` 輸出不變。
- 新增 `--plots {barline,box}`(預設 `barline`)；`plot_box_*` 僅在 `--plots box` 可達(不丟失、預設無 box)。
- 未完成交易率：保留 `plot_bar_incomplete`；新增 `plot_line_incomplete` → 未完成交易率_trend.png。更新 `write_readme` + 末尾 print 區塊含 `_trend` 檔。

## C. 三方對比 — `scripts/experiments/compare-three-stacks.py`（新檔，commit 2）
將 `compare-linkerd-vs-dualmode.py` 由 2 方推廣為 3 方。輸入：`--lsvid-on <dir> --lsvid-off <dir> --linkerd <dir> --out <dir> --round warm --scales 5000,10000,20000 --linkerd-format {multi,single}`(預設 multi)。以 `read_summary(path,fmt)` dispatcher 讀各 `summary.xlsx`(移植 `read_dualmode`/`read_linkerd`)；各回 `scale -> {gw_*, oc_*, rate_pct, mtls_*}` 含 p50。每 metric(gw/oc/rate/mtls)：grouped **Bar**(每 scale 3 條，序 A/LK/B，A=藍/LK=灰/B=紅，範圍大時 log scale) + **Line**(3 線跨 scale)，禁 box；每 metric `.xlsx` 含 `ordering_ok` 欄；`comparison_three_summary.xlsx` 含 `ordering_verdict` sheet(供 §5)。輸出 `artifacts/three-way-<date>/`。mTLS 的 Linkerd → 顯示 "N/A"。

## D. 實驗四腳手架（4 檔，commit 3-6）
- **`fault-recovery-common.sh`**(被 source 的 lib)：env 參數 `STACK`(spire|linkerd)、`COMPOSE_FILE`、`WORKER_CONTAINER`(預設 `php-worker`)、`{ORDER,PRODUCTION,USER}_SVC_CONTAINER`、可選 `DOCKER_HOST_SSH`、`REQUEST_URL`/`HEALTH_URL`、`OUT`、`FAULT_DURATIONS`(預設 `5 30`)。函式移植自 e2e-failure-modes.sh + run-dualmode-distributed.sh：`_docker`(ssh 或 local)、`pause/unpause/kill/start_container`(+EXIT-trap cleanup)、`worker_logs_since`(`docker compose logs --since`)、`wait_for_worker_log`、`post_order`、`wait_health`、`purge_queues`、`record_csv`(檔不存在時寫 goal.md header)、`measure_recovery(scenario,fault_type,target,duration)` — pause/驅動訂單/sleep/`t_fault_clear=date -u RFC3339`+unpause(或 start)/送清除後 probe/輪詢清除後首個 `[perf-saga-complete]`(以標記 `ts=` 為 `t_recovered`)/算 `recovery_sec`/`saga_completed`/`rollback_count`/`record_csv`。
- **`fault-recovery-spire.sh`**(`feat/spiffe-keycloak`)：`STACK=spire`、`COMPOSE_FILE=docker-compose.yml`、preflight `docker ps` 印容器名、迴圈 5 場景 × `FAULT_DURATIONS`、每場景後 assert 清除後成功 `[perf-saga-complete]`、寫 `recovery_spire.csv`。
- **`fault-recovery-linkerd.sh`**(本分支建立、cherry-pick 到 `feat/Linkerd1`)：`STACK=linkerd`、`COMPOSE_FILE="-f docker-compose.yml -f docker-compose.linkerd.yml"`、寫 `recovery_linkerd.csv`。
- **`analyze-fault-recovery.py`**(`feat/spiffe-keycloak`)：`--spire … --linkerd … --out …`；依 `(scenario,fault_type,fault_duration)` 分組；mean/median/p95 `recovery_sec`、`saga_completed` 率、`rollback_count`；**Bar** 每場景(SPIRE vs Linkerd，median+p95，標贏家) + **Line** 跨 fault_duration sweep + saga_completed 率 bar + rollback_count bar；`fault_recovery_summary.xlsx` 含 `verdict` sheet。禁 box。
- 建議實驗四用**單機 local 模式**(乾淨的恢復量測)；分散式 `zt-*` 模式以 `DOCKER_HOST_SSH` 支援。每次先 `docker ps` 核對容器名。

## E. Commit 計畫（順序，皆於 `feat/spiffe-keycloak` 除非註明）
1. `feat(exp1): convert dualmode charts from box to bar+line (default), keep box behind --plots` — `analyze-dualmode-experiment.py`
2. `feat(exp1): three-stack (LSVID-on / Linkerd / LSVID-off) comparison analyzer` — `compare-three-stacks.py`
3. `feat(exp4): shared fault-recovery harness lib` — `fault-recovery-common.sh`
4. `feat(exp4): SPIRE-side fault recovery runner` — `fault-recovery-spire.sh`
5. `feat(exp4): Linkerd 1.x fault recovery runner` — `fault-recovery-linkerd.sh`
6. `feat(exp4): fault-recovery comparison analyzer` — `analyze-fault-recovery.py`
7. `docs(goal): add goal2.md execution + analysis + fine-tuning spec` — `docs/goal/goal2.md`(最後，引用已提交腳本)
- **跨分支(於 goal2.md §9 記載)：** 在 `feat/Linkerd1` `git cherry-pick` commit 3 + 5(純新增檔，不擾動運行中 stack)；先驗證標記一致。分析器留在 `feat/spiffe-keycloak`。

## F. 驗證（不跑完整實驗）
- 對 3 個 Python 檔做 AST/import smoke(`pandas`/`matplotlib`/`openpyxl`/`numpy` 已具備)。
- **三方分析器實測 smoke**：將 `--lsvid-on/--lsvid-off/--linkerd` 指向既有 `summary.xlsx` 目錄(如 `artifacts/2026-06-02_111754_Experimental/`) → 確認產生 bar+line PNG + verdict sheet、無 box。(三序列相同無妨，僅驗管線。)
- **box→bar+line 檢查**：`artifacts/*` 無 round 後綴輸入，故驗收 = code review(box 呼叫已換、已加 `_trend`) + AST smoke；完整功能檢查在使用者首次實跑(於文件列為驗收步驟)。
- 對 3 個 shell 腳本 `bash -n`；若 stack 在線可選跑單一 local scenario-1 故障 smoke。
- 回歸：`composer test:unit` 綠燈；`bash scripts/e2e-failure-modes.sh` 綠燈(確認移植 helper 正常)。

## 風險
- **raw 命名不一致**(既有 artifacts 非 round) — box→bar 驗證受限於使用者首次實跑；於 goal2.md §10 標明。
- **統計選擇** bar/line 用 p50+p99、mean 留在 xlsx；若偏好 mean 當頭條可輕易切換。
- **實驗四時鐘來源**：優先用 worker 標記 `ts=` 當 `t_recovered`；接受 goal.md 的次秒 wall-clock 雜訊。
- **Linkerd 重試遮蔽**：僅在 abort/rollback 標記出現時才採信 `recovery_sec`；由 `saga_completed`/`rollback_count` + 公平性圖把關。
- **微調迴圈無界**：§5 以明確槓桿 + 停止/升級條件設上限。
