#!/bin/sh
# ============================================================================
# Vault PKI bootstrap for pure-Vault service identity (feat/vault-pki).
#
# Replaces SPIRE: Vault is the CA, issues short-lived X.509 per service with a
# SPIFFE-style URI SAN (spiffe://zt.local/<service>). Each service gets its own
# PKI role (constrained to its own SPIFFE id) + AppRole (role_id/secret_id) so a
# vault-agent sidecar can fetch+renew only its own identity.
#
# Idempotent-ish: re-running re-applies config; AppRole secret_ids are reissued.
# Env: VAULT_ADDR (http://vault:8200), VAULT_TOKEN (root in dev), CREDS_DIR.
# ============================================================================
set -eu

: "${VAULT_ADDR:=http://127.0.0.1:8200}"
: "${VAULT_TOKEN:=root}"
: "${TRUST_DOMAIN:=zt.local}"
: "${CREDS_DIR:=/vault/creds}"
: "${SVC_TTL:=24h}"
export VAULT_ADDR VAULT_TOKEN

# service-name : spiffe-path
SERVICES="gateway:gateway worker:worker order-svc:order-service production-svc:production-service user-svc:user-service"

echo "[pki] enable + tune pki engine"
vault secrets enable pki 2>/dev/null || true
vault secrets tune -max-lease-ttl=87600h pki >/dev/null

echo "[pki] generate root CA (CN=$TRUST_DOMAIN)"
if ! vault read pki/cert/ca >/dev/null 2>&1; then
  vault write -field=certificate pki/root/generate/internal \
    common_name="$TRUST_DOMAIN" issuer_name="zt-root" ttl=87600h >/dev/null
fi
vault write pki/config/urls \
  issuing_certificates="${VAULT_ADDR}/v1/pki/ca" \
  crl_distribution_points="${VAULT_ADDR}/v1/pki/crl" >/dev/null

echo "[pki] enable approle"
vault auth enable approle 2>/dev/null || true

mkdir -p "$CREDS_DIR"
for entry in $SERVICES; do
  svc="${entry%%:*}"; spiffe="${entry##*:}"
  uri="spiffe://${TRUST_DOMAIN}/${spiffe}"
  echo "[pki] role=$svc  id=$uri"
  # PKI role constrained to this service's exact SPIFFE id + CN
  vault write "pki/roles/${svc}" \
    allowed_domains="${spiffe}.${TRUST_DOMAIN}" allow_bare_domains=true allow_subdomains=false \
    allowed_uri_sans="${uri}" \
    enforce_hostnames=false require_cn=false \
    max_ttl="$SVC_TTL" ttl="$SVC_TTL" key_type=rsa key_bits=2048 >/dev/null
  # policy: this service may only issue its own role
  vault policy write "${svc}-pki" - >/dev/null <<EOF
path "pki/issue/${svc}" { capabilities = ["create","update"] }
path "pki/cert/ca"      { capabilities = ["read"] }
EOF
  # approle per service
  vault write "auth/approle/role/${svc}" token_policies="${svc}-pki" \
    token_ttl=20m token_max_ttl=1h secret_id_ttl=0 >/dev/null
  rid=$(vault read -field=role_id "auth/approle/role/${svc}/role-id")
  sid=$(vault write -f -field=secret_id "auth/approle/role/${svc}/secret-id")
  mkdir -p "${CREDS_DIR}/${svc}"
  printf '%s' "$rid" > "${CREDS_DIR}/${svc}/role_id"
  printf '%s' "$sid" > "${CREDS_DIR}/${svc}/secret_id"
  chmod 644 "${CREDS_DIR}/${svc}/role_id" "${CREDS_DIR}/${svc}/secret_id"
done

echo "[pki] done. roles+approle creds in ${CREDS_DIR}/<svc>/{role_id,secret_id}"
