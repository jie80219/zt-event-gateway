# zt-event-gateway 實驗比較對象調研

## Context

本專案 (zt-event-gateway) 結合了 **SPIFFE/SPIRE 零信任身份** + **事件驅動 Saga 分散式交易** + **LSVID 巢狀簽章鏈**。實驗目標是量化 **零信任安全層（mTLS + LSVID）對系統效能的影響成本**，需要合適的開源專案作為比較對象。

---

## 推薦的比較對象（按優先順序）

### 1. 自身 Ablation Study（最重要，必做）

利用本專案已有的環境變數開關，做 4 組對照：

| 組別           | LSVID_ENABLED | SPIFFE_MTLS_ENABLED | 意義                                               |
|----------------|:-------------:|:-------------------:|----------------------------------------------------|
| **Baseline**   |       0       |          0          | 純 Saga 效能（無安全層）                           |
| **mTLS only**  |       0       |          1          | 量化 X.509-SVID mTLS 握手<br>+ 加密傳輸成本        |
| **LSVID only** |       1       |          0          | 量化 LSVID 鑄造 (L0→L1→L2)<br>+ 驗證 + token 傳輸成本|
| **Full ZT**    |       1       |          1          | 完整零信任模式（論文主張的架構）                   |

**量測指標：**
- 端到端 Saga 完成延遲（P50 / P95 / P99）
- Gateway 吞吐量（RPS）
- 每步驟延遲拆解（Gateway mint L0 → Worker validate L1 → downstream call with L2）
- CPU / Memory 使用量
- LSVID token payload 大小（bytes per hop）

### 2. HPE-USP-SPIRE/signed-assertions（LSVID 原始 Go 實現）

- **Repo**: https://github.com/hpe-usp-spire/signed-assertions
- **為什麼比較**：這是 LSVID 概念的原始實現（Go 語言），由 HPE + USP 聯合開發。本專案的 `packages/php-lsvid` 是 PHP 實現，可直接對比：
  - Token 鑄造延遲（mint latency）
  - Token 驗證延遲（validate latency）
  - 巢狀層數 (1→5 hops) 對 token 大小和驗證時間的線性成長
  - ID Mode vs Anonymous Mode 的效能差異
- **相關論文**：
  - [IEEE: LSVID Nested Token Approach](https://ieeexplore.ieee.org/document/10838369/)
  - [ResearchGate: Enhancing SPIFFE/SPIRE with Nested Security Token Model](https://www.researchgate.net/publication/380381630)

### 3. Istio + SPIFFE（Service Mesh sidecar-proxy mTLS）

- **Repo**: https://github.com/istio/istio
- **為什麼比較**：Istio 是業界最廣泛採用的 SPIFFE mTLS 方案，代表 **sidecar-proxy 路線**。本專案採用 **application-level 路線**（SpiffeTlsContext 直接在應用層做 mTLS）。兩者的 trade-off：
  - Istio：透明、無需改 code，但有 sidecar 延遲開銷（+166% mTLS latency at P99）
  - 本專案：需改 code，但無 proxy hop，且可嵌入 LSVID
- **已有 benchmark 數據可引用**：
  - [arXiv: Performance Comparison of Service Mesh Frameworks: the MTLS Test Case](https://arxiv.org/abs/2411.02267)
  - mTLS 啟用後延遲增加：Istio +166%, Istio Ambient +8%, Linkerd +33%, Cilium +99%

### 4. Linkerd（輕量級 Service Mesh mTLS baseline）

- **Repo**: https://github.com/linkerd/linkerd2
- **為什麼比較**：Linkerd 是 mTLS 延遲最低的 Service Mesh，可作為 **best-case sidecar baseline**：
  - 2000 RPS 下比 Istio sidecar 快 163ms（P99）
  - proxy 記憶體僅 ~10MB per pod
  - [Linkerd vs Ambient Mesh 2025 Benchmarks](https://linkerd.io/2025/04/24/linkerd-vs-ambient-mesh-2025-benchmarks/)

### 5. Eventuate Tram Sagas（Saga 效能 baseline，次要）

- **Repo**: https://github.com/eventuate-tram/eventuate-tram-sagas
- **Order 範例**: https://github.com/eventuate-tram/eventuate-tram-sagas-examples-customers-and-orders
- **為什麼比較**：業界標準 Saga 實現（Java + Kafka），Order → 扣款 → 確認的流程與本專案相似。可對比「加入零信任層後，Saga 端到端延遲增加了多少」vs「無安全層的 Saga baseline」

---

## 建議的實驗設計結構

```
實驗一：Ablation Study（自身 4 組對照）
  → 證明：零信任安全層的效能成本是可接受的

實驗二：LSVID 微觀效能（本專案 PHP vs HPE Go）
  → 證明：PHP 實現的 LSVID 效能在合理範圍內

實驗三：mTLS 路線對比（Application-level vs Sidecar-proxy）
  → 證明：application-level mTLS 的延遲低於 sidecar 方案
  → 對照：Istio（worst case）、Linkerd（best case）、本專案

實驗四（選做）：完整 Saga 流程對比
  → 對照 Eventuate Tram 無安全層 vs 本專案 Full ZT
  → 證明：即使加上零信任安全層，整體延遲仍在可用範圍
```

---

## 定性比較表（論文適用）

| 特性                   | zt-event-gateway   | Istio+SPIFFE               | Linkerd    | HPE-USP (Go) | Eventuate Tram   |
|------------------------|:------------------:|:--------------------------:|:----------:|:------------:|:----------------:|
| mTLS (X.509-SVID)      | ✅ App-level       | ✅ Sidecar                 | ✅ Sidecar | ✅           | ❌               |
| LSVID 巢狀簽章         | ✅ L0→L1→L2        | ❌                         | ❌         | ✅           | ❌               |
| Delegation（委派）     | ✅                 | ❌                         | ❌         | ✅           | ❌               |
| Path tracing           | ✅                 | ❌<br>（需 tracing sidecar）| ❌         | ✅           | ❌               |
| Attenuation（權限衰減）| ✅ audience/hop    | ❌                         | ❌         | ✅           | ❌               |
| SVID auto-rotation     | ✅ SHM seqlock     | ✅ SDS                     | ✅ auto    | ✅           | N/A              |
| Saga 分散式交易        | ✅ Orchestration   | ❌                         | ❌         | ❌           | ✅ Orchestration |
| Event Sourcing         | ✅ Prooph          | ❌                         | ❌         | ❌           | ✅ Eventuate     |
| 語言                   | PHP 8.3            | Go (control)<br>+ C++ (Envoy)| Go + Rust| Go           | Java             |

---

# 第二部分：安全視角 — 與非工作負載身份類零信任技術比較

> **與上半部（§1）的互補關係**：§1 以 **效能** 為軸，比較對象都是「工作負載身份 / Service Mesh」體系內的同類技術（Istio、Linkerd、HPE-USP LSVID、Eventuate Tram）。§2 以 **安全** 為軸，比較對象改為**非工作負載身份類**的零信任技術——意在回答：「為什麼要做 workload identity？把它換成 OAuth2/JWT、靜態 mTLS、ZTNA 會失去什麼？」
>
> **與 `security-experiment.md` 的互補關係**：後者以本專案自身 4 組 profile（A/B/C/D）為基準做 ablation。§2 則把比較對象擴展到**外部 ZT 技術**，並新增一組 **Profile E（OAuth2 Bearer + Static JWT）** 實測，量化跨架構差距。

---

## §2.1 問題陳述

本專案採用 **per-workload 密碼學身份（SPIFFE X.509-SVID）** + **LSVID 巢狀簽章鏈**，為每一個 hop（Gateway → Worker → 下游服務）重新簽署、鏈式追蹤、強制 audience/issuer 對齊。這是一種「工作負載身份為根本」的零信任實作風格。

業界主流的零信任技術並不都以 workload 為識別單元。多數模型以 **使用者 / 客戶端應用 / 使用者裝置 / 網路 session** 為識別主體：

- OAuth 2.0 / OIDC：以 **使用者** 或 **客戶端 app** 為識別單元
- 靜態 mTLS（企業 PKI）：以 **憑證主體（人工分配的 CN/SAN）** 為識別單元
- ZTNA / SDP：以 **使用者 + 裝置 + 網路 session** 為識別單元
- BeyondCorp：以 **使用者 + 裝置 context** 為識別單元

這些技術也都宣稱實現「零信任」。**問題是：從 22+ 個攻擊案例（見 `security-experiment.md` §3）的角度，它們各自能擋下什麼？擋不下什麼？**

---

## §2.2 比較對象概述（T1–T5）

| #          | 架構                        | 識別單元                | 代表產品 / 標準                                            | 傳輸層                            | 應用層 Token                                 |
|------------|-----------------------------|-------------------------|------------------------------------------------------------|-----------------------------------|----------------------------------------------|
| **本專案** | SPIFFE + LSVID 巢狀鏈       | **per-workload**        | 自研<br>（含 `packages/php-spiffe`,<br>`packages/php-lsvid`）| X.509-SVID mTLS<br>（動態）       | LSVID<br>（L0→L1→L2，巢狀 JWS）              |
| T1         | **OAuth 2.0 / OIDC Bearer** | 使用者 / 客戶端 app     | Auth0、Keycloak、<br>Kong OAuth2                           | TLS（伺服器端）                   | Bearer JWT / opaque token                    |
| T2         | **Static JWT Gateway**      | API 呼叫者              | Kong JWT plugin、<br>AWS API Gateway<br>Lambda Authorizer  | TLS                               | 預簽 JWT<br>（HMAC / RSA）                   |
| T3         | **靜態 mTLS（非 SPIFFE）**  | 憑證主體 CN/SAN         | 企業 PKI、<br>Open Banking FAPI                            | mTLS<br>（靜態，手動輪換）        | 無<br>（可選 OAuth token-binding）           |
| T4         | **ZTNA / SDP**              | 使用者 + 裝置 + session | Zscaler ZPA、<br>Cloudflare Access                         | TLS + 網路層覆蓋<br>（tunneled）  | 控制器發放的<br>session cookie               |
| T5         | **BeyondCorp**              | 使用者 + 裝置 context   | Google IAP、<br>BeyondCorp Enterprise                      | TLS                               | Context-aware token<br>（device posture claim）|

**參考標準**：
- [NIST SP 800-207](https://nvlpubs.nist.gov/nistpubs/SpecialPublications/NIST.SP.800-207.pdf) — 7 tenets
- [NIST SP 800-207A](https://nvlpubs.nist.gov/nistpubs/SpecialPublications/NIST.SP.800-207A.pdf) — 雲原生多雲 ZTA
- [CISA Zero Trust Maturity Model v2.0](https://www.cisa.gov/sites/default/files/2023-04/CISA_Zero_Trust_Maturity_Model_Version_2_508c.pdf)

---

## §2.3 定性比較表（11 維度 × 6 架構）

表格格式：`✅ 強 / 🟡 弱或依實作 / ❌ 無 / △ 可透過延伸達成`

| #  | 維度                    | 本專案（SPIFFE+LSVID）                   | T1 OAuth2 Bearer                    | T2 Static JWT       | T3 靜態 mTLS           | T4 ZTNA/SDP                 | T5 BeyondCorp              |
|----|-------------------------|------------------------------------------|-------------------------------------|---------------------|------------------------|-----------------------------|----------------------------|
| 1  | **身份粒度**            | per-workload                             | per-user / per-client               | per-caller          | per-cert-subject       | per-session                 | per-user+device            |
| 2  | **身份證明**            | 密碼學 attestation<br>(SPIRE node+workload)| IdP federation                      | 預共享金鑰          | 人工發放               | Controller<br>+ device posture| Device cert<br>+ user SSO |
| 3  | **傳輸安全**            | ✅ mTLS 動態                             | 🟡 TLS 單向                         | 🟡 TLS 單向         | ✅ mTLS 靜態           | 🟡 TLS + tunnel             | 🟡 TLS + device cert       |
| 4  | **Token 新鮮度**        | ✅ SVID 短效（分鐘）<br>+ JTI            | 🟡 分鐘~小時                        | 🟡 依簽署端設定     | ❌ 無 token            | 🟡 session TTL              | 🟡 session TTL             |
| 5  | **信任鏈 / 委派**       | ✅ 巢狀 L0→L1→L2                         | △ Token Exchange<br>（RFC 8693）    | ❌                  | ❌                     | ❌                          | ❌                         |
| 6  | **重放防護**            | ✅ JTI cache<br>+ SHM seqlock            | 🟡 依實作<br>（多數無 JTI cache）   | 🟡 同左             | ✅ 依 TLS              | 🟡 session ID binding       | 🟡 session ID binding      |
| 7  | **憑證輪換視窗**        | ✅ SHM seqlock<br>秒級零停機             | N/A                                 | N/A                 | ❌ 人工（月/年）       | ✅ 自動                     | ✅ 自動                    |
| 8  | **Path traceability**   | ✅ 完整 SPIFFE path<br>+ 巢狀 token      | ❌                                  | ❌                  | ❌                     | 🟡 controller log           | 🟡 IAP log                 |
| 9  | **Fail-closed 支援**    | ✅ `LSVID_REQUIRED=1`                    | 🟡 依 gateway 設定                  | 🟡 同左             | ✅ TLS 必備            | ✅ 預設                     | ✅ 預設                    |
| 10 | **密碼學保證**          | SPIRE CA<br>+ 每 workload 私鑰<br>（不離機）| IdP 私鑰集中                     | HMAC/RSA 預共享     | 靜態 CA<br>+ 長效私鑰  | Controller 發放             | Google 內部 CA             |
| 11 | **AMQP/非 HTTP 保護**   | ✅ 應用層 LSVID<br>包住 envelope         | ❌ 通常只保護 HTTP                  | ❌ 同左             | △ 若 AMQP 也開 mTLS    | ❌                          | ❌                         |

**關鍵觀察**：
- **維度 1（身份粒度）**：只有本專案與 T3 做到「服務實例級」識別；T1/T2/T4/T5 把同一服務的所有實例視為等同主體。
- **維度 5（信任鏈/委派）**：本專案是唯一內建 **L0→L1→L2 密碼學委派鏈** 的架構。OAuth2 Token Exchange（RFC 8693）理論上可達成近似效果，但需額外部署授權伺服器並接受集中式信任假設。
- **維度 7（輪換視窗）**：靜態 mTLS 的人工輪換是 **零信任的反模式**——NIST SP 800-207 tenet 5 明示「所有資源的認證與授權都是動態且嚴格強制的」。
- **維度 11（AMQP 保護）**：非 HTTP 協定是 T1/T2/T5 的結構性盲區。本專案的 LSVID 在 envelope 層簽章，與傳輸協定無關。

---

## §2.4 NIST SP 800-207 7 Tenets 對齊度（1–5 分制）

NIST SP 800-207 §2.1 定義零信任的 7 個基本原則。每項對每個架構的對齊度評分：

| #        | Tenet（縮述）                                   | 本專案 |   T1   |   T2   |   T3   |   T4   |   T5   |
|----------|-------------------------------------------------|:------:|:------:|:------:|:------:|:------:|:------:|
| T1       | 所有資料來源與計算服務皆視為資源                |   5    |   3    |   3    |   4    |   4    |   4    |
| T2       | 無論網路位置，所有通訊皆加密                    |   5    |   3    |   3    |   5    |   4    |   4    |
| T3       | 對單一企業資源的存取逐次（per-session）授權     |   5    |   4    |   3    |   2    |   5    |   5    |
| T4       | 存取決定基於動態政策<br>（身份、資產、行為、環境）|   4    |   3    |   2    |   2    |   5    |   5    |
| T5       | 企業監控並量測所有資產的完整性與安全姿態        |   3    |   3    |   2    |   2    |   5    |   5    |
| T6       | 所有資源認證與授權皆動態且嚴格強制              |   5    |   4    |   3    |   2    |   4    |   4    |
| T7       | 企業盡可能收集資訊以改善姿態                    |   4    |   3    |   2    |   2    |   5    |   5    |
| **總分** | **/ 35**                                        | **31** | **23** | **18** | **19** | **32** | **32** |

**解讀**：
- 本專案在 **T1–T3、T6**（身份密碼學基礎）得高分，因為每個 hop 都有密碼學授權。
- **T4、T5、T7**（動態政策 + 資產監控）相對弱，因為本專案沒有整合 device posture / behavioral analytics。這是與 T4/T5（ZTNA/BeyondCorp）的正向互補空間——可視為 **future work**。
- T1/T2 在 T3/T6 拿不到滿分，因為沒有 per-workload 粒度的動態憑證。

**延伸圖（論文第 10 張）**：6 架構 × 7 軸雷達圖，同時顯示「本專案在 tenets 1-3, 6 領先，ZTNA/BeyondCorp 在 4-5, 7 領先」的互補格局。

---

## §2.5 攻擊覆蓋率對照表（22+ 案例 × 6 架構）

以 `scripts/security-suite/lib/attack_client.php::caseMetadata()` 列出的 28 個 case 為基準（22 基本 + D01–D05 chain-depth + HAPPY 控制組），推斷每個架構在典型部署下的防禦結果：

- ✅ = 應被擋下（defense available）
- ❌ = 結構性盲區（structural gap）
- 🟡 = 可能被擋，取決於實作
- 📐 = 控制組（應被接受）

| case_id                 | 類別          |     本專案 D      |    T1 OAuth2    |  T2 JWT  |     T3 靜態 mTLS     | T4 ZTNA  | T5 BeyondCorp |
|-------------------------|---------------|:-----------------:|:---------------:|:--------:|:--------------------:|:--------:|:-------------:|
| F01 alg=none            | token-forgery |        ✅         |       ✅        |   ✅     |          ❌          |    ❌    |      ❌       |
| F02 payload 竄改        | token-forgery |        ✅         |       ✅        |   ✅     |          ❌          |    ✅    |      ✅       |
| F03 sig 替換            | token-forgery |        ✅         |       ✅        |   ✅     |          ❌          |    ✅    |      ✅       |
| F04 無 token            | token-forgery |        ✅         |       ✅        |   ✅     |          ❌          |    ✅    |      ✅       |
| T01 外域 ID             | trust-domain  |        ✅         |       🟡        |   🟡     |          ✅          |    ✅    |      ✅       |
| T02 未授權 audience     | trust-domain  |        ✅         |       🟡        |   🟡     |          ❌          |    ✅    |      ✅       |
| T03 envelope ID 不一致  | trust-domain  |        ✅         | ❌<br>（無 envelope）|   ❌     |          ❌          |    ❌    |      ❌       |
| C01 chain 斷鏈          | chain-attack  |        ✅         | ❌<br>結構性盲區|   ❌     |          ❌          |    ❌    |      ❌       |
| C02 level skip          | chain-attack  |        ✅         |       ❌        |   ❌     |          ❌          |    ❌    |      ❌       |
| C03 aud 剝離            | chain-attack  |        ✅         |       🟡        |   🟡     |          ❌          |    ❌    |      ❌       |
| E01 過期                | time-attack   |        ✅         |       ✅        |   ✅     |          ❌          |    ✅    |      ✅       |
| E02 未來 nbf            | time-attack   |        ✅         |       ✅        |   ✅     |          ❌          |    ✅    |      ✅       |
| E03 clock skew 邊界     | control       |        📐         |       📐        |   📐     |          📐          |    📐    |      📐       |
| R01 sequential replay   | replay        |        ✅         |       🟡        |   🟡     |          ❌          |    ✅    |      ✅       |
| R02 concurrent replay   | replay        |        ✅         |       🟡        |   🟡     |          ❌          |    ✅    |      ✅       |
| M01 無客戶端憑證        | mtls          |        ✅         |       ❌        |   ❌     |          ✅          |    ❌    |      ❌       |
| M02 外域客戶端憑證      | mtls          |        ✅         |       ❌        |   ❌     |          ✅          |    ❌    |      ❌       |
| M03 rotation 競態       | mtls          |        ✅         |       N/A       |   N/A    |          ❌          |   N/A    |      N/A      |
| Q01 直接 AMQP 偽造      | amqp-inject   |        ✅         | ❌<br>結構性盲區|   ❌     | △<br>視 AMQP TLS 設定|    ❌    |      ❌       |
| Q02 偽造 rollback event | amqp-inject   |        ✅         |       ❌        |   ❌     |          △           |    ❌    |      ❌       |
| S01 SHM 竄改            | shm-tamper    | ❌<br>已知限制    |       N/A       |   N/A    |         N/A          |   N/A    |      N/A      |
| S02 seqlock 停留奇數    | shm-tamper    |        🟡         |       N/A       |   N/A    |         N/A          |   N/A    |      N/A      |
| HAPPY 基線              | control       |        📐         |       📐        |   📐     |          📐          |    📐    |      📐       |
| D01 合法 4 層鏈         | chain-depth   |        📐         |       N/A       |   N/A    |         N/A          |   N/A    |      N/A      |
| D02 中層 aud 錯配       | chain-depth   |        ✅         |       N/A       |   N/A    |         N/A          |   N/A    |      N/A      |
| D03 外域 CA 中層        | chain-depth   |        ✅         |       N/A       |   N/A    |         N/A          |   N/A    |      N/A      |
| D04 外層 aud 剝離       | chain-depth   |        ✅         |       N/A       |   N/A    |         N/A          |   N/A    |      N/A      |
| D05 鏈深度超限          | chain-depth   |        ✅         |       N/A       |   N/A    |         N/A          |   N/A    |      N/A      |
| **有效防禦率**          | —             | **22/23 ≈ 96%**   |    **~60%**     | **~55%** |       **~30%**       | **~65%** |   **~65%**    |

（百分比分母排除 N/A 與控制組；T3/T4/T5 的 C/D 類攻擊因結構上不存在 token 鏈，全記 N/A。）

**核心觀察**：
- **chain-attack（C01–C03、D01–D05）是本專案相對所有對照架構的獨特貢獻**——這一類攻擊針對巢狀委派鏈，其他架構沒有巢狀鏈故本來就「無法攻擊」，但也代表它們**不支援 fine-grained delegation**。
- **AMQP-inject（Q01–Q02）**是 T1/T2/T4/T5 的結構性盲區：這些架構把信任邊界畫在 API Gateway 外側，一旦越過就無應用層認證。T3 若 AMQP broker 也開靜態 mTLS 則可抵禦（標為 △）。
- **mTLS（M01–M03）**是 T1/T2/T4/T5 的結構性盲區，因為它們不使用 mTLS。T3 在網路層防禦；本專案在網路 + 應用雙層。

---

## §2.6 實測設計：Profile E（OAuth2 Bearer + Static JWT）

### 目的

從 T1–T5 中挑選 **實作成本最低、代表性最強** 的「OAuth2 Bearer + Static JWT」，作為 Profile E 注入既有 `security-suite`，跑相同攻擊矩陣，以**量化**（而非僅推估）跨架構防禦差距。

### 為何不實作 T3/T4/T5

- **T3（靜態 mTLS）**：需要重新設計整個憑證分發流程、修改所有下游服務的 RoadRunner `.rr.yaml`、手動輪換 CA，工程量遠超論文價值。由 §2.3 維度 7（輪換視窗）與 NIST T6 已足以定性論證其劣勢。
- **T4（ZTNA/SDP）**：商用產品導向，本地測試需要 controller（Cloudflare Access 或 Zscaler），不可能與 SPIRE 混合部署。由 §2.4 雷達圖定性論證。
- **T5（BeyondCorp）**：同 T4。

### Profile E 設計摘要

| 項目         | 本專案 D               | Profile E                                          |
|--------------|------------------------|----------------------------------------------------|
| Ingress 驗證 | Gateway 鑄造 L0        | `jwt-gateway` 驗證<br>`Authorization: Bearer <JWT>`|
| 傳輸層       | mTLS（動態 SVID）      | TLS 單向（或無）                                   |
| 訊息 token   | LSVID 巢狀鏈           | 原始 JWT 傳入<br>envelope.bearer_jwt 欄位          |
| Worker 驗證  | LSVIDValidator 完整鏈  | 僅驗 JWT 簽章（若啟用）<br>或略過                  |
| AMQP 防護    | ✅（應用層 LSVID）     | ❌（結構性盲區）                                   |
| mTLS         | ✅                     | ❌                                                 |

**環境變數（`scripts/security-suite/profiles/E-oauth2-bearer.env`）**：
```
SPIFFE_ENABLED=0
LSVID_ENABLED=0
LSVID_REQUIRED=0
SPIFFE_MTLS_ENABLED=0
PROFILE_E_JWT_HS256_KEY=<32-byte-random>
PROFILE_E_JWT_ISSUER=https://idp.example.test
PROFILE_E_JWT_AUDIENCE=zt-event-gateway
```

### 攻擊案例在 Profile E 的預期結果

為 28 個 case 手動映射到 JWT 等價攻擊（由 `scripts/security-suite/lib/attack_client_profile_e.php` 產生）：

| case_id               | JWT 等價攻擊                         | 預期結果（Profile E）      | 對應架構性質            |
|-----------------------|--------------------------------------|:---------------------------|-------------------------|
| F01 alg=none          | 同（JWT 也有 alg=none）              | rejected                   | OAuth2 basic            |
| F02 payload 竄改      | 改 sub/aud 但留 sig                  | rejected                   | OAuth2 basic            |
| F03 sig 替換          | 同                                   | rejected                   | OAuth2 basic            |
| F04 無 token          | 空 Authorization header              | rejected                   | OAuth2 basic            |
| T01 外域 iss          | `iss=https://evil.example`           | rejected                   | OAuth2 issuer 驗證      |
| T02 aud 錯配          | `aud=unknown-service`                | rejected                   | OAuth2 audience 驗證    |
| T03 envelope 不一致   | N/A<br>（JWT gateway 不重建 envelope 身份）| **accepted violation**     | OAuth2 結構差異         |
| C01–C03 chain         | N/A（無巢狀鏈）                      | **accepted violation**     | **結構性盲區**          |
| E01 過期              | `exp < now`                          | rejected                   | JWT 基本                |
| E02 未來 nbf          | `nbf > now`                          | rejected                   | JWT 基本                |
| R01 replay sequential | 重送同 JWT                           | 🟡 取決於是否有 JTI cache  | OAuth2 弱項             |
| R02 replay concurrent | 並發送                               | 🟡 同上                    | OAuth2 弱項             |
| M01–M03 mtls          | N/A                                  | **accepted violation**     | **結構性盲區**          |
| Q01 直接 AMQP 偽造    | bypass jwt-gateway                   | **accepted violation**     | **結構性盲區**          |
| Q02 偽造 rollback     | 同                                   | **accepted violation**     | **結構性盲區**          |
| S01–S02 SHM           | N/A                                  | N/A                        | 不使用 SHM              |
| D01–D05 chain-depth   | N/A                                  | N/A                        | 無巢狀鏈                |

**預期 Profile E 有效防禦率 ≈ 50%（10/20 有效案例通過）**，明顯低於 Profile D 的 ≈96%。差距集中在 chain-attack、mtls、amqp-inject 三類——正是 **workload identity + 巢狀委派** 的獨特貢獻。

### 實作元件（增量清單）

| 動作 | 路徑                                                    | 說明                                            |
|------|---------------------------------------------------------|-------------------------------------------------|
| 新增 | `docker/php-oauth2-gateway/Dockerfile`                  | 複用 `docker/php-openswoole/Dockerfile` base    |
| 新增 | `docker/php-oauth2-gateway/jwt-gateway.php`             | 驗 JWT Bearer、建 envelope、<br>publish AMQP    |
| 新增 | `docker-compose.profile-e.yml`                          | compose override                                |
| 新增 | `scripts/security-suite/profiles/E-oauth2-bearer.env`   | env 覆寫                                        |
| 新增 | `scripts/security-suite/lib/attack_client_profile_e.php`| JWT 攻擊產生器                                  |
| 編輯 | `scripts/security-suite/run-security-suite.sh`          | `--profile=E` 分支                              |
| 編輯 | `scripts/security-suite/stages/stage2-e2e-http.sh`      | profile=E 時跳過 SPIFFE preflight               |
| 編輯 | `scripts/security-suite/plot/plot_security.py`          | 新增 `09-profile-d-vs-e-coverage.png`           |

### 預期輸出

- `artifacts/security-{STAMP}/E-oauth2-bearer/{http,amqp}-attacks.json`
- `artifacts/security-{STAMP}/experiment-png/09-profile-d-vs-e-coverage.png`
  - 雙欄 heatmap：左 D 全綠、右 E 多紅（chain/mtls/amqp-inject 類別）
  - 論文 Evaluation 章節「跨架構防禦覆蓋率」圖
- `artifacts/security-{STAMP}/experiment-png/10-nist-tenets-radar.png`（選做，由 §2.4 表格數據繪製）

### 執行指令

```bash
# 1) 啟動 Profile E stack（取代 gateway）
docker compose -f docker-compose.yml -f docker-compose.profile-e.yml up -d

# 2) 跑 Profile E 攻擊矩陣
SEC_PROFILES="E-oauth2-bearer" bash scripts/security-suite/run-security-suite.sh

# 3) 連帶跑 D 以便對照（若 artifacts 中未有最新 D）
SEC_PROFILES="D-full-zt E-oauth2-bearer" bash scripts/security-suite/run-security-suite.sh

# 4) 繪圖
python3 scripts/security-suite/plot/plot_security.py artifacts/security-{STAMP}
```

---

## §2.7 範圍邊界與討論

### 定性比較 vs 實測比較的分工

| 比較對象         | 定性（§2.3–§2.5） | 實測（§2.6 Profile E）        |
|------------------|:-----------------:|:------------------------------|
| T1 OAuth2 Bearer |        ✅         | ✅<br>（以 T2 的簡化版實作）  |
| T2 Static JWT    |        ✅         | ✅                            |
| T3 靜態 mTLS     |        ✅         | ❌<br>（工程成本過高）        |
| T4 ZTNA/SDP      |        ✅         | ❌<br>（需商用 controller）   |
| T5 BeyondCorp    |        ✅         | ❌<br>（Google 內部技術）     |

### 與 `security-experiment.md` 的交叉引用

§2.6 Profile E 完全複用 `security-experiment.md` §3 的攻擊矩陣與 §5 的 stage 驅動流程。新增的只有 Profile E 專屬的 JWT payload 產生器與 Figure 09。既有 8 張圖保持不變。

### 已知論證限制

1. **百分比數字是「典型部署」推估**，不是所有 T1–T5 實作都一樣。例如某些成熟的 Kong + JWT + Sender-Constrained Tokens（RFC 8705）部署可以補齊 F 與 R 類攻擊的弱項。§2.5 表格旁應附上「以 out-of-the-box 預設設定評估」的限定說明。
2. **ZTNA/BeyondCorp 在 T5、T7 的高分反映其 **policy-engine** 優勢**。本專案沒有 policy engine（目前只有 audience/issuer 前綴檢查），這是 **future work**（per-service ACL + context-aware policy）。
3. **Profile E 只實作「OAuth2-shape」JWT Bearer**，不實作完整的 OAuth2 Authorization Code Grant 或 RFC 8693 Token Exchange。若未來論文需強化 T1 的代表性，可考慮整合 Keycloak。

---

## Sources

### 本專案內部文件（交叉引用）
- [docs/security-experiment.md](security-experiment.md) — 22+ 攻擊案例矩陣 + 4 profile ablation
- [docs/order-auth-flow.md](order-auth-flow.md) — 下游服務雙層驗證設計
- [docs/thesis/ch5-evaluation.md](thesis/ch5-evaluation.md) — 效能與安全綜合評估

### 效能比較來源（§1）
- [HPE-USP-SPIRE/signed-assertions](https://github.com/hpe-usp-spire/signed-assertions) — LSVID 原始 Go 實現
- [LSVID IEEE Paper](https://ieeexplore.ieee.org/document/10838369/) — LSVID 巢狀 token 論文
- [Nested Security Token Model](https://www.researchgate.net/publication/380381630) — SPIFFE/SPIRE 巢狀 token 增強
- [Service Mesh mTLS Benchmark (arXiv)](https://arxiv.org/abs/2411.02267) — Istio/Linkerd/Cilium mTLS 效能比較
- [Linkerd vs Ambient 2025](https://linkerd.io/2025/04/24/linkerd-vs-ambient-mesh-2025-benchmarks/) — 最新 Service Mesh 延遲數據
- [Eventuate Tram Sagas](https://github.com/eventuate-tram/eventuate-tram-sagas) — Saga 框架
- [Eventuate Order Example](https://github.com/eventuate-tram/eventuate-tram-sagas-examples-customers-and-orders)
- [Istio](https://github.com/istio/istio) — CNCF Service Mesh
- [Linkerd](https://github.com/linkerd/linkerd2) — 輕量級 Service Mesh
- [Zero Trust Architecture SLR](https://arxiv.org/abs/2503.11659) — 零信任架構系統性文獻回顧

### 安全視角比較來源（§2）
- [NIST SP 800-207](https://nvlpubs.nist.gov/nistpubs/SpecialPublications/NIST.SP.800-207.pdf) — Zero Trust Architecture（7 tenets 原始規範）
- [NIST SP 800-207A](https://nvlpubs.nist.gov/nistpubs/SpecialPublications/NIST.SP.800-207A.pdf) — 雲原生多雲 ZTA 模型
- [CISA Zero Trust Maturity Model v2.0](https://www.cisa.gov/sites/default/files/2023-04/CISA_Zero_Trust_Maturity_Model_Version_2_508c.pdf) — 成熟度模型
- [RFC 8693](https://www.rfc-editor.org/rfc/rfc8693) — OAuth 2.0 Token Exchange（委派 token 的標準化）
- [RFC 8705](https://www.rfc-editor.org/rfc/rfc8705) — OAuth 2.0 Mutual-TLS Client Authentication + Certificate-Bound Access Tokens
- [BeyondCorp Paper (Google)](https://research.google/pubs/pub43231/) — Zero Trust 原始模型
- [Petronella: Machine Identity mTLS + SPIFFE 2026](https://petronellatech.com/blog/machine-identity-is-the-new-perimeter-mtls-spiffe-for-zero-trust/)
- [Tetrate: Istio vs Linkerd vs Consul](https://tetrate.io/blog/istio-vs-linkerd-vs-consul)
- [Tetrate: NIST SP 800-207A Explained](https://tetrate.io/blog/nist-sp-800-207a-explained-zero-trust-architecture-model-for-access-control)
- [Kong: OAuth2 mTLS Client Authentication](https://konghq.com/blog/engineering/zero-trust-oauth-2-0-mtls-client-authentication)
- [Scalekit: OAuth vs mTLS for M2M](https://www.scalekit.com/blog/oauth-client-credentials-vs-mtls)
