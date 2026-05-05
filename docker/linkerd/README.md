# Linkerd 1.x mesh — zt-event-gateway

Buoyant Linkerd 1.7.5 (JVM/Finagle, **EOL**) used as observability +
service-discovery + L7 control plane across the 4-host deployment.

## Topology

```
Host A (gateway+worker, rabbitmq, eventstoredb, zipkin)
   linkerd :4140 (outgoing) → 10.1.1.{210,207,214}:4141
Host B (order)        linkerd :4141 (incoming) → order-service:8080
Host C (production)   linkerd :4141 (incoming) → production-service:8080
Host D (user)         linkerd :4141 (incoming) → user-service:8080
```

PHP outbound (`OrderService`, `ProductionService`, `UserService`,
`product_service`) is rewritten by `Filters\LinkerdHostHeaderFilter` to set
`Host: <serviceName>`; Linkerd uses the Host header to route via dtab + file
namer.

## Layout

```
config/linkerd.host-{a,b,c,d}.yaml   # per-host linkerd config
disco/host-{a,b,c,d}/...             # io.l5d.fs entries (port host)
tls/gen-certs.sh                     # self-signed CA + 4 host certs
tls/{ca/, certs/, ca.crt, host-*.{crt,key}}
scripts/distribute-certs.sh          # rsync wrapper to 4 hosts
```

## Phase rollout

| Phase | What | TLS | Rollback |
|---|---|---|---|
| 1 | Host A linkerd outgoing-only; downstream still direct | off | revert `*_SERVICE_HOST` env to `10.1.1.x` |
| 2 | 4 hosts with incoming + outgoing; disco points to peer `:4141` | off | rewrite disco back to direct app ports |
| 3 | Enable mTLS (canary order: user → production → order) | on | comment out `tls:` blocks, reload |
| 4 | Lower zipkin sampleRate (1.0 → 0.1), add prometheus + grafana | on | n/a |

## Phase 1 quickstart (single host A)

```bash
# 1. start the mesh overlay (linkerd + zipkin)
docker compose -f docker-compose.yml -f docker-compose.linkerd.yml up -d

# 2. health-check the proxy
curl -s http://localhost:9990/admin/ping     # → pong
curl -s http://localhost:9411/health         # zipkin

# 3. exercise saga
curl -i -X POST http://localhost:8080/api/orders -H 'Content-Type: application/json' -d '...'

# 4. observe
curl -s http://localhost:9990/admin/metrics/prometheus | grep '^rt:outgoing'
open http://localhost:9411    # zipkin UI
```

## Phase 3 — turn on mTLS

```bash
# 1. generate cert tree (run once on management host)
bash docker/linkerd/tls/gen-certs.sh <HOST_A_IP> 10.1.1.210 10.1.1.207 10.1.1.214

# 2. distribute (excludes ca.key)
bash docker/linkerd/scripts/distribute-certs.sh

# 3. uncomment the `tls:` blocks in each linkerd.host-*.yaml + reload linkerd
docker compose restart linkerd     # per host
```

## Verify cross-host TLS

```bash
openssl s_client -connect 10.1.1.210:4141 \
  -CAfile docker/linkerd/tls/ca.crt \
  -cert docker/linkerd/tls/host-a.crt \
  -key  docker/linkerd/tls/host-a.key
```

## Troubleshooting

| Symptom | Check |
|---|---|
| 504 from outgoing | `curl :9990/delegator?path=/svc/OrderService` for dtab resolution |
| 502 incoming | disco file content; linkerd & app on same docker network |
| Host header is `linkerd:4140` | `LinkerdHostHeaderFilter` not wired into `$filters[before]` |
| TLS handshake failed | `commonNamePattern` ↔ server cert CN mismatch |

## Files NOT in git

- `docker/linkerd/tls/ca/ca.key` — private CA key, keep on management host only
- `docker/linkerd/tls/certs/*.key` — per-host private keys
- `docker/linkerd/tls/host-*.key` — staged copies of the above
