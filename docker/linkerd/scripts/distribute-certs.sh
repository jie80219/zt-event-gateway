#!/usr/bin/env bash
# Distribute mesh certs (excluding ca.key) to 4 hosts.
# Adjust REPO_PATH per host if your deploy layout differs.
set -euo pipefail

HOSTS=(
  "10.1.1.0"     # host-a (gateway+worker)
  "10.1.1.210"   # host-b (order)
  "10.1.1.207"   # host-c (production)
  "10.1.1.214"   # host-d (user)
)
USER="${SSH_USER:-deploy}"
REMOTE_PATH="${REMOTE_PATH:-/opt/zt-event-gateway/docker/linkerd/tls}"

LOCAL_TLS="$(cd "$(dirname "$0")/../tls" && pwd)"

for host in "${HOSTS[@]}"; do
  echo "→ rsync to ${USER}@${host}:${REMOTE_PATH}"
  rsync -avz --delete \
    --exclude='ca/ca.key' \
    --exclude='certs/*.key' \
    --include='ca.crt' \
    --include='host-*.crt' \
    --include='host-*.key' \
    "${LOCAL_TLS}/" "${USER}@${host}:${REMOTE_PATH}/"
done
echo "Done. Each host now has ca.crt + host-{a,b,c,d}.crt + host-{a,b,c,d}.key"
