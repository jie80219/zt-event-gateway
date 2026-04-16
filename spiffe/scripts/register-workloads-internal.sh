#!/usr/bin/env sh
set -eu

SPIRE_BIN="/opt/spire/bin/spire-server"
SOCKET="/tmp/spire-server/private/api.sock"
TRUST_DOMAIN="zt.local"
INTERVAL="${REGISTER_INTERVAL:-60}"

log() { echo "[workload-registrar] $(date +%T) $*"; }

register() {
    local spiffe_id="$1" selector="$2"
    $SPIRE_BIN entry create \
        -socketPath "$SOCKET" \
        -parentID "$AGENT_ID" \
        -spiffeID "$spiffe_id" \
        -selector "$selector" \
        -x509SVIDTTL 3600 2>&1 | grep -v "AlreadyExists" || true
}

while true; do
    until $SPIRE_BIN healthcheck -socketPath "$SOCKET" 2>/dev/null; do
        log "Waiting for SPIRE Server..."
        sleep 5
    done

    AGENT_ID=""
    until [ -n "$AGENT_ID" ]; do
        AGENT_ID=$($SPIRE_BIN agent list -socketPath "$SOCKET" 2>&1 \
            | grep -oE 'spiffe://[^ "]+' | head -1 || echo "")
        [ -z "$AGENT_ID" ] && { log "Waiting for agent attestation..."; sleep 2; }
    done

    log "Agent: $AGENT_ID — registering workloads..."
    register "spiffe://${TRUST_DOMAIN}/php-gateway"        "unix:uid:0"
    register "spiffe://${TRUST_DOMAIN}/php-worker"          "unix:uid:0"
    register "spiffe://${TRUST_DOMAIN}/order-service"       "unix:uid:0"
    register "spiffe://${TRUST_DOMAIN}/production-service"  "unix:uid:0"
    register "spiffe://${TRUST_DOMAIN}/user-service"        "unix:uid:0"
    touch /tmp/registrar-ready
    log "Done. Sleeping ${INTERVAL}s..."
    sleep "$INTERVAL"
done
