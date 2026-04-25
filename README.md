# zt-event-gateway

OpenSwoole gateway + RabbitMQ worker pipeline for order ingress and event forwarding.

## 1. Run the stack

Prerequisite: Docker Desktop (or Docker Engine) is running.

```bash
composer install
docker compose up --build -d rabbitmq gateway
```

Gateway endpoints:

- `GET http://10.1.1.209:8080/api/health`
- `POST http://10.1.1.209:8080/api/orders`

Quick health check:

```bash
curl http://10.1.1.209:8080/api/health
```

Submit an order request (ingress aliases are accepted and normalized):

```bash
curl -X POST http://10.1.1.209:8080/api/orders \
  -H "Content-Type: application/json" \
  -H "X-Correlation-Id: trace-demo-001" \
  -d '{"user_id":1,"product_list":[{"p_key":1,"amount":2}],"total":100}'
```

Gateway response for valid input remains:

- HTTP `202`
- body contains `trace_id`

## 2. Main flow

Business backbone in this phase:

`Gateway -> order_queue -> RequestConsumer -> events`

Current implementation path:

1. Gateway receives `POST /api/orders`.
2. Gateway normalizes payload to canonical order data.
3. Gateway publishes canonical envelope to exchange `events` with routing key `request.new`.
4. Queue `order_queue` receives the request message.
5. `RequestConsumer` validates canonical envelope and SPIFFE trust domain.
6. Worker republishes downstream event into `events` exchange.

## 3. Canonical ingress contract (`schema_version=1`)

Gateway now publishes a fixed envelope shape:

```json
{
  "schema_version": 1,
  "type": "gateway.request",
  "route": "OrderCreateRequestedEvent",
  "id": "trace-demo-001",
  "spiffe_id": "spiffe://zt.local/php-gateway",
  "spiffe_path": ["spiffe://zt.local/php-gateway"],
  "data": {
    "userKey": "1",
    "productList": [
      {"p_key": 1, "amount": 2}
    ],
    "total": 100
  }
}
```

Ingress field mapping used by gateway:

- `userKey` accepts aliases: `user_id`, `customerId`, `customer_id`
- `productList` accepts alias: `product_list`
- product `p_key` accepts aliases: `productId`, `product_id`
- product `amount` accepts aliases: `qty`, `quantity`
- `total` accepts alias: `amount`

Validation behavior:

- invalid JSON: HTTP `400`, no queue message
- missing required fields: HTTP `422`, no queue message
- worker rejects untrusted `spiffe_id` as unrecoverable message

## 4. E2E scripts

Run one full E2E suite:

```bash
composer gateway:e2e
```

Or directly:

```bash
./scripts/e2e-gateway.sh
```

E2E assertions include:

1. health endpoint returns `200`
2. order request returns `202 + trace_id`
3. `order_queue` contains same trace with `spiffe_id`, `spiffe_path`, `route`, and canonical schema
4. worker logs show SPIFFE source verification and downstream publish success
5. invalid JSON returns `400` and never enters queue
6. missing required fields returns `4xx` and never enters queue
7. forged untrusted SPIFFE message is rejected without requeue storm

Supported environment options:

- `E2E_QUEUE_CHECK_MODE=requeue|consume` (default: `requeue`)
- `E2E_DIAG_LEVEL=none|full` (default: `full`)
- `E2E_KEEP_ON_FAIL=1` to keep containers on failure
- `E2E_BUILD_IMAGES=0|1` (default: `1`)
- `E2E_WAIT_TIMEOUT=<seconds>`

## 5. CI entrypoint (Unit -> E2E)

Single command:

```bash
composer ci:verify
```

Behavior:

1. run unit tests
2. run E2E repeatedly (`E2E_RUNS`, default `20`)
3. stop on first E2E failure
4. collect docker logs into `artifacts/ci/e2e-run-N/`
5. prebuild gateway/worker images once by default (`CI_PREBUILD_IMAGES=1`)

Examples:

```bash
# default 20 runs
composer ci:verify

# quick local check
E2E_RUNS=1 composer ci:verify
```

## 6. Related files

- `bin/gateway.php`: OpenSwoole gateway ingress and queue publish
- `src/Ingress/CanonicalOrderRequest.php`: canonical schema normalization + envelope validation
- `src/Worker/RequestConsumer.php`: strict canonical consume + SPIFFE trust validation
- `bin/worker.php`: worker bootstrap and queue subscriptions
- `scripts/e2e-gateway.sh`: end-to-end gateway/worker verification
- `scripts/ci-verify.sh`: CI-oriented unit+E2E runner
- `docs/spiffe-spire.md`: SPIFFE/SPIRE setup notes
