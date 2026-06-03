# goal2 — 實驗一壓測對照 + 實驗四故障恢復對照（執行與分析規格）

> 本文是**執行 + 分析規格**。優化的 WHAT/WHY（Run 1–8 理由、場景矩陣）見
> [`goal.md`](goal.md)；逐步實作計畫見 [`goal2-plan.md`](goal2-plan.md)。
> 程式（圖表/腳本）已建置並提交於 `feat/spiffe-keycloak`；**實際量測由使用者**
> 從 Mac driver 驅動（可發表），Linkerd 側由使用者重部署。

## §0 範圍與分工

| 由誰 | 內容 |
|---|---|
| 已建置（本分支已提交） | 實驗一圖表 box→**Bar+Line**（`analyze-dualmode-experiment.py`）、三方對比 `compare-three-stacks.py`、實驗四 `fault-recovery-common.sh` / `-spire.sh` / `-linkerd.sh` / `analyze-fault-recovery.py` |
| 使用者執行 | 三組壓測 run（A/B/Linkerd）、實驗四 SPIRE 與 Linkerd 兩側 run、Linkerd 重部署 |

**鐵則**：不改 `Sagas/OrderSaga.php`；不弱化三重驗證（`RequestConsumer` JWT、LSVID 鏈、`SpiffeLsvidFilter` re-validate）；新增 env toggle 預設保留現狀；所有圖表**禁用 box，一律 Bar（對比）+ Line（趨勢）**。

---

## §1 目標敘事（假設）

**訂單完成時間** 排序，於每個 scale 的 **warm** round 成立：

```
SPIFFE+Keycloak+LSVID  <  Linkerd 1.x  <  SPIFFE+Keycloak（無 LSVID）
        (A，最快)              (LK)               (B，最慢)
```

- 「較快」的判定 gate = 較低的 `order_completion_ms` **mean**；**p99** 一併報告為尾延遲佐證。
- warm 為頭條數據；cold（重啟後）作為「重啟成本」對照報告。
- 若實測不符 → 進入 §5 微調迴圈。

---

## §2 三組與切換

| 組 | 代號 | 切換方式 | 關鍵 env |
|---|---|---|---|
| SPIFFE+Keycloak+LSVID | A | `docker compose up -d`（compose 預設） | `LSVID_ENABLED=1 LSVID_REQUIRED=1` |
| SPIFFE+Keycloak（無 LSVID） | B | 疊加 `-f docker-compose.override.yml` | `LSVID_ENABLED=0 LSVID_REQUIRED=0 SPIFFE_MTLS_ENABLED=0` |
| Linkerd 1.x | LK | 分支 `feat/Linkerd1`，使用者清 Docker 重部署 `-f docker-compose.yml -f docker-compose.linkerd.yml` | — |

三組皆須 **`PERF_METRIC_ENABLED=1`**（saga 標記才會輸出）。其餘新增 toggle（`AMQP_PREFETCH` 等）一律保持預設，確保 delta 可歸因。

---

## §3 實驗一執行流程（每組一次）

依 goal.md §4 從 Mac 用 `-lan` 別名驅動。每組：

```bash
SHA=$(git rev-parse --short HEAD)
OUT=artifacts/$(date +%Y%m%d-%H%M%S)-${SHA}-lsvidON   # 或 -lsvidOFF / -linkerd

# 1) smoke：先確認單筆訂單走完 Step1→4（看到 ✅ Saga Step 4）
# 2) 壓測（round 後綴 raw 契約：raw/load_<round>_<scale>.csv 等）
SCALES="5000 10000 20000" ROUNDS="warm cold" PERF_METRIC_ENABLED=1 \
  bash scripts/experiments/run-dualmode-distributed.sh "$OUT"

# 3) 逐組分析（預設 barline，禁 box；產 <metric>.png + <metric>_trend.png）
python3 scripts/experiments/analyze-dualmode-experiment.py --in "$OUT" --scales 5000,10000,20000
```

Linkerd 組在 `feat/Linkerd1` 用同一個 `analyze-dualmode-experiment.py`（兩分支共用），產生相同 schema 的 `summary.xlsx`。輸出目錄名嵌 `git rev-parse --short HEAD` 與組別。

> ⚠️ raw 檔名為 **round 後綴**（`load_warm_5000.csv`…）。舊式 `load_5000.csv` 的 artifacts 不相容於此分析器。

---

## §4 三方對比 + 圖表規格

```bash
python3 scripts/experiments/compare-three-stacks.py \
  --lsvid-on  <A的OUT> \
  --lsvid-off <B的OUT> \
  --linkerd   <LK的OUT> \
  --out artifacts/three-way-$(date +%Y%m%d) \
  --round warm --scales 5000,10000,20000          # Linkerd 舊式單sheet → 加 --linkerd-format single
```

每個 metric（Gateway接收請求時間 / 訂單完成時間 / 未完成交易率 / mTLS花費時間）輸出：
- `<metric>_3way.png` — grouped **Bar**，每 scale 三條（序 A=藍 / LK=灰 / B=紅）。
- `<metric>_3way_trend.png` — **Line**，三條序列隨請求數的趨勢。
- `<metric>_3way.xlsx` — mean/p50/p99 表（訂單完成時間含 `ordering_ok`）。
- `comparison_three_summary.xlsx` — `combined` + `ordering_verdict` 兩 sheet。

**頭條檢查**：`ordering_verdict` 每 scale 的 `ordering_ok (A<LK<B)` 全為 True ⇒ §1 假設成立。mTLS 對 Linkerd 為 N/A（sidecar 無 PHP 端樣本），且 A/B 兩組 mTLS-off → 該圖多為 N/A，非焦點。

---

## §5 微調迴圈（若 §1 不成立）

**PASS 條件**：每個 scale 的 warm round `oc_mean(A) < oc_mean(LK) < oc_mean(B)`（即 `ordering_verdict` 全 True）。

**FAIL 處理**（逐步、可收斂）：
1. 從 `ordering_verdict` / `訂單完成時間_3way.xlsx` 找出**哪條不等式、在哪個 scale** 破。
2. 依 goal.md 風格新增一個優化 **Run N**（檔案/位置、改法、零安全損失理由、量測、commit message `perf(<area>): …`），**一個 commit**，僅針對受影響路徑。
3. 只重測受影響的那一組 → 重跑 `analyze-dualmode-experiment.py` → 重跑 `compare-three-stacks.py`。
4. 重複直到 PASS。

**候選槓桿**（依破在哪個 metric/scale 選）：
- `AMQP_PREFETCH`（Run 5 lever）— 吞吐 vs p99；高 scale 完成時間槓桿。
- worker container replica 數 — 併發（單進程同步 consumer，靠 replica 擴展）。
- `SPIFFE_SHM_MAX_SPIN` / `SPIFFE_SHM_SPIN_SLEEP_US`（Run 6 lever）— SVID 讀取 CPU。
- `LSVID_PREP_DEBUG=1` 觀測 prep-cache 命中率（Run 8）；若發現逐請求重建即修。

**停止/升級條件**：若新增 Run 9–11 後 A 仍非最快，停止盲調並重新界定假設（例如限定在特定 scale / 改用 p50 為 gate），與使用者討論，勿無限迴圈。

---

## §6 實驗四執行流程（下游故障恢復）

故障注入在**下游服務容器**（兩套環境注同一容器，唯一差別為傳輸層）。完全不改 OrderSaga，僅用既有 log 標記 + docker/health 輪詢。

**恢復時間定義**：
- `t_fault_clear` = `docker unpause/start` 回傳當下 wall-clock epoch。
- `t_recovered` = 清除後送出的唯一 probe 訂單的 `[perf-saga-complete]` worker `ts=`（以 step1 的 traceId→orderId 對應）。
- `recovery_sec = t_recovered − t_fault_clear`。
- `saga_completed` = 清除後 probe 是否完成（1/0）；`rollback_count` = 故障窗內 `RollbackSaga` 標記數。

**場景矩陣**（每場景掃 `FAULT_DURATIONS="5 30"`）：
1. `step1-transient`：pause production → Step1 abort（`商品資訊查詢失敗`）。
2. `comp-after-recovery`：pause user → Step3 付款失敗 → `RollbackSaga Step 2`。
3. `full-rollback`：送單→等 `Saga Step 3`→pause order → Step4 confirm 失敗（完整 rollback）。
4. `kill-restart`：kill+start production（凸顯 Linkerd sidecar 重解析 vs SPIRE SVID 重抓）。

**執行順序**（兩側分開跑，使用者於兩側間清 Docker + 切分支）：
```bash
# (1) feat/spiffe-keycloak：起 SPIRE stack（PERF_METRIC_ENABLED=1）
OUT=artifacts/fault-recovery-$(date +%Y%m%d-%H%M%S) \
  bash scripts/experiments/fault-recovery-spire.sh        # → recovery_spire.csv

# (2) 使用者清 Docker、checkout feat/Linkerd1、重部署 Linkerd（PERF_METRIC_ENABLED=1）
OUT=<同一OUT> bash scripts/experiments/fault-recovery-linkerd.sh   # → recovery_linkerd.csv
```

**公平性把關**：Linkerd 可能用自身重試遮蔽暫時性故障 → 先確認 abort/rollback 標記確實出現再採信 `recovery_sec`，由 `saga_completed`/`rollback_count` 欄與 §7 完成率圖把關。先 `git show feat/Linkerd1:Sagas/OrderSaga.php | grep perf-saga-complete` 確認標記一致。

**拓撲**：建議單機 local 模式（所有容器同host，恢復量測最乾淨）。分散式則於 `fault-recovery-common.sh` 設 `PROD_HOST/ORDER_HOST/USER_HOST` ssh 別名與真實容器名（每次先 `docker ps` 核對，runner preflight 會印出）。

---

## §7 實驗四圖表規格

```bash
python3 scripts/experiments/analyze-fault-recovery.py \
  --spire <OUT>/recovery_spire.csv \
  --linkerd <OUT>/recovery_linkerd.csv \
  --out artifacts/fault-recovery-cmp-$(date +%Y%m%d)
```

輸出（禁 box）：
- `恢復時間_bar.png` — 每場景 grouped **Bar**，SPIRE vs Linkerd median recovery（**越低越好**）。
- `恢復時間_趨勢.png` — **Line**，隨 `fault_duration` sweep，每場景一子圖。
- `saga完成率_bar.png` — 完成率對比（公平性）。
- `rollback次數_bar.png` — rollback 標記數對比。
- `fault_recovery_summary.xlsx` — `aggregated` + `verdict`（`SPIRE_wins (lower)`）。

**目標**：`verdict` 每場景 `SPIRE_wins=True`（SPIRE 恢復時間贏過 Linkerd）。

---

## §8 驗證

- 三個 Python 檔 AST/import smoke（`pandas`/`matplotlib`/`openpyxl`/`numpy` 已具備）。
- `compare-three-stacks.py` 可對既有 `summary.xlsx` 做 plumbing smoke（三路徑指同一 dir）。
- `bash -n` 三個 shell 腳本。
- 每組壓測前 smoke 一筆訂單看到 `✅ Saga Step 4`。
- 回歸：`composer test:unit` 綠燈；`bash scripts/e2e-failure-modes.sh` 綠燈。

---

## §9 commit / 分支矩陣

| # | commit | 檔案 | 分支 |
|---|---|---|---|
| 1 | `feat(exp1): convert dualmode charts from box to bar+line …` | `analyze-dualmode-experiment.py` | feat/spiffe-keycloak |
| 2 | `feat(exp1): three-stack … comparison analyzer` | `compare-three-stacks.py` | feat/spiffe-keycloak |
| 3 | `feat(exp4): shared fault-recovery harness lib` | `fault-recovery-common.sh` | feat/spiffe-keycloak |
| 4 | `feat(exp4): SPIRE-side fault recovery runner` | `fault-recovery-spire.sh` | feat/spiffe-keycloak |
| 5 | `feat(exp4): Linkerd 1.x fault recovery runner` | `fault-recovery-linkerd.sh` | feat/spiffe-keycloak → **cherry-pick feat/Linkerd1** |
| 6 | `feat(exp4): fault-recovery comparison analyzer` | `analyze-fault-recovery.py` | feat/spiffe-keycloak |
| 7 | `docs(goal): add goal2.md …` | `docs/goal/goal2.md` | feat/spiffe-keycloak |

**跨分支步驟**：在 `feat/Linkerd1`，`git cherry-pick <common-sha> <linkerd-sha>`（commit 3 + 5；皆純新增檔，不擾動運行中 stack）。分析器留在 `feat/spiffe-keycloak`（三方對比在此跑）。

---

## §10 風險

1. **raw 命名不一致**：舊式 artifacts（`load_5000.csv`）不相容 round 後綴分析器；box→Bar+Line 的完整功能驗收落在使用者首次實跑。
2. **統計選擇**：Bar/Line 用 p50+p99（單組分析器）與 mean（三方）；mean 為 §1 gate。如需改 p50 為 gate 可微調。
3. **實驗四時鐘**：`t_recovered` 用 worker 標記 `ts=`、`t_fault_clear` 用 wall-clock epoch；單機同 clock 可比，分散式接受次秒雜訊。
4. **Linkerd 重試遮蔽**：僅在 abort/rollback 標記出現時採信 `recovery_sec`，由 `saga_completed`/`rollback_count` + 完成率圖把關。
5. **容器名漂移**：實驗四容器名以 env 參數化；每次 `docker ps` 核對。
6. **微調迴圈無界**：§5 以明確槓桿 + 停止/升級條件設上限。
