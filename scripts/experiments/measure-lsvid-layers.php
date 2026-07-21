<?php

declare(strict_types=1);

/**
 * ══════════════════════════════════════════════════════════════════════
 *  Unified LSVID per-layer micro-benchmark  (L0 / L1 / L2)
 * ══════════════════════════════════════════════════════════════════════
 *
 *  Rewrite of measure-nesting.php that removes the two ambiguities the
 *  thesis review flagged:
 *
 *    (1) Numbering.  The old script used a 1-indexed `level` (level=1 was
 *        really L0, level=2 was L1 …), which is why the text (L0–L2) and
 *        the tables/figures (L1–L4) disagreed.  This script emits the
 *        canonical layer name directly and NEVER goes past L2, matching
 *        the implementation described in Chapter 5.
 *
 *          L0 — base token minted by the Gateway   (createBase)
 *          L1 — token after the 1st service extend (extend)
 *          L2 — token after the 2nd service extend (extend)
 *
 *    (2) Metric conflation.  The old script reported an *incremental*
 *        extend against a *cumulative* verify without saying so.  This
 *        script measures four clearly-separated quantities per layer:
 *
 *          incremental_extend_us  只計算新增一層的簽章時間
 *                                 (createBase for L0; extend() for L1/L2)
 *          e2e_extend_us          收到前一層 → 解析+驗證+簽章+序列化
 *                                 (validate(prior) + extend();  = mint for L0)
 *          cumulative_verify_us   validate() 整條鏈 L0..Ln（遞迴至 L0）
 *          incremental_verify_us  只驗新增的一層 —— 由 analyzer 以聚合層級
 *                                 cumulative(Ln) − cumulative(L(n-1)) 導出，
 *                                 這裡輸出的是每筆的 cumulative，raw 保真。
 *
 *  Statistics (mean vs median) are NOT decided here — the raw NDJSON keeps
 *  every sample so analyze-lsvid-layers.py can report mean, median, p95,
 *  p99, sd side by side and the thesis can pick one consistently.
 *
 *  The benchmark is self-contained: it requires the php-lsvid sources of
 *  the *checked-out branch* directly (no vendor, no SPIRE, no network) and
 *  uses the package's own TestSvidReader to build an ES256 test PKI — the
 *  same crypto path as production (ES256 leaf in x5c).
 *
 *  Output: NDJSON, one record per (sample, layer):
 *    {"sample":0,"layer":"L0","layer_idx":0,
 *     "incremental_extend_us":141.2,"e2e_extend_us":141.2,
 *     "cumulative_verify_us":268.9,"bytes":812,"warm":true}
 *
 *  Usage (run on a host that has the branch checked out):
 *    php scripts/experiments/measure-lsvid-layers.php \
 *        --samples=2000 --warmup=200 [--cold] > raw.ndjson
 *
 *  Flags:
 *    --samples=N   measurement samples (default 2000)
 *    --warmup=N    warmup iterations, not emitted (default 200)
 *    --cold        rebuild Signer/Validator every sample so the signer
 *                  prep-cache + validator leaf/trust caches are always
 *                  cold (worst case). Default is warm = production steady
 *                  state (SVID stable for hours → caches hot).
 * ────────────────────────────────────────────────────────────────────── */

$pkgBase = __DIR__ . '/../../packages/php-lsvid';
if (!is_dir($pkgBase)) {
    fwrite(STDERR, "ERROR: php-lsvid package not found at {$pkgBase}\n");
    fwrite(STDERR, "Run this on a host with the branch checked out (packages/php-lsvid present).\n");
    exit(1);
}
foreach ([
    'src/LSVID/LSVIDException.php',
    'src/LSVID/SvidReader.php',
    'src/LSVID/LSVID.php',
    'src/LSVID/JtiReplayCache.php',
    'src/LSVID/LSVIDSigner.php',
    'src/LSVID/LSVIDValidator.php',
    'tests/LSVID/TestSvidReader.php',
] as $rel) {
    $f = $pkgBase . '/' . $rel;
    if (!is_file($f)) {
        fwrite(STDERR, "ERROR: missing php-lsvid source: {$rel}\n");
        exit(1);
    }
    require_once $f;
}

use SDPMlab\LSVID\LSVIDSigner;
use SDPMlab\LSVID\LSVIDValidator;
use SDPMlab\LSVID\Tests\TestSvidReader;

const GW_SPIFFE = 'spiffe://zt.local/php-gateway';
const WK_SPIFFE = 'spiffe://zt.local/php-worker';

$opts = getopt('', ['samples::', 'warmup::', 'cold', 'keycloak-bytes::']);
$samples       = max(1, (int) ($opts['samples'] ?? 2000));
$warmup        = max(0, (int) ($opts['warmup'] ?? 200));
$cold          = isset($opts['cold']);
// Synthetic Keycloak access_token length (default 1500 — a real Keycloak
// JWT with two realm roles + a client mapper). Placed in the envelope's
// authorization.jwt field, PARALLEL to (not nested inside) the LSVID.
$keycloakBytes = max(0, (int) ($opts['keycloak-bytes'] ?? 1500));

// Layers benchmarked, in canonical order. Implementation supports L0–L2.
const LAYERS = ['L0', 'L1', 'L2'];

/**
 * Build a production-shape gateway envelope carrying the outermost LSVID
 * token. When $withKeycloak is true, a synthetic Keycloak access_token is
 * injected at authorization.jwt — PARALLEL to the LSVID, not nested in it —
 * so the marginal Keycloak envelope cost can be measured against the LSVID.
 */
function buildEnvelope(string $rawToken, bool $withKeycloak, int $kBytes): string
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
            'productList' => [['p_key' => 1, 'amount' => 1]],
            'total'       => 100,
        ],
    ];
    if ($withKeycloak) {
        $envelope['authorization'] = [
            'jwt'       => substr(str_repeat(bin2hex(random_bytes(32)) . '.', 64), 0, $kBytes),
            'client_id' => 'zt-event-gateway',
        ];
    }
    return json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/**
 * Build the PKI + signer/validator triple. In warm mode this is called
 * once and reused (production steady state); in cold mode it is rebuilt
 * per sample so every crypto cache starts empty.
 *
 * @return array{0: LSVIDSigner, 1: LSVIDSigner, 2: LSVIDValidator}
 */
function makeStack(): array
{
    // Gateway mints L0; worker extends L0 → L1 → L2. Both share one CA so
    // the validator's trust bundle (== CA) can verify either leaf.
    $gwReader  = TestSvidReader::create(GW_SPIFFE, 'zt.local');
    $wkReader  = $gwReader->deriveWorkload(WK_SPIFFE);
    $gwSigner  = new LSVIDSigner($gwReader, defaultTtlSeconds: 300);
    $wkSigner  = new LSVIDSigner($wkReader, defaultTtlSeconds: 300);
    $validator = new LSVIDValidator($wkReader, clockSkewSeconds: 30);
    return [$gwSigner, $wkSigner, $validator];
}

/**
 * Run one sample. Emits one row per layer when $emit is true.
 *
 * Canonical chain (advances L0→L1→L2) is built with the *incremental*
 * extend; that same call time is `incremental_extend_us`. The `e2e`
 * measurement re-does validate(prior)+extend on a throwaway token so it
 * does not perturb the canonical chain.
 */
function runSample(int $idx, LSVIDSigner $gw, LSVIDSigner $wk, LSVIDValidator $val, bool $warm, bool $emit, int $kBytes): void
{
    $prior = null; // raw token of the previous (enclosing-nested) layer
    foreach (LAYERS as $li => $layer) {
        // ── incremental extend: cost of adding exactly this one layer ──
        $t0 = hrtime(true);
        if ($li === 0) {
            $tok = $gw->createBase(
                audience: WK_SPIFFE,
                extraClaims: ['route' => 'OrderCreateRequestedEvent'],
            );
        } else {
            $tok = $wk->extend(priorRawToken: $prior, audience: WK_SPIFFE);
        }
        $incExtendUs = (hrtime(true) - $t0) / 1000.0;
        $raw = $tok->raw;

        // ── cumulative verify: validate the whole chain L0..Ln ──
        $t0 = hrtime(true);
        $val->validate($raw, expectedAudience: WK_SPIFFE);
        $cumVerifyUs = (hrtime(true) - $t0) / 1000.0;

        // ── end-to-end extend: what a hop pays to RECEIVE prior and
        //    PRODUCE this layer (parse + verify prior + sign + serialize).
        //    L0 has no inbound token, so e2e == incremental (pure mint). ──
        if ($li === 0) {
            $e2eExtendUs = $incExtendUs;
        } else {
            $t0 = hrtime(true);
            $val->validate($prior, expectedAudience: WK_SPIFFE);
            $wk->extend(priorRawToken: $prior, audience: WK_SPIFFE);
            $e2eExtendUs = (hrtime(true) - $t0) / 1000.0;
        }

        if ($emit) {
            echo json_encode([
                'sample'                => $idx,
                'layer'                 => $layer,
                'layer_idx'             => $li,
                'incremental_extend_us' => round($incExtendUs, 4),
                'e2e_extend_us'         => round($e2eExtendUs, 4),
                'cumulative_verify_us'  => round($cumVerifyUs, 4),
                'bytes'                 => strlen($raw),
                'envelope_bytes_nokc'   => strlen(buildEnvelope($raw, false, $kBytes)),
                'envelope_bytes_kc'     => strlen(buildEnvelope($raw, true, $kBytes)),
                'warm'                  => $warm,
            ], JSON_UNESCAPED_SLASHES), "\n";
        }

        $prior = $raw;
    }
}

// Warm-mode: one stack reused so signer/validator caches are hot.
[$gw, $wk, $val] = makeStack();

for ($w = 0; $w < $warmup; $w++) {
    if ($cold) {
        [$gw, $wk, $val] = makeStack();
    }
    runSample($w, $gw, $wk, $val, !$cold, false, $keycloakBytes);
}

for ($s = 0; $s < $samples; $s++) {
    if ($cold) {
        [$gw, $wk, $val] = makeStack();
    }
    runSample($s, $gw, $wk, $val, !$cold, true, $keycloakBytes);
}
