# 補強比較對象與 E2E 測試缺口

## Context

本專案已在 `docs/experiment-comparison-targets.md` 建立 5 個比較對象（自身 ablation、HPE-USP-SPIRE、Istio、Linkerd、Eventuate Tram），並於 `docs/thesis/ch5-evaluation.md` 完成 7-phase E2E + 22 × 4 安全矩陣的實驗協定。`ch5-evaluation.md §5.6 威脅與侷限` 明列 6 項未涵蓋面向（單機測試、DoS、side-channel、CA 妥協、mTLS 實作缺口、微觀基準為行程內量測），此外 Phase-1 探勘也發現若干 E2E 空缺（SPIRE Agent kill、AMQP 全面停機、SHM seqlock writer 競態、EventStoreDB 失聯、24h soak、cold-start、尾端 p999 等）。

本計畫**不重寫** 既有比較文件；而是產出一份「增補清單」，分為兩塊：
- **Part A：比較對象補強** — 找出現有 5 個對象之外、能補齊論文定位（尤其 LSVID vs 同級技術、ZT 架構同級系統）且具**可引用的公開數據**或**可本機自建對照**的目標。
- **Part B：E2E 測試缺口** — 依使用者選擇，聚焦於「非功能效能／延遲」、「安全攻擊矩陣補遺」、「韌性與混沌」三大桶，列出具備可執行腳本線索的測試項目。

產出定位：一份 `docs/comparison-and-e2e-gaps.md` 的草案藍本（本計畫為規劃階段，不實寫 docs）。論文章節可在 §5.5.3 與 §5.6 引用。

---

## Part A — 比較對象補強清單

### A.1 LSVID / 身份代幣層（填補「為何不用 JWT-SVID / 一般 capability token」的缺口）

既有文件只有 HPE-USP-SPIRE（同族 Go 實現）。以下是**論文必須正面回答的對照**：

| # | 比較對象 | 類別 | 為什麼補 | 引用數據 | 可自建對照 |
|---|----------|------|----------|----------|-----------|
| A.1.1 | **SPIRE 原生 JWT-SVID** | 同族標準代幣 | 最直接的反問：「為何不用 SPIRE 原生 JWT-SVID 就好？」需量化 LSVID 相對 JWT-SVID 的鏈式驗證開銷 vs 安全增益 | SPIFFE JWT-SVID spec（IETF draft）；無第三方 benchmark | ✅ 已在專案內（packages/php-spiffe 已有 JwtSource） — 可改跑一組「JWT-SVID-only」profile |
| A.1.2 | **Macaroons**（Google, 2014） | Capability token with attenuation/delegation | LSVID 巢狀 + audience 衰減的概念前驅；論文應定位 LSVID 相對 Macaroon 的差異（x.509 綁定 vs HMAC 鏈）| Birgisson et al. NDSS 2014 原論文有 HMAC 衍生延遲數據 | ⚠️ libmacaroons C/Go/Python 有，PHP 需自行包裝（不建議做 end-to-end，僅做微觀對照） |
| A.1.3 | **Biscuit**（CloudFlare 支持，現代 Macaroon） | Capability token + datalog policy | 2023 後學界新標竿；支援 attenuation/delegation 鏈、無狀態驗證；用來回答「為何不直接用 Biscuit」| Biscuit spec v3 + biscuit-rust benchmark（驗章 ~50 μs）| ✅ biscuit-rust CLI 可直接在 Docker 跑；僅做 mint/verify 微觀對照（1000 ops 對照） |
| A.1.4 | **SPIFFE JWT-SVID + OPA** | 代幣 + 外部授權 | 業界「不用巢狀」的替代方案：JWT-SVID 帶到下游，交 OPA/Rego 做鏈式授權。論文需說明為何 in-token chain（LSVID）優於 out-of-band policy | OPA 官方 benchmark（policy decision < 1 ms）| ✅ 可跑 openpolicyagent/opa Docker + JWT-SVID 做決策路徑對照 |

**論文落點**：章節 §5.5.3「與現有方案的比較」目前只有 Istio/Linkerd/Eventuate 三欄，建議擴充至含 JWT-SVID-only、Biscuit、Macaroon 的代幣層 trade-off 表。

### A.2 ZT 網路架構同級（填補 Service Mesh 以外的替代路線）

既有文件有 Istio、Linkerd（兩者皆 sidecar）。以下是**同樣做 app-level 或 agentless ZT** 的對照：

| # | 比較對象 | 類別 | 為什麼補 | 引用數據 | 可自建對照 |
|---|----------|------|----------|----------|-----------|
| A.2.1 | **Envoy + SPIRE SDS**（獨立，非 Istio） | 無控制面的 sidecar | 去除 Istio control-plane 開銷；單一 Envoy + SPIRE SDS 的輕量 baseline | envoyproxy/envoy SDS 文件；無完整 paper 但 CNCF blog 有局部 benchmark | ✅ docker-compose 可起，對照單點 Envoy 延遲 |
| A.2.2 | **OpenZiti**（NetFoundry） | App-level ZT overlay | 最貼近本專案「無 sidecar、內嵌身份」路線；架構同級 | OpenZiti 官方 benchmark（~20 % overhead claimed）；arXiv 未見 | ✅ openziti/ziti-controller + ziti-router Docker 可起 |
| A.2.3 | **Consul Connect + Envoy** | Service Mesh（HashiCorp）| 與 Istio 架構不同但定位同級的業界 baseline；HashiCorp 有自己的 SPIFFE 整合 | Consul Connect docs 有 throughput 數據 | ✅ hashicorp/consul Docker |
| A.2.4 | **Dapr Sidecar（mTLS + Saga + Pub/Sub）** | 最整體貼近本系統 | Dapr 同樣做 mTLS + Pub/Sub + Saga（workflow building block），是「把本系統用 Dapr 重寫」的對照 | Dapr 官方 perf benchmarks（p99 sidecar overhead ~4 ms） | ✅ daprio/daprd + RabbitMQ pubsub component；最適合完整 Saga 對照 |

**論文落點**：§5.5.3 多一列「方案路線（sidecar / 無 sidecar / 內嵌）」軸；§5.2.3 的 mTLS-only vs LSVID-only 分離成本可加入 Envoy+SDS 作 baseline。

### A.3 Saga / 分散式交易層（既有 Eventuate Tram 不足以單獨代表業界）

| # | 比較對象 | 類別 | 為什麼補 | 引用數據 | 可自建對照 |
|---|----------|------|----------|----------|-----------|
| A.3.1 | **Temporal.io** | Workflow-as-code Saga | 業界目前 Saga 最強的標竿（Uber Cadence 分出來）；需說明本系統為何仍走 orchestration + RabbitMQ | Temporal 官方 benchmark；ACM SoCC 2024 有比較論文 | ✅ temporalio/temporal Docker |
| A.3.2 | **Axon Framework + Axon Server** | Event-sourcing + Saga（JVM）| 與本系統 `prooph/event-store` 最同族；Prooph 在 PHP 界地位類似 Axon 在 JVM | Axon 官方文件；無公認 benchmark paper | ✅ axoniq/axonserver Docker + Spring Boot demo |
| A.3.3 | **Apache Kafka + Kafka Streams Saga pattern** | 事件串流 Saga | Eventuate Tram 底下也是 Kafka；此項做成「去除 Eventuate 框架的純 Kafka」baseline，凸顯框架成本 | Confluent performance whitepaper | ✅ confluentinc/cp-kafka |

**論文落點**：§5.5.3 的「Saga 效能」欄位從單一 Eventuate 擴為三列（Temporal / Axon / pure-Kafka），確認本系統延遲基線不是 RabbitMQ 本身造成。

### A.4 事件佇列與 SHM 身份遞送（全新軸，既有文件未觸及）

本系統之 `spiffe-watcher` + SHM seqlock 是一個罕見的設計決策，需有對照。

| # | 比較對象 | 類別 | 為什麼補 | 引用數據 | 可自建對照 |
|---|----------|------|----------|----------|-----------|
| A.4.1 | **SPIFFE Helper**（spiffe/spiffe-helper） | 寫入檔案的 sidecar | 業界最常見的「非 mesh」SVID 遞送方式；對照 SHM vs 檔案寫入的 I/O 成本 | 無 paper；可自行量測 | ✅ spiffe/spiffe-helper Docker 可搭本專案 SPIRE Agent |
| A.4.2 | **Envoy SDS（Secret Discovery Service）**| gRPC stream 直接餵給 proxy | 對照 SHM vs gRPC-stream 的 rotation 延遲 | Envoy docs | ✅ 已在 A.2.1 涵蓋 |
| A.4.3 | **HashiCorp Vault Agent（auto-auth + template）** | Vault 取憑證寫檔 | 非 SPIFFE 路線但目的相同的 credential delivery 對照 | Vault 官方 latency SLO 文件 | ✅ hashicorp/vault agent mode |

**論文落點**：新增 §5.3.7「SVID 遞送機制對照」子節，量測三種遞送方式的 rotation latency（從 SPIRE 頒發新 SVID 到應用真正用上的端對端時間）。

---

## Part B — E2E 測試缺口清單（非功能／安全／韌性）

> 使用者已排除「功能正確性」，故 Saga happy path、envelope 正規化等既有 E2E 覆蓋項目不在此清單。
> 每項標註 **[既有資產]** = 已有類似腳本可擴充，或 **[新建]** = 需從零寫。

### B.1 非功能 — 效能／延遲缺口

現有 Phase 7 覆蓋：單請求 p50/p95/p99（30 樣本）+ 100 req 併發 5 壓測 + Saga 端到端。缺口：

| # | 項目 | 既有 7-phase 是否覆蓋 | 補強理由 | 預期腳本 |
|---|------|:------:|----------|----------|
| B.1.1 | **冷啟動延遲**（container up → 第一筆 200）| ❌ | SHM 未就緒 503 與 trust bundle cold cache（§5.3.6 的 215 μs cold vs 183 μs warm）之端到端影響 | **[新建]** `scripts/e2e-coldstart.sh`：docker compose up → poll /api/health → 連發 50 筆紀錄 p1..p50 |
| B.1.2 | **尾端延遲 p999**（10k 樣本）| ❌（僅 100 樣本）| 論文宣稱 p99 下降（§5.2.1），需 10k 樣本才可信；統計上 100 樣本的 p99 誤差過大 | **[既有資產]** 擴充 `scripts/stress_test.sh` REQUESTS=10000 |
| B.1.3 | **高併發 scaling（50/100/200/500 clients）** | ❌（僅 5 併發）| 目前 RPS 102–110 的瓶頸來源不明；需做併發 sweep 找到 knee point | **[既有資產]** `stress_test.sh` 多跑幾組 CONCURRENCY |
| B.1.4 | **持續負載 soak test（30 min / 4 h）**| ❌ | 驗證 JTI cache GC（§5.6.2）、coroutine leak、AMQP channel exhaustion | **[新建]** `scripts/e2e-soak.sh`：持續低速（10 rps × 30 min）+ `docker stats` 紀錄 RSS/CPU |
| B.1.5 | **資源消耗 per profile（CPU / RSS / FD）**| ❌ | ch5 §5.5 宣稱 trade-off 合理但沒量測成本軸；論文需要 CPU% vs p50 的二維圖 | **[新建]** soak 期間並行 `docker stats --no-stream` 抽樣 |
| B.1.6 | **LSVID 鏈長 sweep（L0..L5）**| ❌ | §5.3 只做 L0→L2；nested 成長公式需更多點驗證線性假設 | **[既有資產]** 擴充 `tests/benchmark-lsvid.php` 延長 chain depth |
| B.1.7 | **簽章演算法 sweep（ES256/ES384/RS256/RS512）**| ❌ | §5.3.5 是理論外推，未實測；需改 SPIRE CA key type 後重跑 | **[新建]** `scripts/bench-alg-sweep.sh` |
| B.1.8 | **實際頻寬量測**（§5.5.2 是估算）| ❌ | 7.8 MB/s 是換算值；需實測 RabbitMQ Prometheus `rabbitmq_queue_messages_bytes` | **[既有資產]** 7-phase 加 RabbitMQ metrics 抽樣 |
| B.1.9 | **Trust bundle 大小對驗證延遲**（1 / 10 / 100 CA）| ❌ | §5.3.6 只在單 CA 下測；federation 場景 bundle 會變大 | **[新建]** 塞入 n 個 dummy CA，量 validate μs |

### B.2 安全攻擊矩陣補遺（對應 §5.6.2、§5.6.3、§5.6.4）

現有：22 cases × 4 profiles = 88 筆。§5.6 明列未涵蓋者：

| # | 攻擊類別 | 案例 | 為何補 | 預期腳本 |
|---|----------|------|--------|----------|
| B.2.1 | **DoS — 偽造 token 洪水** | D01：對 `/api/orders` 丟 1000 rps 之 F03（簽章替換），量 LSVIDValidator CPU | §5.6.2 明列空缺；論文需回答「攻擊者能否用偽造 token 拖垮驗證器」| **[新建]** `scripts/security-suite/stages/stage5-dos.sh` |
| B.2.2 | **DoS — JTI cache 記憶體耗盡** | D02：每秒發 100 個**合法**新 token，連續 10 分鐘，量 JTI cache RSS | JTI cache TTL bucket gc 行為未實測；§5.6.2 點名 | **[新建]** 同 stage5 |
| B.2.3 | **ECDSA 驗章 timing side-channel** | D03：量同一 validator 對「有效簽章」vs「無效簽章」的 μs 差 | §5.6.3 空缺；需確認 openssl_verify 為 constant-time | **[新建]** `tests/timing-sidechannel.php` 做 Welch's t-test |
| B.2.4 | **x5c DER 畸形輸入 fuzz** | D04：AFL/libfuzzer style 隨機 DER 打 parser | §5.6.3 空缺；驗證無 crash / memory corruption | **[新建]** `scripts/security-suite/stages/stage6-fuzz.sh` 用 radamsa/zzuf |
| B.2.5 | **CA 妥協模擬** | D05：竄改 SPIRE Server 之 trust bundle，確認舊 token 在新 bundle 下被拒 | §5.6.4 空缺；真實世界 incident response 必備驗證 | **[新建]** 注入假 CA → rotate → 驗舊 token 被 reject |
| B.2.6 | **跨 trust-domain federation 攻擊** | D06：外部 `spiffe://attacker.local/*` 持有效本域 CA 簽的 token | 論文宣稱「zt.local trust domain 檢查」但未測跨域身份 | **[新建]** 用 SPIRE federation 起兩個 domain |
| B.2.7 | **混淆代理（confused deputy）** | D07：order-service 以自己的 SVID 呼叫 user-service 應拒 | audience 檢查的直接驗證；目前只測攻擊者外部偽造 | **[既有資產]** 擴 stage3-amqp-inject.sh |
| B.2.8 | **TTL 邊界競態** | D08：token exp = now + 50 ms，發送延遲 ~40 ms，驗 validator 判定 | LSVID TTL 1800 s 下難觸發；縮 TTL 到秒級專測 | **[新建]** `scripts/e2e-ttl-race.sh` |
| B.2.9 | **並發重放 R02 擴大**（100 thread）| 現有 R02 規模小 | JTI cache atomic CAS 在高併發下的正確性 | **[既有資產]** stage3 擴 thread 數 |
| B.2.10 | **訊息佇列毒訊息**（持久化畸形訊息）| Q03：發送 payload 使消費者 crash → 重啟 → 再次拉到同訊息 → 再 crash | 檢測是否有 DLX 或 max-retry 防 loop | **[新建]** 加到 stage3 |
| B.2.11 | **Trust bundle rotation 飛行中** | S03：validator A 正在驗 L2，同時 bundle 被 rotate | §5.4.4 shm-tamper 有觸及但未測驗證中途被換 | **[新建]** SHM writer 與 consumer 並行 |
| B.2.12 | **Disk full 導致 SHM 寫失敗** | S04：`/tmp/spiffe-shared` 容器填滿，觀察 spiffe-watcher 行為 | 既有 S02 只測 odd version 停留，未測 writer 根本寫不進 | **[新建]** `tmpfs size=1M` 觸發 |

### B.3 韌性與混沌（§5.6.1 單機測試之延伸）

| # | 混沌注入 | 既有 Phase 6 是否覆蓋 | 補強理由 | 預期腳本 |
|---|----------|:------:|----------|----------|
| B.3.1 | **SPIRE Agent kill** | ❌（只測 worker 重啟）| Agent 崩潰後，已緩存的 SVID 應繼續可用直到 TTL；需驗證 graceful degradation | **[新建]** `scripts/chaos/kill-spire-agent.sh`：TTL 內持續打 → 確認仍 200；TTL 到期 → 確認轉 503 |
| B.3.2 | **SPIRE Server kill** | ❌ | Server 斷線時 Agent 應以緩存對外服務；驗證控制面獨立於資料面 | **[新建]** 同上 |
| B.3.3 | **RabbitMQ 完全停機**（不是 reconnect）| ⚠️（只測連線抖動）| Gateway 應 fail-fast 返 503 而非 timeout；需驗證 AMQP connection loss handler | **[新建]** `scripts/chaos/rmq-outage.sh` |
| B.3.4 | **EventStoreDB 失聯中途** | ❌ | Saga 途中 EventStore 斷線 → 確認事件不丟、恢復後續作 | **[新建]** `scripts/chaos/eventstore-outage.sh` |
| B.3.5 | **SHM seqlock writer 崩潰於奇數版本** | ❌ | §5.4.4 S02 只測 reader 重試；未測 writer 卡死導致永久 odd | **[新建]** gdb attach → stop spiffe-watcher mid-write |
| B.3.6 | **24 h soak（含隨機 chaos）** | ❌ | 記憶體洩漏、fd leak、channel exhaustion 需長時間才能觀察 | **[新建]** `scripts/chaos/soak-24h.sh`：每 10 min 隨機注入 B.3.1~B.3.5 一項 |
| B.3.7 | **網路分區**（Docker network disconnect）| ❌ | split-brain：Gateway 看得到 RMQ 但看不到 SPIRE；混合故障情境 | **[新建]** `docker network disconnect` |
| B.3.8 | **時鐘偏移 ±30s / ±300s** | ❌ | LSVID exp/nbf 對系統時鐘敏感；容器時鐘偏移後行為 | **[新建]** `faketime` 或 libfaketime 注入 |
| B.3.9 | **檔案描述符耗盡** | ❌ | OpenSwoole coroutine 下 FD 耗盡行為；驗證有無 panic vs graceful | **[新建]** `ulimit -n 64` 起 gateway 壓測 |
| B.3.10 | **滾動重啟中持續負載** | ⚠️（只測單次 restart）| 連續 restart 多次 + 期間持續壓測；驗證無訊息丟失 | **[既有資產]** Phase 6 test 21 擴 N 次 |
| B.3.11 | **SVID 緊臨到期時的請求**（TTL − 100 ms）| ❌ | rotation 與 request 競態；既有 M03 併發 40 筆但未控時機 | **[新建]** 精確控時，在 SHM 版本翻轉前後各發 10 筆 |

---

## 優先順序建議（論文影響力 vs 工作量）

| 層級 | 建議先做 | 理由 |
|------|----------|------|
| **P0 — 論文必答** | A.1.1 (JWT-SVID-only profile)、B.1.2 (10k p999)、B.2.1 (DoS)、B.2.5 (CA 妥協)、B.3.1~B.3.4 (基本混沌四件套) | 直接補 §5.6 明列缺口，無這些無法出審 |
| **P1 — 強化定位** | A.2.4 (Dapr 對照)、A.3.1 (Temporal 對照)、B.1.4 (soak)、B.1.7 (alg sweep) | 強化「方案路線」與效能宣稱的可信度 |
| **P2 — 加分項** | A.1.3 (Biscuit)、A.4 (SVID 遞送對照)、B.2.3 (timing side-channel)、B.2.4 (fuzz) | 展現對學界最新工作之掌握 |

---

## 需要修改／新增的檔案路徑

新增（規劃階段尚未寫）：

- `docs/comparison-and-e2e-gaps.md`（本計畫內容的正式版，供論文引用）
- `scripts/e2e-coldstart.sh`、`scripts/e2e-soak.sh`、`scripts/e2e-ttl-race.sh`（B.1.1/B.1.4/B.2.8）
- `scripts/chaos/kill-spire-agent.sh`、`scripts/chaos/rmq-outage.sh`、`scripts/chaos/eventstore-outage.sh`、`scripts/chaos/soak-24h.sh`（B.3）
- `scripts/security-suite/stages/stage5-dos.sh`、`scripts/security-suite/stages/stage6-fuzz.sh`（B.2.1/B.2.4）
- `scripts/bench-alg-sweep.sh`（B.1.7）
- `tests/timing-sidechannel.php`（B.2.3）

擴充（既有）：

- `docs/experiment-comparison-targets.md` — 擴為 A.1~A.4 四軸對照表（或於新文件另寫）
- `docs/thesis/ch5-evaluation.md §5.5.3` — 從 Istio/Linkerd/Eventuate 三列擴至含 JWT-SVID-only、Dapr、Temporal
- `scripts/stress_test.sh` — REQUESTS/CONCURRENCY sweep 參數化（B.1.2/B.1.3）
- `tests/benchmark-lsvid.php` — chain depth 參數化（B.1.6）
- `scripts/security-suite/run-security-suite.sh` — 加 stage5/stage6 驅動

**不修改**：`src/`、`packages/`、`bin/`（此次為實驗/論文補強，非程式碼變動）。

---

## 驗證方式

本計畫的「產出正確性」由三個角度檢查：

1. **引用完整性** — Part A 每個新增對象都有「引用數據來源」欄位，論文撰寫時應能從該欄位直連至可重現的 benchmark 數字（paper / 官方 perf / 自測）。
2. **可執行性** — Part B 每個新項目都能落地成 shell / PHP 腳本（見檔案路徑清單）。落地後以：

```bash
# 驗證 Part A：跑新 profile（例：JWT-SVID-only）
LSVID_ENABLED=0 SPIFFE_MTLS_ENABLED=1 JWT_SVID_ONLY=1 \
  COMPOSE_PROFILES=zt bash scripts/run-thesis-experiment.sh

# 驗證 Part B.1/B.3：新腳本直接跑
bash scripts/e2e-coldstart.sh
bash scripts/e2e-soak.sh DURATION=1800
bash scripts/chaos/kill-spire-agent.sh

# 驗證 Part B.2：security-suite 擴展
bash scripts/security-suite/run-security-suite.sh --profile D --stage stage5-dos
```

3. **論文落點對應** — 清單每項皆註明對應的 ch5 小節（§5.2.3、§5.3.7、§5.5.3、§5.6.1~§5.6.4），撰寫時可直接定位應插入位置。

## 非目標（out-of-scope）

- 不實寫上述任何腳本或文件（本計畫停在規劃階段）
- 不修改既有 `docs/experiment-comparison-targets.md` 或 ch5-evaluation.md（避免覆蓋使用者已寫好的論文底稿，等人工 review 清單後再合併）
- 不做功能正確性補強（依使用者選擇已排除）
- 不做跨主機 / 跨可用區測試（§5.6.1 侷限，屬論文未來工作，本計畫不涵蓋）
