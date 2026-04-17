#!/usr/bin/env bash
# ============================================================================
# Experiment Data Collection Script for ZT Event Gateway
#
# 統一收集論文所需的全部實驗數據（6 大類 28 項指標），輸出結構化 JSON。
#
#   Stage 1 — LSVID 微觀效能 benchmark（不需要 Docker）
#   Stage 2 — SPIFFE SHM 微觀效能 benchmark（不需要 Docker）
#   Stage 3 — Ablation Study: 4 組壓力測試 + 資源消耗
#   Stage 4 — Saga 端到端延遲拆解
#   Stage 5 — Envelope 大小量測（with/without LSVID）
#   Stage 6 — 彙總報告（JSON + Markdown）
#
# Usage:
#   bash scripts/collect-experiment-data.sh              # 完整收集
#   bash scripts/collect-experiment-data.sh --micro-only  # 只跑微觀 benchmark
#   EXP_STRESS_TOTAL=500 EXP_STRESS_CONC=10 bash scripts/collect-experiment-data.sh
#
# Environment overrides:
#   EXP_STRESS_TOTAL    (default: 100)  — 每組壓力測試請求數
#   EXP_STRESS_CONC     (default: 5)    — 並發度
#   EXP_LSVID_ITER      (default: 5000) — LSVID benchmark 迭代次數
#   EXP_SAGA_SAMPLES    (default: 5)    — Saga 延遲量測次數
#   EXP_SKIP_ABLATION   (default: 0)    — 跳過 Stage 3
#   COMPOSE_FILE        (default: docker-compose.yml)
#   COMPOSE_PROFILES    (default: zt)
# ============================================================================
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
export COMPOSE_PROFILES="${COMPOSE_PROFILES:-zt}"

STRESS_TOTAL="${EXP_STRESS_TOTAL:-100}"
STRESS_CONC="${EXP_STRESS_CONC:-5}"
LSVID_ITER="${EXP_LSVID_ITER:-5000}"
SAGA_SAMPLES="${EXP_SAGA_SAMPLES:-5}"
SKIP_ABLATION="${EXP_SKIP_ABLATION:-0}"

MICRO_ONLY=false
[[ "${1:-}" == "--micro-only" ]] && MICRO_ONLY=true

STAMP="$(date +%Y%m%d-%H%M%S)"
OUT_DIR="${PROJECT_DIR}/artifacts/experiment-${STAMP}"
mkdir -p "$OUT_DIR"

REQUEST_URL="http://127.0.0.1:8080/api/orders"
HEALTH_URL="http://127.0.0.1:8080/api/health"
RABBIT_API="http://127.0.0.1:15672/api"
RABBIT_USER="zt"
RABBIT_PASS="ztpass"

# ── Logging ──────────────────────────────────────────────────────────────────

BOLD='\033[1m'; GREEN='\033[32m'; RED='\033[31m'; CYAN='\033[36m'; YELLOW='\033[33m'; RESET='\033[0m'
log()     { echo -e "${BOLD}[exp]${RESET} $(date +%T) $*"; }
pass()    { echo -e "${GREEN}[ok]${RESET}  $*"; }
fail()    { echo -e "${RED}[fail]${RESET} $*"; }
section() { echo -e "\n${CYAN}${BOLD}══ $* ══${RESET}"; }

echo ""
echo "╔══════════════════════════════════════════════════════════════════╗"
echo "║  ZT Event Gateway — Experiment Data Collection                 ║"
echo "║  Output: artifacts/experiment-${STAMP}/                    ║"
echo "╚══════════════════════════════════════════════════════════════════╝"
echo ""
log "STRESS_TOTAL=${STRESS_TOTAL} STRESS_CONC=${STRESS_CONC} LSVID_ITER=${LSVID_ITER}"
log "COMPOSE_PROFILES=${COMPOSE_PROFILES}"

START_TIME="$(date +%s)"

# ── Helpers ──────────────────────────────────────────────────────────────────

wait_health() {
    local url="${1:-$HEALTH_URL}" timeout="${2:-90}" elapsed=0
    while (( elapsed < timeout )); do
        local code
        code="$(curl -s -o /dev/null -w '%{http_code}' "$url" 2>/dev/null || echo "000")"
        [[ "$code" == "200" ]] && return 0
        sleep 2; elapsed=$((elapsed + 2))
    done
    return 1
}

wait_rabbit() {
    local timeout="${1:-60}" elapsed=0
    while (( elapsed < timeout )); do
        local code
        code="$(curl -s -o /dev/null -w '%{http_code}' -u "${RABBIT_USER}:${RABBIT_PASS}" "${RABBIT_API}/overview" 2>/dev/null || echo "000")"
        [[ "$code" == "200" ]] && return 0
        sleep 2; elapsed=$((elapsed + 2))
    done
    return 1
}

wait_worker_log() {
    local since="$1" pattern="$2" timeout="${3:-90}" elapsed=0
    while (( elapsed < timeout )); do
        local logs
        logs="$(docker compose -f "$COMPOSE_FILE" logs --no-color --since "$since" php-worker 2>&1 || true)"
        grep -Fq "$pattern" <<<"$logs" && return 0
        sleep 2; elapsed=$((elapsed + 2))
    done
    return 1
}

purge_queue() {
    curl -sS -o /dev/null -w '%{http_code}' \
        -u "${RABBIT_USER}:${RABBIT_PASS}" \
        -X DELETE "${RABBIT_API}/queues/%2F/$(python3 -c "import urllib.parse; print(urllib.parse.quote('$1',''))" 2>/dev/null || echo "$1")/contents" 2>/dev/null || true
}

fetch_queue_messages() {
    curl -sS \
        -u "${RABBIT_USER}:${RABBIT_PASS}" \
        -H 'content-type: application/json' \
        -X POST "${RABBIT_API}/queues/%2F/$(python3 -c "import urllib.parse; print(urllib.parse.quote('$1',''))" 2>/dev/null || echo "$1")/get" \
        -d "{\"count\":10,\"ackmode\":\"$2\",\"encoding\":\"auto\",\"truncate\":100000}" 2>/dev/null || echo "[]"
}

collect_docker_stats() {
    local output="$1" duration="${2:-10}"
    # Collect docker stats for $duration seconds (1 snapshot/sec)
    local i=0
    echo "[" > "$output"
    while (( i < duration )); do
        docker stats --no-stream --format '{"container":"{{.Name}}","cpu":"{{.CPUPerc}}","mem":"{{.MemUsage}}","net":"{{.NetIO}}","timestamp":'$(date +%s)'}' 2>/dev/null | \
            grep -E 'zt-gateway|zt-php-worker|zt-rabbitmq|zt-spire' >> "$output" || true
        (( i > 0 )) && echo "," >> "$output"
        sleep 1; i=$((i + 1))
    done
    echo "]" >> "$output"
}

# ============================================================================
# Stage 1: LSVID 微觀 Benchmark
# ============================================================================
section "Stage 1: LSVID Micro-Benchmark (N=${LSVID_ITER})"

LSVID_OUT="${OUT_DIR}/lsvid-micro.json"
if [[ -f "tests/benchmark-lsvid.php" ]]; then
    log "running LSVID benchmark..."
    ITER="$LSVID_ITER" php tests/benchmark-lsvid.php > "${OUT_DIR}/lsvid-bench-stdout.txt" 2>&1 || true

    # Copy the generated JSON
    LATEST_LSVID="$(ls -t docs/data/lsvid-bench-*.json 2>/dev/null | head -1 || true)"
    if [[ -n "$LATEST_LSVID" && -f "$LATEST_LSVID" ]]; then
        cp "$LATEST_LSVID" "$LSVID_OUT"
        pass "LSVID micro-benchmark → ${LSVID_OUT}"
    else
        fail "LSVID benchmark ran but no JSON output found"
    fi

    # ── A7: Token size measurement ──────────────────────────────────────
    log "measuring LSVID token sizes..."
    php -n -r '
        require __DIR__ . "/vendor/autoload.php";
        require __DIR__ . "/packages/php-lsvid/tests/LSVID/TestSvidReader.php";
        use SDPMlab\LSVID\LSVIDSigner;
        use SDPMlab\LSVID\Tests\TestSvidReader;

        $gw = TestSvidReader::create("spiffe://zt.local/bench-gateway", "zt.local");
        $wk = $gw->deriveWorkload("spiffe://zt.local/bench-worker");
        $ds = $gw->deriveWorkload("spiffe://zt.local/bench-downstream");

        $gwS = new LSVIDSigner($gw, defaultTtlSeconds: 300);
        $wkS = new LSVIDSigner($wk, defaultTtlSeconds: 300);
        $dsS = new LSVIDSigner($ds, defaultTtlSeconds: 300);

        $l0 = $gwS->createBase(audience: "spiffe://zt.local/bench-worker");
        $l1 = $wkS->extend(priorRawToken: $l0->raw, audience: "spiffe://zt.local/bench-downstream");
        $l2 = $dsS->extend(priorRawToken: $l1->raw, audience: "spiffe://zt.local/bench-final");

        echo json_encode([
            "L0_bytes" => strlen($l0->raw),
            "L1_bytes" => strlen($l1->raw),
            "L2_bytes" => strlen($l2->raw),
            "L0_to_L1_growth" => strlen($l1->raw) - strlen($l0->raw),
            "L1_to_L2_growth" => strlen($l2->raw) - strlen($l1->raw),
            "growth_ratio_L1_L0" => round(strlen($l1->raw) / strlen($l0->raw), 3),
            "growth_ratio_L2_L0" => round(strlen($l2->raw) / strlen($l0->raw), 3),
        ], JSON_PRETTY_PRINT);
    ' > "${OUT_DIR}/lsvid-token-sizes.json" 2>/dev/null && \
        pass "token size data → lsvid-token-sizes.json" || \
        fail "token size measurement failed"
else
    fail "tests/benchmark-lsvid.php not found"
fi

# ============================================================================
# Stage 2: SPIFFE SHM Micro-Benchmark
# ============================================================================
section "Stage 2: SPIFFE SHM Micro-Benchmark"

SPIFFE_OUT="${OUT_DIR}/spiffe-micro.json"
if [[ -f "tests/benchmark-spiffe.php" ]]; then
    log "running SPIFFE benchmark..."
    ITER="$LSVID_ITER" php tests/benchmark-spiffe.php > "${OUT_DIR}/spiffe-bench-stdout.txt" 2>&1 || true

    LATEST_SPIFFE="$(ls -t docs/data/spiffe-bench-*.json 2>/dev/null | head -1 || true)"
    if [[ -n "$LATEST_SPIFFE" && -f "$LATEST_SPIFFE" ]]; then
        cp "$LATEST_SPIFFE" "$SPIFFE_OUT"
        pass "SPIFFE micro-benchmark → ${SPIFFE_OUT}"
    else
        # benchmark-spiffe.php may not output JSON — capture stdout as fallback
        cp "${OUT_DIR}/spiffe-bench-stdout.txt" "$SPIFFE_OUT"
        pass "SPIFFE micro-benchmark (stdout) → ${SPIFFE_OUT}"
    fi
else
    fail "tests/benchmark-spiffe.php not found"
fi

# ── Stop here if --micro-only ────────────────────────────────────────────────
if [[ "$MICRO_ONLY" == true ]]; then
    section "Complete (micro-only mode)"
    log "output directory: ${OUT_DIR}"
    ls -la "$OUT_DIR"
    exit 0
fi

# ============================================================================
# Pre-Stage: Ensure Docker infrastructure is running
# ============================================================================
section "Infrastructure Setup"

# Ensure external network
docker network inspect anser_project_network >/dev/null 2>&1 || \
    docker network create anser_project_network >/dev/null 2>&1 || true

log "starting full stack (COMPOSE_PROFILES=${COMPOSE_PROFILES})"
docker compose -f "$COMPOSE_FILE" down -v --remove-orphans >/dev/null 2>&1 || true
docker compose -f "$COMPOSE_FILE" up -d --build 2>&1 | tail -5

log "waiting for RabbitMQ..."
wait_rabbit 90 || { fail "RabbitMQ not ready"; exit 1; }

log "waiting for gateway..."
wait_health "$HEALTH_URL" 120 || { fail "Gateway not ready"; exit 1; }

# Wait for worker to be listening
sleep 5
worker_logs="$(docker compose -f "$COMPOSE_FILE" logs --no-color php-worker 2>&1 || true)"
if grep -Fq "[worker] listening" <<<"$worker_logs"; then
    pass "full stack ready (gateway + worker + SPIRE + RabbitMQ)"
elif grep -Fq "FATAL" <<<"$worker_logs"; then
    fail "worker FATAL error — check LSVID/SPIFFE configuration"
    docker compose -f "$COMPOSE_FILE" logs --no-color --tail=20 php-worker >&2
    exit 1
else
    log "warning: worker may not be fully ready yet, proceeding..."
fi

# ============================================================================
# Stage 3: Ablation Study (4 profiles)
# ============================================================================
if [[ "$SKIP_ABLATION" != "1" ]]; then
    section "Stage 3: Ablation Study (4 profiles × ${STRESS_TOTAL} req × ${STRESS_CONC} conc)"

    ABLATION_DIR="${OUT_DIR}/ablation"
    mkdir -p "$ABLATION_DIR"

    apply_profile() {
        local profile="$1" gw_enabled="$2" gw_required="$3"
        log "applying profile ${profile} (LSVID_ENABLED=${gw_enabled}, LSVID_REQUIRED=${gw_required})"

        cat > "${PROJECT_DIR}/.experiment-override.yml" <<YAML
services:
  gateway:
    environment:
      LSVID_ENABLED: "${gw_enabled}"
      LSVID_REQUIRED: "${gw_required}"
  php-worker:
    environment:
      LSVID_ENABLED: "${gw_enabled}"
      LSVID_REQUIRED: "${gw_required}"
YAML

        docker compose -f "$COMPOSE_FILE" -f .experiment-override.yml up -d --force-recreate gateway php-worker 2>&1 | tail -3
        sleep 3
        wait_health "$HEALTH_URL" 90 || { fail "gateway not healthy after profile ${profile}"; return 1; }
        sleep 2
    }

    run_profile() {
        local profile="$1" label="$2"
        local stress_json="${ABLATION_DIR}/stress-${profile}.json"
        local resource_json="${ABLATION_DIR}/resource-${profile}.json"

        log "stress test: profile ${profile} (${label})"

        # Start resource collection in background
        collect_docker_stats "$resource_json" "$((STRESS_TOTAL / STRESS_CONC + 10))" &
        local stats_pid=$!

        # Run stress test
        STRESS_JSON_OUT="$stress_json" LSVID_MODE="$profile" \
            bash scripts/stress_test.sh "$STRESS_TOTAL" "$STRESS_CONC" > "${ABLATION_DIR}/stress-${profile}-stdout.txt" 2>&1 || true

        # Wait for stats collection
        kill "$stats_pid" 2>/dev/null; wait "$stats_pid" 2>/dev/null || true

        if [[ -f "$stress_json" ]]; then
            pass "profile ${profile}: ${stress_json}"
        else
            fail "profile ${profile}: no JSON output"
        fi
    }

    # Profile A: Baseline (no LSVID)
    apply_profile A 0 0 && run_profile A "baseline (LSVID=off)"

    # Profile B: LSVID minting only (fail-open)
    apply_profile B 1 0 && run_profile B "LSVID mint (fail-open)"

    # Profile C: LSVID fail-closed
    apply_profile C 1 1 && run_profile C "LSVID fail-closed"

    # Profile D: Full ZT (same as C; re-validation always on)
    apply_profile D 1 1 && run_profile D "Full ZT (fail-closed + re-validate)"

    rm -f "${PROJECT_DIR}/.experiment-override.yml"

    # Restore full ZT mode for remaining stages
    docker compose -f "$COMPOSE_FILE" up -d --force-recreate gateway php-worker 2>&1 | tail -3
    wait_health "$HEALTH_URL" 90 || true
    sleep 3

    # Generate ablation summary
    log "generating ablation summary..."
    php -n -r '
        $dir = $argv[1];
        $profiles = ["A" => "baseline", "B" => "lsvid-mint", "C" => "fail-closed", "D" => "full-zt"];
        $data = [];
        foreach ($profiles as $p => $label) {
            $f = "${dir}/stress-${p}.json";
            if (!file_exists($f)) continue;
            $r = json_decode(file_get_contents($f), true);
            $data[$p] = [
                "label" => $label,
                "success" => $r["success"] ?? 0,
                "failed" => $r["failed"] ?? 0,
                "rate_rps" => $r["rate_per_sec"] ?? 0,
                "latency" => $r["latency_sec"] ?? [],
            ];
        }
        $base = $data["A"]["latency"]["p50"] ?? null;
        foreach ($data as $p => &$d) {
            $cur = $d["latency"]["p50"] ?? null;
            $d["delta_p50_pct"] = ($base && $cur && $base > 0)
                ? round(($cur - $base) / $base * 100, 1)
                : null;
        }
        echo json_encode(["profiles" => $data, "timestamp" => date("c")], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    ' "$ABLATION_DIR" > "${OUT_DIR}/ablation-summary.json" 2>/dev/null && \
        pass "ablation summary → ablation-summary.json" || \
        fail "ablation summary generation failed"
else
    log "skipping ablation study (EXP_SKIP_ABLATION=1)"
fi

# ============================================================================
# Stage 4: Saga End-to-End Latency
# ============================================================================
section "Stage 4: Saga End-to-End Latency (${SAGA_SAMPLES} samples)"

SAGA_OUT="${OUT_DIR}/saga-latency.json"
saga_results="[]"

# Ensure worker is listening
sleep 3
w_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
wait_worker_log "$w_since" "[worker] listening" 60 || {
    # Check full logs
    w_all="$(docker compose -f "$COMPOSE_FILE" logs --no-color php-worker 2>&1 || true)"
    if ! grep -Fq "[worker] listening" <<<"$w_all"; then
        fail "worker not listening — skipping saga measurement"
        echo '{"error":"worker not listening"}' > "$SAGA_OUT"
    fi
}

if [[ ! -f "$SAGA_OUT" ]]; then
    saga_data=()
    for s in $(seq 1 "$SAGA_SAMPLES"); do
        trace="exp-saga-${s}-$(date +%s)"
        purge_queue "order_queue" >/dev/null
        sleep 1

        s_since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        s_start_ms="$(php -n -r 'echo (int)(microtime(true)*1000);')"

        # Send request
        http_code="$(curl -sS -o /dev/null -w '%{http_code}' \
            -X POST "$REQUEST_URL" \
            -H 'Content-Type: application/json' \
            -H "X-Correlation-Id: ${trace}" \
            -d '{"userKey":"1","productList":[{"p_key":1,"amount":1}],"total":100}' 2>/dev/null || echo "000")"

        if [[ "$http_code" != "202" ]]; then
            log "  sample ${s}: HTTP ${http_code} (skipped)"
            continue
        fi

        # Wait for each saga step and record timestamps
        step1_ms=0; step2_ms=0; step3_ms=0; step4_ms=0; compensation_ms=0

        if wait_worker_log "$s_since" "Saga Step 1:" 30; then
            step1_ms="$(php -n -r 'echo (int)(microtime(true)*1000);')"
        fi
        if wait_worker_log "$s_since" "Saga Step 2:" 30; then
            step2_ms="$(php -n -r 'echo (int)(microtime(true)*1000);')"
        fi
        if wait_worker_log "$s_since" "Saga Step 3:" 30; then
            step3_ms="$(php -n -r 'echo (int)(microtime(true)*1000);')"
        fi

        # Step 4 or compensation
        elapsed_wait=0
        while (( elapsed_wait < 30 )); do
            logs="$(docker compose -f "$COMPOSE_FILE" logs --no-color --since "$s_since" php-worker 2>&1 || true)"
            if grep -Fq "Saga Step 4:" <<<"$logs"; then
                step4_ms="$(php -n -r 'echo (int)(microtime(true)*1000);')"
                break
            fi
            if grep -Fq "RollbackSaga" <<<"$logs" || grep -Fq "支付失敗" <<<"$logs"; then
                compensation_ms="$(php -n -r 'echo (int)(microtime(true)*1000);')"
                break
            fi
            sleep 1; elapsed_wait=$((elapsed_wait + 1))
        done

        s_end_ms="$(php -n -r 'echo (int)(microtime(true)*1000);')"

        entry="$(php -n -r '
            echo json_encode([
                "sample" => (int)$argv[1],
                "total_ms" => (int)$argv[2] - (int)$argv[3],
                "request_to_step1_ms" => (int)$argv[4] > 0 ? (int)$argv[4] - (int)$argv[3] : null,
                "step1_to_step2_ms" => ((int)$argv[4] > 0 && (int)$argv[5] > 0) ? (int)$argv[5] - (int)$argv[4] : null,
                "step2_to_step3_ms" => ((int)$argv[5] > 0 && (int)$argv[6] > 0) ? (int)$argv[6] - (int)$argv[5] : null,
                "step3_to_step4_ms" => ((int)$argv[6] > 0 && (int)$argv[7] > 0) ? (int)$argv[7] - (int)$argv[6] : null,
                "completed" => (int)$argv[7] > 0,
                "compensated" => (int)$argv[8] > 0,
            ]);
        ' "$s" "$s_end_ms" "$s_start_ms" "$step1_ms" "$step2_ms" "$step3_ms" "$step4_ms" "$compensation_ms")"

        saga_data+=("$entry")
        completed="completed"
        [[ "$step4_ms" == "0" ]] && completed="compensated"
        total_ms=$((s_end_ms - s_start_ms))
        log "  sample ${s}: ${total_ms}ms (${completed})"
    done

    # Build JSON array
    php -n -r '
        $items = array_slice($argv, 1);
        $parsed = array_map(fn($s) => json_decode($s, true), $items);
        $parsed = array_filter($parsed);

        $totals = array_column($parsed, "total_ms");
        sort($totals);
        $n = count($totals);

        $summary = $n > 0 ? [
            "samples" => $n,
            "avg_ms" => round(array_sum($totals) / $n, 1),
            "min_ms" => $totals[0],
            "max_ms" => $totals[$n-1],
            "p50_ms" => $totals[(int)($n*0.5)],
            "p95_ms" => $totals[min($n-1, (int)($n*0.95))],
        ] : ["samples" => 0, "error" => "no valid samples"];

        echo json_encode([
            "timestamp" => date("c"),
            "summary" => $summary,
            "samples" => array_values($parsed),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    ' "${saga_data[@]}" > "$SAGA_OUT" 2>/dev/null && \
        pass "saga latency → saga-latency.json" || \
        fail "saga latency JSON generation failed"
fi

# ============================================================================
# Stage 5: Envelope Size Measurement
# ============================================================================
section "Stage 5: Envelope Size Measurement"

ENVELOPE_OUT="${OUT_DIR}/envelope-size.json"

# Send a request and capture the envelope from RabbitMQ
purge_queue "order_queue" >/dev/null
sleep 1

trace_size="exp-size-$(date +%s)-$$"
curl -sS -o /dev/null \
    -X POST "$REQUEST_URL" \
    -H 'Content-Type: application/json' \
    -H "X-Correlation-Id: ${trace_size}" \
    -d '{"userKey":"42","productList":[{"p_key":1,"amount":2},{"p_key":3,"amount":1}],"total":500}' 2>/dev/null || true

sleep 3

queue_json="$(fetch_queue_messages "order_queue" "ack_requeue_true")"

TRACE_ID="$trace_size" php -n -r '
    $rows = json_decode(stream_get_contents(STDIN), true);
    $trace = getenv("TRACE_ID");
    $result = ["trace" => $trace, "found" => false];

    if (is_array($rows)) {
        foreach ($rows as $row) {
            $payload = $row["payload"] ?? null;
            if (!is_string($payload)) continue;
            $data = json_decode($payload, true);
            if (!is_array($data) || ($data["id"] ?? "") !== $trace) continue;

            $lsvid = $data["lsvid"] ?? null;
            $dataOnly = $data;
            unset($dataOnly["lsvid"]);

            $result = [
                "found" => true,
                "trace" => $trace,
                "envelope_total_bytes" => strlen($payload),
                "envelope_without_lsvid_bytes" => strlen(json_encode($dataOnly, JSON_UNESCAPED_SLASHES)),
                "lsvid_token_bytes" => is_string($lsvid) ? strlen($lsvid) : 0,
                "lsvid_present" => is_string($lsvid) && $lsvid !== "",
                "lsvid_overhead_pct" => null,
                "data_bytes" => strlen(json_encode($data["data"] ?? [], JSON_UNESCAPED_SLASHES)),
                "spiffe_path_entries" => count($data["spiffe_path"] ?? []),
                "schema_version" => $data["schema_version"] ?? null,
            ];
            if ($result["envelope_without_lsvid_bytes"] > 0 && $result["lsvid_token_bytes"] > 0) {
                $result["lsvid_overhead_pct"] = round(
                    $result["lsvid_token_bytes"] / $result["envelope_without_lsvid_bytes"] * 100, 1
                );
            }
            break;
        }
    }

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
' <<<"$queue_json" > "$ENVELOPE_OUT" 2>/dev/null

if grep -q '"found":true' "$ENVELOPE_OUT" 2>/dev/null; then
    total="$(php -n -r 'echo json_decode(file_get_contents($argv[1]),true)["envelope_total_bytes"] ?? "?";' "$ENVELOPE_OUT")"
    lsvid="$(php -n -r 'echo json_decode(file_get_contents($argv[1]),true)["lsvid_token_bytes"] ?? "?";' "$ENVELOPE_OUT")"
    pct="$(php -n -r 'echo json_decode(file_get_contents($argv[1]),true)["lsvid_overhead_pct"] ?? "?";' "$ENVELOPE_OUT")"
    pass "envelope: total=${total}B, LSVID=${lsvid}B (${pct}% overhead) → envelope-size.json"
else
    fail "could not capture envelope from queue"
fi

# ============================================================================
# Stage 6: Summary Report
# ============================================================================
section "Stage 6: Summary Report"

# Generate unified summary JSON
php -n -r '
    $dir = $argv[1];
    $stamp = $argv[2];
    $summary = [
        "experiment_id" => $stamp,
        "generated_at" => date("c"),
        "env" => [
            "php_version" => PHP_VERSION,
            "os" => php_uname("s") . " " . php_uname("m"),
        ],
        "stages" => [],
    ];

    // Stage 1: LSVID micro
    $f = "${dir}/lsvid-micro.json";
    if (file_exists($f)) {
        $summary["stages"]["lsvid_micro"] = json_decode(file_get_contents($f), true);
    }
    $f = "${dir}/lsvid-token-sizes.json";
    if (file_exists($f)) {
        $summary["stages"]["lsvid_token_sizes"] = json_decode(file_get_contents($f), true);
    }

    // Stage 2: SPIFFE micro
    $f = "${dir}/spiffe-micro.json";
    if (file_exists($f)) {
        $d = json_decode(file_get_contents($f), true);
        $summary["stages"]["spiffe_micro"] = is_array($d) ? $d : ["raw" => file_get_contents($f)];
    }

    // Stage 3: Ablation
    $f = "${dir}/ablation-summary.json";
    if (file_exists($f)) {
        $summary["stages"]["ablation"] = json_decode(file_get_contents($f), true);
    }

    // Stage 4: Saga
    $f = "${dir}/saga-latency.json";
    if (file_exists($f)) {
        $summary["stages"]["saga_latency"] = json_decode(file_get_contents($f), true);
    }

    // Stage 5: Envelope
    $f = "${dir}/envelope-size.json";
    if (file_exists($f)) {
        $summary["stages"]["envelope_size"] = json_decode(file_get_contents($f), true);
    }

    file_put_contents("${dir}/experiment-summary.json",
        json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$OUT_DIR" "$STAMP" 2>/dev/null

# Generate markdown report
php -n -r '
    $dir = $argv[1];
    $f = "${dir}/experiment-summary.json";
    if (!file_exists($f)) exit;
    $s = json_decode(file_get_contents($f), true);
    $md = [];
    $md[] = "# Experiment Report";
    $md[] = "";
    $md[] = "- **ID**: " . ($s["experiment_id"] ?? "?");
    $md[] = "- **Generated**: " . ($s["generated_at"] ?? "?");
    $md[] = "- **PHP**: " . ($s["env"]["php_version"] ?? "?");
    $md[] = "- **OS**: " . ($s["env"]["os"] ?? "?");
    $md[] = "";

    // LSVID micro
    if (isset($s["stages"]["lsvid_micro"]["results"])) {
        $md[] = "## LSVID Micro-Benchmark";
        $md[] = "";
        $md[] = "| Operation | ops/s | μs/op |";
        $md[] = "|-----------|------:|------:|";
        foreach ($s["stages"]["lsvid_micro"]["results"] as $r) {
            $md[] = sprintf("| %s | %s | %.2f |", $r["name"], number_format((int)$r["ops_per_sec"]), $r["us_per_op"]);
        }
        $md[] = "";
    }

    // Token sizes
    if (isset($s["stages"]["lsvid_token_sizes"])) {
        $t = $s["stages"]["lsvid_token_sizes"];
        $md[] = "## LSVID Token Sizes";
        $md[] = "";
        $md[] = "| Level | Bytes | Growth vs L0 |";
        $md[] = "|-------|------:|-------------:|";
        $md[] = sprintf("| L0 | %d | — |", $t["L0_bytes"] ?? 0);
        $md[] = sprintf("| L1 | %d | +%d (%.1fx) |", $t["L1_bytes"] ?? 0, $t["L0_to_L1_growth"] ?? 0, $t["growth_ratio_L1_L0"] ?? 0);
        $md[] = sprintf("| L2 | %d | +%d (%.1fx) |", $t["L2_bytes"] ?? 0, ($t["L1_to_L2_growth"] ?? 0) + ($t["L0_to_L1_growth"] ?? 0), $t["growth_ratio_L2_L0"] ?? 0);
        $md[] = "";
    }

    // Ablation
    if (isset($s["stages"]["ablation"]["profiles"])) {
        $md[] = "## Ablation Study";
        $md[] = "";
        $md[] = "| Profile | Success | RPS | p50 (s) | p95 (s) | p99 (s) | Δp50 |";
        $md[] = "|---------|--------:|----:|--------:|--------:|--------:|-----:|";
        foreach ($s["stages"]["ablation"]["profiles"] as $p => $d) {
            $lat = $d["latency"] ?? [];
            $delta = isset($d["delta_p50_pct"]) ? sprintf("%+.1f%%", $d["delta_p50_pct"]) : "—";
            $md[] = sprintf("| %s (%s) | %d | %.1f | %.3f | %.3f | %.3f | %s |",
                $p, $d["label"], $d["success"], $d["rate_rps"],
                $lat["p50"] ?? 0, $lat["p95"] ?? 0, $lat["p99"] ?? 0, $delta);
        }
        $md[] = "";
    }

    // Saga
    if (isset($s["stages"]["saga_latency"]["summary"])) {
        $sl = $s["stages"]["saga_latency"]["summary"];
        $md[] = "## Saga End-to-End Latency";
        $md[] = "";
        $md[] = sprintf("- Samples: %d", $sl["samples"] ?? 0);
        $md[] = sprintf("- avg: %sms, p50: %sms, p95: %sms", $sl["avg_ms"] ?? "?", $sl["p50_ms"] ?? "?", $sl["p95_ms"] ?? "?");
        $md[] = sprintf("- min: %sms, max: %sms", $sl["min_ms"] ?? "?", $sl["max_ms"] ?? "?");
        $md[] = "";
    }

    // Envelope
    if (isset($s["stages"]["envelope_size"]) && ($s["stages"]["envelope_size"]["found"] ?? false)) {
        $e = $s["stages"]["envelope_size"];
        $md[] = "## Envelope Size";
        $md[] = "";
        $md[] = sprintf("- Total: %d bytes", $e["envelope_total_bytes"]);
        $md[] = sprintf("- Without LSVID: %d bytes", $e["envelope_without_lsvid_bytes"]);
        $md[] = sprintf("- LSVID token: %d bytes (%.1f%% overhead)", $e["lsvid_token_bytes"], $e["lsvid_overhead_pct"] ?? 0);
        $md[] = "";
    }

    file_put_contents("${dir}/experiment-report.md", implode("\n", $md) . "\n");
' "$OUT_DIR" 2>/dev/null

pass "summary → experiment-summary.json"
pass "report  → experiment-report.md"

# ============================================================================
# Final Summary
# ============================================================================
section "Experiment Data Collection Complete"

END_TIME="$(date +%s)"
DURATION=$((END_TIME - START_TIME))

echo ""
log "output directory: ${OUT_DIR}"
log "duration: ${DURATION}s"
echo ""
ls -la "$OUT_DIR"
echo ""

if [[ -f "${OUT_DIR}/experiment-report.md" ]]; then
    echo "── Report Preview ──"
    head -40 "${OUT_DIR}/experiment-report.md"
    echo "..."
fi

log "done"
