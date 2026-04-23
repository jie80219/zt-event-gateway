#!/usr/bin/env sh
# ──────────────────────────────────────────────────────────────────
#  Workload Registrar — multi-agent variant
#
#  Registers each SPIFFE ID under the agent that will actually attest
#  it. The parent agent's x509pop hash is computed from that agent's
#  leaf certificate (SHA1 of the DER-encoded cert), which matches the
#  SPIFFE ID assigned by SPIRE Server at attestation time.
#
#  This removes the previous "head -1 / single parent" bug that made
#  every downstream spiffe-sidecar fail with "X509Source not
#  initialized" because their own agents could not issue an SVID.
#
#  Mappings are static (workload SPIFFE ID ↔ agent cert file). Missing
#  cert files (e.g. a microservice stack not yet brought up) are
#  tolerated — each loop iteration retries, so `docker compose up -d`
#  on any of the 4 stacks in any order converges once all 4 agents
#  have their certs bind-mounted.
#
#  The 60-second loop is also the recovery mechanism for agent cert
#  rotation: after you regenerate an agent cert (new hash), the next
#  iteration picks up the new fingerprint and registers a new entry.
# ──────────────────────────────────────────────────────────────────
set -eu

SPIRE_BIN="/opt/spire/bin/spire-server"
SOCKET="/tmp/spire-server/private/api.sock"
TRUST_DOMAIN="${TRUST_DOMAIN:-zt.local}"
INTERVAL="${REGISTER_INTERVAL:-60}"

log() { echo "[workload-registrar] $(date +%T) $*"; }

# Compute the agent's x509pop hash from its leaf cert PEM.
# SPIRE formats the hash as lowercase hex of SHA1(DER(leafCert)).
fingerprint() {
    openssl x509 -in "$1" -noout -fingerprint -sha1 2>/dev/null \
        | cut -d= -f2 | tr -d ':' | tr '[:upper:]' '[:lower:]'
}

# One row per workload: SPIFFE_ID|CERT_PATH|SELECTOR
# CERT_PATH is inside the registrar container — the host path is
# bind-mounted by docker-compose.yml.
read_mappings() {
    cat <<'EOF'
php-gateway|/agent-certs/zt/agent.crt.pem|unix:uid:0
php-worker|/agent-certs/zt/agent.crt.pem|unix:uid:0
order-service|/agent-certs/order/order-agent.crt.pem|unix:uid:0
production-service|/agent-certs/production/production-agent.crt.pem|unix:uid:0
user-service|/agent-certs/user/user-agent.crt.pem|unix:uid:0
EOF
}

register_entry() {
    spiffe_id="$1"
    parent_hash="$2"
    selector="$3"
    $SPIRE_BIN entry create \
        -socketPath "$SOCKET" \
        -parentID "spiffe://${TRUST_DOMAIN}/spire/agent/x509pop/${parent_hash}" \
        -spiffeID "spiffe://${TRUST_DOMAIN}/${spiffe_id}" \
        -selector "$selector" \
        -x509SVIDTTL 600 2>&1 | grep -v "AlreadyExists" || true
}

while true; do
    # Wait for SPIRE Server to be reachable
    until $SPIRE_BIN healthcheck -socketPath "$SOCKET" 2>/dev/null; do
        log "Waiting for SPIRE Server..."
        sleep 5
    done

    registered=0
    skipped=0
    read_mappings | while IFS='|' read -r spiffe_id cert_path selector; do
        [ -z "$spiffe_id" ] && continue
        if [ ! -f "$cert_path" ]; then
            log "[skip] $spiffe_id — cert missing at $cert_path (agent not up?)"
            skipped=$((skipped + 1))
            continue
        fi
        parent=$(fingerprint "$cert_path")
        if [ -z "$parent" ]; then
            log "[skip] $spiffe_id — fingerprint empty for $cert_path"
            skipped=$((skipped + 1))
            continue
        fi
        log "[reg]  $spiffe_id → parent=${parent}"
        register_entry "$spiffe_id" "$parent" "$selector"
        registered=$((registered + 1))
    done

    touch /tmp/registrar-ready
    log "Done. Sleeping ${INTERVAL}s..."
    sleep "$INTERVAL"
done
