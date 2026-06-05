#!/bin/bash

# ── Wait for Vault PKI mTLS material (rendered by vault-agent) ──
# The vault-agent sidecar writes tls.crt/tls.key/ca.crt to /vault/out,
# which RoadRunner's http.ssl listener serves on :8443. The compose
# command already guards on tls.crt, but re-check here for safety.
CERTS_DIR="/vault/out"
MAX_WAIT=120
ELAPSED=0

echo "[start_service] Waiting for Vault mTLS material in ${CERTS_DIR} ..."
while [ ! -s "${CERTS_DIR}/tls.crt" ] || [ ! -s "${CERTS_DIR}/tls.key" ] || [ ! -s "${CERTS_DIR}/ca.crt" ]; do
    sleep 2
    ELAPSED=$((ELAPSED + 2))
    if [ "$ELAPSED" -ge "$MAX_WAIT" ]; then
        echo "[start_service] WARNING: Vault mTLS material not found after ${MAX_WAIT}s — RoadRunner may fail to bind :8443"
        break
    fi
    echo "[start_service] Waiting... (${ELAPSED}s / ${MAX_WAIT}s)"
done

# ── Install dependencies ────────────────────────────────────────
if [ ! -d "./vendor" ]; then
    composer install
fi

if [ ! -f "./vendor/bin/rr_server" ]; then
    php spark burner:init RoadRunner
fi

# ── Start RoadRunner with retry ─────────────────────────────────
MAX_RETRIES=3
RETRY_DELAY=5
EXIT_CODE=1

for attempt in $(seq 1 "$MAX_RETRIES"); do
    echo "[start_service] Starting RoadRunner (attempt ${attempt}/${MAX_RETRIES})..."
    php spark burner:start
    EXIT_CODE=$?
    if [ "$EXIT_CODE" -eq 0 ]; then
        break
    fi
    echo "[start_service] RoadRunner exited with code ${EXIT_CODE}"
    if [ "$attempt" -lt "$MAX_RETRIES" ]; then
        echo "[start_service] Retrying in ${RETRY_DELAY}s..."
        sleep "$RETRY_DELAY"
    fi
done

exit "${EXIT_CODE}"
