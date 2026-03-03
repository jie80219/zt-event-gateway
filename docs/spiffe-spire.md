# SPIFFE / SPIRE integration guide

This folder contains a practical baseline to add workload identity for the PHP gateway deployment.

## Components

- `spire-server/server.conf`: SPIRE Server configuration (trust domain `zt.local`)
- `spire-agent/agent.conf`: SPIRE Agent configuration template
- `../docker/envoy/envoy-spiffe.yaml`: Envoy config that enforces mTLS with SPIFFE IDs
- `scripts/register-workloads.sh`: registration template for gateway workloads

## Recommended rollout

1. Keep local development on `docker-compose.yml` (non-mTLS) first.
2. Deploy SPIRE Server + Agents (Docker/Kubernetes).
3. Register workload identities:
   - `spiffe://zt.local/gateway`
   - `spiffe://zt.local/php-gateway`
4. Switch Envoy from `envoy.yaml` to `envoy-spiffe.yaml`.
5. Verify request path `/api/orders` only succeeds when SAN is `spiffe://zt.local/php-gateway`.

## Notes

- PHP process itself does not need to parse SVID directly when Envoy sidecars terminate and verify mTLS.
- The gateway can pass verified identity to PHP through `x-spiffe-id` if needed.
