## Minimal Kubernetes manifests

This directory contains the smallest Kubernetes baseline for the current stack:

- `gateway` (Envoy)
- `php-gateway`
- `php-worker`
- `rabbitmq`

These manifests intentionally do not include SPIFFE/SPIRE yet. The goal is to match the current `docker-compose.yml` runtime path first.

### Prerequisites

1. Build and push the PHP app image to a registry your cluster can pull from.
2. Replace `IMAGE_PLACEHOLDER` in `php-gateway.yaml` and `php-worker.yaml`.

### Apply

```bash
kubectl apply -f k8s/
```

### Verify

```bash
kubectl get pods -n zt-event-gateway
kubectl port-forward -n zt-event-gateway svc/gateway 8080:8080
curl http://127.0.0.1:8080/api/health
```
