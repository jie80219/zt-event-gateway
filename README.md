# zt-event-gateway

OpenSwoole gateway + RabbitMQ worker pipeline for order ingress and Saga-orchestrated event forwarding.

## 1. Run the stack

Prerequisite: Docker Desktop (or Docker Engine) is running.

```bash
composer install
docker compose up --build -d rabbitmq gateway php-worker
```

Gateway endpoints:

- `GET  http://10.1.1.209:8080/api/health`
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

Gateway response for valid input:

- HTTP `202`
- body contains `trace_id`

## 2. Main flow

`Gateway -> order_queue -> RequestConsumer -> Saga events`

1. Gateway receives `POST /api/orders`.
2. Gateway normalizes payload to canonical order data.
3. Gateway publishes canonical envelope to exchange `events` with routing key `request.new`.
4. Queue `order_queue` receives the request message.
5. `RequestConsumer` validates the canonical envelope and republishes `OrderCreateRequestedEvent`.
6. Saga handlers in `Sagas/OrderSaga.php` orchestrate downstream calls.

## 3. Canonical ingress contract (`schema_version=1`)

```json
{
  "schema_version": 1,
  "type": "gateway.request",
  "route": "OrderCreateRequestedEvent",
  "id": "trace-demo-001",
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

## 4. E2E smoke test

```bash
composer gateway:e2e
# or
bash scripts/e2e-gateway.sh
```

Assertions:

1. health endpoint returns `200`
2. order request returns `202` + `trace_id`
3. `order_queue` contains the request with `route` + canonical schema
4. worker invokes `request-consumer`

Environment options:

- `E2E_KEEP_ON_FAIL=1` keep containers on failure
- `E2E_BUILD_IMAGES=0|1` (default `1`)
- `E2E_WAIT_TIMEOUT=<seconds>` (default `60`)

## 5. CI entrypoint

```bash
composer ci:baseline   # runs scripts/ci-verify.sh in baseline mode
```

Behavior:

1. run unit tests
2. prebuild gateway + php-worker images
3. run `scripts/e2e-gateway.sh` (E2E_RUNS times)
4. on failure, dump docker logs into `artifacts/ci/`

## 6. Related files

- `bin/gateway.php` — OpenSwoole gateway ingress + queue publish
- `bin/worker.php` — worker bootstrap + queue subscriptions
- `src/Ingress/CanonicalOrderRequest.php` — canonical schema normalization + envelope validation
- `src/Worker/RequestConsumer.php` — request consumer (envelope → event publish)
- `src/Worker/EventConsumer.php` — saga event dispatch
- `Sagas/OrderSaga.php` — saga orchestration handlers
- `scripts/e2e-gateway.sh` — end-to-end smoke test
- `scripts/ci-verify.sh` — CI-oriented unit + E2E runner
