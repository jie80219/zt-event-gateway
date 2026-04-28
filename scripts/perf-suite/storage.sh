#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════
#  Storage Footprint — LSVID/SVID vs Keycloak JWT
#
#  Measures the byte-level cost of identity material across four
#  dimensions, for the arm currently active (EXP_ARM={A,D,F}).
#
#    --dim=static   Per-workload static credentials (one-time footprint)
#    --dim=wire     Per-request token wire size (envelope + HTTP headers)
#    --dim=perhop   Per-hop token growth (LSVID L0→L1→L2 vs Bearer flat)
#    --dim=cache    Runtime in-memory cache footprint (PHP benchmark)
#    --dim=all      Run all four sequentially (default)
#
#  Usage:
#    EXP_ARM=D bash scripts/perf-suite/storage.sh --dim=all
#    EXP_ARM=A bash scripts/perf-suite/storage.sh --dim=static
#
#  Env:
#    EXP_ARM            A | D | F                (required)
#    OUT_DIR            output directory         (default: artifacts/storage-${EXP_ARM}-${STAMP})
#    GATEWAY_URL        gateway endpoint         (default: http://127.0.0.1:8080)
#    SPIFFE_SHM_DIR     SHM base                 (default: /tmp/spiffe-shared)
#    KEYCLOAK_URL       keycloak base            (default: http://127.0.0.1:8088)
#    KEYCLOAK_REALM     realm name               (default: zt)
#    WORKER_CONTAINER   docker container name    (default: php-worker)
# ══════════════════════════════════════════════════════════════════
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$PROJECT_DIR"

DIM="all"
for arg in "$@"; do
    case "$arg" in
        --dim=*) DIM="${arg#--dim=}" ;;
        --help|-h) sed -n '2,30p' "$0"; exit 0 ;;
        *) echo "unknown arg: $arg" >&2; exit 2 ;;
    esac
done

EXP_ARM="${EXP_ARM:-}"
[[ -z "$EXP_ARM" ]] && { echo "ERROR: EXP_ARM must be set (A|D|F)" >&2; exit 2; }

PROFILE_FILE="$PROJECT_DIR/scripts/perf-suite/profiles/${EXP_ARM}.env"
[[ -f "$PROFILE_FILE" ]] || { echo "ERROR: $PROFILE_FILE not found" >&2; exit 2; }
# shellcheck disable=SC1090
source "$PROFILE_FILE"

STAMP="$(date +%Y%m%d-%H%M%S)"
OUT_DIR="${OUT_DIR:-${PROJECT_DIR}/artifacts/storage-${EXP_ARM}-${STAMP}}"
mkdir -p "$OUT_DIR"

GATEWAY_URL="${GATEWAY_URL:-http://127.0.0.1:8080}"
SPIFFE_SHM_DIR="${SPIFFE_SHM_DIR:-/tmp/spiffe-shared}"
KEYCLOAK_URL="${KEYCLOAK_URL:-http://127.0.0.1:8088}"
KEYCLOAK_REALM="${KEYCLOAK_REALM:-zt}"
WORKER_CONTAINER="${WORKER_CONTAINER:-zt-php-worker}"
ORDER_SERVICE_CONTAINER="${ORDER_SERVICE_CONTAINER:-order-service}"
RABBIT_API="${RABBIT_API:-http://127.0.0.1:15672/api}"
RABBIT_USER="${RABBIT_USER:-zt}"
RABBIT_PASS="${RABBIT_PASS:-ztpass}"
AMQP_EXCHANGE="${AMQP_EXCHANGE:-events}"
REQUEST_ROUTING_KEY="${REQUEST_ROUTING_KEY:-request.new}"
L1_EVENT_RK="${L1_EVENT_RK:-OrderCreateRequestedEvent}"

BOLD='\033[1m'; GREEN='\033[32m'; YELLOW='\033[33m'; CYAN='\033[36m'; RESET='\033[0m'
log()  { echo -e "${BOLD}[storage:${EXP_ARM}]${RESET} $(date +%T) $*"; }
sect() { echo -e "\n${CYAN}${BOLD}── $* ──${RESET}"; }
ok()   { echo -e "${GREEN}[ok]${RESET}  $*"; }
warn() { echo -e "${YELLOW}[warn]${RESET} $*"; }

# ── Live-stack vs declared-arm consistency check ────────────────────────────
# storage.sh measures the running stack; if the gateway's actual SPIFFE/LSVID
# toggles don't match what this arm declares, every measurement gets the wrong
# label. We refuse to proceed silently — caller must align profile first
# (e.g. via run-3arm-experiment.sh / zt-cost-matrix's apply_profile).
check_stack_matches_arm() {
    if ! command -v docker >/dev/null 2>&1; then return 0; fi
    local gw="${GATEWAY_CONTAINER:-zt-gateway}"
    docker ps --format '{{.Names}}' | grep -q "^${gw}$" || { warn "gateway container $gw not running — skip stack check"; return 0; }
    local env_dump actual_spiffe actual_lsvid
    env_dump="$(docker inspect "$gw" --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null || true)"
    # `grep` returns 1 on no-match → would trip set -e+pipefail; guard with || true.
    # Empty string when the variable isn't set in the container env.
    actual_spiffe="$(printf '%s\n' "$env_dump" | grep '^SPIFFE_ENABLED=' | head -1 | cut -d= -f2 || true)"
    actual_lsvid="$(printf '%s\n' "$env_dump" | grep '^LSVID_ENABLED='  | head -1 | cut -d= -f2 || true)"
    # Treat unset gateway env as "default-on" (= 1), matching gateway.php's
    # getenv()-with-default behaviour.
    actual_spiffe="${actual_spiffe:-1}"
    actual_lsvid="${actual_lsvid:-1}"
    if [[ "$actual_spiffe" != "$SPIFFE_ENABLED" || "$actual_lsvid" != "$LSVID_ENABLED" ]]; then
        warn "stack/arm mismatch: declared arm=$EXP_ARM (SPIFFE=$SPIFFE_ENABLED LSVID=$LSVID_ENABLED) but $gw running (SPIFFE=$actual_spiffe LSVID=$actual_lsvid)"
        warn "→ measurements will reflect the RUNNING stack, not arm $EXP_ARM"
        if [[ "${STORAGE_FORCE:-0}" != "1" ]]; then
            echo "Set STORAGE_FORCE=1 to proceed anyway, or align the stack first." >&2
            exit 3
        fi
    fi
}
check_stack_matches_arm

# ── Helpers ─────────────────────────────────────────────────────────────────
file_bytes() {
    [[ -f "$1" ]] || { echo 0; return; }
    if [[ "$OSTYPE" == "darwin"* ]]; then stat -f%z "$1"; else stat -c%s "$1"; fi
}

# ────────────────────────────────────────────────────────────────────────────
# 3.1  PER-WORKLOAD STATIC CREDENTIALS
# ────────────────────────────────────────────────────────────────────────────
dim_static() {
    sect "dim 1 — per-workload static credentials"
    local out="$OUT_DIR/static.json"

    if [[ "$EXP_ARM" == "A" ]]; then
        python3 - "$out" <<'PY'
import json, sys
json.dump({"arm":"A-baseline","note":"no identity material","entries":[]},
          open(sys.argv[1],"w"), indent=2)
PY
        ok "wrote $out (baseline = N/A)"
        return
    fi

    if [[ "$EXP_ARM" == "D" ]]; then
        # SPIFFE arm — SHM lives in either:
        #   (a) host /tmp/spiffe-shared          (4-host topology, each host maps its own dir)
        #   (b) named docker volume `spiffe-shared` mounted into spiffe-watcher
        # Try host first, fall back to `docker exec spiffe-watcher`.
        local shm_source="host" entries="[]" meta_b=0
        local watcher="${SPIFFE_WATCHER_CONTAINER:-zt-spiffe-watcher}"

        if [[ -d "$SPIFFE_SHM_DIR/x509" ]]; then
            entries="$(python3 - "$SPIFFE_SHM_DIR" <<'PY'
import json, os, sys
base = sys.argv[1]
out = []
xdir = os.path.join(base, "x509")
if os.path.isdir(xdir):
    for slot in sorted(os.listdir(xdir)):
        if not slot.endswith(".json"): continue
        p = os.path.join(xdir, slot)
        try: d = json.load(open(p))
        except Exception: continue
        out.append({
            "slot":             slot,
            "spiffe_id":        d.get("spiffe_id",""),
            "cert_pem_bytes":   len(d.get("cert_pem","")),
            "key_pem_bytes":    len(d.get("key_pem","")),
            "bundle_pem_bytes": len(d.get("bundle_pem","")),
            "file_bytes":       os.path.getsize(p),
        })
print(json.dumps(out))
PY
)"
            meta_b="$(file_bytes "$SPIFFE_SHM_DIR/meta.json")"
        elif command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' | grep -q "^${watcher}$"; then
            shm_source="docker:$watcher"
            log "host SHM not present — reading via docker exec $watcher"
            entries="$(docker exec "$watcher" sh -c '
for f in /tmp/spiffe-shared/x509/*.json; do
  [ -f "$f" ] || continue
  echo "===SLOT===$(basename "$f")===$(stat -c%s "$f" 2>/dev/null || stat -f%z "$f")"
  cat "$f"
  echo
done' 2>/dev/null | python3 -c '
import json, sys, re
buf = sys.stdin.read()
out = []
parts = re.split(r"===SLOT===([^=]+)===(\d+)", buf)
# parts: ["", slot1, size1, body1, slot2, size2, body2, ...]
for i in range(1, len(parts), 3):
    slot = parts[i].strip()
    size = int(parts[i+1])
    body = parts[i+2].strip()
    try: d = json.loads(body)
    except Exception: continue
    out.append({
        "slot":             slot,
        "spiffe_id":        d.get("spiffe_id",""),
        "cert_pem_bytes":   len(d.get("cert_pem","")),
        "key_pem_bytes":    len(d.get("key_pem","")),
        "bundle_pem_bytes": len(d.get("bundle_pem","")),
        "file_bytes":       size,
    })
print(json.dumps(out))
')"
            meta_b="$(docker exec "$watcher" sh -c 'stat -c%s /tmp/spiffe-shared/meta.json 2>/dev/null || stat -f%z /tmp/spiffe-shared/meta.json 2>/dev/null || echo 0')"
        else
            warn "neither host $SPIFFE_SHM_DIR nor docker container '$watcher' available — entries empty"
        fi

        python3 - "$out" "$entries" "$meta_b" "$SPIFFE_SHM_DIR" "$shm_source" <<'PY'
import json, sys
out, entries, meta_b, base, source = sys.argv[1:]
json.dump({
    "arm":             "D-full-zt",
    "shm_base":        base,
    "shm_source":      source,
    "meta_json_bytes": int(meta_b or 0),
    "entries":         json.loads(entries),
}, open(out,"w"), indent=2)
PY
        ok "wrote $out (source=$shm_source, entries=$(python3 -c "import json;print(len(json.loads('''$entries''')))"))"
        return
    fi

    if [[ "$EXP_ARM" == "F" ]]; then
        # Keycloak arm — pull JWKS from realm endpoint and report bytes.
        local jwks_url="${KEYCLOAK_URL}/realms/${KEYCLOAK_REALM}/protocol/openid-connect/certs"
        local oidc_url="${KEYCLOAK_URL}/realms/${KEYCLOAK_REALM}/.well-known/openid-configuration"
        local jwks_body oidc_body jwks_b oidc_b http
        if jwks_body="$(curl -sf --max-time 5 "$jwks_url" 2>/dev/null)"; then
            jwks_b="${#jwks_body}"
        else
            jwks_b=0; warn "JWKS fetch failed: $jwks_url"
        fi
        if oidc_body="$(curl -sf --max-time 5 "$oidc_url" 2>/dev/null)"; then
            oidc_b="${#oidc_body}"
        else
            oidc_b=0; warn "OIDC discovery fetch failed: $oidc_url"
        fi
        python3 - "$out" "$jwks_url" "$jwks_b" "$oidc_b" "$KEYCLOAK_REALM" <<'PY'
import json, sys
out, jwks_url, jwks_b, oidc_b, realm = sys.argv[1:]
json.dump({
    "arm": "F-keycloak-jwt",
    "realm": realm,
    "jwks_url": jwks_url,
    "jwks_bytes": int(jwks_b),
    "oidc_discovery_bytes": int(oidc_b),
    "note": "JWKS is shared by all clients; static cost = realm-scoped, not per-workload",
}, open(out,"w"), indent=2)
PY
        ok "wrote $out"
        return
    fi
}

# ────────────────────────────────────────────────────────────────────────────
# Shared probe-queue capture — used by both dim_wire and dim_perhop
# Adapted from scripts/collect-experiment-data.sh Stage 5b. Returns the path
# to a JSON file containing L0/L1/L2 raw tokens + segment lengths.
# ────────────────────────────────────────────────────────────────────────────
capture_chain() {
    local out_json="$1"
    local trace="storage-cap-$(date +%s)-$$"
    local probe_l0="storage_probe_L0_$$"
    local probe_l1="storage_probe_L1_$$"

    # Declare + bind probe queues to capture envelopes off the live exchange
    local q
    for q in "$probe_l0" "$probe_l1"; do
        curl -sS -o /dev/null -u "${RABBIT_USER}:${RABBIT_PASS}" \
            -H 'content-type: application/json' \
            -X PUT "${RABBIT_API}/queues/%2F/${q}" \
            -d '{"durable":false,"auto_delete":false,"arguments":{"x-message-ttl":60000}}' 2>/dev/null || true
    done
    curl -sS -o /dev/null -u "${RABBIT_USER}:${RABBIT_PASS}" \
        -H 'content-type: application/json' \
        -X POST "${RABBIT_API}/bindings/%2F/e/${AMQP_EXCHANGE}/q/${probe_l0}" \
        -d "{\"routing_key\":\"${REQUEST_ROUTING_KEY}\",\"arguments\":{}}" 2>/dev/null || true
    curl -sS -o /dev/null -u "${RABBIT_USER}:${RABBIT_PASS}" \
        -H 'content-type: application/json' \
        -X POST "${RABBIT_API}/bindings/%2F/e/${AMQP_EXCHANGE}/q/${probe_l1}" \
        -d "{\"routing_key\":\"${L1_EVENT_RK}\",\"arguments\":{}}" 2>/dev/null || true

    # Reset L2 capture log on order-service (best-effort; absent on F arm)
    local order_ctn
    order_ctn="$(docker ps --format '{{.Names}}' 2>/dev/null | grep -E "${ORDER_SERVICE_CONTAINER}" | head -1 || true)"
    if [[ -n "$order_ctn" ]]; then
        docker exec "$order_ctn" sh -c ': > /tmp/lsvid-capture.log' 2>/dev/null || true
    fi

    sleep 1

    # Dispatch a single order — envelope mints L0 → worker extends L1 → filter L2
    curl -sS -o /dev/null --max-time 10 \
        -X POST "$GATEWAY_URL/api/orders" \
        -H 'Content-Type: application/json' \
        -H "X-Correlation-Id: $trace" \
        -d '{"userKey":"42","productList":[{"p_key":1,"amount":2}],"total":250}' || true
    sleep 6

    # Drain the probe queues via Management API
    local l0_rows l1_rows l2_log
    l0_rows="$(curl -sS -u "${RABBIT_USER}:${RABBIT_PASS}" \
        -H 'content-type: application/json' \
        -X POST "${RABBIT_API}/queues/%2F/${probe_l0}/get" \
        -d '{"count":10,"ackmode":"ack_requeue_false","encoding":"auto"}' 2>/dev/null || echo '[]')"
    l1_rows="$(curl -sS -u "${RABBIT_USER}:${RABBIT_PASS}" \
        -H 'content-type: application/json' \
        -X POST "${RABBIT_API}/queues/%2F/${probe_l1}/get" \
        -d '{"count":10,"ackmode":"ack_requeue_false","encoding":"auto"}' 2>/dev/null || echo '[]')"
    l2_log=""
    [[ -n "$order_ctn" ]] && l2_log="$(docker exec "$order_ctn" cat /tmp/lsvid-capture.log 2>/dev/null || true)"

    # Cleanup probe queues
    for q in "$probe_l0" "$probe_l1"; do
        curl -sS -o /dev/null -u "${RABBIT_USER}:${RABBIT_PASS}" \
            -X DELETE "${RABBIT_API}/queues/%2F/${q}" 2>/dev/null || true
    done

    # Parse + emit JSON
    PROBE_L0_JSON="$l0_rows" PROBE_L1_JSON="$l1_rows" \
    L2_LOG="$l2_log" TRACE="$trace" ARM="$EXP_ARM" \
    php -n -r '
        $l0Rows = json_decode(getenv("PROBE_L0_JSON") ?: "[]", true) ?: [];
        $l1Rows = json_decode(getenv("PROBE_L1_JSON") ?: "[]", true) ?: [];
        $l2Log  = getenv("L2_LOG") ?: "";
        $arm    = getenv("ARM") ?: "?";

        // Pick the identity-token field in the envelope. SPIFFE arm = "lsvid";
        // Keycloak arm = "access_token" (or similar); fall back to scanning.
        $extractToken = function (array $rows): array {
            $hits = [];
            foreach ($rows as $row) {
                $payload = $row["payload"] ?? null;
                if (!is_string($payload)) continue;
                $env = json_decode($payload, true);
                if (!is_array($env)) continue;
                $tok = $env["lsvid"] ?? ($env["access_token"] ?? ($env["bearer"] ?? null));
                if (is_string($tok) && $tok !== "") { $hits[] = $tok; }
            }
            return $hits;
        };

        $l0Tokens = $extractToken($l0Rows);
        $l1Tokens = $extractToken($l1Rows);
        // L2: order-service writes "trace\thop\trawToken" lines when LSVID_CAPTURE_DEBUG=1
        $l2Tokens = [];
        foreach (array_filter(explode("\n", trim($l2Log))) as $line) {
            $f = explode("\t", $line);
            if (count($f) >= 3 && $f[2] !== "") $l2Tokens[] = $f[2];
        }

        $segLens = function (?string $raw): array {
            if (!is_string($raw) || $raw === "") return ["bytes"=>0,"header"=>0,"payload"=>0,"signature"=>0];
            $p = explode(".", $raw);
            return [
                "bytes"     => strlen($raw),
                "header"    => strlen($p[0] ?? ""),
                "payload"   => strlen($p[1] ?? ""),
                "signature" => strlen($p[2] ?? ""),
            ];
        };

        $l0Raw = $l0Tokens[0] ?? null;
        $l1Raw = $l1Tokens[0] ?? null;
        $l2Raw = $l2Tokens[0] ?? null;

        $out = [
            "arm" => $arm,
            "trace_id" => getenv("TRACE"),
            "captured" => [
                "L0" => $l0Raw !== null,
                "L1" => $l1Raw !== null,
                "L2" => $l2Raw !== null,
            ],
            "sizes" => [
                "L0" => $segLens($l0Raw),
                "L1" => $segLens($l1Raw),
                "L2" => $segLens($l2Raw),
            ],
            "previews" => [
                "L0" => $l0Raw ? substr($l0Raw, 0, 40)."..." : null,
                "L1" => $l1Raw ? substr($l1Raw, 0, 40)."..." : null,
                "L2" => $l2Raw ? substr($l2Raw, 0, 40)."..." : null,
            ],
            "rows_seen" => [
                "L0" => count($l0Rows),
                "L1" => count($l1Rows),
                "L2" => count(array_filter(explode("\n", trim($l2Log)))),
            ],
        ];

        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    ' > "$out_json" 2>/dev/null || true
}

# ────────────────────────────────────────────────────────────────────────────
# 3.2  PER-REQUEST WIRE SIZE  (envelope + header view of capture_chain output)
# ────────────────────────────────────────────────────────────────────────────
dim_wire() {
    sect "dim 2 — per-request token wire size"
    local out="$OUT_DIR/wire.json"
    local cap="$OUT_DIR/_chain-capture.json"
    capture_chain "$cap"

    python3 - "$out" "$cap" "$EXP_ARM" <<'PY'
import json, sys
out_path, cap_path, arm = sys.argv[1:]
try:
    cap = json.loads(open(cap_path).read())
except Exception as e:
    cap = {"_error": str(e)}
sizes = cap.get("sizes", {})
# envelope_token_bytes := L1 (worker-extended on event queue)
# header_token_bytes   := L2 (filter-extended on outgoing HTTP)
envelope = (sizes.get("L1") or {}).get("bytes") or (sizes.get("L0") or {}).get("bytes") or 0
header   = (sizes.get("L2") or {}).get("bytes") or 0
json.dump({
    "arm": arm,
    "envelope_token_bytes": envelope,
    "header_token_bytes":   header,
    "captured":             cap.get("captured"),
    "rows_seen":            cap.get("rows_seen"),
    "previews":             cap.get("previews"),
    "trace_id":             cap.get("trace_id"),
}, open(out_path,"w"), indent=2)
PY
    ok "wrote $out"
}

# ────────────────────────────────────────────────────────────────────────────
# 3.3  PER-HOP TOKEN GROWTH  (L0/L1/L2 size sequence from same capture)
# ────────────────────────────────────────────────────────────────────────────
dim_perhop() {
    sect "dim 3 — per-hop token growth"
    local out="$OUT_DIR/perhop.json"
    local cap="$OUT_DIR/_chain-capture.json"
    [[ -f "$cap" ]] || capture_chain "$cap"

    python3 - "$out" "$cap" "$EXP_ARM" <<'PY'
import json, sys
out_path, cap_path, arm = sys.argv[1:]
try:
    cap = json.loads(open(cap_path).read())
except Exception as e:
    json.dump({"arm": arm, "error": str(e)}, open(out_path,"w"), indent=2)
    sys.exit(0)

sizes = cap.get("sizes", {})
hops = []
for hop_idx, level in enumerate(("L0", "L1", "L2")):
    s = sizes.get(level) or {}
    if s.get("bytes"):
        hops.append({"hop": hop_idx, "level": level, "bytes": s["bytes"],
                     "header": s.get("header",0), "payload": s.get("payload",0),
                     "signature": s.get("signature",0)})

growth = [hops[i]["bytes"] - hops[i-1]["bytes"] for i in range(1, len(hops))]
ratio = round(hops[-1]["bytes"] / hops[0]["bytes"], 3) if len(hops) >= 2 and hops[0]["bytes"] else None
json.dump({
    "arm": arm,
    "trace_id": cap.get("trace_id"),
    "hops": hops,
    "growth_per_hop_bytes": growth,
    "ratio_last_to_first": ratio,
    "captured": cap.get("captured"),
}, open(out_path,"w"), indent=2)
PY
    ok "wrote $out"
}

# ────────────────────────────────────────────────────────────────────────────
# 3.4  CACHE / IN-MEMORY FOOTPRINT
# ────────────────────────────────────────────────────────────────────────────
dim_cache() {
    sect "dim 4 — cache / in-memory footprint"
    local out="$OUT_DIR/cache.json"
    local bench="$PROJECT_DIR/tests/benchmark-storage-cache.php"
    if [[ ! -f "$bench" ]]; then
        warn "$bench missing — skipping"
        echo '{"arm":"'"$EXP_ARM"'","note":"benchmark script missing"}' > "$out"
        return
    fi

    # Run inside worker container if available, else host PHP. The image is
    # built with `COPY . /app` (no live bind mount), so we docker cp the bench
    # script in before exec — no image rebuild needed.
    if command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' | grep -q "^${WORKER_CONTAINER}$"; then
        # Copy into /app/tests/ so the bench's relative `__DIR__/../vendor/autoload.php`
        # resolves to the in-image /app/vendor (which is the only autoload present).
        docker cp "$bench" "$WORKER_CONTAINER:/app/tests/benchmark-storage-cache.php" 2>/dev/null \
            || warn "docker cp failed"
        docker exec -e EXP_ARM="$EXP_ARM" -e CACHE_OUT="/tmp/cache-bench.json" \
            "$WORKER_CONTAINER" php /app/tests/benchmark-storage-cache.php \
            > "$OUT_DIR/cache.stdout" 2>&1 || warn "container bench exited non-zero"
        docker cp "$WORKER_CONTAINER:/tmp/cache-bench.json" "$out" 2>/dev/null \
            || warn "could not pull cache-bench.json from container"
    elif command -v php >/dev/null 2>&1; then
        EXP_ARM="$EXP_ARM" CACHE_OUT="$out" php "$bench" \
            > "$OUT_DIR/cache.stdout" 2>&1 || warn "host bench exited non-zero"
    else
        warn "neither docker nor php on host — skipping"
        echo '{"arm":"'"$EXP_ARM"'","note":"no PHP runtime available"}' > "$out"
    fi
    ok "wrote $out"
}

# ── Driver ──────────────────────────────────────────────────────────────────
log "arm=$EXP_ARM dim=$DIM out=$OUT_DIR"
case "$DIM" in
    static)  dim_static ;;
    wire)    dim_wire ;;
    perhop)  dim_perhop ;;
    cache)   dim_cache ;;
    all)     dim_static; dim_wire; dim_perhop; dim_cache ;;
    *) echo "unknown --dim: $DIM (use static|wire|perhop|cache|all)" >&2; exit 2 ;;
esac

ok "storage run complete: $OUT_DIR"
