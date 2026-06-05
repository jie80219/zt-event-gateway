# Goal 3 — 純 Vault（Vault PKI 服務身分）重建

## 目的
把 Vault 對照 stack 從「另一套舊程式（Workerman + 每請求開 AMQP 連線）」改成**以 feat/spiffe-keycloak 為底**，只把身分/安全層換成 Vault，其餘（OpenSwoole gateway + channel pool、worker、RabbitMQ、下游服務、OrderSaga、4-host 拓撲）與 SPIFFE 完全一致。

→ 四方比較（SPIFFE+Keycloak / +LSVID / Linkerd / Vault）變成乾淨的 apples-to-apples：差異只在身分層，不混雜 gateway 架構，且不會在 concurrency=5000 崩潰。

分支：`feat/vault-pki`（從 `feat/spiffe-keycloak`）。

## 兩個已敲定決定
1. 從 SPIFFE 底重建（非沿用舊 feat/vault）。
2. **Vault 也做服務身分**：用 **Vault PKI** 簽發每個服務的短期 X.509 憑證，做服務間 mTLS，取代 SPIRE 的 X.509-SVID。

## 鐵則（沿用）
- 不可改 `Sagas/OrderSaga.php`（訂單須走完 Step 1→4；量測標記沿用）。
- 不弱化驗證：mTLS 仍 require_and_verify client cert；身分仍逐請求驗。
- 逐步驗證：每階段先 smoke 一筆訂單看到 `✅ Saga Step 4` 再往下。

## 身分層替換對照

| SPIFFE 元件 | 純 Vault 取代 |
|---|---|
| SPIRE Server（自管 CA） | **Vault PKI secrets engine**（Vault 當 CA，root/intermediate） |
| SPIRE Agent（node/workload attestation） | vault-agent + AppRole（每服務一 role）取憑證 |
| `spiffe-watcher`（SVID→SHM） | **vault-agent template** 把 cert/key/ca render 成檔案 + 自動續期 |
| `workload-registrar` | Vault PKI role（規範每服務可簽的 CN/URI SAN） |
| `SpiffeTableReader` / SHM seqlock | 讀 vault-agent render 的憑證檔 |
| `SpiffeTlsContext`（SVID→Guzzle/cURL mTLS） | `VaultTlsContext`（Vault 憑證→mTLS 參數）；介面盡量沿用 |
| `WorkermanSpiffeAuth` / `TlsPeerAuthorizer` | Vault 憑證的 peer 驗證（驗 CN/URI SAN ∈ 信任域） |
| LSVID（巢狀簽章鏈） | **移除**（純 Vault：X.509 憑證即身分，無 LSVID 鏈） |
| Keycloak JWT | 維持現狀（user 級 token）；服務身分改走 Vault mTLS。後續可再議是否也 Vault 化 |
| 下游 spire-agent + spiffe-helper | 下游 vault-agent render server+client 憑證；RoadRunner 信任 Vault CA |

> Vault 憑證的「SPIFFE-like 身分」：用 URI SAN `spiffe://zt.local/<service>` 或 CN=`<service>.zt.local`，讓 peer 驗證邏輯可沿用「身分字串 ∈ 信任域」的判斷。

## 分階段實作（每階段一組 commit，跑完 smoke 再進下一階段）

- **P1 — Vault PKI + 憑證簽發**：起 Vault、設 PKI engine（CA + per-service role）、vault-agent template render `tls.crt/tls.key/ca.crt`。驗證每服務拿得到有效憑證。
- **P2 — TLS context 改寫**：新增 `VaultTlsContext`（讀 render 的憑證檔），worker/gateway bootstrap 改用它；移除 SPIRE/SHM/LSVID 接線（保留介面）。
- **P3 — 下游 mTLS**：下游 vault-agent render 憑證，RoadRunner `client_auth_type: require_and_verify`，信任 Vault CA。worker→下游用 Vault client cert。
- **P4 — 移除 SPIFFE/LSVID/SPIRE**：compose 拿掉 spire-*/watcher/registrar；程式移除 LSVID 接線；env 開關 `IDENTITY_BACKEND=vault`。
- **P5 — 部署 + smoke**：4 host 起 Vault-PKI stack，smoke 一筆訂單走完 Step 1→4（含 Vault mTLS）。
- **P6 — 量測**：`run-dualmode-distributed.sh`（同 gateway，concurrency=count 不會崩）5k/10k/20k → analyze → 併入四方圖。

## 風險/開放問題
- Vault PKI 憑證 TTL 與續期頻率（對照 SPIRE 24h SVID）。
- 下游 RoadRunner 是否支援以 Vault CA 驗 client cert（SPIFFE 版用 `require_and_verify_client_cert`，沿用）。
- Keycloak 是否保留（user 級 JWT）；本計畫先保留，僅換服務身分。
- 規模大、需多階段；每階段獨立可驗證、可 commit。
