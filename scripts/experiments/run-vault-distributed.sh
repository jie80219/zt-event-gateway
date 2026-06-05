#!/usr/bin/env bash
# ============================================================================
# Vault-stack perf runner — thin wrapper over run-dualmode-distributed.sh.
#
# The Vault stack (feat/vault) strips SPIFFE and runs a Workerman gateway with
# secrets managed by Vault Agent + AppRole. It differs from the SPIFFE+Keycloak
# dual-mode stack only in transport/identity wiring, so the load/drain/log-slice
# machinery is identical — we just override the stack parameters:
#
#   GW_CONTAINER       zt-anser-gateway   (Workerman gateway; SPIFFE = zt-gateway)
#   WORKER_CONTAINER   zt-php-worker      (same name as SPIFFE)
#   RABBIT_CONTAINER   zt-rabbitmq        (same name as SPIFFE)
#   TOKEN_MODE=none    /api/orders has no bearer filter → no Keycloak token
#   REQUEST_QUEUE_NAME=request_queue      (SPIFFE default = order_queue)
#   MTLS_PROBE_ENABLED=0                  (no mTLS on the Vault stack → N/A)
#
# Requires the [perf-request-in] / [perf-saga-step1] / [perf-saga-complete]
# markers to be present on feat/vault (commits e093742, 4837559) and the stack
# brought up with PERF_METRIC_ENABLED=1.
#
# Usage:  bash scripts/experiments/run-vault-distributed.sh <out-dir>
# Env:    SCALES (5000 10000 20000)  ROUNDS (warm cold)
#         GATEWAY_HOST  DRIVER_HOST  (see run-dualmode-distributed.sh)
# Output: <out-dir>/raw/{load,worker,mtls}_<round>_<scale>.{csv,log,err}
#         then run analyze-dualmode-experiment.py --in <out-dir>
# ============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"

GW_CONTAINER="${GW_CONTAINER:-zt-anser-gateway}" \
WORKER_CONTAINER="${WORKER_CONTAINER:-zt-php-worker}" \
RABBIT_CONTAINER="${RABBIT_CONTAINER:-zt-rabbitmq}" \
TOKEN_MODE="${TOKEN_MODE:-none}" \
REQUEST_QUEUE_NAME="${REQUEST_QUEUE_NAME:-request_queue}" \
MTLS_PROBE_ENABLED="${MTLS_PROBE_ENABLED:-0}" \
    exec bash "$HERE/run-dualmode-distributed.sh" "$@"
