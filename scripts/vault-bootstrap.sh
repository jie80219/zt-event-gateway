#!/usr/bin/env bash
# Bring up Vault, seed secrets, mint AppRole creds, then start the rest of
# the stack. Idempotent: re-running will re-seed secrets but preserves the
# existing per-role secret_id files so already-running agents stay valid.
set -euo pipefail

cd "$(dirname "$0")/.."

if ! docker network inspect anser_project_network >/dev/null 2>&1; then
  echo "[vault] creating external network anser_project_network"
  docker network create anser_project_network >/dev/null
fi

echo "[vault] starting vault server..."
docker compose up -d vault

echo "[vault] waiting for vault to be healthy..."
for i in $(seq 1 60); do
  status=$(docker inspect -f '{{.State.Health.Status}}' zt-vault 2>/dev/null || echo "starting")
  if [ "$status" = "healthy" ]; then
    break
  fi
  sleep 1
done
if [ "$status" != "healthy" ]; then
  echo "[vault] vault did not become healthy within 60s" >&2
  docker logs zt-vault --tail 50 >&2 || true
  exit 1
fi

echo "[vault] running one-shot bootstrap (vault-init)..."
docker compose run --rm vault-init

echo "[vault] starting application stack..."
docker compose up -d

cat <<EOF

[vault] bootstrap complete.

  UI:        http://localhost:8200/ui
  Root tok:  zt-root  (dev mode)
  AppRole creds: docker/vault/creds/<role>/{role_id,secret_id}

  Next steps for CI4 services (separate compose projects):
    ( cd Services/Order_service     && docker compose up -d )
    ( cd Services/User_service      && docker compose up -d )
    ( cd Services/Production_service && docker compose up -d )

EOF
