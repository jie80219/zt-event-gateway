## Minimal Kubernetes manifests

This directory contains the smallest Kubernetes baseline for the current stack:

- `gateway` (Envoy)
- `php-gateway`
- `php-worker`
- `rabbitmq`
- `user-service`
- `user-db`
- `order-service`
- `production-service`
- `payment-service`

These manifests intentionally do not include SPIFFE/SPIRE yet. The goal is to match the current `docker-compose.yml` runtime path first.

### Prerequisites

1. Build and push all application images to a registry your cluster can pull from.
2. Replace image placeholders:
   - `k8s/user-service.yaml` -> `IMAGE_USER_SERVICE`
   - `k8s/order-service.yaml` -> `IMAGE_ORDER_SERVICE`
   - `k8s/production-service.yaml` -> `IMAGE_PRODUCTION_SERVICE`
   - `k8s/payment-service.yaml` -> `IMAGE_PAYMENT_SERVICE`
3. `k8s/user-service.yaml` expects app files in `/app` (including `start_service.sh`) inside the image.
4. For parity with docker-compose (`webdevops/php:8.1 + ./app:/app`), build `IMAGE_USER_SERVICE` from that base image and copy your `./app` into `/app`.

### Apply

```bash
kubectl apply -f k8s/
```

### Verify

```bash
kubectl get pods -n zt-event-gateway
kubectl port-forward -n zt-event-gateway svc/gateway 8080:8080
curl http://127.0.0.1:8080/api/health

# Verify routed business APIs can reach target services
curl -i http://127.0.0.1:8080/api/v1/products
```
