# Per-Run measurement results (count=2000, concurrency=100, warm, Group B: SPIFFE+Keycloak, no LSVID)

Measured via scripts/experiments/measure-run.sh. Stack: gateway+worker on 10.1.1.209,
downstream order/prod/user on zt-order/prod/user, WORKER_PROCESSES=4, prefetch=1.

| Run | commit | completion% | gw p50 | gw p99 | saga p50 | saga p95 | saga p99 | notes |
|-----|--------|-------------|--------|--------|----------|----------|----------|-------|
| 1 (baseline valid) | 4632e5d | 100.0 | 7.71 | 15.35 | 533.4 | 733.5 | 753.1 | token memo; load-driver userKey fix made completion measurable |
| 2 | 7b... (jwks memo) | 100.0 | 5.96 | 28.38 | 523.7 | 724.1 | 738.9 | JWKS keymap memoized |
| 3 | downstream keep-alive | 100.0 | 6.01 | 27.95 | 565.7 | 720.6 | 739.4 | TCP keepalive + reuse (cURL already reused; marginal) |
| 4 | 6d5a5a0 (keycloak keep-alive) | 100.0 | 6.05 | 28.08 | 602.67 | 692.47 | 769.51 | KeycloakClient keep-alive (watcher refresh path) |
