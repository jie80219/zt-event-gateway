<?php
/**
 * Summarize LSVID stress-experiment JSON outputs into a markdown table.
 *
 * Reads docs/data/stress-{A,B,C,D}-{stamp}.json and writes
 * docs/data/stress-summary.md with absolute numbers and deltas vs the
 * baseline profile A.
 *
 * Usage:
 *   php scripts/summarize-stress.php [stamp]
 *
 * If `stamp` is omitted, uses the most recent set of four profile files.
 */

declare(strict_types=1);

$dataDir = dirname(__DIR__) . '/docs/data';
$stamp = $argv[1] ?? null;

if ($stamp === null) {
    $candidates = glob("{$dataDir}/stress-A-*.json") ?: [];
    if ($candidates === []) {
        fwrite(STDERR, "No stress-A-*.json files found in {$dataDir}\n");
        exit(1);
    }
    rsort($candidates);
    if (!preg_match('/stress-A-(.+)\.json$/', $candidates[0], $m)) {
        fwrite(STDERR, "Could not parse timestamp from " . $candidates[0] . "\n");
        exit(1);
    }
    $stamp = $m[1];
}

$profiles = ['A', 'B', 'C', 'D'];
$loaded = [];
foreach ($profiles as $p) {
    $path = "{$dataDir}/stress-{$p}-{$stamp}.json";
    if (!file_exists($path)) {
        fwrite(STDERR, "Missing file {$path}\n");
        continue;
    }
    $loaded[$p] = json_decode(file_get_contents($path), true);
}
if ($loaded === []) {
    fwrite(STDERR, "No JSON loaded, nothing to summarize.\n");
    exit(1);
}

$labels = [
    'A' => 'A — baseline (LSVID off)',
    'B' => 'B — minting only (fail-open)',
    'C' => 'C — fail-closed',
    'D' => 'D — fail-closed + re-validate',
];

$baseline = $loaded['A'] ?? null;
$rows = [];
foreach ($profiles as $p) {
    if (!isset($loaded[$p])) {
        continue;
    }
    $r = $loaded[$p];
    $delta = function (string $key) use ($r, $baseline) {
        if ($baseline === null) {
            return '';
        }
        $base = $baseline['latency_sec'][$key] ?? null;
        $cur  = $r['latency_sec'][$key] ?? null;
        if ($base === null || $cur === null || $base == 0) {
            return '';
        }
        $pct = (($cur - $base) / $base) * 100;
        return sprintf(' (%+.1f%%)', $pct);
    };
    $rateDelta = '';
    if ($baseline !== null && isset($baseline['rate_per_sec']) && $baseline['rate_per_sec'] > 0) {
        $pct = (($r['rate_per_sec'] - $baseline['rate_per_sec']) / $baseline['rate_per_sec']) * 100;
        $rateDelta = sprintf(' (%+.1f%%)', $pct);
    }
    $rows[] = [
        'profile'   => $labels[$p],
        'success'   => $r['success'] ?? 0,
        'failed'    => $r['failed'] ?? 0,
        'rate'      => sprintf('%.1f%s', $r['rate_per_sec'] ?? 0, $rateDelta),
        'p50'       => sprintf('%.3f%s', $r['latency_sec']['p50'] ?? 0, $delta('p50')),
        'p95'       => sprintf('%.3f%s', $r['latency_sec']['p95'] ?? 0, $delta('p95')),
        'p99'       => sprintf('%.3f%s', $r['latency_sec']['p99'] ?? 0, $delta('p99')),
    ];
}

$md = [];
$md[] = "# LSVID Stress Summary";
$md[] = "";
$md[] = "Run timestamp: `{$stamp}`";
$md[] = "";
$md[] = "| Profile | Success | Failed | Throughput (req/s) | p50 (s) | p95 (s) | p99 (s) |";
$md[] = "|---|---|---|---|---|---|---|";
foreach ($rows as $r) {
    $md[] = sprintf(
        '| %s | %d | %d | %s | %s | %s | %s |',
        $r['profile'], $r['success'], $r['failed'], $r['rate'], $r['p50'], $r['p95'], $r['p99'],
    );
}
$md[] = "";
$md[] = "Deltas are computed against profile A (baseline, LSVID off).";
$md[] = "";

$out = "{$dataDir}/stress-summary.md";
file_put_contents($out, implode("\n", $md));
echo "Wrote {$out}\n";
