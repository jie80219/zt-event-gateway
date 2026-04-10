<?php
/**
 * LSVID — Performance Benchmark Suite
 *
 * Measures the CPU cost of every hot-path LSVID operation in the
 * zt-event-gateway request lifecycle:
 *
 *   1. LSVIDSigner::createBase()       (gateway L0 mint)
 *   2. LSVIDSigner::extend()           (worker L0 → L1)
 *   3. LSVIDSigner::extend()           (filter L1 → L2)
 *   4. LSVID::parse()                  (cheap header/payload split)
 *   5. LSVIDValidator::validate() L0   (single-level verification)
 *   6. LSVIDValidator::validate() L1   (two-level chain)
 *   7. LSVIDValidator::validate() L2   (three-level chain)
 *   8. JtiReplayCache::seenOrRecord()  (fresh + hit paths)
 *   9. Trust-bundle CA cache cold vs hot (first vs subsequent validate)
 *
 * Each measurement: N warmup iterations + N timed iterations using hrtime.
 * Results are printed to stdout AND serialized to a JSON file for the
 * experiment report to pick up.
 *
 * Usage:
 *   php tests/benchmark-lsvid.php           # RS256 only (key type from reader)
 *   ITER=20000 php tests/benchmark-lsvid.php # override iteration count
 *
 * Output:
 *   docs/data/lsvid-bench-{timestamp}.json   raw data
 *   docs/data/lsvid-bench-latest.json        symlink/copy for reports
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../packages/php-lsvid/tests/LSVID/TestSvidReader.php';

use SDPMlab\LSVID\JtiReplayCache;
use SDPMlab\LSVID\LSVID;
use SDPMlab\LSVID\LSVIDSigner;
use SDPMlab\LSVID\LSVIDValidator;
use SDPMlab\LSVID\Tests\TestSvidReader;

final class Bench
{
    /** @var array<int, array{name: string, ops_per_sec: float, us_per_op: float, iterations: int}> */
    public array $results = [];

    public function run(string $name, int $iterations, callable $fn): void
    {
        // Warmup ~10% of iterations (capped at 500) to prime opcache + any
        // memoization caches the code under test may have.
        $warm = min(500, max(10, (int) ($iterations / 10)));
        for ($i = 0; $i < $warm; $i++) {
            $fn();
        }

        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $fn();
        }
        $elapsed = (hrtime(true) - $start) / 1e9;

        $opsPerSec = $iterations / max($elapsed, 1e-9);
        $usPerOp = ($elapsed / max($iterations, 1)) * 1e6;

        $this->results[] = [
            'name' => $name,
            'iterations' => $iterations,
            'ops_per_sec' => $opsPerSec,
            'us_per_op' => $usPerOp,
        ];

        printf(
            "  %-52s %12s ops/s  %10.2f us/op\n",
            $name,
            number_format((int) $opsPerSec),
            $usPerOp,
        );
    }

    public function save(string $path, array $env): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $payload = [
            'generated_at' => date('c'),
            'env' => $env,
            'results' => $this->results,
        ];
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function summary(): void
    {
        echo "\n" . str_repeat('═', 82) . "\n";
        printf("%-52s %14s %10s\n", 'Operation', 'Throughput', 'Latency');
        echo str_repeat('─', 82) . "\n";
        foreach ($this->results as $r) {
            printf(
                "%-52s %10s ops/s  %8.2f us\n",
                $r['name'],
                number_format((int) $r['ops_per_sec']),
                $r['us_per_op'],
            );
        }
        echo str_repeat('═', 82) . "\n";
    }
}

// ── Setup ──────────────────────────────────────────────────────────
$N = (int) (getenv('ITER') ?: 5_000);
$bench = new Bench();

echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
echo "║              LSVID — Performance Benchmark (N={$N} per case)             ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════╝\n";
echo "  PHP " . PHP_VERSION . " | " . php_uname('s') . " " . php_uname('m') . "\n";
echo "  opcache: " . (function_exists('opcache_get_status') && opcache_get_status(false) ? 'on' : 'off') . "\n\n";

$gwReader = TestSvidReader::create('spiffe://zt.local/bench-gateway', 'zt.local');
$wkReader = $gwReader->deriveWorkload('spiffe://zt.local/bench-worker');
$dsReader = $gwReader->deriveWorkload('spiffe://zt.local/bench-downstream');

$gwSigner = new LSVIDSigner($gwReader, defaultTtlSeconds: 300);
$wkSigner = new LSVIDSigner($wkReader, defaultTtlSeconds: 300);
$dsSigner = new LSVIDSigner($dsReader, defaultTtlSeconds: 300);

// Pre-generated tokens for parse/validate cases where we don't want mint
// cost in the loop.
$fixedL0 = $gwSigner->createBase(audience: 'spiffe://zt.local/bench-worker');
$fixedL1 = $wkSigner->extend(priorRawToken: $fixedL0->raw, audience: 'spiffe://zt.local/bench-downstream');
$fixedL2 = $dsSigner->extend(priorRawToken: $fixedL1->raw, audience: 'spiffe://zt.local/bench-final');

// ── 1. Signing ────────────────────────────────────────────────────
echo "[Sign / Extend]\n";

$bench->run('LSVIDSigner::createBase() — L0 mint', $N, function () use ($gwSigner) {
    $gwSigner->createBase(audience: 'spiffe://zt.local/bench-worker');
});

$bench->run('LSVIDSigner::extend() — L0 → L1', $N, function () use ($wkSigner, $fixedL0) {
    $wkSigner->extend(priorRawToken: $fixedL0->raw, audience: 'spiffe://zt.local/bench-downstream');
});

$bench->run('LSVIDSigner::extend() — L1 → L2', $N, function () use ($dsSigner, $fixedL1) {
    $dsSigner->extend(priorRawToken: $fixedL1->raw, audience: 'spiffe://zt.local/bench-final');
});

// ── 2. Parsing (no crypto) ─────────────────────────────────────────
echo "\n[Parse (no crypto)]\n";

$bench->run('LSVID::parse() — L0', $N, function () use ($fixedL0) {
    LSVID::parse($fixedL0->raw);
});

$bench->run('LSVID::parse() — L1 (nested)', $N, function () use ($fixedL1) {
    LSVID::parse($fixedL1->raw);
});

$bench->run('LSVID::parse() — L2 (double nested)', $N, function () use ($fixedL2) {
    LSVID::parse($fixedL2->raw);
});

// ── 3. Validation (full crypto) ────────────────────────────────────
echo "\n[Validate — full crypto + CA + SAN + trust domain]\n";

// IMPORTANT: jtiCache is NOT shared across iterations for validate() runs
// because replay detection would reject every subsequent call. We build a
// fresh validator (with no cache) for pure verify-cost measurements, and
// separately measure jti cache ops below.
$validator = new LSVIDValidator(
    $wkReader,
    clockSkewSeconds: 30,
    jtiCache: null,
    trustDomain: 'zt.local',
    requireNbf: false,
    requireAudienceOnAllLevels: true,
);

$bench->run('LSVIDValidator::validate() — L0', $N, function () use ($validator, $fixedL0) {
    $validator->validate($fixedL0->raw);
});

$bench->run('LSVIDValidator::validate() — L0+L1', $N, function () use ($validator, $fixedL1) {
    $validator->validate($fixedL1->raw);
});

$bench->run('LSVIDValidator::validate() — L0+L1+L2', $N, function () use ($validator, $fixedL2) {
    $validator->validate($fixedL2->raw);
});

// ── 4. Trust-bundle cache (cold vs warm) ───────────────────────────
echo "\n[Trust bundle cache]\n";

// Cold cache: build a NEW validator each call to force trust bundle re-parse.
$bench->run('validate() — cold trust bundle (new validator)', min(500, $N), function () use ($wkReader, $fixedL0) {
    $v = new LSVIDValidator($wkReader, clockSkewSeconds: 30, trustDomain: 'zt.local');
    $v->validate($fixedL0->raw);
});

// Warm cache is just the same validator instance — we already measured it
// above as "LSVIDValidator::validate() — L0". Record a copy under a clearer
// label for the report.
$bench->run('validate() — warm trust bundle (same validator)', $N, function () use ($validator, $fixedL0) {
    $validator->validate($fixedL0->raw);
});

// ── 5. JTI replay cache ────────────────────────────────────────────
echo "\n[JtiReplayCache]\n";

$cache = new JtiReplayCache();
$freshJtis = [];
for ($i = 0; $i < $N; $i++) {
    $freshJtis[] = bin2hex(random_bytes(16));
}
$exp = time() + 3600;

$bench->run('JtiReplayCache::seenOrRecord() — fresh', $N, function () use ($cache, &$freshJtis, $exp) {
    static $i = 0;
    $cache->seenOrRecord($freshJtis[$i++ % count($freshJtis)], $exp);
});

$hitJti = $freshJtis[0];
$bench->run('JtiReplayCache::seenOrRecord() — replay hit', $N, function () use ($cache, $hitJti, $exp) {
    $cache->seenOrRecord($hitJti, $exp);
});

// ── Summary + JSON dump ────────────────────────────────────────────
$bench->summary();

$stamp = date('Ymd-His');
$outDir = dirname(__DIR__) . '/docs/data';
$outFile = "{$outDir}/lsvid-bench-{$stamp}.json";
$bench->save($outFile, [
    'php_version' => PHP_VERSION,
    'os' => php_uname('s') . ' ' . php_uname('m'),
    'iterations_per_case' => $N,
]);
copy($outFile, "{$outDir}/lsvid-bench-latest.json");

echo "\nWrote {$outFile}\n";
echo "Wrote {$outDir}/lsvid-bench-latest.json\n";
