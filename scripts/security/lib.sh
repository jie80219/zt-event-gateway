#!/usr/bin/env bash
# Shared helpers for the 15-CVE security probe matrix. Sourced by every
# probe-tier{1,2}-*.sh driver and by run-attack-matrix.sh.
#
# CSV schema (one row per case execution):
#   category,case_id,cve_ref,stack,result,latency_ms,target,detail
#
# Required env from caller:
#   STACK            spiffe-keycloak | vault
#   OUT              output dir (created if missing)
#
# Optional env:
#   GATEWAY_ALIAS    SSH alias for gateway host  (default: zt-gateway)
#   ORDER_ALIAS      SSH alias for order host    (default: zt-order)
#   PROD_ALIAS       SSH alias for production    (default: zt-prod)
#   USER_ALIAS       SSH alias for user host     (default: zt-user)
#   GATEWAY_URL      override gateway URL        (default: http://127.0.0.1:8080)
#
# Defaults target the four non-'-lan' hosts (WAN aliases 140.127.74.142:709x,
# four distinct hosts behind NAT port-forwards). Export the '-lan' aliases to
# drive over the low-latency LAN instead, e.g. GATEWAY_ALIAS=zt-gateway-lan.

set -uo pipefail

GATEWAY_ALIAS="${GATEWAY_ALIAS:-zt-gateway}"
ORDER_ALIAS="${ORDER_ALIAS:-zt-order}"
PROD_ALIAS="${PROD_ALIAS:-zt-prod}"
USER_ALIAS="${USER_ALIAS:-zt-user}"

GATEWAY_URL="${GATEWAY_URL:-http://127.0.0.1:8080}"
A_GW="$GATEWAY_URL/api/orders"
A_HEALTH="$GATEWAY_URL/api/health"

if [[ -z "${STACK:-}" ]]; then
    echo "lib.sh: STACK must be set before sourcing (spiffe-keycloak|vault)" >&2
    return 1 2>/dev/null || exit 1
fi
case "$STACK" in
    spiffe-keycloak|vault) ;;
    *) echo "lib.sh: STACK=$STACK invalid (use spiffe-keycloak|vault)" >&2
       return 1 2>/dev/null || exit 1 ;;
esac

if [[ -z "${OUT:-}" ]]; then
    OUT="artifacts/sec-${STACK}-$(date +%Y%m%d-%H%M%S)"
fi
mkdir -p "$OUT/raw"
CSV="$OUT/raw/${STACK}.csv"
if [[ ! -s "$CSV" ]]; then
    echo "category,case_id,cve_ref,stack,result,latency_ms,target,detail" >"$CSV"
fi

log()  { printf '[%s] %s %s\n' "$STACK" "$(date +%T)" "$*" >&2; }

# Escape a field for CSV (quote if contains comma/quote/newline).
csv_escape() {
    local f="$1"
    if [[ "$f" == *,* || "$f" == *\"* || "$f" == *$'\n'* ]]; then
        f="${f//\"/\"\"}"
        printf '"%s"' "$f"
    else
        printf '%s' "$f"
    fi
}

# Emit one CSV row.
# Args: category case_id cve_ref result latency_ms target detail
emit() {
    local category=$1 case_id=$2 cve_ref=$3 result=$4 latency_ms=$5 target=$6 detail=$7
    {
        csv_escape "$category"; printf ','
        csv_escape "$case_id";  printf ','
        csv_escape "$cve_ref";  printf ','
        csv_escape "$STACK";    printf ','
        csv_escape "$result";   printf ','
        csv_escape "$latency_ms"; printf ','
        csv_escape "$target";   printf ','
        csv_escape "$detail";   printf '\n'
    } >>"$CSV"
    printf '  %-22s %-10s result=%-10s lat=%-5s %s\n' \
        "$category" "$case_id" "$result" "$latency_ms" "$detail" >&2
}

# Classify HTTP status into rejected/accepted/blocked.
#   2xx/3xx → accepted   (server processed)
#   4xx     → rejected   (server refused)
#   5xx     → rejected   (server errored — treated as defense outcome)
#   000     → blocked    (connect failed / mTLS handshake denied)
#
# IMPORTANT — 'blocked' vs 'n/a':
#   'blocked' means the TARGET actively denied the connection (mTLS handshake
#   refused, port closed) — i.e. a real DEFENSE, and it scores as REJECT.
#   Use classify_http() ONLY for a request that SHOULD reach a live endpoint,
#   so a 000 genuinely reflects the target's refusal.
#   When the HARNESS itself cannot execute a case (missing tool, capture
#   failed, credential material absent, container unreachable) DO NOT emit
#   'blocked' — emit 'n/a' so the case is excluded from the rejection-ratio
#   denominator instead of being miscounted as a defense.
classify_http() {
    local code="$1"
    case "$code" in
        2*|3*) echo accepted ;;
        000)   echo blocked ;;
        4*|5*) echo rejected ;;
        *)     echo unknown ;;
    esac
}

# curl via SSH, returns "<code>|<elapsed_ms>".
ssh_curl() {
    local alias=$1 url=$2 method=${3:-GET} headers=${4:-} body=${5:-}
    local hdr_args=""
    if [[ -n "$headers" ]]; then
        # headers is a literal '\n'-separated list; convert to -H flags inline
        while IFS= read -r h; do
            [[ -n "$h" ]] && hdr_args+=" -H '$h'"
        done <<<"$headers"
    fi
    local data_args=""
    if [[ -n "$body" ]]; then
        data_args="--data-binary '$body'"
    fi
    local raw
    raw=$(ssh -n "$alias" "curl -sS -o /dev/null -w '%{http_code}|%{time_total}' \
        -X $method '$url' $hdr_args $data_args" 2>/dev/null) || raw="000|0"
    local code="${raw%%|*}"
    local t_sec="${raw##*|}"
    local ms
    ms=$(awk -v t="$t_sec" 'BEGIN{ printf "%d", t*1000 }')
    printf '%s|%s' "$code" "$ms"
}

# Get a fresh Keycloak access token (aud=gateway via client-app) by minting
# from inside the docker network so the iss claim matches what gateway expects.
# Meaningful on spiffe-keycloak and vault (both gate ingress with Keycloak JWT).
kc_token() {
    case "$STACK" in
        spiffe-keycloak|vault) ;;
        *) echo ""; return ;;
    esac
    ssh -n "$GATEWAY_ALIAS" 'docker exec zt-gateway curl -sS -X POST \
        "http://keycloak:8080/realms/zt/protocol/openid-connect/token" \
        -d "grant_type=client_credentials" \
        -d "client_id=client-app" \
        -d "client_secret=client-app-dev-secret" 2>/dev/null' \
        | jq -r '.access_token // empty' 2>/dev/null || true
}

# Stack-aware: rabbitmq host (always on gateway box).
RABBIT_HOST="${RABBIT_HOST:-127.0.0.1}"
RABBIT_USER="${RABBIT_USER:-zt}"
RABBIT_PASS="${RABBIT_PASS:-ztpass}"

# Publish a raw payload to a queue via management API. Returns "routed" /
# "not-routed" / "broker-rejected".
amqp_publish() {
    local queue=$1 payload=$2
    local body
    body=$(printf '%s' "$payload" \
        | python3 -c '
import json,sys
p=sys.stdin.read()
print(json.dumps({"properties":{},"routing_key":"'"$queue"'","payload":p,"payload_encoding":"string"}))
')
    local resp
    resp=$(ssh -n "$GATEWAY_ALIAS" "curl -sS --max-time 5 -u $RABBIT_USER:$RABBIT_PASS -X POST \
        'http://$RABBIT_HOST:15672/api/exchanges/%2F//publish' \
        -H 'Content-Type: application/json' --data '$body' 2>&1 | head -1") || true
    if   [[ "$resp" =~ \"routed\":true ]];  then echo routed
    elif [[ "$resp" =~ \"routed\":false ]]; then echo not-routed
    else                                          echo broker-rejected
    fi
}

# Wait for the worker to log a Saga step within `timeout` seconds for a
# given trace. Returns "ok" or "timeout".
wait_for_saga_step() {
    local trace=$1 step_pattern=$2 timeout=${3:-15}
    local deadline=$(( $(date +%s) + timeout ))
    while [[ $(date +%s) -lt $deadline ]]; do
        if ssh -n "$GATEWAY_ALIAS" "docker logs --since=30s --tail 200 zt-php-worker 2>&1 \
            | grep -F '$trace' | grep -qE '$step_pattern'"; then
            echo ok; return
        fi
        sleep 0.5
    done
    echo timeout
}

# Convenience: assert tooling exists on a host (echoes "yes" or "no").
have_tool() {
    local alias=$1 tool=$2
    ssh -n "$alias" "command -v '$tool' >/dev/null 2>&1 && echo yes || echo no" 2>/dev/null
}

print_csv_path() { printf '%s\n' "$CSV"; }
