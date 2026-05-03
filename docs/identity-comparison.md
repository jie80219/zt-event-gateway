# SPIFFE/SPIRE vs Keycloak vs Vault — 機制與流程比較

三者經常被相提並論，但其實**解決的問題在不同層**，並非互斥。本文用一頁釐清：

- **SPIFFE/SPIRE** ≈ 給 **workload (service)** 的「身份證簽發系統」
- **Keycloak** ≈ 給 **使用者 (human)** 的「登入/SSO 系統」
- **Vault** ≈ 給 **任何東西** 的「祕密保險箱 + 動態憑證簽發」

---

## 1. TL;DR：一句話定位

| 系統             | 為誰簽發身分                         | 給出的東西                                | 信任建立方式                                    |
| ---------------- | ------------------------------------ | ---------------------------------------- | ----------------------------------------------- |
| **SPIFFE/SPIRE** | Workload（容器、process、VM）         | **SVID**：X.509 cert 或 JWT，含 SPIFFE ID | 平台 attestation（k8s SA / unix pid / AWS IID） |
| **Keycloak**     | 終端使用者（也支援 service account）  | **OIDC/OAuth2 token**：id_token, access_token, refresh_token | username+pw / social / LDAP / WebAuthn / OTP    |
| **Vault**        | 任何能透過 auth method 證明身分的 client | **Vault token + 取出的 secret**（KV / 動態 DB user / PKI cert） | AppRole / k8s SA JWT / AWS IAM / OIDC / token   |

---

## 2. 機制對照表

| 維度               | SPIFFE/SPIRE                                   | Keycloak                                              | Vault                                                       |
| ------------------ | ---------------------------------------------- | ----------------------------------------------------- | ----------------------------------------------------------- |
| 主要使用者         | Service-to-service                             | Human → Service                                       | Service / Human → secret                                    |
| 標準               | SPIFFE spec (CNCF)                             | OIDC, OAuth2, SAML 2.0                                | 自家 API（無正式 spec）                                     |
| 身分識別子         | `spiffe://td/ns/foo/sa/bar` (URI SAN)          | `sub` claim（user UUID）                              | entity_id + alias（依 auth method）                         |
| 憑證型態           | X.509 SVID **或** JWT-SVID                     | JWT (id/access)、SAML assertion                       | Vault token (UUID-like)；副產物可為 X.509 / DB pwd / KV blob |
| 取得方式           | Workload API (Unix socket) + attestation       | OIDC redirect / Direct grant / Client credentials     | `vault login -method=<approle\|k8s\|aws>` (HTTP API)        |
| 「客戶端要不要帶 secret」 | **不用** — 由本地 attestor 自動證明           | 需要 username+pw 或 client_secret                     | 需要 role_id+secret_id 或 SA token                           |
| 預設 TTL           | 1 小時（X.509-SVID 預設），常設更短             | access ~5 min, refresh ~30 min（可調）                 | token 1h、lease 變動；Vault Agent render 10s 重抓           |
| 旋轉模式           | Agent 持續續簽 + 推到 Workload API socket       | refresh_token rotation                                | token renew + lease renew + Agent re-render                 |
| 撤銷               | CRL 可選；通常依靠短 TTL                       | session 強制終止、token revoke endpoint               | `vault token revoke`、`vault lease revoke`                  |
| 信任根分發         | Trust bundle 自動推到所有 Agent                 | JWKS endpoint (`/realms/x/protocol/openid-connect/certs`) | Vault CA / 各 secret engine 各自管                          |
| Sidecar / SDK      | spiffe-helper / go-spiffe / java-spiffe         | keycloak-js, OAuth2-proxy, mod_auth_openidc           | vault-agent (sidecar 渲染 env / file)                       |
| 平台耦合           | 強：Node attestor 綁 k8s/aws/docker             | 弱：純 HTTP                                           | 弱：純 HTTP（auth method 可選平台耦合）                     |
| 程式碼侵入         | 低（mTLS 由 sidecar / SDK 處理）               | 中（要懂 OIDC flow）                                  | **零**（vault-agent render 成 env，業務 `getenv()` 不變）    |
| 主要對手           | mTLS hand-rolled、cert-manager                  | Auth0、Okta、AWS Cognito                              | AWS Secrets Manager、GCP Secret Manager、cert-manager       |
| OSS / 商業         | Apache 2.0（CNCF Graduated）                    | Apache 2.0（Red Hat 維護）                            | BUSL 1.1（HashiCorp，>= 1.14；舊版仍 MPL）                  |

---

## 3. 流程圖（ASCII）

### 3.1 SPIFFE/SPIRE — Workload 取得 SVID

平台 attestation 是核心：**workload 不需要 present 任何 secret**，由 SPIRE Agent 觀察
runtime（PID 屬於哪個 k8s pod、哪個 SA、哪個 UID）來證明身分。

```
                         ┌──────────────────────┐
                         │   SPIRE  Server      │
                         │  (CA + registry)     │
                         └─────────┬────────────┘
                                   │ 1. Node attestation
                                   │    (k8s SAT / AWS IID / x509-SVID)
                                   │ 2. Issue node SVID
                                   ▼
   ┌───────────────────────────────────────────────────────────┐
   │  Node                                                     │
   │  ┌────────────────────────┐                               │
   │  │ SPIRE  Agent           │◄── 3. Workload connects to    │
   │  │   - workload attestor  │       /tmp/spire-agent.sock   │
   │  │   - workload API srv   │       (Unix Domain Socket)    │
   │  └────────┬───────────────┘                               │
   │           │ 4. attest workload                            │
   │           │    (uid/gid/pid → k8s pod label)              │
   │           │ 5. match registration entry                   │
   │           │    (selector → SPIFFE ID)                     │
   │           │ 6. ask SPIRE Server to mint SVID              │
   │           │ 7. push X.509-SVID + trust bundle             │
   │           ▼                                               │
   │  ┌────────────────────────┐                               │
   │  │ Workload (container)   │── 8. mTLS to peer using SVID  │
   │  │   uses go-spiffe SDK   │                               │
   │  └────────────────────────┘                               │
   └───────────────────────────────────────────────────────────┘

  關鍵：步驟 4-5 不需要 workload 自帶任何 credential。
       身分由「在哪台機器、哪個 pod、哪個 UID 跑起來的」決定。
```

### 3.2 Keycloak — OIDC Authorization Code Flow（使用者登入）

```
   User-Agent (browser)              App (RP)             Keycloak (OP)
   ┌──────────────┐               ┌─────────┐            ┌──────────────┐
   │              │               │         │            │  realm /     │
   │              │               │         │            │  client      │
   └──────┬───────┘               └────┬────┘            └──────┬───────┘
          │                            │                        │
          │ 1. GET /protected          │                        │
          │ ─────────────────────────► │                        │
          │ 2. 302 to /authorize       │                        │
          │ ◄───────────────────────── │                        │
          │                            │                        │
          │ 3. GET /authorize?         │                        │
          │      response_type=code    │                        │
          │      client_id=...         │                        │
          │      redirect_uri=...      │                        │
          │      scope=openid          │                        │
          │ ──────────────────────────────────────────────────► │
          │                            │                        │
          │ 4. Login form              │                        │
          │ ◄────────────────────────────────────────────────── │
          │ 5. POST username+password  │                        │
          │ ──────────────────────────────────────────────────► │
          │                            │                        │
          │ 6. 302 redirect_uri?code   │                        │
          │ ◄────────────────────────────────────────────────── │
          │                            │                        │
          │ 7. GET redirect_uri?code   │                        │
          │ ─────────────────────────► │                        │
          │                            │ 8. POST /token         │
          │                            │    grant_type=         │
          │                            │     authorization_code │
          │                            │    code=...            │
          │                            │    client_secret=...   │
          │                            │ ─────────────────────► │
          │                            │ 9. id_token +          │
          │                            │    access_token +      │
          │                            │    refresh_token       │
          │                            │ ◄───────────────────── │
          │ 10. Set-Cookie + content   │                        │
          │ ◄───────────────────────── │                        │
          │                            │                        │
          │ 11. App calls API:         │                        │
          │     Authorization:         │                        │
          │      Bearer <access_token> │                        │
          │     API verifies via JWKS  │                        │
          │     (cache / refetch)      │                        │
          │                            │                        │

  關鍵：步驟 5 是 user-interactive；步驟 9 簽出 JWT，後續 API
       用 Keycloak public key (JWKS) 驗章，不再回打 Keycloak。
```

### 3.3 Vault — AppRole + Vault Agent 取 Secret（zt-event-gateway 採用）

```
   ┌────────────────────────┐                  ┌──────────────────────┐
   │  vault-init (one-shot) │                  │      Vault server    │
   │  - root token          │                  │   (KV v2 + AppRole)  │
   └─────────┬──────────────┘                  └─────────┬────────────┘
             │ 1. enable approle, mount kv               │
             │ 2. write secret/zt-event-gateway/...      │
             │ 3. for each role:                         │
             │      create policy                        │
             │      create AppRole                       │
             │      get role_id, secret_id               │
             │ ────────────────────────────────────────► │
             │                                           │
             ▼                                           │
   ┌────────────────────────┐                            │
   │  docker/vault/creds/   │                            │
   │   <role>/role_id       │ (bind-mount, 0640)         │
   │   <role>/secret_id     │                            │
   └─────────┬──────────────┘                            │
             │                                           │
             │  4. read role_id + secret_id              │
             ▼                                           │
   ┌────────────────────────┐  5. POST /v1/auth/         │
   │  vault-agent (sidecar) │     approle/login          │
   │   - auto_auth          │ ─────────────────────────► │
   │   - template renderer  │                            │
   │                        │  6. client_token           │
   │                        │ ◄───────────────────────── │
   │                        │                            │
   │                        │  7. every 10s:             │
   │                        │     GET /v1/secret/data/.. │
   │                        │ ─────────────────────────► │
   │                        │  8. secret JSON            │
   │                        │ ◄───────────────────────── │
   │                        │                            │
   │                        │  9. render template ──┐    │
   │                        │     /vault/runtime/   │    │
   │                        │       runtime.env     │    │
   │                        │     /vault/out/.env   │    │
   └─────────┬──────────────┘                       │    │
             │                                      │    │
             │ 10. shared volume                    │    │
             ▼                                      ▼    │
   ┌────────────────────────┐                            │
   │  Main container        │                            │
   │  entrypoint:           │                            │
   │   until [-s ...]; do   │                            │
   │     sleep 1; done      │                            │
   │   source runtime.env   │                            │
   │   exec original CMD    │                            │
   │                        │                            │
   │  PHP code:             │                            │
   │   getenv('AMQP_USER')  │ ← 完全沒改                  │
   └────────────────────────┘                            │

  關鍵：步驟 4-5 是 secret-in-secret-out（要先有 role_id+secret_id 檔）。
       步驟 9 的 render 是 zt-event-gateway 的「不改 PHP」核心：
       sidecar 把 Vault 內容寫成主應用本來就在讀的 env / .env 檔。
```

### 3.4 三者並列「身分證明的代價」對比

```
  SPIFFE/SPIRE                Keycloak                  Vault (AppRole)
  ────────────                ────────                  ───────────────

  Workload                    User Browser              Container
     │                           │                          │
     │ (no secret)               │ username+password        │ role_id + secret_id
     │                           │                          │ (檔案 in shared vol)
     ▼                           ▼                          ▼
  SPIRE Agent                Keycloak Login Page       Vault auth/approle/login
     │                           │                          │
     │ attest (uid, ns, sa)      │ verify                   │ verify
     │                           │                          │
     ▼                           ▼                          ▼
  Mint SVID                   Mint OIDC tokens          Mint Vault token
   (X.509 / JWT)               (id/access/refresh)          │
                                                            │ (then) read KV
                                                            ▼
                                                         Mint env vars

  代價：                      代價：                     代價：
   - 部署 attestor              - User 記密碼              - role_id 要事先送進容器
   - registry 維護              - HTTPS + redirect         - secret_id 要保護好
   - 短 TTL 自動處理            - JWKS rotation            - lease 續期由 agent 做

  最少互動：SPIRE             最多互動：Keycloak (UI)    中等：Vault
```

---

## 4. 旋轉機制深度比較

| 系統     | 旋轉觸發者     | 客戶端是否需重連 | 平均生效延遲              |
| -------- | -------------- | ---------------- | ------------------------- |
| SPIRE    | Agent 自動續簽 | 否（連線下次握手用新 cert） | < TTL/2，預設 30 min 內   |
| Keycloak | refresh_token  | 否（背景換新 access） | 視 refresh window，~5 min |
| Vault    | Agent re-render + 應用重啟 | **是**（PHP/process 不會 hot-reload env） | render 10s + restart 數秒 |

> Vault 的弱點：env 變數是 process 啟動時讀的，**不會** hot reload。
> SPIRE / Keycloak 因為 token/cert 是「請求時」帶出去的，不需重啟。
> 在 zt-event-gateway 中，`docs/vault-integration.md` 明確標示 RabbitMQ 連線常駐，
> 旋轉後要 `docker compose restart php-worker` 才生效。

---

## 5. 撤銷機制比較

```
  SPIFFE/SPIRE                Keycloak                   Vault
  ────────────                ────────                   ─────

  ┌─ delete registration─┐  ┌─ /admin/realms/x/users/ ┐ ┌─ vault token revoke ─┐
  │   entry              │  │   <id>/logout           │ │  <accessor>          │
  └──────────┬───────────┘  └──────────┬──────────────┘ └──────────┬───────────┘
             │                         │                            │
             ▼                         ▼                            ▼
   下次續簽失敗                  該 user session 失效         token 立即失效
   (TTL 內仍有效)                refresh_token 拒簽            lease 連帶撤銷
   通常仰賴短 TTL                可選 token revocation         可級聯 (orphan/兒)

  弱點：需要等 TTL              弱點：JWT 過期前難立即作廢      強項：即時、可級聯
```

---

## 6. 適用場景

| 需求                                          | 推薦                                  | 原因                                           |
| --------------------------------------------- | ------------------------------------- | ---------------------------------------------- |
| 兩個 microservice 之間互信 (mTLS)              | **SPIFFE/SPIRE**                      | 標準化 workload identity，0 secret             |
| 終端使用者登入網站 / app                       | **Keycloak**                          | 完整 OIDC + UI + 第三方登入                     |
| 把 RabbitMQ / DB 密碼從 plaintext 拉走         | **Vault** (KV)                        | 這正是 zt-event-gateway 的方案                  |
| 動態簽發短期 DB user                           | **Vault** (Database secret engine)    | SPIRE 不簽 DB pwd                              |
| 簽發短期 X.509 給 service                      | **SPIRE > Vault PKI**                 | SPIRE 自帶 attestation；Vault PKI 要自己做 auth |
| 給 user 也給 service 一致 token                | **Keycloak** (user) + **SPIRE** (svc) | 同 realm 不同主體                              |
| 完全 zero-trust 多租戶 k8s                     | **SPIRE + Vault**                     | SPIRE 給 SVID，Vault 用 SVID 換 secret         |

---

## 7. 三者能否組合？常見 pattern

### 7.1 SPIRE + Vault：JWT-SVID 換 Vault token

```
  Workload ── JWT-SVID ──► Vault (auth/jwt/login)
                              │
                              ▼
                          client_token + lease
                              │
                              ▼
                          read KV / DB cred
```

優點：完全無 plaintext secret 落盤；連 role_id 都不需要。
缺點：Vault JWT auth 設定較複雜，要 trust SPIRE 的 JWKS。

### 7.2 Keycloak + Vault：使用者拿 token 取 secret

```
  User ── Keycloak login ──► id_token (JWT)
            │
            ▼
  Frontend ── id_token ──► Vault (auth/oidc 或 auth/jwt)
                              │
                              ▼
                          按 user role 給對應 policy
```

場景：開發者透過 SSO 取臨時 DB 帳密（dev portal）。

### 7.3 三者並用：典型 prod stack

```
   ┌────────────┐    OIDC    ┌──────────────┐
   │   User     │ ─────────► │  Keycloak    │
   └────────────┘            └──────────────┘

   ┌────────────┐  attest   ┌──────────────┐
   │  Workload  │ ────────► │  SPIRE       │  → SVID
   └─────┬──────┘            └──────────────┘
         │ JWT-SVID
         ▼
   ┌──────────────┐
   │   Vault      │  → DB cred / KV / PKI cert
   └──────────────┘
```

---

## 8. 在 zt-event-gateway 的對應

目前 `feat/vault` 分支採 **Vault** 作 secret 中央倉。三者放在實驗對照組的位置如下
（見 `.claude/plans/end-to-end-robust-sundae.md`）：

| 對照組                        | 對應 commit / 分支 | 機制特色                                              |
| ----------------------------- | ------------------ | ----------------------------------------------------- |
| **Plain env**（baseline）     | `main`             | docker-compose 直接寫 `RABBITMQ_PASS=ztpass`           |
| **Vault Agent (本分支)**      | `feat/vault`       | AppRole + sidecar render `.env`，PHP 程式不變          |
| **SPIFFE/SPIRE** (待對照)     | 預留 `Services/*/spiffe/` 目錄 | mTLS service-to-service，憑證 0 secret 取得 |
| **Keycloak**                  | 不在此 stack 對照  | 終端 user 議題，與本系統 service-to-service 不同層    |

> **為何本論文不把 Keycloak 列入對照？** Keycloak 解決的是「**使用者**怎麼證明自己是
> 誰」，而本研究探討的是「**workload (php-worker / saga / CI4 service)** 之間怎麼遞送
> credential」。同一張表硬比會混淆主體。Keycloak 在此放在 §2「相關工作」做機制差異
> 對比即可，不進入 §5 量測組。

---

## 9. 一頁速查

```
  SPIFFE/SPIRE                Keycloak                  Vault
  ──────────────              ─────────                 ──────
  WHO: workload                WHO: human                WHO: anything
  HOW: attestation             HOW: password+OIDC        HOW: AppRole / k8s SA / token
  GIVE: SVID (X.509/JWT)       GIVE: OIDC tokens         GIVE: token + secret
  ROTATE: agent re-issue       ROTATE: refresh_token     ROTATE: agent re-render
  TRUST: trust bundle          TRUST: JWKS               TRUST: Vault CA + lease
  SECRET ON CLIENT? no         SECRET ON CLIENT? yes (pw) SECRET ON CLIENT? yes (role/secret_id)
  STANDARD: SPIFFE spec        STANDARD: OIDC/OAuth2     STANDARD: 自家 API
  KILLER FEATURE:              KILLER FEATURE:           KILLER FEATURE:
   平台 attestation 0 secret    完整 SSO + UI + 社交登入    動態 DB user / PKI / KV 一站式
```
****