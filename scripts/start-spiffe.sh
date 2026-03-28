#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════
#  Start SPIFFE infrastructure + register workloads
#
#  This script:
#    1. Starts SPIRE Server (x509pop attestor)
#    2. Starts SPIRE Agent (auto-attests with x509pop certificate)
#    3. Registers workload entries (php-gateway, php-worker)
#    4. Starts spiffe-helper (writes PEM files to shared volume)
#    5. Starts all application services
#
#  Usage:
#    ./scripts/start-spiffe.sh
# ══════════════════════════════════════════════════════════════════
set -euo pipefail

log() { echo "[spiffe] $(date +%T) $*"; }

# ── 1. Start SPIRE Server ─────────────────────────────────────────
log "Starting SPIRE Server..."
docker compose up -d spire-server
log "Waiting for SPIRE Server healthy..."
until docker exec zt-spire-server /opt/spire/bin/spire-server healthcheck 2>/dev/null; do
    sleep 2
done
log "SPIRE Server is healthy."

# ── 2. Start SPIRE Agent (x509pop — no token needed) ──────────────
log "Starting SPIRE Agent (x509pop attestation)..."
docker compose up -d spire-agent
log "Waiting for SPIRE Agent healthy..."
elapsed=0
until docker exec zt-spire-agent /opt/spire/bin/spire-agent healthcheck 2>/dev/null; do
    sleep 3
    elapsed=$((elapsed+3))
    if [ $elapsed -ge 60 ]; then
        log "WARNING: Agent healthcheck timeout, checking logs..."
        docker logs zt-spire-agent --tail 5 2>&1
        break
    fi
done
log "SPIRE Agent started."

# ── 3. Register workloads ─────────────────────────────────────────
log "Registering workloads..."

# Get the agent's SPIFFE ID
AGENT_ID=$(docker exec zt-spire-server /opt/spire/bin/spire-server agent list 2>&1 | grep -oE 'spiffe://[^ "]+' | head -1 || echo "")
if [ -z "$AGENT_ID" ]; then
    log "WARNING: No agent attested yet. Using default parent ID."
    AGENT_ID="spiffe://zt.local/spire/agent/x509pop/$(openssl x509 -in spiffe/certs/agent.crt.pem -noout -fingerprint -sha256 2>/dev/null | sed 's/.*=//;s/://g' | tr 'A-F' 'a-f')"
fi
log "Agent ID: $AGENT_ID"

# Register php-gateway (ignore AlreadyExists)
docker exec zt-spire-server /opt/spire/bin/spire-server entry create \
    -parentID "$AGENT_ID" \
    -spiffeID "spiffe://zt.local/php-gateway" \
    -selector "unix:uid:0" \
    -x509SVIDTTL 3600 2>&1 | grep -v "AlreadyExists" || true

# Register php-worker
docker exec zt-spire-server /opt/spire/bin/spire-server entry create \
    -parentID "$AGENT_ID" \
    -spiffeID "spiffe://zt.local/php-worker" \
    -selector "unix:uid:0" \
    -x509SVIDTTL 3600 2>&1 | grep -v "AlreadyExists" || true

log "Registered entries:"
docker exec zt-spire-server /opt/spire/bin/spire-server entry show 2>&1 | grep "SPIFFE ID"

# ── 4. Start spiffe-helper ────────────────────────────────────────
log "Starting spiffe-helper (PEM file writer)..."
docker compose up -d spiffe-helper
sleep 5

# Verify PEM files exist
log "Checking PEM files..."
docker run --rm -v zt-event-gateway_spiffe-certs:/certs alpine ls -la /certs/ 2>&1

# ── 5. Start application services ─────────────────────────────────
log "Starting application services..."
docker compose up -d

log ""
log "═══════════════════════════════════════════════"
log "  SPIFFE infrastructure is ready!"
log ""
log "  SPIRE Server:  zt-spire-server (healthy)"
log "  SPIRE Agent:   zt-spire-agent (x509pop)"
log "  SPIFFE Helper: zt-spiffe-helper → /certs/"
log ""
log "  PEM files shared to Gateway + Worker:"
log "    /certs/svid.pem"
log "    /certs/svid_key.pem"
log "    /certs/bundle.pem"
log "═══════════════════════════════════════════════"
