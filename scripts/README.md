# Scripts

## Quick usage

```bash
chmod +x scripts/*.sh
./scripts/e2e-gateway.sh   # smoke test (gateway + worker + saga happy path)
./scripts/ci-verify.sh     # CI wrapper: unit + repeated E2E
```

## 目前留下的腳本

| 腳本 | 用途 |
|------|------|
| `e2e-gateway.sh` | Gateway 煙霧測試：健康檢查、`POST /api/orders` 回 202、envelope 落到 `order_queue`、worker 消費 |
| `e2e-failure-modes.sh` | 邊界情境：壞 JSON、缺欄位、AMQP 異常 |
| `ci-verify.sh` | CI 驅動：跑 unit tests + 重複 E2E（`E2E_RUNS`） |
| `diagnose-order-saga.sh` | 互動式診斷：抽 worker log 找出 saga 卡在哪一步 |
| `experiments/run-perf-experiment.sh` | 效能實驗（可指定 scale/concurrency） |
| `experiments/run-perf-multihost.sh` | 4-host 拓樸下的效能實驗驅動（透過 SSH） |
| `experiments/analyze-perf-experiment.py` | perf-experiment 結果分析 |
| `experiments/load-driver.py` | 訂單壓測驅動 |
| `e2e-failure-modes.sh` | E2E 失敗模式測試 |

執行 E2E 前確保 docker-compose stack 已起：

```bash
docker compose up -d rabbitmq eventstoredb gateway php-worker
```
