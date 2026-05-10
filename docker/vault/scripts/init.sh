#!/bin/sh
# Bootstrap Vault for zt-event-gateway. Idempotent: safe to re-run.
# Runs inside the vault-init container; assumes vault server is reachable
# at $VAULT_ADDR with a root token in $VAULT_TOKEN.
set -eu

: "${VAULT_ADDR:?VAULT_ADDR not set}"
: "${VAULT_TOKEN:?VAULT_TOKEN not set}"

echo "[vault-init] waiting for vault to be ready..."
i=0
until vault status >/dev/null 2>&1; do
  i=$((i + 1))
  if [ "$i" -gt 60 ]; then
    echo "[vault-init] vault not ready after 60s, aborting" >&2
    exit 1
  fi
  sleep 1
done
echo "[vault-init] vault ready."

# --- AppRole auth method ---
if ! vault auth list -format=json | grep -q '"approle/"'; then
  vault auth enable approle
  echo "[vault-init] enabled approle"
else
  echo "[vault-init] approle already enabled"
fi

# --- KV v2 secrets engine at secret/ (already mounted in dev mode, idempotent for prod) ---
if ! vault secrets list -format=json | grep -q '"secret/"'; then
  vault secrets enable -path=secret -version=2 kv
  echo "[vault-init] enabled kv v2 at secret/"
else
  echo "[vault-init] secret/ already mounted"
fi

# --- Seed secrets (overwrites are fine; values are the same as previously hard-coded) ---
vault kv put secret/zt-event-gateway/rabbitmq \
  user=zt \
  pass=ztpass

vault kv put secret/zt-event-gateway/jwt \
  secret='LcNsT2z3SZCtfjYtxzyp4RxgghAy0crm'

vault kv put secret/zt-event-gateway/db/order \
  host=order_DB \
  db=order \
  user=root \
  pass=root \
  port=5432

vault kv put secret/zt-event-gateway/db/user \
  host=user_DB \
  db=user \
  user=root \
  pass=root \
  port=5432

vault kv put secret/zt-event-gateway/db/production \
  host=production_DB \
  db=production \
  user=root \
  pass=root \
  port=5432

echo "[vault-init] secrets seeded"

# --- Policies + AppRoles + creds ---
mkdir -p /vault/creds

for role in php-worker anser-gateway order-svc user-svc production-svc; do
  vault policy write "$role" "/vault/policies/${role}.hcl"

  vault write -f "auth/approle/role/${role}" \
    token_policies="$role" \
    token_ttl=1h \
    token_max_ttl=24h \
    secret_id_ttl=0 \
    secret_id_num_uses=0

  mkdir -p "/vault/creds/${role}"

  # role_id is stable; rewrite each run for simplicity
  vault read -field=role_id "auth/approle/role/${role}/role-id" \
    > "/vault/creds/${role}/role_id"

  # Only mint a new secret_id if there isn't one yet (lets agents keep working
  # across re-runs of this script without losing their existing secret_id).
  if [ ! -s "/vault/creds/${role}/secret_id" ]; then
    vault write -f -field=secret_id "auth/approle/role/${role}/secret-id" \
      > "/vault/creds/${role}/secret_id"
    echo "[vault-init] minted secret_id for ${role}"
  else
    echo "[vault-init] secret_id for ${role} already present, leaving alone"
  fi

  # vault-init runs as root (image USER=root) so the rendered creds end up
  # root:root by default. The sidecar vault-agent containers drop to uid 100
  # (gid 1000, "vault") in docker-entrypoint.sh, so they can't read root:root
  # 0640 files. Hand ownership to vault:vault before exit so all sidecars
  # — including the ones on remote service hosts that bind-mount this dir —
  # can authenticate via AppRole.
  chown 100:1000 "/vault/creds/${role}/role_id" "/vault/creds/${role}/secret_id"
  chmod 0640 "/vault/creds/${role}/role_id" "/vault/creds/${role}/secret_id"
done

echo "[vault-init] done."
