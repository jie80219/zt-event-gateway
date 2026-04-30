# `feat/spiffe` vs `feat/go-spiffe` 分支比較分析

## Context
使用者要求比較兩條分支的差異，了解 `feat/go-spiffe` 相對於 `feat/spiffe` 引入了哪些改動、屬於哪類工作（實作 / 清理 / 文件），並釐清兩者的核心架構方向是否一致。本份非實作任務 — 僅為分析報告。

---

## 基本事實（git topology）

- 共同祖先（merge-base）：`bf60a85` "update scripts"
- `feat/spiffe` 超前 1 commit（僅文件與設定；無程式碼）
- `feat/go-spiffe` 超前 3 commits（一次大型 Go 實作 + 兩次清理）
- 兩條分支**沒有分叉的程式碼衝突**，`feat/go-spiffe` 是 `feat/spiffe` 架構的**後繼演進**
- 截至 HEAD：
  - `feat/spiffe` = `c13c65d`
  - `feat/go-spiffe` = `7c4af61`

---

## `feat/spiffe` 獨有的 1 commit

| Commit | 內容 | 類別 |
|---|---|---|
| `c13c65d` "add file" | 新增 `docs/thesis/ch4-resilience.md`（343 行，論文韌性章節）、更新 `docs/data/lsvid-bench-latest.json`、調整 `.claude/settings.local.json` | 論文／文件 |

**關鍵觀察**：`feat/spiffe` 超前的唯一變更是**論文文件**，沒有任何程式碼差異。

---

## `feat/go-spiffe` 獨有的 3 commits

### 1. `f4f4ce5` chore: 清理未使用與重複的檔案（-2,737 行）
- 刪除 `src/Spiffe/SpiffeHelper.php`（**785 行**，舊版單體實作）
- 刪除 `anser-gateway/Dockerfile*`、`env`、`supervisord*.conf`（已由 `docker/php-openswoole/Dockerfile` 取代）
- 刪除 `spiffe/helper/*.conf`（spiffe-helper 配置，已由 `bin/spiffe-watcher.php` 取代）
- 刪除 `scripts/e2e-full-stack.sh`（750 行，已由 `e2e-full-architecture.sh` 取代）
- 刪除重複的 `src/GPBMetadata/Workloadapi.php` 與 `spiffe/proto/workloadapi.proto`（保留 `packages/php-spiffe` 內版本）
- 移除 CI artifacts、`.phpunit.cache/`、`supervisord.pid`、`packages/php-spiffe/bin/spiffe-watcher.php`
- 修正 `composer.json` autoload 指向 `packages/php-spiffe/src/GPBMetadata/`

### 2. `e325acb` chore: 移除 scripts/ 與 docs/ 中已棄用的檔案（-125 行）
- 刪除 `scripts/check_status.sh`、`run_all_events.sh`、`stop_all.sh`（已由 docker compose healthcheck / down 取代）
- 刪除 `docs/Data-flow.mmd`、`docs/spiffe-spire.md`（已被更完整文件涵蓋）

### 3. `7c4af61` update spiffe for go lang（+6,430 行）— 核心變更
**新增 Go sidecar 服務 `spiffe-sidecar/`**（取代部分 PHP 職責）：
- `cmd/spiffe-sidecar/main.go` — 主程式（148 行）
- `internal/watcher/watcher.go` — SPIRE Workload API 監聽（298 行）
- `internal/lsvid/{lsvid,signer,validator,jti_cache}.go` — LSVID 簽署／驗證引擎（1,016 行）+ 測試 625 行
- `internal/shm/{schema,writer}.go` — SHM seqlock 寫入（343 行）+ 測試 235 行
- `internal/api/{handlers,protocol,server}.go` — 以 HTTP 對 PHP 暴露 `POST /lsvid/create-base`、`/lsvid/extend`、`/lsvid/validate`、`GET /health`、`/identity`（359 行）
- `internal/config/config.go` — 環境變數設定（105 行）
- `go.mod` 依賴 `github.com/spiffe/go-spiffe/v2 v2.4.0`、`go-jose/go-jose/v4`
- `Dockerfile` + `Makefile`

**配套 PHP 與腳本調整**：
- `src/MessageQueue/Consumer.php` — 加入 `MAX_RETRIES=3` 與 `x-delivery-count` / `x-death` header 追蹤，避免失敗訊息無限 requeue
- `bin/worker.php` — 預設下游 port 從 `8081/8082/8083` 修正為 `8082/8083/8084`
- `scripts/start-go-services.sh`、`stop-go-services.sh`、`analyze-codebase.sh`、`analyze-health.sh`、`analyze-spiffe.sh`（1,215 行新腳本）
- `scripts/e2e-full-architecture.sh` 從 23→28 測試，加入失敗診斷 dump（Gateway / Worker logs、RMQ queue、container status）、事件層級 schema 驗證、LSVID chain issuer 驗證
- `scripts/e2e-gateway.sh` +74 行
- `spiffe/scripts/register-workloads-internal.sh` 更新註冊流程

**大量單元測試新增**（+1,870 行）：
- `tests/Unit/Events/EventClassTest.php`
- `tests/Unit/HandlerScannerTest.php`
- `tests/Unit/Ingress/CanonicalOrderRequestTest.php`
- `tests/Unit/MessageQueue/ConsumerTest.php`
- `tests/Unit/MessageQueue/MessageBusTest.php`
- `tests/Unit/QueueTopologyTest.php`
- `tests/Unit/Worker/EventConsumerTest.php`
- `tests/Unit/Worker/RequestConsumerTest.php`

---

## 架構方向差異

| 面向 | `feat/spiffe` | `feat/go-spiffe` |
|---|---|---|
| LSVID 簽署／驗證語言 | 純 PHP（`packages/php-lsvid` + `SpiffeHelper.php`） | Go sidecar 對外提供 HTTP API，PHP 呼叫 |
| SVID watcher | `bin/spiffe-watcher.php`（PHP） | Go `internal/watcher`（保留 PHP 版作為過渡） |
| SHM writer | PHP `SpiffeTableStore` | Go `internal/shm/writer.go`（新增並行路徑） |
| 舊單體 `SpiffeHelper.php` | 保留 785 行 | 已刪除 |
| 訊息消費重試 | 無限 requeue | MAX_RETRIES=3 + `x-delivery-count` header |
| E2E 測試數量 | 26 項 | 28 項，加入失敗診斷 dump |
| 單元測試 | 現況 | 8 支新測試檔（涵蓋 Event / Handler / Consumer / QueueTopology） |
| 下游服務預設 port | 8081/8082/8083 | 8082/8083/8084（與 CLAUDE.md 一致） |
| 獨有論文文件 | `ch4-resilience.md`（343 行） | — |

---

## 核心結論

1. **`feat/go-spiffe` 是 `feat/spiffe` 的下一代版本**：先做清理（移除 2,862 行已棄用程式碼與 PHP 舊實作），再導入 Go sidecar 作為 LSVID 與 SHM 的**關鍵路徑載體**，PHP 應用層透過 HTTP API 呼叫 sidecar，改以 go-spiffe SDK 取代 PHP gRPC 客戶端。
2. **`feat/spiffe` 獨有的 343 行論文章節（`ch4-resilience.md`）在 `feat/go-spiffe` 不存在** — 若要合併，需把該文件 cherry-pick 過去，否則會遺失。
3. **安全性改進**：`Consumer.php` 的 `MAX_RETRIES` 限制避免 poison message 無限循環，屬於真實生產環境的必要修補。
4. **測試覆蓋明顯強化**：`feat/go-spiffe` 新增 8 支 PHP 單元測試 + Go sidecar 自帶 1,085 行測試（`lsvid_test.go` 625、`shm_test.go` 235 等）。
5. **合併建議路徑**：以 `feat/go-spiffe` 為主幹，將 `c13c65d` 的論文文件 cherry-pick 進來即可得到兩者全部內容；兩邊沒有程式碼層級的衝突。

---

## 關鍵檔案清單（供後續追查）

- Go sidecar：`spiffe-sidecar/cmd/spiffe-sidecar/main.go`
- Go LSVID 核心：`spiffe-sidecar/internal/lsvid/{lsvid,signer,validator}.go`
- Go SHM：`spiffe-sidecar/internal/shm/writer.go`
- PHP Consumer 重試邏輯：`src/MessageQueue/Consumer.php:12`（`MAX_RETRIES`）、`:60`（`getDeliveryCount`）
- 啟動腳本：`scripts/start-go-services.sh`、`scripts/stop-go-services.sh`
- 擴充 E2E：`scripts/e2e-full-architecture.sh`
- `feat/spiffe` 獨有論文：`docs/thesis/ch4-resilience.md`（僅存在於 `feat/spiffe`）

## Verification
此為分析任務，無實作。若要驗證結論可執行：

```bash
# 確認 commit 範圍
git log --oneline $(git merge-base feat/spiffe feat/go-spiffe)..feat/spiffe
git log --oneline $(git merge-base feat/spiffe feat/go-spiffe)..feat/go-spiffe

# 確認 ch4-resilience.md 僅在 feat/spiffe 存在
git ls-tree feat/spiffe -- docs/thesis/ch4-resilience.md
git ls-tree feat/go-spiffe -- docs/thesis/ch4-resilience.md

# 確認 SpiffeHelper.php 已在 feat/go-spiffe 刪除
git ls-tree feat/go-spiffe -- src/Spiffe/SpiffeHelper.php
```
