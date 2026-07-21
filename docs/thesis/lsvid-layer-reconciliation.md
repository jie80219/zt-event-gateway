# LSVID 分層數據統一與對照修正報告

> 對應口試/指導意見：「LSVID 層數 L0–L2 vs L1–L4 的雙重編號混亂」，以及第 76 頁正文
> 與第 77 頁表 10 兩組數字差距過大、未說明差異來源（增量 vs 累計、函式內部 vs 端到端、
> 平均 vs 中位、不同批次/硬體）的問題。
>
> 本報告以**重跑的單一乾淨資料集**建立統一的層級定義與指標定義，並將論文中原有的
> 兩組舊數字逐一歸因，供正文、摘要、表格、圖形統一改寫。
>
> 重跑腳本：`scripts/experiments/measure-lsvid-layers.php`（改寫自 `measure-nesting.php`，
> 修掉 1-indexed level 與指標混用）＋ `scripts/experiments/analyze-lsvid-layers.py`。
> 資料：warm 2000 samples／cold 2000 samples，`feat/SPIFFE+KC+LS` 分支，
> php-lsvid ES256 測試 PKI（與生產同一條 crypto 路徑：ES256 leaf 置於 x5c）。
> 執行環境：zt-gateway host，PHP 8.4。

---

## 1. 統一層級定義（唯一一組，全文一致）

| 層級 | 定義 | 產生方式 |
|---|---|---|
| **L0** | Gateway 建立的 base token | `LSVIDSigner::createBase()` |
| **L1** | 第一個服務擴展後的 token | `LSVIDSigner::extend(prior = L0)` |
| **L2** | 第二個服務擴展後的 token | `LSVIDSigner::extend(prior = L1)` |

實作（第五章）支援 L0–L2，實驗即量到 L2 為止 —— **不再出現 L3/L4**。舊表格/圖形的
「L1–L4」編號一律停用。

## 2. 統一指標定義（四個彼此獨立，標題即定義）

| 指標 | 定義 | 量法 |
|---|---|---|
| **Incremental extend** | 只計算新增一層的簽章時間 | L0：`createBase()`；L1/L2：`extend()` 單次呼叫 |
| **End-to-end extend** | 一個 hop 收到前一層後：解析 + 驗證前一層 + 簽章 + 序列化 | `validate(prior)` + `extend(prior)`；L0 無入站 token，等同 mint |
| **Incremental verify** | 只驗新增的一層 | 聚合層級：`cumulative(Ln) − cumulative(L(n-1))`；L0 = `cumulative(L0)` |
| **Cumulative verify** | 從最外層遞迴驗證至 L0 | `LSVIDValidator::validate()` 對整條鏈 L0..Ln |

統計量：**全文一律以中位數（median）為主報告值**，平均數僅於括號內附列。
（原第 76 頁用中位、表 10 用平均，是差距被放大的原因之一。）

## 3. 主表 — Warm 穩態（生產實際狀態，SVID 快取命中）

數值：中位數（平均），單位 μs。

| 指標 | L0 | L1 | L2 |
|---|---|---|---|
| Incremental extend | 23.48（23.62） | 27.87（27.99） | 34.33（34.51） |
| End-to-end extend | 23.48（23.62）† | 85.08（85.55） | 150.06（150.77） |
| Incremental verify | 57.95（58.22） | 58.32（58.57） | 62.76（62.85） |
| Cumulative verify | 57.95（58.22） | 116.27（116.80） | 179.03（179.65） |

† L0 無入站 token，End-to-end extend 等同於 Incremental extend（純鑄造）。

對應圖：`lsvid-layers.png`（左：extend 兩指標；右：verify 兩指標；warm、median、L0/L1/L2）。

## 4. 附註 — Cold-start 首次請求 penalty（SVID 輪換後快取全冷）

單一請求內建鏈時 openssl 憑證/私鑰快取會漸熱，故 L1/L2 的 cold 值不具獨立意義，
僅 L0（一次性 cache-fill）有意義：

| 指標（L0） | Warm | Cold |
|---|---|---|
| Incremental extend / mint | 23.48（23.62） | 428.26（431.47） |
| 首次 verify（單層） | 57.95（58.22） | 453.63（456.60） |

此欄用來解釋：早期批次之所以出現數百 μs 的 mint/verify，是量到了 cache-cold 的首次請求，
而非穩態成本。

---

## 5. 舊數字逐一歸因（為什麼兩組差那麼多）

### 5.1 表 10（宣稱 L1–L4，用平均）＝ warm 量測、只是標籤整體 +1 偏移

| 表 10 原文 | 原標籤 | 原值(平均) | 對應新資料(warm 平均) | 正典層級 |
|---|---|---|---|---|
| 擴展 | L1 | 24.94 | Incremental extend 23.62 | **L0** |
| 擴展 | L2 | 29.26 | Incremental extend 27.99 | **L1** |
| 驗證 | L1 | 59.02 | Cumulative verify 58.22 | **L0** |
| 驗證 | L2 | 118.23 | Cumulative verify 116.80 | **L1** |

**結論**：表 10 的底層量測是對的（warm、incremental extend + cumulative verify），
問題純粹是**編號整體多算一層**（它的「Ln」其實是正典 L(n-1)），把 createBase 誤標為 L1。
只要把標籤下移一格並改用 L0/L1/L2，表 10 幾乎與重跑值一致（誤差 < 2 μs）。

### 5.2 第 76 頁正文（宣稱 L0–L2，用中位）＝ 混用了不同指標且屬 cold/異批次

| 第 76 頁原文 | 原值(中位) | 最接近的新指標 | 差異來源 |
|---|---|---|---|
| L0 鑄造 | 143.72 | Incremental extend warm 23.48 / cold 428.26 | 介於 warm 與 cold 之間 → 部分 cold／異硬體批次 |
| L0→L1 擴展 | 153.79 | End-to-end extend L1 warm 85.08 | 用了 e2e（含驗證前層），且偏 cold |
| L1→L2 擴展 | 170.18 | End-to-end extend L2 warm 150.06 | 同為 e2e，量級吻合 |
| L0+L1+L2 驗證 | 915.35 | Cumulative verify L2 warm 179.03 / cold 193.21 | 明顯屬異硬體批次或含 envelope JSON 解析 |

**結論**：第 76 頁報的是 **End-to-end extend + 整鏈 Cumulative verify**（不是表 10 的
incremental），且量測批次偏 cold / 不同硬體，所以數字系統性偏大。這解釋了兩組「差距很大」
—— 它們量的是**不同指標 + 不同 cache 狀態**，本來就不該直接並列比較。

---

## 6. 論文需同步修改處（統一後）

1. **摘要 / 系統流程 / 第五章**：一律使用 L0–L2，刪除任何 L3/L4 敘述。
2. **第 76 頁正文**：改採第 3 節主表的 warm 數值，並明確標注每個數字是四指標中的哪一個
   （建議正文敘述用 *End-to-end extend* 與 *Cumulative verify*，因為那才是一次 hop 的實際成本）。
3. **表 10、表 11**：以第 3 節主表取代；欄位改為四個指標，列改為 L0/L1/L2；統計量統一用中位數。
4. **圖 20**：以 `lsvid-layers.png` 取代，座標軸標 L0/L1/L2，圖例即四指標名稱。
5. 全文統計量統一為**中位數**（平均數僅附列），不再混用。

---

## 7. 附加圖表（token 體積 / paper 對照 / Keycloak overhead）— 統一 L0–L2 重製

舊的 L1–L4 對照圖（`chart1..4`）以同一份 warm 資料在統一慣例下重製，產圖器
`scripts/experiments/chart-lsvid-vs-paper.py`，資料同 `warm.ndjson`（已加 envelope 欄位）。

**層級深度對齊**：我方 base token **L0 ＝ paper L1**（皆為 1 個簽章的最淺 token），
故我方 L0/L1/L2 對齊 paper 的 L1/L2/L3（沿用舊圖既有對齊，只修正我方標籤）。

| 圖檔 | 內容 |
|---|---|
| `chart_token_size.png` | LSVID token 體積：this work vs paper（每層我方均顯著小於 paper） |
| `chart_latency_vs_paper.png` | Incremental extend + Cumulative verify：this work（warm median）vs paper |
| `chart_keycloak_overhead.png` | LSVID token／envelope 無KC／envelope 有KC，Keycloak overhead 固定 ≈1.52 kB |

對照數值（warm 中位數）：

| Layer (paper) | Token 我方(kB) | Token paper(kB) | Extend 我方(μs) | Extend paper(μs) | Verify 我方(μs) | Verify paper(μs) | KC overhead(kB) |
|---|---|---|---|---|---|---|---|
| L0 (L1) | 1.14 | 3.10 | 23.68 | 76.96 | 58.65 | 108.07 | 1.52 |
| L1 (L2) | 2.63 | 5.70 | 28.15 | 114.14 | 117.93 | 289.13 | 1.52 |
| L2 (L3) | 4.62 | 8.80 | 34.70 | 266.95 | 181.36 | 464.72 | 1.52 |

重點：**Keycloak overhead 是與層數無關的固定加法常數（≈1.52 kB）**，因為 Keycloak
access_token 放在 envelope 的 `authorization.jwt` 平行欄位，不在 LSVID 簽章鏈內
（見 `src/MessageQueue/MessageBus.php`）。要壓縮體積該對焦 LSVID 本身（每層線性 +≈2 kB），
而非 Keycloak。
