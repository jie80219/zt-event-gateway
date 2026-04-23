<?php

declare(strict_types=1);

/**
 * Aggregate multiple experiment artifacts into a single thesis-ready JSON.
 *
 * Inputs (defaults can be overridden via env vars):
 *   EXPERIMENT_DIR   — artifacts/experiment-<STAMP>/        (from collect-experiment-data.sh)
 *   SECURITY_DIR     — artifacts/security-<STAMP>/          (from run-security-suite.sh, optional)
 *   LSVID_BENCH      — docs/data/lsvid-bench-latest.json    (optional)
 *
 * Output:
 *   OUT_PATH         — docs/data/thesis-experiment-<STAMP>.json
 *                      (also updates docs/data/thesis-experiment-latest.json symlink)
 *
 * Usage:
 *   php scripts/aggregate-thesis-data.php
 *   EXPERIMENT_DIR=artifacts/experiment-20260423-101010 \
 *     php scripts/aggregate-thesis-data.php
 */

$root = dirname(__DIR__);

function readJson(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $raw = preg_replace('/^\s*Deprecated:.*$/m', '', $raw);
    $data = json_decode(trim($raw), true);
    return is_array($data) ? $data : null;
}

function findLatestDir(string $parent, string $prefix): ?string
{
    if (!is_dir($parent)) {
        return null;
    }
    $matches = glob("$parent/$prefix*") ?: [];
    $dirs = array_values(array_filter($matches, 'is_dir'));
    if (empty($dirs)) {
        return null;
    }
    usort($dirs, fn ($a, $b) => filemtime($b) <=> filemtime($a));
    return $dirs[0];
}

$experimentDir = getenv('EXPERIMENT_DIR')
    ?: findLatestDir("$root/artifacts", 'experiment-');
$securityDir = getenv('SECURITY_DIR')
    ?: findLatestDir("$root/artifacts", 'security-');
$lsvidBenchPath = getenv('LSVID_BENCH') ?: "$root/docs/data/lsvid-bench-latest.json";

if (!$experimentDir) {
    fwrite(STDERR, "[aggregate] no experiment artifact found; run collect-experiment-data.sh first\n");
    exit(1);
}

fwrite(STDERR, "[aggregate] experiment_dir = $experimentDir\n");
fwrite(STDERR, "[aggregate] security_dir   = " . ($securityDir ?: '(none)') . "\n");

$stamp = preg_replace('/^.*experiment-/', '', basename($experimentDir));

$summary = [
    'experiment_id'  => $stamp,
    'generated_at'   => date('c'),
    'sources'        => [
        'experiment_dir' => $experimentDir,
        'security_dir'   => $securityDir,
        'lsvid_bench'    => $lsvidBenchPath,
    ],
    'env'            => [
        'php_version' => PHP_VERSION,
        'os'          => php_uname('s') . ' ' . php_uname('m'),
    ],
    'profiles'       => [],
    'lsvid_size'     => [
        'synthesized'    => null,
        'real_from_wire' => null,
        'envelope'       => null,
    ],
    'security_matrix' => null,
    'micro_benchmarks' => null,
    'saga_latency'    => null,
    'notes'           => [],
];

// ── Profiles: latency & throughput per A/B/C/D ──────────────────────────────
$ablation = readJson("$experimentDir/ablation-summary.json");
if ($ablation && isset($ablation['profiles'])) {
    foreach ($ablation['profiles'] as $name => $p) {
        $latency = $p['latency'] ?? [];
        $summary['profiles'][$name] = [
            'label'            => $p['label'] ?? $name,
            'success'          => $p['success'] ?? null,
            'failed'           => $p['failed'] ?? null,
            'throughput_rps'   => $p['rate_rps'] ?? null,
            'latency_p50_ms'   => isset($latency['p50']) ? round($latency['p50'] * 1000, 2) : null,
            'latency_p90_ms'   => isset($latency['p90']) ? round($latency['p90'] * 1000, 2) : null,
            'latency_p95_ms'   => isset($latency['p95']) ? round($latency['p95'] * 1000, 2) : null,
            'latency_p99_ms'   => isset($latency['p99']) ? round($latency['p99'] * 1000, 2) : null,
            'latency_avg_ms'   => isset($latency['avg']) ? round($latency['avg'] * 1000, 2) : null,
            'delta_p50_pct'    => $p['delta_p50_pct'] ?? null,
        ];
    }
} else {
    $summary['notes'][] = 'ablation-summary.json missing — no per-profile latency data';
}

// ── LSVID size: synthesized (Stage 1) ───────────────────────────────────────
$syntheticSizes = readJson("$experimentDir/lsvid-token-sizes.json");
if ($syntheticSizes) {
    $summary['lsvid_size']['synthesized'] = [
        'source'             => 'benchmark-lsvid.php (in-process signing)',
        'L0_bytes'           => $syntheticSizes['L0_bytes'] ?? null,
        'L1_bytes'           => $syntheticSizes['L1_bytes'] ?? null,
        'L2_bytes'           => $syntheticSizes['L2_bytes'] ?? null,
        'L0_to_L1_growth'    => $syntheticSizes['L0_to_L1_growth'] ?? null,
        'L1_to_L2_growth'    => $syntheticSizes['L1_to_L2_growth'] ?? null,
        'growth_ratio_L1_L0' => $syntheticSizes['growth_ratio_L1_L0'] ?? null,
        'growth_ratio_L2_L0' => $syntheticSizes['growth_ratio_L2_L0'] ?? null,
    ];
}

// ── LSVID size: real chain (Stage 5b) ───────────────────────────────────────
$realChain = readJson("$experimentDir/lsvid-real-chain.json");
if ($realChain) {
    $summary['lsvid_size']['real_from_wire'] = [
        'source'   => 'live capture from order_queue, event queue, and downstream X-LSVID',
        'trace_id' => $realChain['trace_id'] ?? null,
        'captured' => $realChain['captured'] ?? null,
        'sizes'    => $realChain['sizes'] ?? null,
        'growth'   => $realChain['growth'] ?? null,
        'nested_chain_intact' => $realChain['nested_chain_intact'] ?? null,
    ];
}

// ── LSVID size: envelope overhead (Stage 5) ─────────────────────────────────
$envelope = readJson("$experimentDir/envelope-size.json");
if ($envelope && ($envelope['found'] ?? false)) {
    $summary['lsvid_size']['envelope'] = [
        'envelope_total_bytes'         => $envelope['envelope_total_bytes'] ?? null,
        'envelope_without_lsvid_bytes' => $envelope['envelope_without_lsvid_bytes'] ?? null,
        'lsvid_token_bytes'            => $envelope['lsvid_token_bytes'] ?? null,
        'lsvid_overhead_pct'           => $envelope['lsvid_overhead_pct'] ?? null,
        'data_bytes'                   => $envelope['data_bytes'] ?? null,
    ];
}

// ── Cross-check: synthesized vs real, should match within 2% ────────────────
$syn = $summary['lsvid_size']['synthesized'] ?? null;
$real = $summary['lsvid_size']['real_from_wire']['sizes'] ?? null;
if ($syn && $real && ($real['L0']['bytes'] ?? 0) > 0 && ($syn['L0_bytes'] ?? 0) > 0) {
    $synL0 = $syn['L0_bytes'];
    $realL0 = $real['L0']['bytes'];
    $diffPct = abs($synL0 - $realL0) / $synL0 * 100;
    $summary['lsvid_size']['sanity_check'] = [
        'synthesized_L0'    => $synL0,
        'real_L0'           => $realL0,
        'diff_pct'          => round($diffPct, 2),
        'within_tolerance'  => $diffPct < 5.0,
    ];
    if ($diffPct >= 5.0) {
        $summary['notes'][] = sprintf(
            'synthesized L0 (%dB) and real-wire L0 (%dB) diverge by %.1f%% — investigate',
            $synL0, $realL0, $diffPct,
        );
    }
}

// ── Micro-benchmarks ────────────────────────────────────────────────────────
$lsvidBench = readJson($lsvidBenchPath);
if ($lsvidBench && isset($lsvidBench['results'])) {
    $ops = [];
    foreach ($lsvidBench['results'] as $r) {
        $ops[$r['name'] ?? '?'] = [
            'ops_per_sec' => $r['ops_per_sec'] ?? null,
            'us_per_op'   => $r['us_per_op'] ?? null,
        ];
    }
    $summary['micro_benchmarks'] = [
        'lsvid_ops' => $ops,
        'iterations' => $lsvidBench['iterations'] ?? null,
        'run_at'     => $lsvidBench['generated_at'] ?? null,
    ];
}

// ── Saga latency breakdown (Stage 4) ────────────────────────────────────────
$saga = readJson("$experimentDir/saga-latency.json");
if ($saga) {
    $summary['saga_latency'] = $saga;
}

// ── Security matrix (security-suite) ────────────────────────────────────────
if ($securityDir) {
    $secSummary = readJson("$securityDir/security-summary.json");
    if ($secSummary) {
        $summary['security_matrix'] = [
            'source'    => $securityDir,
            'raw'       => $secSummary,
            'per_stage' => [],
        ];

        // Convenience view: group by category → profile
        foreach (['stage1', 'stage2', 'stage3', 'stage4'] as $stage) {
            $stageFile = "$securityDir/$stage-summary.json";
            $stageData = readJson($stageFile);
            if ($stageData) {
                $summary['security_matrix']['per_stage'][$stage] = $stageData;
            }
        }
    } else {
        $summary['notes'][] = "security-summary.json missing in $securityDir";
    }
}

// ── Write output ────────────────────────────────────────────────────────────
$outDir = "$root/docs/data";
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$outPath = "$outDir/thesis-experiment-$stamp.json";
file_put_contents(
    $outPath,
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
);

$latestPath = "$outDir/thesis-experiment-latest.json";
@unlink($latestPath);
copy($outPath, $latestPath);

fwrite(STDERR, "[aggregate] wrote $outPath\n");
fwrite(STDERR, "[aggregate] wrote $latestPath\n");

// ── Terse stdout summary for humans ─────────────────────────────────────────
echo "=== Thesis Experiment Summary: $stamp ===\n";
if (!empty($summary['profiles'])) {
    echo "\nProfiles:\n";
    printf("  %-3s %-18s %-10s %-10s %-10s %-10s\n", "ID", "label", "p50_ms", "p95_ms", "p99_ms", "rps");
    foreach ($summary['profiles'] as $id => $p) {
        printf(
            "  %-3s %-18s %-10s %-10s %-10s %-10s\n",
            $id,
            $p['label'] ?? '?',
            $p['latency_p50_ms'] ?? '-',
            $p['latency_p95_ms'] ?? '-',
            $p['latency_p99_ms'] ?? '-',
            $p['throughput_rps'] ?? '-',
        );
    }
}

if ($summary['lsvid_size']['synthesized']) {
    $syn = $summary['lsvid_size']['synthesized'];
    echo "\nLSVID sizes (synthesized):\n";
    printf("  L0=%sB  L1=%sB  L2=%sB  (L2/L0=%.2fx)\n",
        $syn['L0_bytes'] ?? '?',
        $syn['L1_bytes'] ?? '?',
        $syn['L2_bytes'] ?? '?',
        $syn['growth_ratio_L2_L0'] ?? 0,
    );
}

if ($summary['lsvid_size']['real_from_wire']) {
    $rw = $summary['lsvid_size']['real_from_wire'];
    $sz = $rw['sizes'] ?? [];
    echo "\nLSVID sizes (real-from-wire):\n";
    printf("  L0=%sB  L1=%sB  L2=%sB\n",
        $sz['L0']['bytes'] ?? '?',
        $sz['L1']['bytes'] ?? '?',
        $sz['L2']['bytes'] ?? '?',
    );
    if (!empty($rw['nested_chain_intact'])) {
        $n = $rw['nested_chain_intact'];
        echo "  nested chain: L1⊇L0=" . (($n['L1_nested_equals_L0'] ?? false) ? 'yes' : 'no');
        if (isset($n['L2_nested_equals_L1'])) {
            echo "  L2⊇L1=" . ($n['L2_nested_equals_L1'] ? 'yes' : 'no');
        }
        echo "\n";
    }
}

if (!empty($summary['notes'])) {
    echo "\nNotes:\n";
    foreach ($summary['notes'] as $n) {
        echo "  - $n\n";
    }
}
echo "\nOutput: $outPath\n";
