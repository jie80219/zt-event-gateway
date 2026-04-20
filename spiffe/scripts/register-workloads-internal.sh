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

# Per-service agent fingerprints (SHA1 of that service's agent.crt.pem).
# Each service's SPIRE Agent uses a unique cert, producing a unique x509pop
# SPIFFE ID. Registering each workload under the CORRECT agent prevents slot
# thrashing caused by multiple agents sharing the same cert.
ZT_AGENT_FP="108285a34846632a2f590aa305bb6cf5647a0f9a"
PROD_AGENT_FP="d8100b37d64feba4a551203fbf0c4b54c0d451fd"
ORDER_AGENT_FP="7824e65cb1981adb4d336ca2b91849f69c4d2462"
USER_AGENT_FP="058c416cf5d8e9e0f3a862bccde1881f0c69878b"

agent_id() { echo "spiffe://${TRUST_DOMAIN}/spire/agent/x509pop/$1"; }

wait_for_agent() {
    local fp="$1" agent_id
    agent_id=$(agent_id "$fp")
    local tries=10
    until $SPIRE_BIN agent list -socketPath "$SOCKET" 2>&1 | grep -q "$agent_id"; do
        tries=$((tries - 1))
        if [ "$tries" -le 0 ]; then
            log "  WARN: agent $agent_id did not attest within 60s — skipping registration"
            return 1
        fi
        sleep 2
    done
    return 0
}

while true; do
    until $SPIRE_BIN healthcheck -socketPath "$SOCKET" 2>/dev/null; do
        log "Waiting for SPIRE Server..."
        sleep 5
    done

    log "Waiting for all 4 agents to attest..."
    AGENT_ID=$(agent_id "$ZT_AGENT_FP")
    until $SPIRE_BIN agent list -socketPath "$SOCKET" 2>&1 | grep -q "$AGENT_ID"; do
        log "  Waiting for zt-agent ($AGENT_ID)..."
        sleep 2
    done

    log "Registering workloads under their respective agents..."
    AGENT_ID=$(agent_id "$ZT_AGENT_FP")
    register "spiffe://${TRUST_DOMAIN}/php-gateway"        "unix:uid:0"
    register "spiffe://${TRUST_DOMAIN}/php-worker"          "unix:uid:0"

    if wait_for_agent "$PROD_AGENT_FP"; then
        AGENT_ID=$(agent_id "$PROD_AGENT_FP")
        register "spiffe://${TRUST_DOMAIN}/production-service"  "unix:uid:0"
    fi

    if wait_for_agent "$ORDER_AGENT_FP"; then
        AGENT_ID=$(agent_id "$ORDER_AGENT_FP")
        register "spiffe://${TRUST_DOMAIN}/order-service"       "unix:uid:0"
    fi

    if wait_for_agent "$USER_AGENT_FP"; then
        AGENT_ID=$(agent_id "$USER_AGENT_FP")
        register "spiffe://${TRUST_DOMAIN}/user-service"        "unix:uid:0"
    fi

    touch /tmp/registrar-ready
    log "Done. Sleeping ${INTERVAL}s..."
    sleep "$INTERVAL"
done
