# zt-event-gateway 實驗比較對象調研

## Context

本專案 (zt-event-gateway) 結合了 **SPIFFE/SPIRE 零信任身份** + **事件驅動 Saga 分散式交易** + **LSVID 巢狀簽章鏈**。實驗目標是量化 **零信任安全層（mTLS + LSVID）對系統效能的影響成本**，需要合適的開源專案作為比較對象。

---

## 推薦的比較對象（按優先順序）

### 1. 自身 Ablation Study（最重要，必做）

利用本專案已有的環境變數開關，做 4 組對照：

| 組別           | LSVID_ENABLED | SPIFFE_MTLS_ENABLED | 意義                                                  |
|----------------|:-------------:|:-------------------:|-------------------------------------------------------|
| **Baseline**   |       0       |          0          | 純 Saga 效能（無安全層）                              |
| **mTLS only**  |       0       |          1          | 量化 X.509-SVID mTLS 握手 + 加密傳輸成本             |
| **LSVID only** |       1       |          0          | 量化 LSVID 鑄造(L0→L1→L2) + 驗證 + token 傳輸成本   |
| **Full ZT**    |       1       |          1          | 完整零信任模式（論文主張的架構）                      |

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

| 特性                       |  zt-event-gateway  |       Istio+SPIFFE        |  Linkerd   | HPE-USP (Go) | Eventuate Tram  | 
|----------------------------|:------------------:|:-------------------------:|:----------:|:------------:|:---------------:|
| mTLS (X.509-SVID)         |    ✅ App-level    |       ✅ Sidecar          | ✅ Sidecar |      ✅      |       ❌        |
| LSVID 巢狀簽章            |   ✅ L0→L1→L2     |            ❌             |     ❌     |      ✅      |       ❌        |
| Delegation (委派)          |        ✅          |            ❌             |     ❌     |      ✅      |       ❌        |
| Path tracing               |        ✅          |  ❌ (需 tracing sidecar)  |     ❌     |      ✅      |       ㄦ        |
| Attenuation (權限衰減)     | ✅ audience/hop    |            ❌             |     ❌     |      ✅      |       ❌        |
| SVID auto-rotation         |  ✅ SHM seqlock   |          ✅ SDS           |  ✅ auto   |      ✅      |      N/A        |
| Saga 分散式交易            | ✅ Orchestration   |            ❌             |     ❌     |      ❌      | ✅ Orchestration |
| Event Sourcing             |    ✅ Prooph       |            ❌             |     ❌     |      ❌      |  ✅ Eventuate   |
| 語言                       |     PHP 8.3        | Go (control) + C++ (Envoy)|  Go + Rust |      Go      |      Java       |

---

## Sources

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
