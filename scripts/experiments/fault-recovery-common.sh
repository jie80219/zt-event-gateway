#!/usr/bin/env bash
# ============================================================================
# Experiment-4 shared library — downstream-service fault-recovery measurement.
#
# Sourced by fault-recovery-spire.sh (feat/spiffe-keycloak) and
# fault-recovery-linkerd.sh (feat/Linkerd1). Both stacks inject the SAME
# fault into the SAME downstream container; the only difference is the
# transport layer (SPIRE mTLS/LSVID vs Linkerd 1.x sidecar). Measurement
# uses ONLY frozen OrderSaga log markers + docker/health polling — it does
# NOT modify any business logic.
#
#   recovery_sec = t_recovered − t_fault_clear
#     t_fault_clear = wall-clock epoch when `docker unpause/start` returns
#     t_recovered   = the worker-emitted [perf-saga-complete] ts= of the
#                     post-clear probe order (worker microtime epoch)
#
# CSV schema (recovery_<stack>.csv):
#   stack,scenario,fault_type,fault_target,fault_duration_sec,
#   t_fault_clear,recovery_sec,saga_completed,rollback_count,trace_id
#
# Requires PERF_METRIC_ENABLED=1 on the stack so the saga markers are emitted.
#
# Topology env (defaults = single-host, all containers local):
#   STACK                     spire | linkerd            (set by runner)
#   OUT                       output dir                 (set by runner)
#   WORKER_HOST               ssh alias for worker host  (default: "" = local)
#   WORKER_CONTAINER          default: zt-php-worker
#   PROD_HOST / ORDER_HOST / USER_HOST    ssh alias per downstream host ("" = local)
#   PRODUCTION_SVC_CONTAINER / ORDER_SVC_CONTAINER / USER_SVC_CONTAINER
#                             defaults: production-service / order-service / user-service
#   REQUEST_URL               default: http://127.0.0.1:8080/api/orders
#   HEALTH_URL                default: http://127.0.0.1:8080/api/health
#   FAULT_DURATIONS           default: "5 30"            (seconds; the sweep)
#   PROBE_TIMEOUT             default: 120               (s to await recovery probe)
#   MID_MARKER_TIMEOUT        default: 90                (s to await mid-saga marker)
# ============================================================================
set -euo pipefail

WORKER_HOST="${WORKER_HOST:-}"
WORKER_CONTAINER="${WORKER_CONTAINER:-zt-php-worker}"
PROD_HOST="${PROD_HOST:-}"
ORDER_HOST="${ORDER_HOST:-}"
USER_HOST="${USER_HOST:-}"
PRODUCTION_SVC_CONTAINER="${PRODUCTION_SVC_CONTAINER:-production-service}"
ORDER_SVC_CONTAINER="${ORDER_SVC_CONTAINER:-order-service}"
USER_SVC_CONTAINER="${USER_SVC_CONTAINER:-user-service}"
REQUEST_URL="${REQUEST_URL:-http://127.0.0.1:8080/api/orders}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1:8080/api/health}"
FAULT_DURATIONS="${FAULT_DURATIONS:-5 30}"
PROBE_TIMEOUT="${PROBE_TIMEOUT:-120}"
MID_MARKER_TIMEOUT="${MID_MARKER_TIMEOUT:-90}"
# Default JSON body — set via if/then because `${VAR:-default-with-braces}`
# breaks bash brace counting (the inner `}` closes the param expansion early).
if [[ -z "${ORDER_BODY:-}" ]]; then
    ORDER_BODY='{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}'
fi

CSV_PATH=""             # set by record_csv_init
declare -a _PAUSED=()   # for cleanup
declare -a _STOPPED=()

log()  { printf '[exp4] %s %s\n' "$(date +%T)" "$*"; }
warn() { printf '\033[33m[exp4][WARN]\033[0m %s\n' "$*" >&2; }
err()  { printf '\033[31m[exp4][ERR]\033[0m %s\n' "$*" >&2; }

# ── docker wrapper: run on a remote host via ssh, or locally ────────────────
_docker() {
    local host="$1"; shift
    if [[ -n "$host" ]]; then
        ssh "$host" docker "$@"
    else
        docker "$@"
    fi
}

# Resolve the ssh host for a given downstream container name.
_host_for() {
    case "$1" in
        "$PRODUCTION_SVC_CONTAINER") printf '%s' "$PROD_HOST" ;;
        "$ORDER_SVC_CONTAINER")      printf '%s' "$ORDER_HOST" ;;
        "$USER_SVC_CONTAINER")       printf '%s' "$USER_HOST" ;;
        *)                           printf '' ;;
    esac
}

container_exists() {
    local host; host="$(_host_for "$1")"
    _docker "$host" ps --format '{{.Names}}' | grep -Fxq "$1"
}

# ── fault injection ─────────────────────────────────────────────────────────
inject_fault() {                          # inject_fault <pause|kill> <container>
    local kind="$1" name="$2" host; host="$(_host_for "$name")"
    if ! container_exists "$name"; then
        err "container '$name' not running on host='${host:-local}' — cannot inject"
        return 1
    fi
    if [[ "$kind" == "kill" ]]; then
        log "kill $name (host=${host:-local})"
        _docker "$host" kill "$name" >/dev/null
        _STOPPED+=("$name")
    else
        log "pause $name (host=${host:-local})"
        _docker "$host" pause "$name" >/dev/null
        _PAUSED+=("$name")
    fi
}

# Returns wall-clock epoch (float) captured immediately after clear completes.
clear_fault() {                           # clear_fault <pause|kill> <container>
    local kind="$1" name="$2" host; host="$(_host_for "$name")"
    if [[ "$kind" == "kill" ]]; then
        _docker "$host" start "$name" >/dev/null 2>&1 || true
        _drop _STOPPED "$name"
    else
        _docker "$host" unpause "$name" >/dev/null 2>&1 || true
        _drop _PAUSED "$name"
    fi
    date +%s.%N
}

_drop() {                                 # _drop <arrayname> <value>
    local -n arr="$1"; local val="$2" keep=()
    for c in "${arr[@]:-}"; do [[ "$c" != "$val" ]] && keep+=("$c"); done
    arr=("${keep[@]:-}")
}

cleanup() {
    for c in "${_PAUSED[@]:-}"; do
        [[ -n "$c" ]] && _docker "$(_host_for "$c")" unpause "$c" >/dev/null 2>&1 || true
    done
    for c in "${_STOPPED[@]:-}"; do
        [[ -n "$c" ]] && _docker "$(_host_for "$c")" start "$c" >/dev/null 2>&1 || true
    done
}
trap cleanup EXIT

# ── HTTP / health / queue helpers ───────────────────────────────────────────
new_trace_id() { printf 'exp4-%s-%s-%s-%s' "$1" "$(date +%s)" "$$" "${RANDOM}"; }

post_order() {                            # post_order <trace> ; echoes HTTP code
    local trace="$1"
    curl -sS -o /dev/null -w '%{http_code}' \
        -X POST "$REQUEST_URL" \
        -H 'Content-Type: application/json' \
        -H "X-Correlation-Id: ${trace}" \
        -d "$ORDER_BODY" 2>/dev/null || printf '000'
}

wait_health() {
    for _ in $(seq 1 60); do
        if curl -fsS -m 3 "$HEALTH_URL" >/dev/null 2>&1; then return 0; fi
        sleep 2
    done
    err "gateway health check failed: $HEALTH_URL"
    return 1
}

worker_logs_since() {                     # worker_logs_since <RFC3339|duration>
    _docker "$WORKER_HOST" logs "$WORKER_CONTAINER" --since "$1" 2>&1 || true
}

wait_for_worker_log() {                   # since pattern timeout
    local since="$1" pattern="$2" timeout="$3" elapsed=0
    while (( elapsed < timeout )); do
        if grep -Fq "$pattern" <<<"$(worker_logs_since "$since")"; then return 0; fi
        sleep 2; elapsed=$((elapsed + 2))
    done
    return 1
}

# Count rollback markers emitted since a timestamp (compensation accounting).
count_rollbacks_since() {                 # count_rollbacks_since <since>
    local logs; logs="$(worker_logs_since "$1")"
    grep -cE 'RollbackSaga Step 2|RollbackSaga Step 1' <<<"$logs" || true
}

# Resolve a probe trace's [perf-saga-complete] worker ts= (epoch), or "" if none.
# step1 carries traceId+orderId; complete carries only orderId — so we map
# trace → orderId → completion ts. `|| true` on every pipeline because grep
# returns 1 when no match (saga still in flight) and `set -e + pipefail`
# would otherwise kill the caller's `var=$(...)` assignment.
saga_complete_ts_for_trace() {            # saga_complete_ts_for_trace <since> <trace>
    local since="$1" trace="$2" logs oid
    logs="$(worker_logs_since "$since")"
    oid="$({ grep -F "traceId=${trace}" <<<"$logs" \
            | grep -F '[perf-saga-step1]' \
            | sed -n 's/.*orderId=\([^ ]*\).*/\1/p' | head -1; } || true)"
    [[ -z "$oid" ]] && return 0
    { grep -F '[perf-saga-complete]' <<<"$logs" \
        | grep -F "orderId=${oid}" \
        | sed -n 's/.*ts=\([0-9.]*\).*/\1/p' | head -1; } || true
}

# ── CSV ─────────────────────────────────────────────────────────────────────
record_csv_init() {                       # record_csv_init <stack>
    CSV_PATH="${OUT:?OUT must be set}/recovery_${1}.csv"
    mkdir -p "$OUT"
    if [[ ! -f "$CSV_PATH" ]]; then
        echo "stack,scenario,fault_type,fault_target,fault_duration_sec,t_fault_clear,recovery_sec,saga_completed,rollback_count,trace_id" >"$CSV_PATH"
    fi
}

record_csv() {                            # 10 positional fields, in schema order
    printf '%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n' "$@" >>"$CSV_PATH"
}

# ── Core measurement ────────────────────────────────────────────────────────
# measure_recovery <scenario> <fault_type:pause|kill> <fault_target_container>
#                  <fault_duration> <inject_mode:pre|mid> <mid_marker> <rollback_expected:0|1>
#
#   pre : inject the fault BEFORE the fault-window order (Step1-transient,
#         forced-compensation). The order is driven during the fault so it
#         aborts/rolls back.
#   mid : drive the order first, wait for <mid_marker> (e.g. "Saga Step 3"),
#         THEN inject the fault so a later saga step fails (full-rollback).
measure_recovery() {
    local scenario="$1" ftype="$2" target="$3" dur="$4" mode="$5" marker="$6" rb_expected="$7"
    local stack="${STACK:?STACK must be set}"
    local since fault_trace probe_trace code t_clear t_rec recovery rb saga_ok

    wait_health || { record_csv "$stack" "$scenario" "$ftype" "$target" "$dur" "" "" 0 0 "health-fail"; return 1; }
    since="$(date -u +%Y-%m-%dT%H:%M:%S)"
    fault_trace="$(new_trace_id "$scenario-fault")"

    log "── scenario=$scenario type=$ftype target=$target dur=${dur}s mode=$mode ──"
    if [[ "$mode" == "pre" ]]; then
        inject_fault "$ftype" "$target" || { record_csv "$stack" "$scenario" "$ftype" "$target" "$dur" "" "" 0 0 "inject-fail"; return 1; }
        sleep 1
        code="$(post_order "$fault_trace")"
        log "fault-window order trace=$fault_trace http=$code"
    else  # mid
        code="$(post_order "$fault_trace")"
        log "pre-fault order trace=$fault_trace http=$code"
        if ! wait_for_worker_log "$since" "$marker" "$MID_MARKER_TIMEOUT"; then
            warn "mid marker '$marker' not seen in ${MID_MARKER_TIMEOUT}s — injecting anyway"
        fi
        inject_fault "$ftype" "$target" || { record_csv "$stack" "$scenario" "$ftype" "$target" "$dur" "" "" 0 0 "inject-fail"; return 1; }
    fi

    sleep "$dur"
    rb="$(count_rollbacks_since "$since")"; rb="${rb//[^0-9]/}"; rb="${rb:-0}"
    if [[ "$rb_expected" == "1" && "$rb" == "0" ]]; then
        warn "scenario=$scenario expected a rollback but saw none in fault window"
    fi

    t_clear="$(clear_fault "$ftype" "$target")"
    log "fault cleared at t_fault_clear=$t_clear"

    # post-clear recovery probe
    local since_clear; since_clear="$(date -u +%Y-%m-%dT%H:%M:%S)"
    probe_trace="$(new_trace_id "$scenario-probe")"
    code="$(post_order "$probe_trace")"
    log "recovery probe trace=$probe_trace http=$code"

    t_rec=""; local elapsed=0
    while (( elapsed < PROBE_TIMEOUT )); do
        t_rec="$(saga_complete_ts_for_trace "$since_clear" "$probe_trace")"
        [[ -n "$t_rec" ]] && break
        sleep 2; elapsed=$((elapsed + 2))
    done

    if [[ -n "$t_rec" ]]; then
        recovery="$(awk -v a="$t_rec" -v b="$t_clear" 'BEGIN{printf "%.3f", a-b}')"
        saga_ok=1
        log "recovered: t_recovered=$t_rec recovery_sec=$recovery"
    else
        recovery=""; saga_ok=0
        warn "scenario=$scenario: NO post-clear [perf-saga-complete] within ${PROBE_TIMEOUT}s"
    fi

    record_csv "$stack" "$scenario" "$ftype" "$target" "$dur" "$t_clear" "$recovery" "$saga_ok" "$rb" "$probe_trace"
    # settle before next scenario
    sleep 3
}
