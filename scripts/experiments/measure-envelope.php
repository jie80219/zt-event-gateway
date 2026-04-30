<?php

declare(strict_types=1);

/**
 * Standalone byte-size measurement for the canonical request envelope
 * with and without LSVID L0/L1/L2 nesting.
 *
 * The script does NOT need RabbitMQ or SPIRE — it builds the same
 * envelope shape that {@see CanonicalOrderRequest} emits and signs LSVID
 * tokens against the package's TestSvidReader (in-memory CA + leaf, same
 * algorithm path as production).
 *
 * Output (one JSON object per line, NDJSON) so the bash driver can pipe
 * it into a percentile calculator without parsing the whole stream:
 *
 *   {"layer":"envelope_no_lsvid","bytes":412}
 *   {"layer":"envelope_l0","bytes":1387}
 *   {"layer":"lsvid_l0","bytes":975}
 *   ...
 *
 * Usage:
 *   php scripts/experiments/measure-envelope.php --samples=50 --variant=both
 *
 *   --samples=N   number of envelope/token pairs to mint per variant
 *   --variant=    "off" (LSVID disabled), "on" (LSVID enabled, L0/L1/L2),
 *                 or "both" (default)
 *   --product-count=K  number of items in the productList (default 1)
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/php-lsvid/tests/LSVID/TestSvidReader.php';

use SDPMlab\LSVID\LSVIDSigner;
use SDPMlab\LSVID\Tests\TestSvidReader;

const GW_SPIFFE = 'spiffe://zt.local/php-gateway';
const WK_SPIFFE = 'spiffe://zt.local/php-worker';
const DOWNSTREAM_SPIFFE = 'spiffe://zt.local/order-service';

$opts = getopt('', ['samples::', 'variant::', 'product-count::']);
$samples = (int) ($opts['samples'] ?? 50);
$variant = $opts['variant'] ?? 'both';
$productCount = max(1, (int) ($opts['product-count'] ?? 1));

if (!in_array($variant, ['off', 'on', 'both'], true)) {
    fwrite(STDERR, "ERROR: --variant must be off|on|both\n");
    exit(1);
}

function buildProductList(int $count): array
{
    $list = [];
    for ($i = 0; $i < $count; $i++) {
        $list[] = ['p_key' => $i + 1, 'amount' => mt_rand(1, 5)];
    }
    return $list;
}

function makeEnvelopeOff(int $productCount): string
{
    $envelope = [
        'schema_version' => 1,
        'type' => 'gateway.request',
        'route' => 'OrderCreateRequestedEvent',
        'id' => 'txn_' . bin2hex(random_bytes(8)),
        'spiffe_id' => GW_SPIFFE,
        'spiffe_path' => [GW_SPIFFE],
        'data' => [
            'userKey' => '1',
            'productList' => buildProductList($productCount),
            'total' => mt_rand(100, 9999),
        ],
    ];
    return json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function makeEnvelopeWith(string $lsvid, int $productCount): string
{
    $envelope = [
        'schema_version' => 1,
        'type' => 'gateway.request',
        'route' => 'OrderCreateRequestedEvent',
        'id' => 'txn_' . bin2hex(random_bytes(8)),
        'spiffe_id' => GW_SPIFFE,
        'spiffe_path' => [GW_SPIFFE],
        'lsvid' => $lsvid,
        'data' => [
            'userKey' => '1',
            'productList' => buildProductList($productCount),
            'total' => mt_rand(100, 9999),
        ],
    ];
    return json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function emit(string $layer, int $bytes): void
{
    echo json_encode(['layer' => $layer, 'bytes' => $bytes], JSON_THROW_ON_ERROR), "\n";
}

// ── LSVID off ─────────────────────────────────────────────────────────────
if ($variant === 'off' || $variant === 'both') {
    for ($i = 0; $i < $samples; $i++) {
        emit('envelope_no_lsvid', strlen(makeEnvelopeOff($productCount)));
    }
}

// ── LSVID on (L0 / L1 / L2) ───────────────────────────────────────────────
if ($variant === 'on' || $variant === 'both') {
    // Build a fresh test PKI for each invocation so token jti claims are
    // unique. The CA + leaf live entirely in memory.
    $gwReader = TestSvidReader::create(GW_SPIFFE, 'zt.local');
    $wkReader = $gwReader->deriveWorkload(WK_SPIFFE);
    $gwSigner = new LSVIDSigner($gwReader, defaultTtlSeconds: 300);
    $wkSigner = new LSVIDSigner($wkReader, defaultTtlSeconds: 300);

    for ($i = 0; $i < $samples; $i++) {
        // L0: gateway → worker (audience = worker).
        $l0 = $gwSigner->createBase(
            audience: WK_SPIFFE,
            extraClaims: ['traceId' => 'txn_' . bin2hex(random_bytes(8)), 'route' => 'OrderCreateRequestedEvent', 'level' => 'L0'],
        );
        emit('lsvid_l0', strlen($l0->raw));
        emit('envelope_l0', strlen(makeEnvelopeWith($l0->raw, $productCount)));

        // L1: worker re-extends to itself (consumer→consumer hand-off).
        $l1 = $wkSigner->extend(
            priorRawToken: $l0->raw,
            audience: WK_SPIFFE,
            extraClaims: ['level' => 'L1'],
        );
        emit('lsvid_l1', strlen($l1->raw));
        emit('envelope_l1', strlen(makeEnvelopeWith($l1->raw, $productCount)));

        // L2: worker → downstream service (audience = downstream).
        $l2 = $wkSigner->extend(
            priorRawToken: $l1->raw,
            audience: DOWNSTREAM_SPIFFE,
            extraClaims: ['level' => 'L2'],
        );
        emit('lsvid_l2', strlen($l2->raw));
        // L2 is sent in an HTTP X-LSVID header to downstream services,
        // not in an AMQP envelope. Approximate the outbound request
        // size as: serialized headers + minimal JSON body.
        $approxOutbound = "POST /api/v1/order HTTP/1.1\r\n"
            . "Host: order-service:8082\r\n"
            . "Content-Type: application/json\r\n"
            . "Accept: application/json\r\n"
            . "X-LSVID: {$l2->raw}\r\n"
            . "Content-Length: 64\r\n"
            . "\r\n"
            . '{"o_key":"' . bin2hex(random_bytes(12)) . '","product_detail":[]}';
        emit('downstream_http_l2', strlen($approxOutbound));
    }
}
