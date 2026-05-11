<?php

declare(strict_types=1);

/**
 * Nested LSVID benchmark — extension/validate latency + token byte size
 * across nesting levels 1..N, for paper-comparison purposes.
 *
 * Output is NDJSON, one record per (sample, level):
 *
 *   {"sample":0,"level":1,"extend_us":76.5,"validate_us":108.2,"bytes":1024,
 *    "envelope_bytes":1180,"with_keycloak_claim":false}
 *
 * Where:
 *   level=1  → createBase()             (a fresh L0)
 *   level=2  → extend(prior=L1)         (= L1 in repo naming)
 *   level=N  → extend(prior=L(N-1))     (cumulative)
 *
 * validate_us measures LSVIDValidator::validate() on the *outermost*
 * token at that level, so it grows roughly linearly with the chain.
 *
 * Usage:
 *   php scripts/experiments/measure-nesting.php \
 *       --samples=200 --max-level=4 --warmup=20 \
 *       [--with-keycloak-claim] [--keycloak-bytes=1500]
 *
 * Flags:
 *   --samples=N             measurement samples (default 200)
 *   --warmup=N              warmup iterations before timing (default 20)
 *   --max-level=N           highest nesting level to measure (default 4)
 *   --with-keycloak-claim   include a fake authorization.jwt blob in the
 *                           envelope to show the marginal Keycloak cost
 *   --keycloak-bytes=N      synthetic Keycloak access-token length
 *                           (default 1500 — roughly a real Keycloak JWT
 *                           with two realm roles + client mapper)
 *   --product-count=K       items in productList (default 1)
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/php-lsvid/tests/LSVID/TestSvidReader.php';

use SDPMlab\LSVID\LSVID;
use SDPMlab\LSVID\LSVIDSigner;
use SDPMlab\LSVID\LSVIDValidator;
use SDPMlab\LSVID\Tests\TestSvidReader;

const GW_SPIFFE = 'spiffe://zt.local/php-gateway';
const WK_SPIFFE = 'spiffe://zt.local/php-worker';
const DOWNSTREAM_SPIFFE = 'spiffe://zt.local/order-service';

$opts = getopt('', [
    'samples::',
    'warmup::',
    'max-level::',
    'with-keycloak-claim',
    'mode::',
    'keycloak-bytes::',
    'product-count::',
]);
$samples       = max(1, (int) ($opts['samples'] ?? 200));
$warmup        = max(0, (int) ($opts['warmup'] ?? 20));
$maxLevel      = max(1, (int) ($opts['max-level'] ?? 4));
$mode          = $opts['mode'] ?? (isset($opts['with-keycloak-claim']) ? 'kc' : 'nokc');
$keycloakBytes = max(0, (int) ($opts['keycloak-bytes'] ?? 1500));
$productCount  = max(1, (int) ($opts['product-count'] ?? 1));

if (!in_array($mode, ['nokc', 'kc', 'both'], true)) {
    fwrite(STDERR, "ERROR: --mode must be one of nokc|kc|both\n");
    exit(1);
}

/** ──────────────────────────────────────────────────────────────────────
 * Test PKI — same algorithm path as production (ES256 / leaf cert in x5c).
 *
 * Two distinct workloads share a CA so the validator can verify any leaf
 * issued under it:
 *   - gateway signer  (mints L0)
 *   - worker  signer  (extends L0 → L1 → L2 → L3 → …)
 *
 * The validator uses the worker reader (its trust bundle == CA), and we
 * call `validate(audience = WK_SPIFFE)` to sidestep audience mismatch
 * across nesting levels: every level audiences "worker" except the final
 * downstream call, which the paper-comparison numbers don't include.
 * ──────────────────────────────────────────────────────────────────── */
$gwReader  = TestSvidReader::create(GW_SPIFFE, 'zt.local');
$wkReader  = $gwReader->deriveWorkload(WK_SPIFFE);
$gwSigner  = new LSVIDSigner($gwReader, defaultTtlSeconds: 300);
$wkSigner  = new LSVIDSigner($wkReader, defaultTtlSeconds: 300);
$validator = new LSVIDValidator($wkReader, clockSkewSeconds: 30);

/**
 * Build a production-shape envelope with a single LSVID token at the
 * outermost position.  Optionally injects a synthetic Keycloak access
 * token in `authorization.jwt` so the marginal envelope cost of the
 * Keycloak layer can be compared against the LSVID cost.
 */
function buildEnvelope(string $rawToken, int $productCount, bool $withKeycloak, int $kBytes): string
{
    $envelope = [
        'schema_version' => 1,
        'type'           => 'gateway.request',
        'route'          => 'OrderCreateRequestedEvent',
        'id'             => 'txn_' . bin2hex(random_bytes(8)),
        'spiffe_id'      => GW_SPIFFE,
        'spiffe_path'    => [GW_SPIFFE, WK_SPIFFE],
        'lsvid'          => $rawToken,
        'data'           => [
            'userKey'     => '1',
            'productList' => array_map(
                fn (int $i): array => ['p_key' => $i + 1, 'amount' => mt_rand(1, 5)],
                range(0, $productCount - 1),
            ),
            'total'       => mt_rand(100, 9999),
        ],
    ];
    if ($withKeycloak) {
        // A real Keycloak access_token is a JWT (header.payload.signature)
        // ~1.0–2.0 kB depending on realm roles + audience mappers. We use
        // base64url-ish padding to keep the synthetic token wire-realistic.
        $envelope['authorization'] = [
            'jwt'       => substr(str_repeat(bin2hex(random_bytes(32)) . '.', 64), 0, $kBytes),
            'client_id' => 'zt-event-gateway',
        ];
    }
    return json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function emit(array $row): void
{
    echo json_encode($row, JSON_UNESCAPED_SLASHES), "\n";
}

/** One sample = mint level-1, then extend → 2 → 3 → … → maxLevel.
 *  Each level records its own extension time, validation time, token bytes.
 *  If `$modesToEmit` has two entries, the same LSVID chain emits one row
 *  per mode — so extend_us / validate_us / bytes are identical and only
 *  envelope_bytes differs.
 */
function runSample(
    int $sampleIdx,
    int $maxLevel,
    LSVIDSigner $gwSigner,
    LSVIDSigner $wkSigner,
    LSVIDValidator $validator,
    int $productCount,
    array $modesToEmit,
    int $kBytes,
    bool $emitOutput,
): void {
    $prior = null;
    for ($lvl = 1; $lvl <= $maxLevel; $lvl++) {
        $t0 = hrtime(true);
        if ($lvl === 1) {
            $next = $gwSigner->createBase(
                audience: WK_SPIFFE,
                extraClaims: [
                    'traceId' => 'txn_' . bin2hex(random_bytes(8)),
                    'route'   => 'OrderCreateRequestedEvent',
                    'level'   => 'L0',
                ],
            );
        } else {
            $next = $wkSigner->extend(
                priorRawToken: $prior,
                audience: WK_SPIFFE,
                extraClaims: ['level' => 'L' . ($lvl - 1)],
            );
        }
        $extendNs = hrtime(true) - $t0;
        $prior = $next->raw;

        // Validate (cumulative — verifies full chain up to this level).
        $t0 = hrtime(true);
        $validator->validate($prior, expectedAudience: WK_SPIFFE);
        $validateNs = hrtime(true) - $t0;

        if (!$emitOutput) {
            continue;
        }
        foreach ($modesToEmit as $withKc) {
            emit([
                'sample'                => $sampleIdx,
                'level'                 => $lvl,
                'extend_us'             => $extendNs / 1000,
                'validate_us'           => $validateNs / 1000,
                'bytes'                 => strlen($prior),
                'envelope_bytes'        => strlen(buildEnvelope($prior, $productCount, $withKc, $kBytes)),
                'with_keycloak_claim'   => $withKc,
            ]);
        }
    }
}

// In `both` mode the same sample produces two NDJSON lines per level —
// identical extend_us / validate_us / bytes (LSVID is the same), only
// envelope_bytes differs (because the Keycloak access_token is in the
// envelope, parallel to LSVID, not nested inside it).
$modesToEmit = $mode === 'both' ? [false, true] : [$mode === 'kc'];

// ── Warmup (don't emit) — primes openssl, autoload, JIT-warm path. ────
for ($w = 0; $w < $warmup; $w++) {
    runSample($w, $maxLevel, $gwSigner, $wkSigner, $validator, $productCount, $modesToEmit, $keycloakBytes, false);
}

// ── Measurement ────────────────────────────────────────────────────────
for ($s = 0; $s < $samples; $s++) {
    runSample($s, $maxLevel, $gwSigner, $wkSigner, $validator, $productCount, $modesToEmit, $keycloakBytes, true);
}
