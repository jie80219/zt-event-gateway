#!/usr/bin/env bash
# mtls-probe.sh — measure SPIFFE mTLS handshake cost.
#
# Runs INSIDE the zt-php-worker container. Spins up an openssl s_server
# loaded with the worker's SVID + CA bundle, then makes N curl requests
# back to it (loopback) and emits one `[perf-mtls]` line per request to
# stdout. The orchestrator captures those into worker_<scale>.log.
#
# Why a probe? The current build's saga path uses plain HTTP for downstream
# calls (SPIFFE_MTLS_ENABLED gate is wired but the worker's actual service
# clients don't yet route through SpiffeTlsContext). This probe measures
# what an mTLS-enabled call WOULD cost on the same container/CPU.
#
# Usage:
#   bash /app/scripts/experiments/mtls-probe.sh <count>
set -euo pipefail

COUNT="${1:-200}"
PORT="${MTLS_PROBE_PORT:-18443}"
CERT="${SPIFFE_PEM_DIR:-/tmp/spiffe-certs}/svid.pem"
KEY="${SPIFFE_PEM_DIR:-/tmp/spiffe-certs}/svid_key.pem"
CA="${SPIFFE_PEM_DIR:-/tmp/spiffe-certs}/bundle.pem"

if [[ ! -f "$CERT" || ! -f "$KEY" || ! -f "$CA" ]]; then
    echo "[mtls-probe] missing SPIFFE cert files at $CERT / $KEY / $CA" >&2
    exit 1
fi

# ── Start mTLS-required s_server in background ────────────────────────────
openssl s_server \
    -accept "$PORT" \
    -cert "$CERT" -key "$KEY" \
    -CAfile "$CA" \
    -Verify 1 \
    -tls1_3 -www -quiet \
    -naccept $((COUNT + 20)) \
    >/tmp/mtls-server.log 2>&1 &
SRV_PID=$!

cleanup() {
    if kill -0 "$SRV_PID" 2>/dev/null; then
        kill "$SRV_PID" 2>/dev/null || true
        wait "$SRV_PID" 2>/dev/null || true
    fi
}
trap cleanup EXIT

# Wait for server to bind
for i in 1 2 3 4 5 6 7 8 9 10; do
    if curl -sk --max-time 1 \
        --cert "$CERT" --key "$KEY" --cacert "$CA" \
        "https://localhost:${PORT}/" -o /dev/null 2>/dev/null; then
        break
    fi
    sleep 0.2
    if [[ "$i" == "10" ]]; then
        echo "[mtls-probe] s_server failed to start" >&2
        cat /tmp/mtls-server.log >&2 || true
        exit 1
    fi
done

# ── Loop N requests, emit [perf-mtls] line per call ──────────────────────
# CURLOPT_FORBID_REUSE/FRESH_CONNECT equivalent: use --no-keepalive and
# bypass connection cache by specifying a new resolution each iteration.
# In practice curl reopens the TCP+TLS connection between separate curl
# invocations, so each one performs a full handshake.
for i in $(seq 1 "$COUNT"); do
    line="$(curl -sk --max-time 5 \
        --cert "$CERT" --key "$KEY" --cacert "$CA" \
        --resolve "localhost:${PORT}:127.0.0.1" \
        -o /dev/null \
        -w '%{time_appconnect} %{time_connect} %{time_total}' \
        "https://localhost:${PORT}/" 2>/dev/null || echo "0 0 0")"
    read -r app conn total <<<"$line"
    # Convert seconds → ms with 3 decimals via awk (no bc dependency)
    awk -v a="$app" -v c="$conn" -v t="$total" -v p="$PORT" \
        'BEGIN { printf "[perf-mtls] handshake_ms=%.3f connect_ms=%.3f total_ms=%.3f url=https://localhost:%s/\n", a*1000, c*1000, t*1000, p }'
done
