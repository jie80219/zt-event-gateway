#!/bin/bash

# ── Use HTTP-only RoadRunner config ─────────────────────────────
cp .rr-no-ssl.yaml .rr.yaml

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
