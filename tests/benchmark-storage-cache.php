<?php
/**
 * Storage Footprint — Runtime / In-Memory Cache (Experiment 3, Dim 4)
 *
 * Measures the in-process memory cost of each identity layer's caching
 * strategy. Branch-aware: only measures classes that are autoloadable
 * on the current branch.
 *
 *   SPIFFE arm (feat/spiffe):
 *     - SpiffeTableReader instance + read primary X.509 SVID
 *     - LSVIDValidator (warmed: trust bundle parsed)
 *     - JtiReplayCache populated with N entries
 *
 *   Keycloak arm (feat/keycloak):
 *     - JwksCache populated from realm endpoint (or stub)
 *     - TokenCache populated with N tokens
 *     - JwtValidator instance
 *
 * Output:
 *   $CACHE_OUT (default: docs/data/storage-cache-{stamp}.json)
 *
 * Env:
 *   EXP_ARM     A | D | F     (informational, written into output)
 *   CACHE_OUT   path          (default: docs/data/storage-cache-{stamp}.json)
 *   N_ENTRIES   int           (default: 1000 — populate caches with N entries)
 */

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "ERROR: vendor/autoload.php not found — run composer install first\n");
    exit(1);
}
require $autoload;

$arm = getenv('EXP_ARM') ?: 'unknown';
$N   = (int) (getenv('N_ENTRIES') ?: 1000);
$out = getenv('CACHE_OUT') ?: (__DIR__ . '/../docs/data/storage-cache-' . date('Ymd-His') . '.json');

@mkdir(dirname($out), 0755, true);

$results = [];

/**
 * Measure memory delta around a builder closure. Forces gc_collect_cycles
 * before/after so the delta reflects the actual retained heap of the built
 * artifact, not transient peak.
 */
function measure(string $label, callable $build): array
{
    gc_collect_cycles();
    $before_real = memory_get_usage(true);
    $before_emalloc = memory_get_usage(false);
    $start = hrtime(true);

    $artifact = null;
    try {
        $artifact = $build();
        $error = null;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }

    $elapsed_us = (hrtime(true) - $start) / 1e3;
    gc_collect_cycles();

    $after_real = memory_get_usage(true);
    $after_emalloc = memory_get_usage(false);

    return [
        'label'                   => $label,
        'available'               => $error === null,
        'error'                   => $error,
        'mem_real_delta_bytes'    => $after_real - $before_real,
        'mem_emalloc_delta_bytes' => $after_emalloc - $before_emalloc,
        'mem_real_after_bytes'    => $after_real,
        'build_us'                => round($elapsed_us, 2),
        'serialized_bytes'        => $artifact !== null ? strlen(@serialize($artifact)) : 0,
    ];
}

echo "═══ Storage Cache Benchmark — arm=$arm N=$N ═══\n";

// ────────────────────────────────────────────────────────────────────────────
// SPIFFE arm probes (D)
// ────────────────────────────────────────────────────────────────────────────
$spiffe_available = class_exists(\Spiffe\SharedMemory\SpiffeTableReader::class);

$results['spiffe'] = [
    'classes_loadable' => $spiffe_available,
    'probes'           => [],
];

if ($spiffe_available) {
    // 1) SpiffeTableReader (SHM reader instance)
    $results['spiffe']['probes'][] = measure(
        'SpiffeTableReader::__construct + readX509Primary',
        function () {
            $base = getenv('SPIFFE_SHM_DIR') ?: '/tmp/spiffe-shared';
            $reader = new \Spiffe\SharedMemory\SpiffeTableReader($base);
            $svid = method_exists($reader, 'readX509Primary')
                ? @$reader->readX509Primary()
                : null;
            return ['reader' => $reader, 'svid' => $svid];
        }
    );

    // 2) LSVIDValidator with warm trust bundle (only if test reader package present)
    if (class_exists(\SDPMlab\LSVID\LSVIDValidator::class)
        && class_exists(\SDPMlab\LSVID\Tests\TestSvidReader::class)) {
        $results['spiffe']['probes'][] = measure(
            'LSVIDValidator (warm trust bundle)',
            function () {
                $reader = \SDPMlab\LSVID\Tests\TestSvidReader::create(
                    'spiffe://zt.local/cache-bench', 'zt.local'
                );
                return new \SDPMlab\LSVID\LSVIDValidator(
                    $reader,
                    clockSkewSeconds: 30,
                    jtiCache: null,
                    trustDomain: 'zt.local',
                );
            }
        );
    } else {
        $results['spiffe']['probes'][] = [
            'label'     => 'LSVIDValidator (warm trust bundle)',
            'available' => false,
            'error'     => 'LSVID test reader not autoloadable',
        ];
    }

    // 3) JtiReplayCache populated
    if (class_exists(\SDPMlab\LSVID\JtiReplayCache::class)) {
        $results['spiffe']['probes'][] = measure(
            "JtiReplayCache populated with $N entries",
            function () use ($N) {
                $cache = new \SDPMlab\LSVID\JtiReplayCache();
                $exp = time() + 3600;
                for ($i = 0; $i < $N; $i++) {
                    $cache->seenOrRecord(bin2hex(random_bytes(16)), $exp);
                }
                return $cache;
            }
        );
    }
}

// ────────────────────────────────────────────────────────────────────────────
// Keycloak arm probes (F) — only present on feat/keycloak
// ────────────────────────────────────────────────────────────────────────────
$keycloak_available = class_exists(\App\Keycloak\JwksCache::class)
    || class_exists(\Keycloak\JwksCache::class);

$results['keycloak'] = [
    'classes_loadable' => $keycloak_available,
    'probes'           => [],
];

if ($keycloak_available) {
    // Class namespace varies — try both common locations
    $jwksClass = class_exists(\App\Keycloak\JwksCache::class)
        ? \App\Keycloak\JwksCache::class
        : \Keycloak\JwksCache::class;
    $tokenClass = class_exists(\App\Keycloak\TokenCache::class)
        ? \App\Keycloak\TokenCache::class
        : (class_exists(\Keycloak\TokenCache::class) ? \Keycloak\TokenCache::class : null);

    // 1) JwksCache (constructed empty — real fetch is network-bound)
    $results['keycloak']['probes'][] = measure(
        'JwksCache::__construct (empty)',
        function () use ($jwksClass) { return new $jwksClass(); }
    );

    // 2) TokenCache populated
    if ($tokenClass !== null) {
        $results['keycloak']['probes'][] = measure(
            "TokenCache populated with $N entries",
            function () use ($tokenClass, $N) {
                $cache = new $tokenClass();
                for ($i = 0; $i < $N; $i++) {
                    // Best-effort generic populate; concrete API may differ
                    if (method_exists($cache, 'put')) {
                        $cache->put('aud-' . $i, 'eyJ' . str_repeat('x', 800), time() + 300);
                    } elseif (method_exists($cache, 'set')) {
                        $cache->set('aud-' . $i, 'eyJ' . str_repeat('x', 800));
                    }
                }
                return $cache;
            }
        );
    }
}

// ────────────────────────────────────────────────────────────────────────────
// Baseline arm probe (A) — empty harness
// ────────────────────────────────────────────────────────────────────────────
$results['baseline'] = [
    'note'     => 'A-baseline has no identity cache; reported as zero-cost reference',
    'mem_real_baseline_bytes' => memory_get_usage(true),
];

// ────────────────────────────────────────────────────────────────────────────
// Disk artifacts (SHM / JWKS cache files) — best-effort host filesystem scan
// ────────────────────────────────────────────────────────────────────────────
$disk = [];
$shm = getenv('SPIFFE_SHM_DIR') ?: '/tmp/spiffe-shared';
if (is_dir($shm)) {
    $disk['spiffe_shm'] = [];
    $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($shm));
    foreach ($rii as $f) {
        if ($f->isFile()) {
            $disk['spiffe_shm'][] = [
                'path'  => str_replace($shm . '/', '', $f->getPathname()),
                'bytes' => $f->getSize(),
            ];
        }
    }
}

$payload = [
    'arm'             => $arm,
    'php_version'     => PHP_VERSION,
    'os'              => php_uname('s') . ' ' . php_uname('m'),
    'generated_at'    => date('c'),
    'iterations'      => $N,
    'memory_real_baseline_bytes' => memory_get_usage(true),
    'memory_real_peak_bytes'     => memory_get_peak_usage(true),
    'results'         => $results,
    'disk_artifacts'  => $disk,
];

file_put_contents($out, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Summary stdout
foreach (['spiffe', 'keycloak'] as $bucket) {
    echo "\n[$bucket]" . ($results[$bucket]['classes_loadable'] ? '' : ' (classes not loadable on this branch)') . "\n";
    foreach (($results[$bucket]['probes'] ?? []) as $p) {
        if ($p['available'] ?? false) {
            printf("  %-50s real=%+d B  emalloc=%+d B  build=%.2f us\n",
                $p['label'],
                $p['mem_real_delta_bytes'] ?? 0,
                $p['mem_emalloc_delta_bytes'] ?? 0,
                $p['build_us'] ?? 0
            );
        } else {
            printf("  %-50s SKIPPED (%s)\n", $p['label'], $p['error'] ?? 'n/a');
        }
    }
}

echo "\nWrote $out\n";
