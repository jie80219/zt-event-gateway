#!/usr/bin/env bash
set -euo pipefail

: "${SPIRE_SERVER_CONTAINER:=spire-server}"
: "${TRUST_DOMAIN:=zt.local}"
: "${GATEWAY_PARENT_ID:?Set GATEWAY_PARENT_ID first}"
: "${PHP_GATEWAY_PARENT_ID:?Set PHP_GATEWAY_PARENT_ID first}"

# Example:
# export GATEWAY_PARENT_ID=spiffe://zt.local/spire/agent/x509pop/<gateway-agent-fingerprint>
# export PHP_GATEWAY_PARENT_ID=spiffe://zt.local/spire/agent/x509pop/<php-gateway-agent-fingerprint>

/opt/spire/bin/spire-server entry create \
  -parentID "${GATEWAY_PARENT_ID}" \
  -spiffeID "spiffe://${TRUST_DOMAIN}/gateway" \
  -selector unix:user:envoy

/opt/spire/bin/spire-server entry create \
  -parentID "${PHP_GATEWAY_PARENT_ID}" \
  -spiffeID "spiffe://${TRUST_DOMAIN}/php-gateway" \
  -selector unix:user:envoy

/opt/spire/bin/spire-server entry show
