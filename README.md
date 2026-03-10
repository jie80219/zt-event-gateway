# zt-event-gateway

Starter skeleton for:

- PHP event-driven flow (producer + consumer)
- Anser-Gateway for API ingress before event-driven workflow
- SPIFFE/SPIRE-ready secure routing config for workload identity verification

## 1) Run the stack

Prerequisite: Docker Desktop (or Docker Engine) must be running.

```bash
composer install
docker compose up --build -d
```

Gateway entrypoints:
- `http://localhost:8080` => Anser-Gateway (`anser-gateway`)

Health check through Anser-Gateway:

```bash
curl http://localhost:8080/api/health
```

Publish an order event:

```bash
curl -X POST http://localhost:8080/api/orders \
  -H "Content-Type: application/json" \
  -d '{"customerId":"cust-001","amount":1680,"currency":"TWD"}'
```

Async ingress flow:

`Anser-Gateway -> request_queue -> RequestConsumer -> events exchange -> EventConsumer(OrderSaga)`

Watch worker logs:

```bash
docker logs -f zt-php-worker
```

Dynamic LB helper services:

```bash
docker logs -f zt-lb-monitor
docker logs -f zt-lb-recalc-worker
```

Infra UIs:

- Consul UI: http://localhost:8500
- Redis: `localhost:6379`

Quick checks for entropy score pipeline:

```bash
docker compose exec redis redis-cli HGETALL metrics:anser-gateway
docker compose logs monitor --tail=50
docker compose logs recalc-worker --tail=50
```

Anser-EDA style flow (manual):

```bash
composer install
php initialization.php
php consumer.php OrderCreateRequestedEvent
php consumer.php OrderCreatedEvent
php consumer.php InventoryDeductedEvent
php consumer.php PaymentProcessedEvent
php publisher.php
```

Anser-EDA style flow (inside Docker `app` container):

```bash
docker compose up --build -d
docker compose exec app php initialization.php
docker compose exec app php consumer.php OrderCreateRequestedEvent
```

RabbitMQ management UI through unified gateway entry:

- http://localhost:15672/
- login: `zt / ztpass`

## 2) Event contract

Gateway ingress writes order requests into queue `request_queue`:

```json
{
  "type": "OrderCreateRequestedEvent",
  "data": {
    "orderId": "...",
    "userKey": "...",
    "productList": [],
    "total": 1680
  }
}
```

Then `RequestConsumer` republishes system events to exchange `events`, and `EventConsumer` consumes the event queues generated from saga handlers.

## 3) SPIFFE/SPIRE integration

- secure Envoy config: `docker/envoy/envoy-spiffe.yaml` (optional, not enabled in current Anser-only compose)
- SPIRE setup notes: `docs/spiffe-spire.md`

Recommended rollout:

1. Run the default compose stack first.
2. Deploy SPIRE server and agents.
3. Register workload SPIFFE IDs for gateway workloads.
4. Switch Envoy to `envoy-spiffe.yaml` to enforce mTLS and SAN checks.

## 4) Key files

- `bin/worker.php`: Request/Event worker entrypoint
- `Sagas/OrderSaga.php`: Anser-EDA style saga orchestration
- `src/EventBus.php`: event publish/subscribe abstraction
- `src/MessageQueue/MessageBus.php`: RabbitMQ publish/bind plumbing
- `initialization.php`: auto setup queues from Saga handlers
- `consumer.php`: Anser-EDA style queue consumer
- `publisher.php`: event publisher
- `anser-gateway/config/Routes.php`: Anser-Gateway route entry
- `anser-gateway/system/ServiceDiscovery/LoadBalance/EntropyScoring.php`: entropy score calculation
- `anser-gateway/monitor/monitor_trigger.php`: metric variance monitor
- `anser-gateway/worker/recalc_worker.php`: MQ-driven score recalculation worker
