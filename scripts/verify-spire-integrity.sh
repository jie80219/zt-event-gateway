#!/usr/bin/env bash
# ============================================================================
# SPIRE / SPIFFE trust-plane integrity probe.
#
# Verifies the cross-container contract between spire-server, spire-agent,
# workload-registrar, spiffe-watcher, and the SHM consumers (gateway/worker)
# in isolation from the application E2E. Use this:
#
#   • Locally (fail-fast) before running the full architecture E2E:
#       COMPOSE_PROFILES=zt bash scripts/verify-spire-integrity.sh
#   • In CI as a focused gate on the trust plane:
#       COMPOSE_PROFILES=zt bash scripts/verify-spire-integrity.sh
#
# Checks (each prints PASS/FAIL and bumps counters; exit=0 iff all passed):
#   1.  spire-server container healthy
#   2.  spire-agent container healthy
#   3.  spire-agent Workload API socket is present
#   4.  workload-registrar registered 5 expected SPIFFE IDs on spire-server
#   5.  spiffe-watcher container healthy
#   6.  spiffe-watcher SHM meta.json reports x509_state=ready and fresh
#   7.  SHM primary SVID slot (x509/0.json) is present with required fields
#   8.  spiffe-watcher Prometheus /metrics endpoint serves a 200 with counters
#       (only if host can reach the container — non-fatal)
#
# Exit codes:
#   0   all checks passed
#   1   one or more checks failed
#   2   prerequisite missing (docker / docker compose)
#
# Environment overrides:
#   COMPOSE_FILE           docker-compose.yml
#   SPIRE_EXPECTED_IDS     space-separated SPIFFE IDs the registrar must
#                          have created. Matches register-workloads-internal.sh.
#   SPIRE_STALE_SECS       fresh window for spiffe-watcher meta.json (default 120)
#   SPIRE_WATCHER_METRICS  optional URL for probe 8 (empty = skip)
# ============================================================================
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
STALE_SECS="${SPIRE_STALE_SECS:-120}"
METRICS_URL="${SPIRE_WATCHER_METRICS:-}"

if [[ -n "${SPIRE_EXPECTED_IDS:-}" ]]; then
    # shellcheck disable=SC2206
    EXPECTED_IDS=( ${SPIRE_EXPECTED_IDS} )
else
    EXPECTED_IDS=(
        "spiffe://zt.local/php-gateway"
        "spiffe://zt.local/php-worker"
        "spiffe://zt.local/order-service"
        "spiffe://zt.local/production-service"
        "spiffe://zt.local/user-service"
    )
fi

PASS_COUNT=0
FAIL_COUNT=0

log()  { printf '[spire-probe] %s %s\n' "$(date +%T)" "$*"; }
pass() { PASS_COUNT=$((PASS_COUNT + 1)); printf '\033[32m[PASS]\033[0m %s\n' "$*"; }
fail() { FAIL_COUNT=$((FAIL_COUNT + 1)); printf '\033[31m[FAIL]\033[0m %s\n' "$*" >&2; }
skip() { printf '\033[33m[SKIP]\033[0m %s\n' "$*"; }

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || { fail "missing command: $1"; exit 2; }
}

require_cmd docker
docker compose version >/dev/null 2>&1 || { fail "docker compose not available"; exit 2; }

cd "$PROJECT_DIR"

compose() { docker compose -f "$COMPOSE_FILE" "$@"; }

# ── Helper: inspect container health ─────────────────────────────────────
container_health() {
    # Returns "healthy" / "unhealthy" / "starting" / "none" / "missing".
    local svc="$1"
    local cid
    cid="$(compose ps -q "$svc" 2>/dev/null | head -1)"
    if [[ -z "$cid" ]]; then
        echo "missing"
        return
    fi
    docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$cid" 2>/dev/null || echo "unknown"
}

# ── Test 1: spire-server healthy ─────────────────────────────────────────
log "test 1: spire-server healthcheck"
h="$(container_health spire-server)"
if [[ "$h" == "healthy" ]]; then
    pass "spire-server is healthy"
else
    fail "spire-server health=${h} (expected healthy — is COMPOSE_PROFILES=zt set?)"
fi

# ── Test 2: spire-agent healthy ──────────────────────────────────────────
log "test 2: spire-agent healthcheck"
h="$(container_health spire-agent)"
if [[ "$h" == "healthy" ]]; then
    pass "spire-agent is healthy"
else
    fail "spire-agent health=${h}"
fi

# ── Test 3: Workload API socket reachable from a SHM-mounted container ──
#   The spire-agent image is scratch-based and has no shell utilities, so
#   we probe the socket from spiffe-watcher (which mounts the same
#   spire-agent-sockets volume read-only and has PHP).
log "test 3: Workload API socket /run/spire/sockets/agent.sock (probed via spiffe-watcher)"
if compose exec -T spiffe-watcher php -r 'exit(is_file("/run/spire/sockets/agent.sock") && filetype("/run/spire/sockets/agent.sock")==="socket" ? 0 : 1);' 2>/dev/null; then
    pass "Workload API UDS is a socket"
else
    # Fall back to `stat` via sh — also probed from spiffe-watcher.
    if compose exec -T spiffe-watcher sh -c '[ -S /run/spire/sockets/agent.sock ]' 2>/dev/null; then
        pass "Workload API UDS is a socket (fallback stat)"
    else
        fail "Workload API UDS missing or not a socket"
    fi
fi

# ── Test 4: Registrar populated all expected SPIFFE IDs ─────────────────
log "test 4: workload-registrar registrations on spire-server"
# `spire-server entry show` lists entries server knows about. We grep for
# each expected SPIFFE ID.
set +e
entries_out="$(compose exec -T spire-server /opt/spire/bin/spire-server entry show 2>&1)"
entries_rc=$?
set -e

if (( entries_rc != 0 )); then
    fail "spire-server entry show failed: ${entries_out}"
else
    missing=()
    for id in "${EXPECTED_IDS[@]}"; do
        if ! grep -Fq "$id" <<<"$entries_out"; then
            missing+=("$id")
        fi
    done
    if (( ${#missing[@]} == 0 )); then
        pass "all ${#EXPECTED_IDS[@]} expected SPIFFE IDs registered"
    else
        fail "missing registrations: ${missing[*]}"
    fi
fi

# ── Test 5: spiffe-watcher healthy ──────────────────────────────────────
log "test 5: spiffe-watcher healthcheck"
h="$(container_health spiffe-watcher)"
if [[ "$h" == "healthy" ]]; then
    pass "spiffe-watcher is healthy"
else
    fail "spiffe-watcher health=${h}"
fi

# ── Test 6: SHM meta.json fresh ─────────────────────────────────────────
log "test 6: SHM meta.json state + freshness (stale threshold=${STALE_SECS}s)"
set +e
meta_json="$(compose exec -T spiffe-watcher cat /tmp/spiffe-shared/meta.json 2>&1)"
meta_rc=$?
set -e

if (( meta_rc != 0 )); then
    fail "cannot read /tmp/spiffe-shared/meta.json from spiffe-watcher: ${meta_json}"
else
    # Parse via PHP since it's guaranteed present on the host (composer scripts use it).
    parse_result="$(STALE=$STALE_SECS php -n -r '
        $raw = stream_get_contents(STDIN);
        $data = json_decode($raw, true);
        if (!is_array($data)) { echo "invalid-json"; exit(2); }
        $state = $data["x509_state"] ?? "";
        $updated = (int) ($data["updated_at"] ?? 0);
        $stale = (int) (getenv("STALE") ?: 120);
        $age = time() - $updated;
        if ($state !== "ready") { echo "state=" . $state; exit(2); }
        if ($updated <= 0 || $age > $stale) { echo "stale age=" . $age . "s"; exit(2); }
        echo "ok age=" . $age . "s version=" . ($data["version"] ?? "?");
        exit(0);
    ' <<<"$meta_json" 2>&1)"
    if [[ "$parse_result" == ok* ]]; then
        pass "SHM meta.json ${parse_result}"
    else
        fail "SHM meta.json bad: ${parse_result}"
    fi
fi

# ── Test 7: SHM primary SVID slot valid ─────────────────────────────────
log "test 7: SHM primary SVID slot /tmp/spiffe-shared/x509/0.json"
set +e
slot_json="$(compose exec -T spiffe-watcher cat /tmp/spiffe-shared/x509/0.json 2>&1)"
slot_rc=$?
set -e

if (( slot_rc != 0 )); then
    fail "cannot read primary SVID slot: ${slot_json}"
else
    slot_check="$(php -n -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($d)) { echo "invalid-json"; exit(2); }
        $sid = $d["spiffe_id"] ?? "";
        $cert = $d["cert_pem"] ?? ($d["svid_pem"] ?? "");
        $key = $d["key_pem"] ?? "";
        $bundle = $d["bundle_pem"] ?? "";
        if ($sid === "") { echo "missing spiffe_id"; exit(2); }
        if ($cert === "" || strpos($cert, "BEGIN CERTIFICATE") === false) { echo "missing svid_pem"; exit(2); }
        if ($key === "" || strpos($key, "PRIVATE KEY") === false) { echo "missing key_pem"; exit(2); }
        if ($bundle === "" || strpos($bundle, "BEGIN CERTIFICATE") === false) { echo "missing bundle_pem"; exit(2); }
        echo "ok spiffe_id=" . $sid;
        exit(0);
    ' <<<"$slot_json")"
    if [[ "$slot_check" == ok* ]]; then
        pass "primary SVID slot ${slot_check}"
    else
        fail "primary SVID slot bad: ${slot_check}"
    fi
fi

# ── Test 8: spiffe-watcher Prometheus /metrics (optional) ────────────────
if [[ -n "$METRICS_URL" ]]; then
    log "test 8: spiffe-watcher /metrics at ${METRICS_URL}"
    set +e
    metrics_body="$(curl -sS --max-time 5 "$METRICS_URL" 2>&1)"
    metrics_rc=$?
    set -e
    if (( metrics_rc == 0 )) \
        && grep -Fq 'spiffe_watcher_x509_ready' <<<"$metrics_body" \
        && grep -Fq 'spiffe_watcher_x509_ready 1' <<<"$metrics_body"; then
        pass "metrics endpoint reports x509_ready=1"
    else
        fail "metrics endpoint unreachable or missing counters (url=${METRICS_URL})"
    fi
else
    skip "test 8: /metrics probe (set SPIRE_WATCHER_METRICS to enable)"
fi

# ── Summary ─────────────────────────────────────────────────────────────
printf '\n'
printf '  ┌─────────────────────────────────────┐\n'
printf '  │  SPIRE integrity probe summary      │\n'
printf '  ├─────────────────────────────────────┤\n'
printf '  │  \033[32mPassed: %2d\033[0m                       │\n' "$PASS_COUNT"
printf '  │  \033[31mFailed: %2d\033[0m                       │\n' "$FAIL_COUNT"
printf '  └─────────────────────────────────────┘\n'

(( FAIL_COUNT == 0 ))
