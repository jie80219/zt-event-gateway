#!/usr/bin/env bash
# ============================================================================
# probe-spiffe-deep.sh — SVID-targeted security deep probe.
#
# Stack-only driver for SPIFFE+LSVID stacks (feat/spiffe-keycloak). Linkerd /
# baseline have no analogous surfaces; do NOT feed this output into a
# cross-stack matrix. Output goes to artifacts/sec-spiffe-deep-<ts>/results.jsonl
# in the same JSONL schema as lib.sh emits — aggregate-security.py can ingest
# it as an independent deep-report input.
#
# Categories:
#   V  Pre-flight controls (gate — fail-fast)
#   G  LSVID structural integrity — exercise LSVIDValidator rules via AMQP
#      envelope injection with crafted tokens.
#   H  SHM seqlock / file tampering — host-root threat model (mutates
#      /var/lib/docker/volumes/zt-event-gateway_spiffe-shared/_data on gateway).
#   I  SVID rotation race — burst /api/orders before/during/after watcher
#      restart, capture accepted/total + p99.
#   J  Attestor selector inspection PoC — assert registrar selector breadth.
#
# Env:
#   STACK              defaults "spiffe-deep" (drives OUT path via lib.sh)
#   CATEGORY           comma-separated subset, e.g. "V,G" (default all)
#   DRY                1 = only run V then exit 0
#   GATEWAY_ALIAS      ssh alias (default lib.sh's zt-gateway-lan; we override
#                      to zt-gateway because driver runs from the Mac).
#   OUT                output dir (default lib.sh autogen)
#
# Exit codes:
#   0  all categories completed (regardless of attack-case verdicts)
#   2  pre-flight controls (V) failed — entire run is invalid, retry after fix
# ============================================================================
set -euo pipefail

STACK="${STACK:-spiffe-deep}"
GATEWAY_ALIAS="${GATEWAY_ALIAS:-zt-gateway}"
ORDER_ALIAS="${ORDER_ALIAS:-zt-order}"
PROD_ALIAS="${PROD_ALIAS:-zt-prod}"
USER_ALIAS="${USER_ALIAS:-zt-user}"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/lib.sh"

CATEGORY="${CATEGORY:-V,G,H,I,J}"
DRY="${DRY:-0}"
SHM_HOST_DIR="/var/lib/docker/volumes/zt-event-gateway_spiffe-shared/_data"
MINT_CONTAINER_PATH="/app/scripts/_mint-lsvid-test-token.php"
MINT_HOST_PATH="/root/zt-event-gateway/scripts/_mint-lsvid-test-token.php"
WORKER_SPIFFE_ID="${WORKER_SPIFFE_ID:-spiffe://zt.local/php-worker}"
GATEWAY_SPIFFE_ID="${GATEWAY_SPIFFE_ID:-spiffe://zt.local/php-gateway}"

# ────────────────────────────────────────────────────────────────────────────
# Helpers
# ────────────────────────────────────────────────────────────────────────────

ensure_minter_in_container() {
    ssh "$GATEWAY_ALIAS" "docker exec zt-gateway test -f $MINT_CONTAINER_PATH" 2>/dev/null && return 0
    log "Copying _mint-lsvid-test-token.php into zt-gateway container"
    ssh "$GATEWAY_ALIAS" "docker exec zt-gateway sh -c 'mkdir -p /app/scripts' && docker cp $MINT_HOST_PATH zt-gateway:$MINT_CONTAINER_PATH" >/dev/null
}

mint_lsvid() {
    # $1 = variant (happy|expired|wrong-aud|tampered)
    # $2 (opt) = --audience=<spiffe-id>
    local variant=$1 audopt=${2:-}
    ssh "$GATEWAY_ALIAS" "docker exec -e SPIFFE_ID=$GATEWAY_SPIFFE_ID zt-gateway \
        php $MINT_CONTAINER_PATH --variant=$variant $audopt" 2>/dev/null \
        | tr -d '\r' | head -1
}

# Base64url (no padding) encode/decode via python3 — portable across Mac+Linux.
b64url_decode() {
    python3 -c 'import sys,base64; s=sys.argv[1]; s+="="*(-len(s)%4); sys.stdout.buffer.write(base64.urlsafe_b64decode(s.encode()))' "$1"
}
b64url_encode_stdin() {
    python3 -c 'import sys,base64; sys.stdout.write(base64.urlsafe_b64encode(sys.stdin.buffer.read()).rstrip(b"=").decode())'
}

# Mutate header (segment 0) or payload (segment 1) of a compact-JWS token.
# Re-assembles with the ORIGINAL signature — for LSVIDValidator rules that
# fire BEFORE signature check (required-claim, alg-whitelist, x5c presence,
# iss-SAN), this is sufficient. Rules that fire AFTER sig check will report
# "signature verification failed" instead.
mutate_lsvid_segment() {
    # $1 = token, $2 = segment ("header"|"payload"), $3 = jq filter
    local token=$1 segment=$2 filter=$3
    local h="${token%%.*}"
    local rest="${token#*.}"
    local p="${rest%%.*}"
    local s="${rest#*.}"
    local target_b64 mutated
    case "$segment" in
        header)  target_b64=$h ;;
        payload) target_b64=$p ;;
        *) echo "mutate_lsvid_segment: bad segment $segment" >&2; return 1 ;;
    esac
    mutated=$(b64url_decode "$target_b64" | jq -c "$filter" | b64url_encode_stdin)
    case "$segment" in
        header)  printf '%s.%s.%s' "$mutated" "$p" "$s" ;;
        payload) printf '%s.%s.%s' "$h" "$mutated" "$s" ;;
    esac
}

publish_envelope_with_lsvid() {
    # $1 = envelope JSON string; returns "routed:true|false" from broker
    local envelope=$1
    local payload
    payload=$(jq -nc --arg p "$envelope" \
        '{properties:{},routing_key:"order_queue",payload:$p,payload_encoding:"string"}')
    ssh "$GATEWAY_ALIAS" "curl -sS --max-time 5 -u zt:ztpass -X POST \
        'http://127.0.0.1:15672/api/exchanges/%2F//publish' \
        -H 'Content-Type: application/json' --data '$payload'" 2>&1 | head -1 || true
}

worker_log_since() {
    # $1 = trace_id (filter), $2 = seconds back (default 15)
    local trace=$1 since=${2:-15}
    ssh "$GATEWAY_ALIAS" "docker logs --since ${since}s zt-php-worker 2>&1 \
        | grep -iE 'LSVID|spiffe|invalid|untrusted|replay|reject|$trace' \
        | tail -30" 2>/dev/null || true
}

classify_lsvid_blocked_by() {
    local log=$1
    if   grep -qiE "Untrusted SPIFFE source"          <<<"$log"; then echo "spiffe-id-mismatch"
    elif grep -qiE "missing required claim"           <<<"$log"; then echo "lsvid-required-claim"
    elif grep -qiE "Unsupported LSVID alg"            <<<"$log"; then echo "lsvid-alg"
    elif grep -qiE "missing x5c"                      <<<"$log"; then echo "lsvid-x5c-missing"
    elif grep -qiE "not parseable|Failed to extract"  <<<"$log"; then echo "lsvid-x5c-malformed"
    elif grep -qiE "not signed by any trusted CA"     <<<"$log"; then echo "lsvid-trust"
    elif grep -qiE "notBefore|notAfter|expired|in the future" <<<"$log"; then echo "lsvid-temporal"
    elif grep -qiE "signature verification failed"    <<<"$log"; then echo "lsvid-sig"
    elif grep -qiE "does not match cert SAN"          <<<"$log"; then echo "lsvid-iss-san"
    elif grep -qiE "replay detected"                  <<<"$log"; then echo "lsvid-replay"
    elif grep -qiE "chain broken|nested audience"     <<<"$log"; then echo "lsvid-chain"
    elif grep -qiE "audience mismatch"                <<<"$log"; then echo "lsvid-aud"
    elif grep -qiE "L0 subject .* does not match"     <<<"$log"; then echo "lsvid-l0-subject"
    elif grep -qiE "Invalid inbound LSVID"            <<<"$log"; then echo "lsvid-other"
    else                                                            echo "unknown"
    fi
}

# Decide verdict for a G case from worker log:
#   accepted   → log shows "LSVID L# OK" AND no rejection keyword
#   rejected   → any rejection keyword present
#   unknown    → neither (e.g. message vanished, broker rejected)
classify_g_verdict() {
    local log=$1
    if grep -qiE "LSVID L[0-9]+ OK"   <<<"$log" \
        && ! grep -qiE "rejected|exception|Untrusted|Invalid|replay detected|missing|mismatch|not signed|Unsupported" <<<"$log"; then
        echo "accepted"
    elif grep -qiE "rejected|exception|Untrusted|Invalid|replay detected|missing|mismatch|not signed|Unsupported" <<<"$log"; then
        echo "rejected"
    else
        echo "unknown"
    fi
}

# ────────────────────────────────────────────────────────────────────────────
# Cleanup (always run on exit)
# ────────────────────────────────────────────────────────────────────────────
cleanup() {
    local rc=$?
    ssh "$GATEWAY_ALIAS" 'docker start zt-spiffe-watcher >/dev/null 2>&1 || docker restart zt-spiffe-watcher >/dev/null 2>&1' 2>/dev/null || true
    return $rc
}
trap cleanup EXIT

# ────────────────────────────────────────────────────────────────────────────
# Category V — pre-flight controls (gate)
# ────────────────────────────────────────────────────────────────────────────
run_category_v() {
    log "Category V: pre-flight controls"
    local failed=0

    # V1: happy /api/orders POST + saga reaches Step 4
    local trace="V1-smoke-$(date +%s)"
    local code
    code=$(ssh "$GATEWAY_ALIAS" "curl -sS -o /dev/null -w '%{http_code}' \
        -X POST 'http://127.0.0.1:8080/api/orders' \
        -H 'Content-Type: application/json' -H 'X-Correlation-Id: $trace' \
        --data '{\"userKey\":\"1\",\"productList\":[{\"p_key\":1,\"amount\":1}],\"total\":100}'" 2>/dev/null || echo 000)
    sleep 6
    local step4
    step4=$(ssh "$GATEWAY_ALIAS" "docker logs --since 15s zt-php-worker 2>&1 | grep -c 'Saga Step 4' || true")
    local v1_ok="rejected"
    [[ "$code" =~ ^2..$ ]] && [[ "${step4:-0}" -ge 1 ]] && v1_ok="accepted"
    [[ "$v1_ok" == "accepted" ]] || failed=1
    emit V1 "control" "$A_GW" "$code" "$v1_ok" "happy saga reached Step 4=$step4 (control)" 0

    # V2: SHM x509_state ready
    local x509_state
    x509_state=$(ssh "$GATEWAY_ALIAS" "docker exec zt-spiffe-watcher cat /tmp/spiffe-shared/meta.json 2>/dev/null | jq -r '.x509_state // \"missing\"'" 2>/dev/null || echo "missing")
    local v2_ok="rejected"
    [[ "$x509_state" == "ready" ]] && v2_ok="accepted"
    [[ "$v2_ok" == "accepted" ]] || failed=1
    emit V2 "control" "shm.meta.x509_state" "$x509_state" "$v2_ok" "watcher SHM meta.json x509_state (control)" 0

    # V3: registrar shows entries for all 5 expected SPIFFE IDs
    local entry_count
    entry_count=$(ssh "$GATEWAY_ALIAS" "docker exec zt-spire-server /opt/spire/bin/spire-server entry show 2>&1 | grep -c 'SPIFFE ID'" 2>/dev/null || echo 0)
    local v3_ok="rejected"
    [[ "${entry_count:-0}" -ge 5 ]] && v3_ok="accepted"
    [[ "$v3_ok" == "accepted" ]] || failed=1
    emit V3 "control" "spire-server.entries" "$entry_count" "$v3_ok" "registered SPIFFE entries >= 5 (control)" 0

    if [[ $failed -ne 0 ]]; then
        log "ABORT: pre-flight controls failed; data unsafe, exit 2"
        print_summary
        exit 2
    fi
    log "Category V: all controls PASS"
}

# ────────────────────────────────────────────────────────────────────────────
# Category G — LSVID structural integrity
# ────────────────────────────────────────────────────────────────────────────

# Build an envelope with optional overrides via jq filter.
make_envelope() {
    # $1 = trace_id, $2 = lsvid_token, $3 (opt) = jq override filter
    local trace=$1 lsvid=$2 filter=${3:-.}
    jq -nc \
        --arg trace "$trace" \
        --arg lsvid "$lsvid" \
        --arg gid "$GATEWAY_SPIFFE_ID" \
        '{
            schema_version: 1,
            type: "gateway.request",
            route: "OrderCreateRequestedEvent",
            id: $trace,
            spiffe_id: $gid,
            spiffe_path: [$gid],
            lsvid: $lsvid,
            data: {userKey: "1", productList: [{p_key: 1, amount: 1}], total: 100}
        }' | jq -c "$filter"
}

g_case() {
    # $1 case_id, $2 token, $3 desc, $4 expected_block, $5 (opt) envelope-jq-filter
    local case_id=$1 token=$2 desc=$3 expected=$4 env_filter=${5:-.}
    if [[ -z "$token" || "$token" == ERROR* ]]; then
        emit "$case_id" "lsvid-deep" "rabbitmq.order_queue" "—" "unknown" \
            "$desc — minter/mutation produced empty token: $token"
        return
    fi
    local trace="probe-deep-${case_id}-$(date +%s%N)"
    local envelope
    envelope=$(make_envelope "$trace" "$token" "$env_filter")
    local resp
    resp=$(publish_envelope_with_lsvid "$envelope")
    local broker_status="unknown"
    [[ "$resp" =~ \"routed\":true  ]] && broker_status="broker-routed"
    [[ "$resp" =~ \"routed\":false ]] && broker_status="broker-no-route"
    sleep 3
    local glog
    glog=$(worker_log_since "$trace" 12)
    local blocked_by
    blocked_by=$(classify_lsvid_blocked_by "$glog")
    local verdict
    verdict=$(classify_g_verdict "$glog")
    local match="—"
    [[ "$blocked_by" == "$expected" ]] && match="match"
    [[ "$verdict" == "accepted" && "$expected" == "none" ]] && match="match"
    emit "$case_id" "lsvid-deep" "rabbitmq.order_queue" "$broker_status" "$verdict" \
        "$desc — expected_block=$expected got_block=$blocked_by ($match) — trace=$trace"
}

run_category_g() {
    log "Category G: LSVID structural integrity"
    ensure_minter_in_container

    local happy
    happy=$(mint_lsvid happy "--audience=$WORKER_SPIFFE_ID")
    if [[ -z "$happy" || "$happy" == ERROR* ]]; then
        log "WARN: minter produced no token; skipping Category G entirely"
        emit G0 "lsvid-deep" "minter" "fail" "unknown" "minter failed: $happy"
        return
    fi

    # G1 missing iss
    g_case G1 "$(mutate_lsvid_segment "$happy" payload 'del(.iss)')" \
        "drop iss claim" "lsvid-required-claim"
    # G2 missing exp
    g_case G2 "$(mutate_lsvid_segment "$happy" payload 'del(.exp)')" \
        "drop exp claim" "lsvid-required-claim"
    # G3 missing jti
    g_case G3 "$(mutate_lsvid_segment "$happy" payload 'del(.jti)')" \
        "drop jti claim" "lsvid-required-claim"
    # G4 alg=HS256
    g_case G4 "$(mutate_lsvid_segment "$happy" header '.alg="HS256"')" \
        "alg switched to HS256" "lsvid-alg"
    # G5 alg=none
    g_case G5 "$(mutate_lsvid_segment "$happy" header '.alg="none"')" \
        "alg switched to none" "lsvid-alg"
    # G6 strip x5c
    g_case G6 "$(mutate_lsvid_segment "$happy" header 'del(.x5c)')" \
        "drop x5c header" "lsvid-x5c-missing"
    # G7 garbage x5c
    g_case G7 "$(mutate_lsvid_segment "$happy" header '.x5c=["garbage-not-a-pem"]')" \
        "x5c contains non-PEM garbage" "lsvid-x5c-malformed"
    # G8 expired (minter variant)
    g_case G8 "$(mint_lsvid expired "--audience=$WORKER_SPIFFE_ID")" \
        "minter --variant=expired" "lsvid-temporal"
    # G9 tampered signature (minter variant)
    g_case G9 "$(mint_lsvid tampered "--audience=$WORKER_SPIFFE_ID")" \
        "minter --variant=tampered (sig=AAA…)" "lsvid-sig"
    # G10 iss ≠ cert SAN
    g_case G10 "$(mutate_lsvid_segment "$happy" payload '.iss="spiffe://zt.local/imposter-service"')" \
        "iss rewritten away from cert SAN" "lsvid-iss-san"
    # G11 JTI replay — happy token sent twice (second should be replay-rejected
    # iff jti cache is wired; otherwise finding = "no replay protection")
    g_case G11a "$happy" "first send (control half of replay test)" "none"
    sleep 1
    g_case G11 "$happy" "replay of G11a — same jti expected to be rejected" "lsvid-replay"
    # G13 wrong audience (minter variant)
    g_case G13 "$(mint_lsvid wrong-aud)" \
        "minter --variant=wrong-aud" "lsvid-aud"
    # G14 envelope spiffe_id ≠ L0 subject (mutate envelope only, keep happy token)
    g_case G14 "$happy" "envelope spiffe_id forged while L0.sub correct" "lsvid-l0-subject" \
        '.spiffe_id = "spiffe://zt.local/imposter-svc" | .spiffe_path = [.spiffe_id]'
    # G15 happy control
    g_case G15 "$happy" "happy LSVID + clean envelope (control)" "none"
}

# ────────────────────────────────────────────────────────────────────────────
# Category H — SHM seqlock / file tampering
# ────────────────────────────────────────────────────────────────────────────
H_RESTORE_NEEDED=0

shm_stop_watcher() {
    ssh "$GATEWAY_ALIAS" "docker stop zt-spiffe-watcher >/dev/null 2>&1" || true
    H_RESTORE_NEEDED=1
}

shm_restore() {
    if [[ $H_RESTORE_NEEDED -eq 1 ]]; then
        ssh "$GATEWAY_ALIAS" "docker start zt-spiffe-watcher >/dev/null 2>&1 || \
            (cd ~/zt-event-gateway && COMPOSE_PROFILES=zt docker compose up -d spiffe-watcher >/dev/null 2>&1)"
        # wait for watcher to re-publish a valid SHM
        for _ in 1 2 3 4 5 6 7 8 9 10; do
            sleep 2
            local state
            state=$(ssh "$GATEWAY_ALIAS" "docker exec zt-spiffe-watcher cat /tmp/spiffe-shared/meta.json 2>/dev/null | jq -r '.x509_state // \"missing\"'" 2>/dev/null || echo missing)
            [[ "$state" == "ready" ]] && break
        done
        H_RESTORE_NEEDED=0
    fi
}

h_probe_gateway() {
    # send 1 request, return "HTTP_<code>"; capture gateway log excerpt
    local trace="H-probe-$(date +%s%N)"
    local code
    code=$(ssh "$GATEWAY_ALIAS" "curl -sS -o /dev/null -w '%{http_code}' --max-time 8 \
        -X POST 'http://127.0.0.1:8080/api/orders' \
        -H 'Content-Type: application/json' -H 'X-Correlation-Id: $trace' \
        --data '{\"userKey\":\"1\",\"productList\":[{\"p_key\":1,\"amount\":1}],\"total\":100}'" 2>/dev/null || echo 000)
    local glog
    glog=$(ssh "$GATEWAY_ALIAS" "docker logs --since 12s zt-gateway 2>&1 | grep -iE 'spiffe|svid|credentials|tls|503|signer' | tail -3" 2>/dev/null || true)
    echo "$code|$(printf '%s' "$glog" | tr '\n' ';' | head -c 200)"
}

h_case() {
    # $1 case_id, $2 mutate_cmd (run via ssh on gateway host), $3 desc, $4 expected_degraded(0|1)
    local case_id=$1 mutate=$2 desc=$3 expected_degraded=$4
    shm_stop_watcher
    if [[ -n "$mutate" ]]; then
        ssh "$GATEWAY_ALIAS" "$mutate" >/dev/null 2>&1 || true
    fi
    sleep 1
    local result code glog
    result=$(h_probe_gateway)
    code="${result%%|*}"
    glog="${result#*|}"
    local verdict
    if [[ "$code" =~ ^2..$ ]]; then verdict="accepted"
    else verdict="rejected"
    fi
    local outcome="undetermined"
    if [[ "$expected_degraded" == "1" && "$verdict" == "rejected" ]]; then outcome="expected-degraded"
    elif [[ "$expected_degraded" == "0" && "$verdict" == "accepted" ]]; then outcome="expected-ok"
    elif [[ "$expected_degraded" == "1" && "$verdict" == "accepted" ]]; then outcome="UNEXPECTED-served"
    else outcome="UNEXPECTED-failed"
    fi
    emit "$case_id" "shm-tamper" "$A_GW" "$code" "$verdict" \
        "$desc — outcome=$outcome — gw_log=$glog"
    shm_restore
}

run_category_h() {
    log "Category H: SHM seqlock / file tampering (host-root threat model)"

    # H6 control first — measure expected baseline with watcher running normally
    h_case H6 "" "no tamper (control)" 0
    H_RESTORE_NEEDED=1  # ensure cleanup runs even though we didn't stop watcher
    shm_restore

    # H1: set version to odd permanently (watcher stopped, version stuck)
    h_case H1 "
        jq '.version = (.version + 1 | if . % 2 == 0 then . + 1 else . end)' \
            $SHM_HOST_DIR/meta.json > $SHM_HOST_DIR/meta.json.tmp \
            && mv $SHM_HOST_DIR/meta.json.tmp $SHM_HOST_DIR/meta.json
    " "meta.json version forced odd (seqlock DoS)" 1

    # H2: state lie — meta says ready but x509/0.json deleted
    h_case H2 "
        rm -f $SHM_HOST_DIR/x509/0.json
    " "delete x509/0.json while meta.json claims x509_state=ready" 1

    # H3: cert/key swap — replace cert_pem with garbage PEM
    h_case H3 "
        jq '.cert_pem=\"-----BEGIN CERTIFICATE-----\nGARBAGE_NOT_A_CERT\n-----END CERTIFICATE-----\n\"' \
            $SHM_HOST_DIR/x509/0.json > $SHM_HOST_DIR/x509/0.json.tmp \
            && mv $SHM_HOST_DIR/x509/0.json.tmp $SHM_HOST_DIR/x509/0.json
    " "x509/0.json cert_pem replaced with garbage" 1

    # H4: truncated JSON in x509/0.json
    h_case H4 "
        echo '{\"spiffe_id\":\"spiffe://zt.local/php-gateway\",\"cert_pem\":\"---' \
            > $SHM_HOST_DIR/x509/0.json
    " "x509/0.json contains truncated JSON" 1

    # H5: chmod 000 on x509/0.json
    h_case H5 "
        chmod 000 $SHM_HOST_DIR/x509/0.json
    " "x509/0.json mode=000 (unreadable)" 1
}

# ────────────────────────────────────────────────────────────────────────────
# Category I — SVID rotation race
# ────────────────────────────────────────────────────────────────────────────

burst_orders() {
    # $1 = count, $2 = trace prefix; emits CSV rows: idx,start_ns,end_ns,code
    local count=$1 trace=$2
    ssh "$GATEWAY_ALIAS" "python3 - <<PYEOF
import time, urllib.request, urllib.error, json
COUNT = $count
TRACE_PREFIX = '$trace'
URL = 'http://127.0.0.1:8080/api/orders'
BODY = json.dumps({'userKey':'1','productList':[{'p_key':1,'amount':1}],'total':100}).encode()
out = []
for i in range(COUNT):
    req = urllib.request.Request(URL, data=BODY,
        headers={'Content-Type':'application/json',
                 'X-Correlation-Id': f'{TRACE_PREFIX}-{i}'},
        method='POST')
    start = time.monotonic_ns()
    try:
        with urllib.request.urlopen(req, timeout=10) as r:
            code = r.status
    except urllib.error.HTTPError as e:
        code = e.code
    except Exception:
        code = 0
    end = time.monotonic_ns()
    out.append(f'{i},{start},{end},{code}')
print('\\n'.join(out))
PYEOF
"
}

summarize_burst() {
    # stdin = CSV; outputs: 'accepted=A total=T err=E p99_ms=P median_ms=M'
    python3 - <<'PYEOF'
import sys
rows = [r.strip().split(',') for r in sys.stdin if r.strip()]
if not rows:
    print("accepted=0 total=0 err=0 p99_ms=0 median_ms=0")
    sys.exit(0)
codes = [int(r[3]) for r in rows]
lat = sorted([(int(r[2])-int(r[1]))/1e6 for r in rows])
accepted = sum(1 for c in codes if 200 <= c < 300)
err = len(codes) - accepted
p99 = lat[max(0, int(len(lat)*0.99)-1)]
med = lat[len(lat)//2]
print(f"accepted={accepted} total={len(codes)} err={err} p99_ms={p99:.1f} median_ms={med:.1f}")
PYEOF
}

i_case() {
    # $1 case_id, $2 desc, $3 rotation_kind (none|restart|kill), $4 count, $5 is_attack
    local case_id=$1 desc=$2 kind=$3 count=$4 atk=${5:-0}
    case "$kind" in
        restart)
            ssh "$GATEWAY_ALIAS" 'nohup docker restart zt-spiffe-watcher >/dev/null 2>&1 &' >/dev/null
            ;;
        kill)
            ssh "$GATEWAY_ALIAS" 'docker kill -s SIGKILL zt-spiffe-watcher >/dev/null 2>&1' || true
            ;;
    esac
    local csv summary
    csv=$(burst_orders "$count" "rot-$case_id-$(date +%s)")
    summary=$(printf '%s\n' "$csv" | summarize_burst)
    local accepted total err
    accepted=$(grep -oE 'accepted=[0-9]+' <<<"$summary" | cut -d= -f2)
    total=$(grep -oE 'total=[0-9]+' <<<"$summary" | cut -d= -f2)
    err=$(grep -oE 'err=[0-9]+' <<<"$summary" | cut -d= -f2)
    local verdict="accepted"
    [[ "${err:-0}" -gt 2 ]] && verdict="degraded"
    emit "$case_id" "rotation-race" "$A_GW" "$accepted/$total" "$verdict" \
        "$desc — $summary" "$atk"
}

run_category_i() {
    log "Category I: SVID rotation race"
    i_case I1 "no rotation, 40-req baseline" none 40 0
    sleep 2
    i_case I2 "restart watcher then 40-req burst" restart 40 0
    sleep 8   # let watcher self-heal
    i_case I3 "SIGKILL watcher then 10-req burst" kill 10 1
    # explicit recovery wait before I4 control
    sleep 10
    for _ in 1 2 3 4 5 6; do
        sleep 3
        local state
        state=$(ssh "$GATEWAY_ALIAS" "docker exec zt-spiffe-watcher cat /tmp/spiffe-shared/meta.json 2>/dev/null | jq -r '.x509_state // \"missing\"'" 2>/dev/null || echo missing)
        [[ "$state" == "ready" ]] && break
    done
    i_case I4 "after watcher self-heal, 1-req control" none 1 0
}

# ────────────────────────────────────────────────────────────────────────────
# Category J — Attestor selector inspection PoC
# ────────────────────────────────────────────────────────────────────────────
run_category_j() {
    log "Category J: attestor selector inspection"

    # J1: assert breadth of registered selectors (config-level finding).
    # On the existing register-workloads-internal.sh contract, every workload
    # is registered with selector "unix:uid:0" only — meaning any root process
    # on the same host could in principle attest. We emit this as
    # verdict=accepted (attack vector exists at configuration time).
    local selectors
    selectors=$(ssh "$GATEWAY_ALIAS" "docker exec zt-spire-server /opt/spire/bin/spire-server entry show 2>&1 | grep -E 'Selector' | sort -u | tr '\n' ';' | head -c 300" 2>/dev/null || echo "")
    local lax="no"
    grep -qE 'unix:uid:0' <<<"$selectors" && lax="yes"
    local distinct
    distinct=$(echo "$selectors" | tr ';' '\n' | grep -cE 'Selector' || true)
    local verdict="rejected"
    [[ "$lax" == "yes" && "${distinct:-0}" -le 1 ]] && verdict="accepted"
    emit J1 "attestor-selector" "spire-server.entries.selectors" "$distinct" "$verdict" \
        "lax_selectors=$lax distinct_selectors=$distinct — selectors=$selectors"

    # J2 (control): a properly-attested workload INSIDE its registered
    # container (zt-php-worker) should hold a valid SVID — meta says ready
    # is good enough for this control.
    local x509_state
    x509_state=$(ssh "$GATEWAY_ALIAS" "docker exec zt-spiffe-watcher cat /tmp/spiffe-shared/meta.json 2>/dev/null | jq -r '.x509_state // \"missing\"'" 2>/dev/null || echo missing)
    local v="rejected"
    [[ "$x509_state" == "ready" ]] && v="accepted"
    emit J2 "attestor-selector" "shm.meta.x509_state" "$x509_state" "$v" \
        "control: legitimate workload has SVID present" 0
}

# ────────────────────────────────────────────────────────────────────────────
# Main
# ────────────────────────────────────────────────────────────────────────────

# V always runs first.
run_category_v

if [[ "$DRY" == "1" ]]; then
    log "DRY=1 — controls passed; skipping G/H/I/J"
    print_summary
    exit 0
fi

[[ ",$CATEGORY," == *,G,* ]] && run_category_g
[[ ",$CATEGORY," == *,H,* ]] && run_category_h
[[ ",$CATEGORY," == *,I,* ]] && run_category_i
[[ ",$CATEGORY," == *,J,* ]] && run_category_j

# Category K + L (Paper-5 attack toolkit: tcpreplay + fakelib)
if [[ ",$CATEGORY," == *,K,* || ",$CATEGORY," == *,L,* ]]; then
    SCRIPT_DIR="${SCRIPT_DIR:-$(cd "$(dirname "$0")" && pwd)}"
    source "$SCRIPT_DIR/probe-paper5-attacks.sh"
    [[ ",$CATEGORY," == *,K,* ]] && run_category_k
    [[ ",$CATEGORY," == *,L,* ]] && run_category_l
fi

print_summary
