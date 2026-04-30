# SPIFFE/SPIRE 安全性實測與視覺化分析

本文件描述 zt-event-gateway 專案的**零信任安全層實測框架**：威脅模型、攻擊矩陣、實驗方法、結果解讀、論文引用建議。與 `docs/experiment-comparison-targets.md`（效能視角）互補。

---

## 1. Context

既有的 `artifacts/experiment-*` 圖表已證明「加上零信任層只增加約 10% p99 延遲」。本實驗要回答另一個問題：

> **這 10% 的延遲代價實際上擋下了哪些攻擊？擋不下哪些？**

輸出：機器可讀 JSON（供論文表格）+ 8 張 PNG（供論文章節圖）。

---

## 2. 威脅模型

**攻擊者能力假設**（from `scripts/security-suite/lib/attack_client.php`）：

- 可直接對 RabbitMQ broker 發 AMQP 訊息（modeling compromised internal service）。
- 可取得自己的 SPIRE-issued SVID（合法 domain 成員但試圖越權）。
- 可取得完整合法 LSVID 的 raw token（試圖 replay）。
- **不能** 偽造 SPIRE CA 簽發的 X.509（CA 私鑰不外洩）。
- **不能** 直接存取目標服務的本機 `/tmp/spiffe-shared/` — 但 `S01` case 主動違反此假設以量化 worst-case。

**防禦層（by code）**：

| Layer | 檔案 | 檢查 |
|-------|------|------|
| Ingress | `bin/gateway.php` | 簽發 L0；讀 SHM 確認 SVID ready |
| RequestConsumer | `src/Worker/RequestConsumer.php:147-160` | SPIFFE 前綴、envelope 結構、L0.sub 一致 |
| LSVIDValidator | `packages/php-lsvid/src/LSVID/LSVIDValidator.php:78-177` | 簽章、trust domain、audience、chain、expiry、nbf |
| JtiReplayCache | `packages/php-lsvid/src/LSVID/JtiReplayCache.php` | JTI 去重 |
| Downstream mTLS | `src/Spiffe/TLS/SpiffeTlsContext.php` | X.509-SVID 客戶端憑證 |
| SHM seqlock | `src/Spiffe/SharedMemory/SpiffeTableStore.php` | 原子更新 + reader spin |

---

## 3. 攻擊矩陣（22 cases，7 大類）

完整清單見 `scripts/security-suite/lib/attack_client.php::caseMetadata()`，以下為速查：

| Category | 案例 | 攻擊描述 |
|----------|------|----------|
| **token-forgery** | F01 / F02 / F03 / F04 | header 竄改（alg=none）/ payload 竄改 / 簽章替換 / 缺 LSVID |
| **trust-domain** | T01 / T02 / T03 | 外域 SPIFFE ID / 未授權 workload / envelope 與 L0 不一致 |
| **chain-attack** | C01 / C02 / C03 | 斷鏈 nested.aud≠iss / 跳級 / 中間層缺 aud |
| **time-attack** | E01 / E02 / E03 | 過期 / 未來 nbf / clock skew 邊界（控制組，應接受） |
| **replay** | R01 / R02 | 同 JTI 二次送（序列 / 並發） |
| **mtls** | M01 / M02 / M03 | 下游無客戶端憑證 / 外域憑證 / 旋轉競態 |
| **amqp-inject** | Q01 / Q02 | 直接 AMQP publish 偽造 envelope / 偽造 rollback 事件 |
| **shm-tamper** | S01 / S02 | 本機竄改 SHM / seqlock 停留奇數版本 |
| **baseline** | HAPPY | 合法請求（控制組，應接受） |

每個 case 在 4 profiles（A/B/C/D）下各跑一次，總計 **22 × 4 = 88 records**。

---

## 4. Profile 對照

| Profile | LSVID_ENABLED | LSVID_REQUIRED | SPIFFE_MTLS_ENABLED | 預期安全姿態 |
|---------|:---:|:---:|:---:|------|
| **A-baseline** | 0 | 0 | 0 | 幾乎所有攻擊會通過（證明安全層的必要性） |
| **B-mtls-only** | 0 | 0 | 1 | token 層攻擊全通過，但連線層受保護 |
| **C-lsvid-only** | 1 | 1 | 0 | token 層攻擊被擋，但無連線層防護 |
| **D-full-zt** | 1 | 1 | 1 | 所有攻擊都應被擋（論文主張的目標架構） |

---

## 5. 實驗方法

### 5.1 執行

```bash
# 1) 啟動完整 stack
docker compose --profile zt up -d
scripts/verify-spire-integrity.sh

# 2) 跑完整 suite（10-15 分鐘）
composer security:suite
# 或單跑 unit 層做快速煙霧測試：
composer security:unit

# 3) 產出 8 張 PNG
python3 -m venv .venv
.venv/bin/pip install -r scripts/security-suite/plot/requirements.txt
composer security:plot
```

### 5.2 Stage 分工

| Stage | 檔案 | 測試層 | 輸出 |
|-------|------|--------|------|
| 1 | `stages/stage1-unit-matrix.sh` | 進程內 LSVIDValidator | `unit-results.json` |
| 2 | `stages/stage2-e2e-http.sh` | HTTP → Gateway → Worker | `http-attacks.json` |
| 3 | `stages/stage3-amqp-inject.sh` | 直連 RabbitMQ + SHM 探測 | `amqp-attacks.json` |
| 4 | `stages/stage4-rotation-race.sh` | mTLS + SVID rotation | `rotation-race.json` |

彙整：`run-security-suite.sh` 最後用 Python 把所有 JSON 聚合成 `security-summary.json`。

### 5.3 資料 Schema

每一筆 case record：

```json
{
  "case_id": "F02",
  "category": "token-forgery",
  "description": "Payload tampering: sub/aud rewrite",
  "layer_expected": "LSVIDValidator",
  "profile": "D-full-zt",
  "attempt": {
    "sent_at": "2026-04-22T12:34:56.789Z",
    "request_kind": "http",
    "payload_sha256": "..."
  },
  "outcome": {
    "status": "rejected",
    "is_expected": true,
    "http_code": 403,
    "amqp_ack": false,
    "rejected_by": "LSVIDValidator",
    "reject_reason": "signature_verification_failed",
    "exception": "SDPMlab\\LSVID\\LSVIDException",
    "detect_latency_us": 423
  },
  "log_snippet": "[request-consumer] Invalid inbound LSVID: ..."
}
```

---

## 6. 視覺化圖表

`scripts/security-suite/plot/plot_security.py` 產出 8 張圖到 `artifacts/security-{STAMP}/experiment-png/`：

| # | 檔名 | 類型 | 用途 |
|---|------|------|------|
| 1 | `01-attack-matrix.png` | heatmap | case × profile 綠/紅總覽 |
| 2 | `02-defense-by-layer.png` | stacked bar | 每 profile 下各層攔截數 |
| 3 | `03-reject-reason-taxonomy.png` | horizontal bar | 拒絕原因分類 |
| 4 | `04-detect-latency-by-category.png` | boxplot (log) | 偵測延遲分佈 |
| 5 | `05-profile-security-posture.png` | radar | 5 軸安全覆蓋率 |
| 6 | `06-rotation-race-timeline.png` | scatter | rotation 前後 request 狀態 |
| 7 | `07-amqp-bypass-outcome.png` | grouped bar | AMQP 注入在各 profile 表現 |
| 8 | `08-security-vs-perf-tradeoff.png` | scatter | 安全覆蓋率 vs p95 延遲（交叉引用 `ablation-summary.json`） |

---

## 7. 結果解讀建議（論文寫作提示）

1. **圖 1（attack-matrix）** 是論文的「rhetorical anchor」— 一眼可見 D-full-zt 全綠、A-baseline 大片紅。放在結果章節最前面。
2. **圖 5（radar）** 是 defense-in-depth 論證的核心 — 顯示單獨開 mTLS 或單獨開 LSVID 都有盲區。
3. **圖 8（tradeoff）** 回應 reviewer 常見問題「10% 代價是否值得」— 用覆蓋率百分比直接對應代價百分比。
4. **圖 6（rotation race）** 是本架構相較 sidecar（Istio/Linkerd）的獨特賣點 — 因為 SHM seqlock 讓 rotation 不需重連 proxy，race window 很小。
5. **圖 3（taxonomy）** 可以用來論證 LSVIDValidator 的豐富性（>8 類不同 reject reason，表示驗證多面向）。

---

## 8. 已知設計限制（結果必然暴露，不是 bug）

| 限制 | 在哪個 case 暴露 | 建議寫法 |
|------|-----------------|----------|
| SPIFFE ID 是 prefix 允許清單，非 per-service ACL | T02 在 C/D profile 應該被擋（依 audience），但若 validator 沒設對 audience，會變成已知漏洞 | Future work：per-service ACL |
| 下游 service 可能沒強制 `require_and_verify_client_cert` | M01 | 如果 M01 status=accepted，論文應列為 future work，不要偽裝成「通過」 |
| SHM 無密碼學完整性 | S01 | 明寫「威脅模型假設宿主機不可存取；S01 是 worst-case reference」 |
| Saga 沒有自動補償 | （此框架未測）| 另寫 chapter（`docs/thesis/ch4-resilience.md`） |

---

## 9. 可重現性

- `attack_client.php` 使用 `srand(20260422)` 固定偽造 payload 的 trace_id — 同 STAMP 下重跑得一樣的 hash。
- 偵測延遲會有噪音（±20-30%），建議同一 profile 內多跑幾次取中位數（目前每 case 一次，足夠做相對比較）。
- `scripts/verify-spire-integrity.sh` 在 suite 前後各跑一次，確認 SPIRE stack 狀態未被測試破壞。

---

## 10. 相關檔案速查

```
scripts/security-suite/
├── run-security-suite.sh      # 總驅動
├── stages/
│   ├── stage1-unit-matrix.sh
│   ├── stage2-e2e-http.sh
│   ├── stage3-amqp-inject.sh
│   └── stage4-rotation-race.sh
├── lib/
│   ├── attack_client.php      # case → forged payload
│   ├── rabbit_inject.php      # direct AMQP publish
│   └── run_unit_matrix.php    # in-process LSVIDValidator driver
└── plot/
    ├── plot_security.py       # matplotlib/seaborn renderer
    └── requirements.txt

artifacts/security-{STAMP}/
├── A-baseline/{unit,http,amqp}-*.json
├── B-mtls-only/...
├── C-lsvid-only/...
├── D-full-zt/...
├── security-summary.json      # aggregated
└── experiment-png/            # 8 PNGs

docs/
├── security-experiment.md     # this file
├── experiment-comparison-targets.md  # perf companion
└── lsvid-experiment.md        # LSVID micro-bench
```
