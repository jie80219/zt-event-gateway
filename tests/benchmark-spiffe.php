<?php
/**
 * SPIFFE PHP SDK — Performance Benchmark Suite
 *
 * Measures throughput and latency of core operations that run
 * on every request in a zero-trust gateway:
 *
 *   1. SpiffeId parsing & validation
 *   2. TrustDomain resolution
 *   3. AuthorizationPolicy evaluation
 *   4. SharedMemory seqlock read (cross-process credential access)
 *   5. TlsCredential file materialization
 *   6. HTTP/2 frame encoding (gRPC framing overhead)
 *   7. HPACK header encoding/decoding
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Spiffe\SpiffeId;
use Spiffe\TrustDomain;
use Spiffe\TLS\AuthorizationPolicy;
use Spiffe\TLS\TlsCredential;
use Spiffe\Runtime\Http2Frame;
use Spiffe\SharedMemory\SpiffeTableSchema;
use Spiffe\SharedMemory\SpiffeTableStore;
use Spiffe\SharedMemory\SpiffeTableReader;

final class Benchmark
{
    private array $results = [];

    public function run(string $name, int $iterations, callable $fn): void
    {
        // Warmup
        for ($i = 0; $i < min(100, $iterations); $i++) {
            $fn();
        }

        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $fn();
        }
        $elapsed = (hrtime(true) - $start) / 1e9; // seconds

        $opsPerSec = $iterations / $elapsed;
        $usPerOp = ($elapsed / $iterations) * 1e6;

        $this->results[] = [
            'name' => $name,
            'iterations' => $iterations,
            'elapsed' => $elapsed,
            'ops_per_sec' => $opsPerSec,
            'us_per_op' => $usPerOp,
        ];

        printf("  %-45s %10s ops/s  %8.2f us/op\n",
            $name,
            number_format((int) $opsPerSec),
            $usPerOp,
        );
    }

    public function summary(): void
    {
        echo "\n" . str_repeat('=', 72) . "\n";
        printf("%-45s %12s %10s\n", 'Operation', 'Throughput', 'Latency');
        echo str_repeat('-', 72) . "\n";

        foreach ($this->results as $r) {
            printf("%-45s %10s/s %8.2f us\n",
                $r['name'],
                number_format((int) $r['ops_per_sec']),
                $r['us_per_op'],
            );
        }
        echo str_repeat('=', 72) . "\n";
    }
}

// ── Setup ────────────────────────────────────────────────────────────

$bench = new Benchmark();

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║          SPIFFE PHP SDK — Performance Benchmark                 ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
echo "  PHP " . PHP_VERSION . " | " . php_uname('s') . " | " . php_uname('m') . "\n\n";

$N = 100_000;

// ── 1. SpiffeId Parsing ──────────────────────────────────────────────

echo "[SpiffeId]\n";

$bench->run('SpiffeId::parse() — simple path', $N, function () {
    SpiffeId::parse('spiffe://zt.local/gateway');
});

$bench->run('SpiffeId::parse() — deep path', $N, function () {
    SpiffeId::parse('spiffe://production.example.com/ns/default/sa/order-service');
});

$bench->run('SpiffeId::equals()', $N, function () {
    static $a, $b;
    $a ??= SpiffeId::parse('spiffe://zt.local/gateway');
    $b ??= SpiffeId::parse('spiffe://zt.local/gateway');
    $a->equals($b);
});

$bench->run('SpiffeId::memberOf()', $N, function () {
    static $id, $td;
    $id ??= SpiffeId::parse('spiffe://zt.local/gateway');
    $td ??= TrustDomain::parse('zt.local');
    $id->memberOf($td);
});

echo "\n[TrustDomain]\n";

$bench->run('TrustDomain::parse() — plain', $N, function () {
    TrustDomain::parse('zt.local');
});

$bench->run('TrustDomain::parse() — from URI', $N, function () {
    TrustDomain::parse('spiffe://zt.local/some/path');
});

// ── 2. Authorization Policy ──────────────────────────────────────────

echo "\n[AuthorizationPolicy]\n";

$simplePolicy = AuthorizationPolicy::create()
    ->allowTrustDomain('zt.local')
    ->denyId('spiffe://zt.local/blocked');

$complexPolicy = AuthorizationPolicy::create()
    ->allowTrustDomains(['zt.local', 'partner.example.com', 'staging.internal'])
    ->allowPathPrefix('/services/')
    ->allowPathPattern('#^/workers/order-\d+$#')
    ->denyIds(['spiffe://zt.local/blocked', 'spiffe://zt.local/revoked'])
    ->denyTrustDomain('evil.domain');

$allowedId = SpiffeId::parse('spiffe://zt.local/gateway');
$deniedId = SpiffeId::parse('spiffe://zt.local/blocked');
$foreignId = SpiffeId::parse('spiffe://evil.domain/attacker');
$prefixId = SpiffeId::parse('spiffe://zt.local/services/order');

$bench->run('Simple policy — allow (trust domain)', $N, function () use ($simplePolicy, $allowedId) {
    $simplePolicy->allows($allowedId);
});

$bench->run('Simple policy — deny (exact ID)', $N, function () use ($simplePolicy, $deniedId) {
    $simplePolicy->allows($deniedId);
});

$bench->run('Complex policy — allow (path prefix)', $N, function () use ($complexPolicy, $prefixId) {
    $complexPolicy->allows($prefixId);
});

$bench->run('Complex policy — deny (foreign domain)', $N, function () use ($complexPolicy, $foreignId) {
    $complexPolicy->allows($foreignId);
});

$bench->run('Policy evaluate() with reason', $N, function () use ($complexPolicy, $prefixId) {
    $complexPolicy->evaluate($prefixId);
});

// ── 3. SharedMemory (file-based seqlock) ─────────────────────────────

echo "\n[SharedMemory — file-based seqlock]\n";

$tmpDir = sys_get_temp_dir() . '/spiffe-bench-' . getmypid();
SpiffeTableSchema::createAll($tmpDir);

// Pre-populate data
$store = new SpiffeTableStore($tmpDir);
SpiffeTableSchema::atomicWrite(
    "$tmpDir/x509/0.json",
    json_encode([
        'spiffe_id' => 'spiffe://zt.local/gateway',
        'trust_domain' => 'zt.local',
        'cert_pem' => str_repeat('A', 2048),
        'key_pem' => str_repeat('B', 1024),
        'bundle_pem' => str_repeat('C', 2048),
        'hint' => 'internal',
        'updated_at' => time(),
    ]),
);
SpiffeTableSchema::atomicWrite(
    "$tmpDir/meta.json",
    json_encode([
        'version' => 42, 'x509_state' => 'ready', 'jwt_state' => 'ready',
        'x509_count' => 1, 'jwt_count' => 0, 'updated_at' => time(), 'error' => '',
    ]),
);

$reader = new SpiffeTableReader($tmpDir);

$bench->run('SharedMemory readX509Primary()', $N, function () use ($reader) {
    $reader->readX509Primary();
});

$bench->run('SharedMemory readMeta()', $N, function () use ($reader) {
    $reader->readMeta();
});

$bench->run('SharedMemory isReady()', $N, function () use ($reader) {
    $reader->isReady();
});

$bench->run('SharedMemory version()', $N, function () use ($reader) {
    $reader->version();
});

SpiffeTableSchema::cleanup($tmpDir);

// ── 4. TlsCredential ────────────────────────────────────────────────

echo "\n[TlsCredential]\n";

$bench->run('TlsCredential construction', $N, function () {
    new TlsCredential('spiffe://zt.local/gw', 'zt.local', 'cert', 'key', 'ca', 0);
});

// File materialization is I/O — use fewer iterations
$bench->run('TlsCredential materializeFiles()', 1000, function () {
    $cred = new TlsCredential('id', 'td', 'cert-data', 'key-data', 'ca-data', 0);
    $cred->materializeFiles();
    $cred->cleanup();
});

// ── 5. HTTP/2 Frame Encoding ─────────────────────────────────────────

echo "\n[HTTP/2 Frame Encoding (gRPC transport)]\n";

$bench->run('Http2Frame::grpcHeaders()', $N, function () {
    Http2Frame::grpcHeaders('/spiffe.workload.SpiffeWorkloadAPI/FetchX509SVID', 1);
});

$bench->run('Http2Frame::grpcData() — 1KB payload', $N, function () {
    static $payload;
    $payload ??= pack('CN', 0, 1024) . str_repeat('x', 1024);
    Http2Frame::grpcData($payload, 1);
});

$bench->run('Http2Frame::encode() DATA frame', $N, function () {
    Http2Frame::encode(Http2Frame::DATA, Http2Frame::FLAG_END_STREAM, 1, 'hello world');
});

$bench->run('Http2Frame::decodeHeader()', $N, function () {
    static $frame;
    $frame ??= Http2Frame::encode(Http2Frame::DATA, 0, 1, 'test');
    Http2Frame::decodeHeader(substr($frame, 0, 9));
});

$bench->run('Http2Frame::hpackEncode() + hpackDecode()', $N, function () {
    $encoded = Http2Frame::hpackEncode('content-type', 'application/grpc')
        . Http2Frame::hpackEncode('te', 'trailers');
    Http2Frame::hpackDecode($encoded);
});

// ── Summary ──────────────────────────────────────────────────────────

$bench->summary();
