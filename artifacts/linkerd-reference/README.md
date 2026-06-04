# Linkerd 1.x 參考數據（使用者提供，本輪不重跑）

來源：使用者 Mac `/Volumes/ImportantStuffSMB/楊傑智/實驗/compare-3way-20260603-133800/`
（本機無法存取 SMB，故由使用者手動貼入）。供 `compare-three-stacks.py` 三方合併使用。

## 檔案與單位（⚠️ 兩份單位不同，合併時須統一）

| 檔案 | metric | 對應圖 | 單位 | 提供統計 |
|---|---|---|---|---|
| `gateway_ingress_linkerd.csv` | Gateway 接收請求時間 | compare-gateway-ingress.png | **秒 (s)** | count/mean/p50/p90/p95/p99/max |
| `order_completion_linkerd.csv` | 訂單完成時間 | compare-order-completion.png | **毫秒 (ms)** | p50/p99（無 mean） |

## 合併注意

- A/B 端（measure / analyze-dualmode）gateway 與 saga 延遲都是 **ms**。
  → 合併前須把 `gateway_ingress_linkerd.csv` 的秒值 ×1000 轉成 ms，才能與 A/B apples-to-apples。
- 訂單完成時間只給 p50/p99，**無 mean**。goal2 §1 的排序 gate 原本用 `oc_mean`；
  若沿用此 Linkerd 數據，排序比較需改以 **p50** 為 gate（goal2 §10 風險 2 已預留此微調空間）。
- scale = 5000 / 10000 / 20000，warm round。
