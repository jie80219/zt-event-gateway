# Scripts

## Quick usage

```bash
chmod +x scripts/*.sh
./scripts/e2e-gateway.sh
./scripts/ci-verify.sh
```

## e2e-gateway.sh

Validates the gateway+worker backbone flow:

- `GET /api/health` returns `200`
- `POST /api/orders` returns `202` and `trace_id`
- `order_queue` contains canonical ingress envelope (`schema_version=1`) with SPIFFE metadata
- `php-worker` verifies source and republishes downstream event
- invalid input does not enter queue
- forged untrusted `spiffe_id` is rejected without retry storm

Main env vars:

- `E2E_QUEUE_CHECK_MODE=requeue|consume` (default: `requeue`)
- `E2E_DIAG_LEVEL=none|full` (default: `full`)
- `E2E_KEEP_ON_FAIL=1` keeps containers on failure
- `E2E_BUILD_IMAGES=0|1` (default: `1`)
- `E2E_WAIT_TIMEOUT=<seconds>`

## ci-verify.sh

CI-oriented single entry:

1. run Unit tests
2. run E2E repeatedly (`E2E_RUNS`, default `20`)
3. fail fast and collect logs under `artifacts/ci/e2e-run-N/`
4. prebuild images once by default (`CI_PREBUILD_IMAGES=1`)

Example:

```bash
E2E_RUNS=1 ./scripts/ci-verify.sh
```
