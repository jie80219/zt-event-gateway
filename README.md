# zt-event-gateway

Starter skeleton for:

- PHP event-driven flow (producer + consumer)
- Envoy gateway for centralized routing and ingress management
- SPIFFE/SPIRE-ready secure routing config for workload identity verification

## 1) Run the stack

Prerequisite: Docker Desktop (or Docker Engine) must be running.

```bash
composer install
docker compose up --build -d
```

Health check through Envoy gateway:

```bash
curl http://localhost:8080/api/health
```

Publish an order event:

```bash
curl -X POST http://localhost:8080/api/orders \
  -H "Content-Type: application/json" \
  -d '{"customerId":"cust-001","amount":1680,"currency":"TWD"}'
```

Watch worker logs:

```bash
docker logs -f zt-php-worker
```

RabbitMQ management UI through unified gateway entry:

- http://localhost:8080/rabbitmq/
- login: `guest / guest`

## 2) Event contract

Events are published to exchange `domain.events` with routing key `orders.created.v1`.

```json
{
  "eventId": "uuid-v7",
  "eventType": "orders.created.v1",
  "occurredAt": "2026-03-03T09:00:00+00:00",
  "source": "php-gateway",
  "payload": {
    "orderId": "...",
    "customerId": "...",
    "amount": 1680,
    "currency": "TWD"
  }
}
```

## 3) SPIFFE/SPIRE integration

- secure Envoy config: `docker/envoy/envoy-spiffe.yaml`
- SPIRE setup notes: `docs/spiffe-spire.md`

Recommended rollout:

1. Run the default compose stack first.
2. Deploy SPIRE server and agents.
3. Register workload SPIFFE IDs for gateway and php-gateway.
4. Switch Envoy to `envoy-spiffe.yaml` to enforce mTLS and SAN checks.

## 4) Key files

- `public/index.php`: HTTP API (`POST /orders`, `GET /health`)
- `src/Event/RabbitMqEventBus.php`: event publish/consume plumbing
- `src/Worker/OrderCreatedWorker.php`: consumer worker handler
- `docker/envoy/envoy.yaml`: unified gateway routing
- `docker/envoy/envoy-spiffe.yaml`: SPIFFE/SPIRE-secure gateway routing
