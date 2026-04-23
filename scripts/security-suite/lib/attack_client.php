<?php

declare(strict_types=1);

// Route any runtime warnings (e.g. duplicate Swow module load from CLI)
// to stderr so they don't corrupt the JSON payload on stdout.
ini_set('display_errors', 'stderr');

/**
 * Attack payload builder — generates forged / tampered LSVID + envelope
 * for the security-suite stages (stage2-e2e-http, stage3-amqp-inject).
 *
 * Usage (CLI):
 *   php attack_client.php <case_id> [--json]
 *
 * Output JSON shape (stdout):
 *   {
 *     "case_id":       "F01",
 *     "category":      "token-forgery",
 *     "description":   "...",
 *     "layer_expected":"LSVIDValidator",
 *     "envelope":      { ...canonical envelope with tampered fields... },
 *     "lsvid_raw":     "eyJ...",
 *     "extra":         { ...debug info... }
 *   }
 *
 * All attacks are fully synthetic — never uses production SPIRE CA.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
// TestSvidReader lives under the lsvid package's autoload-dev namespace, which
// the project-level vendor/autoload.php does not register. Require it directly.
require_once __DIR__ . '/../../../packages/php-lsvid/tests/LSVID/TestSvidReader.php';

use SDPMlab\LSVID\LSVIDSigner;
use SDPMlab\LSVID\Tests\TestSvidReader;

// ── Fixed seed — keeps payload_sha256 stable across re-runs of same suite ────
srand(20260422);

const TD_REAL    = 'zt.local';
const TD_FOREIGN = 'evil.local';
const GW_ID      = 'spiffe://zt.local/gateway';
const WK_ID      = 'spiffe://zt.local/worker';
const DS_ID      = 'spiffe://zt.local/downstream';
const FOREIGN_ID = 'spiffe://evil.local/attacker';

/**
 * Build a canonical envelope matching CanonicalOrderRequest::validateEnvelope().
 *
 * @param array<string,mixed> $overrides claim-level overrides (merged in)
 * @return array<string,mixed>
 */
function buildEnvelope(?string $lsvid = null, array $overrides = []): array
{
    $env = [
        'schema_version' => 1,
        'type'           => 'gateway.request',
        'route'          => 'order.create',
        'id'             => 'atk-' . bin2hex(random_bytes(6)),
        'spiffe_id'      => GW_ID,
        'spiffe_path'    => [GW_ID],
        'data' => [
            'userKey'     => '1',
            'productList' => [['p_key' => 1, 'amount' => 1]],
            'total'       => 100,
        ],
    ];
    if ($lsvid !== null) {
        $env['lsvid'] = $lsvid;
    }
    foreach ($overrides as $k => $v) {
        $env[$k] = $v;
    }
    return $env;
}

/** Tamper a single base64url segment of a JWT-like token. */
function tamperJwtSegment(string $raw, int $segmentIdx, callable $transform): string
{
    $parts = explode('.', $raw);
    if (!isset($parts[$segmentIdx])) {
        throw new RuntimeException("no segment {$segmentIdx} in token");
    }
    $decoded = base64url_decode($parts[$segmentIdx]);
    $tampered = $transform($decoded);
    $parts[$segmentIdx] = base64url_encode($tampered);
    return implode('.', $parts);
}

function base64url_encode(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function base64url_decode(string $s): string
{
    $pad = 4 - (strlen($s) % 4);
    if ($pad < 4) {
        $s .= str_repeat('=', $pad);
    }
    return base64_decode(strtr($s, '-_', '+/'));
}

/**
 * Singletons: within the legitimate trust domain (zt.local) we need every
 * call to share a single CA so a downstream validator can verify tokens
 * regardless of which workload identity minted them. The *foreign* domain
 * gets its own CA so T01 tokens genuinely fail CA verification.
 */
function legalReader(string $spiffeId): TestSvidReader
{
    static $base = null;
    if ($base === null) {
        $base = TestSvidReader::create(GW_ID, TD_REAL);
    }
    if ($spiffeId === $base->spiffeId()) {
        return $base;
    }
    return $base->deriveWorkload($spiffeId);
}

function foreignReader(string $spiffeId = FOREIGN_ID): TestSvidReader
{
    static $base = null;
    if ($base === null) {
        $base = TestSvidReader::create(FOREIGN_ID, TD_FOREIGN);
    }
    if ($spiffeId === $base->spiffeId()) {
        return $base;
    }
    return $base->deriveWorkload($spiffeId);
}

/** Factory: a legal L0 token. Subject/audience are SPIFFE IDs under zt.local. */
function mintBaseLegal(string $trustDomain = TD_REAL, string $subject = GW_ID, string $audience = WK_ID): string
{
    $reader = $trustDomain === TD_REAL ? legalReader($subject) : foreignReader($subject);
    $signer = new LSVIDSigner($reader, defaultTtlSeconds: 300);
    return $signer->createBase($audience)->raw;
}

/** Factory: mint L0 under a different trust domain (foreign CA). */
function mintBaseForeign(): string
{
    $reader = foreignReader(FOREIGN_ID);
    $signer = new LSVIDSigner($reader, defaultTtlSeconds: 300);
    return $signer->createBase(WK_ID)->raw;
}

/** Factory: mint an already-expired L0 (using TTL hacking). */
function mintExpired(): string
{
    $reader = legalReader(GW_ID);
    $signer = new LSVIDSigner($reader, defaultTtlSeconds: 1);
    $raw = $signer->createBase(WK_ID)->raw;
    // Sleep > validator skew (10s) + small margin so the comparison exp + skew < now is strict.
    sleep(13);
    return $raw;
}

/** Factory: mint a future-dated token (nbf in future). */
function mintFutureNbf(): string
{
    $reader = legalReader(GW_ID);
    $signer = new LSVIDSigner($reader, defaultTtlSeconds: 600);
    $future = time() + 3600;
    return $signer->createBase(WK_ID, notBefore: $future)->raw;
}

/** Factory: mint L0+L1 then replace L0.aud so chain breaks (nested.aud != enclosing.iss). */
function mintBrokenChain(): string
{
    $gwReader = legalReader(GW_ID);
    $wkReader = legalReader(WK_ID);
    $gwSigner = new LSVIDSigner($gwReader, defaultTtlSeconds: 300);
    $wkSigner = new LSVIDSigner($wkReader, defaultTtlSeconds: 300);

    // Mint a legal L0 aimed at an unexpected audience, then extend.
    // Validator will see nested.aud != enclosing.iss.
    $l0 = $gwSigner->createBase('spiffe://zt.local/wrong-hop')->raw;
    return $wkSigner->extend($l0, WK_ID)->raw;
}

/**
 * Factory: build a legal N-level nested chain, all signed by the same legit CA.
 *
 * Depth=3 → 4 levels: L0 (gw) → L1 (hop-1) → L2 (hop-2) → L3 (hop-3 → final).
 * Every extension's reader identity equals the prior level's audience, so the
 * chain-continuity check (nested.aud == enclosing.iss) passes for every level.
 *
 * @param int $depth number of extensions above L0 (L0+depth levels total)
 */
function mintLegalDeepChain(int $depth, string $finalAudience = DS_ID): string
{
    if ($depth < 1) {
        throw new InvalidArgumentException("depth must be >= 1, got {$depth}");
    }

    // L0
    $hop0 = 'spiffe://zt.local/hop-0';
    $l0Signer = new LSVIDSigner(legalReader(GW_ID), defaultTtlSeconds: 300);
    $raw = $l0Signer->createBase($hop0)->raw;
    $prevAud = $hop0;

    // L1 … L{depth}
    for ($i = 1; $i <= $depth; $i++) {
        $nextAud = $i === $depth ? $finalAudience : 'spiffe://zt.local/hop-' . $i;
        // The signer identity for this level must equal the prior level's aud.
        $hopSigner = new LSVIDSigner(legalReader($prevAud), defaultTtlSeconds: 300);
        $raw = $hopSigner->extend($raw, $nextAud)->raw;
        $prevAud = $nextAud;
    }
    return $raw;
}

/**
 * Factory: legal 3-level chain but with a deliberate audience mismatch at L1.
 *
 *   L0.aud = hop-1        (gw → hop-1)     ← legal
 *   L1.aud = WRONG-HOP    (hop-1 → WRONG)  ← deliberate mid-chain break
 *   L2.aud = final        (hop-2 → final)
 *
 * Validator walks root-first and, at L2, sees nested(=L1).aud = WRONG-HOP but
 * L2.iss = hop-2, so the chain-continuity rule rejects the token.
 */
function mintChainAudMismatchMid(): string
{
    $hop1 = 'spiffe://zt.local/hop-1';
    $hop2 = 'spiffe://zt.local/hop-2';
    $wrong = 'spiffe://zt.local/WRONG-HOP';

    $l0Signer = new LSVIDSigner(legalReader(GW_ID), defaultTtlSeconds: 300);
    $l0 = $l0Signer->createBase($hop1)->raw;

    // L1 legitimately issued by hop-1 but aimed at a hop that nobody else uses.
    $l1Signer = new LSVIDSigner(legalReader($hop1), defaultTtlSeconds: 300);
    $l1 = $l1Signer->extend($l0, $wrong)->raw;

    // L2 issued by hop-2 — but nested(L1).aud = WRONG ≠ hop-2 = L2.iss.
    // Final aud = WK_ID so the outermost-audience check doesn't catch this
    // first; we want the chain-continuity failure to be the trigger.
    $l2Signer = new LSVIDSigner(legalReader($hop2), defaultTtlSeconds: 300);
    return $l2Signer->extend($l1, WK_ID)->raw;
}

/**
 * Factory: legal L0, then L1 issued by a foreign (rogue) CA.
 *
 * Structure looks legit (iss/aud/chain-continuity are correct on paper), but
 * L1's leaf certificate is not signed by the legit trust bundle, so
 * openssl_x509_verify fails at L1 and the token is rejected.
 */
function mintChainForeignIntermediate(): string
{
    $hop1 = 'spiffe://zt.local/hop-1';
    $l0Signer = new LSVIDSigner(legalReader(GW_ID), defaultTtlSeconds: 300);
    $l0 = $l0Signer->createBase($hop1)->raw;

    // foreignReader() uses a DIFFERENT CA chain — even if we name the iss
    // plausibly, the validator's CA bundle won't verify its leaf cert.
    // Final aud = WK_ID so the cert-verification failure is the trigger,
    // not an outermost audience mismatch.
    $l1Signer = new LSVIDSigner(foreignReader($hop1), defaultTtlSeconds: 300);
    return $l1Signer->extend($l0, WK_ID)->raw;
}

/**
 * Factory: over-deep chain exceeding LSVID::MAX_NESTED_DEPTH (=16).
 *
 * Produces 18 levels (L0 + 17 extensions), which LSVID::parse() must reject
 * at parse-time before any signature verification runs.
 */
function mintOverDeepChain(): string
{
    return mintLegalDeepChain(17, DS_ID);
}

function mintHappyPath(): string
{
    return mintBaseLegal();
}

/**
 * @return array{envelope: array<string,mixed>, lsvid_raw: ?string, extra: array<string,mixed>}
 */
function buildCase(string $caseId): array
{
    switch ($caseId) {
        // ── 1. Token forgery ────────────────────────────────────────────
        case 'F01': // header tampering — flip alg to "none"
            $legal = mintBaseLegal();
            $tampered = tamperJwtSegment($legal, 0, function (string $hdr): string {
                $j = json_decode($hdr, true);
                $j['alg'] = 'none';
                return json_encode($j);
            });
            return ['envelope' => buildEnvelope($tampered), 'lsvid_raw' => $tampered, 'extra' => ['mutation' => 'alg=none']];

        case 'F02': // payload tampering — rewrite sub/aud but keep sig
            $legal = mintBaseLegal();
            $tampered = tamperJwtSegment($legal, 1, function (string $pl): string {
                $j = json_decode($pl, true);
                $j['sub'] = FOREIGN_ID;
                $j['aud'] = DS_ID;
                return json_encode($j);
            });
            return ['envelope' => buildEnvelope($tampered), 'lsvid_raw' => $tampered, 'extra' => ['mutation' => 'sub→attacker, aud→downstream']];

        case 'F03': // signature re-base64 (produces invalid bytes)
            $legal = mintBaseLegal();
            $parts = explode('.', $legal);
            $parts[2] = base64url_encode('NOT_A_VALID_SIG' . random_bytes(32));
            $tampered = implode('.', $parts);
            return ['envelope' => buildEnvelope($tampered), 'lsvid_raw' => $tampered, 'extra' => ['mutation' => 'sig replaced with random bytes']];

        case 'F04': // no LSVID header at all (probe LSVID_REQUIRED)
            return ['envelope' => buildEnvelope(null), 'lsvid_raw' => null, 'extra' => ['mutation' => 'envelope has no lsvid']];

        // ── 2. Trust domain violation ───────────────────────────────────
        case 'T01': // foreign trust domain
            $foreign = mintBaseForeign();
            return [
                'envelope' => buildEnvelope($foreign, ['spiffe_id' => FOREIGN_ID, 'spiffe_path' => [FOREIGN_ID]]),
                'lsvid_raw' => $foreign,
                'extra' => ['mutation' => 'iss=spiffe://evil.local/*'],
            ];

        case 'T02': // same trust domain, unauthorized workload audience
            $unauthorized = mintBaseLegal(TD_REAL, 'spiffe://zt.local/unauthorized', 'spiffe://zt.local/nobody');
            return [
                'envelope' => buildEnvelope($unauthorized, ['spiffe_id' => 'spiffe://zt.local/unauthorized']),
                'lsvid_raw' => $unauthorized,
                'extra' => ['mutation' => 'aud=spiffe://zt.local/nobody'],
            ];

        case 'T03': // envelope.spiffe_id != L0.sub
            $legal = mintBaseLegal();
            return [
                'envelope' => buildEnvelope($legal, ['spiffe_id' => 'spiffe://zt.local/other', 'spiffe_path' => ['spiffe://zt.local/other']]),
                'lsvid_raw' => $legal,
                'extra' => ['mutation' => 'envelope source != L0 sub'],
            ];

        // ── 3. Chain attacks ───────────────────────────────────────────
        case 'C01': // nested.aud != enclosing.iss
            $broken = mintBrokenChain();
            return ['envelope' => buildEnvelope($broken), 'lsvid_raw' => $broken, 'extra' => ['mutation' => 'L0.aud=wrong-hop, then extended']];

        case 'C02': // level skip — send L0 but claim it is L2 by adding fake claims
            $legal = mintBaseLegal();
            $tampered = tamperJwtSegment($legal, 1, function (string $pl): string {
                $j = json_decode($pl, true);
                $j['nested'] = 'FAKE_L1_TOKEN_THAT_DOES_NOT_EXIST';
                return json_encode($j);
            });
            return ['envelope' => buildEnvelope($tampered), 'lsvid_raw' => $tampered, 'extra' => ['mutation' => 'injected fake nested pointer']];

        case 'C03': // build L0 with aud then strip aud in a re-encode
            $legal = mintBaseLegal();
            $tampered = tamperJwtSegment($legal, 1, function (string $pl): string {
                $j = json_decode($pl, true);
                unset($j['aud']);
                return json_encode($j);
            });
            return ['envelope' => buildEnvelope($tampered), 'lsvid_raw' => $tampered, 'extra' => ['mutation' => 'aud stripped from payload']];

        // ── 4. Time attacks ────────────────────────────────────────────
        case 'E01': // expired (exp already past)
            $expired = mintExpired();
            return ['envelope' => buildEnvelope($expired), 'lsvid_raw' => $expired, 'extra' => ['mutation' => 'TTL=1s + sleep, then send']];

        case 'E02': // nbf in the future
            $fut = mintFutureNbf();
            return ['envelope' => buildEnvelope($fut), 'lsvid_raw' => $fut, 'extra' => ['mutation' => 'nbf=now+3600']];

        case 'E03': // within clock skew tolerance — should be accepted (control)
            $reader = legalReader(GW_ID);
            $signer = new LSVIDSigner($reader, defaultTtlSeconds: 600);
            $raw = $signer->createBase(WK_ID, notBefore: time() + 5)->raw; // 5s in future, within 10s skew
            return ['envelope' => buildEnvelope($raw), 'lsvid_raw' => $raw, 'extra' => ['mutation' => 'nbf=now+15 (within skew, should accept)', 'expect_accept' => true]];

        // ── 5. Replay ──────────────────────────────────────────────────
        case 'R01': // same JTI — script will send twice; payload is a single legal token
        case 'R02': // same as R01 but concurrent
            $legal = mintBaseLegal();
            return ['envelope' => buildEnvelope($legal), 'lsvid_raw' => $legal, 'extra' => ['mutation' => 'reuse same JTI twice', 'send_twice' => true]];

        // ── 6. mTLS / rotation ─────────────────────────────────────────
        case 'M01': case 'M02': case 'M03':
            // These are executed by stage4-rotation-race.sh or direct curl without mTLS.
            // Provide a legal envelope; the network-layer mutation happens in stage script.
            $legal = mintBaseLegal();
            return ['envelope' => buildEnvelope($legal), 'lsvid_raw' => $legal, 'extra' => ['mutation' => 'network-layer probe — see stage4 script']];

        // ── 7. AMQP / SHM direct-inject ────────────────────────────────
        case 'Q01': // direct AMQP publish with forged envelope (no gateway)
            $foreign = mintBaseForeign();
            return [
                'envelope' => buildEnvelope($foreign, ['spiffe_id' => FOREIGN_ID, 'spiffe_path' => [FOREIGN_ID]]),
                'lsvid_raw' => $foreign,
                'extra' => ['mutation' => 'direct publish to order_queue', 'target' => 'order_queue'],
            ];
        case 'Q02': // forged rollback event injection into saga
            $legal = mintBaseLegal(TD_REAL, FOREIGN_ID, WK_ID);
            return [
                'envelope' => buildEnvelope($legal, [
                    'route' => 'rollback.order',
                    'spiffe_id' => FOREIGN_ID,
                ]),
                'lsvid_raw' => $legal,
                'extra' => ['mutation' => 'forged RollbackOrderEvent from attacker', 'target' => 'RollbackOrderEvent'],
            ];

        case 'S01': // SHM file tamper — handled by shell stage (docker exec echo …)
        case 'S02':
            return ['envelope' => [], 'lsvid_raw' => null, 'extra' => ['mutation' => 'SHM probe — see stage3 script']];

        case 'HAPPY': // baseline control — legitimate request, should succeed
            $legal = mintHappyPath();
            return ['envelope' => buildEnvelope($legal), 'lsvid_raw' => $legal, 'extra' => ['mutation' => 'legitimate baseline', 'expect_accept' => true]];

        // ── 8. Chain depth (A2 experiment) ─────────────────────────────
        case 'D01': // legal 4-level chain (L0→L1→L2→L3) — control for depth path
            $deep = mintLegalDeepChain(3, WK_ID);
            return [
                'envelope' => buildEnvelope($deep),
                'lsvid_raw' => $deep,
                'extra' => ['mutation' => 'legal chain depth=4 (L0..L3)', 'expect_accept' => true, 'chain_depth' => 4],
            ];

        case 'D02': // mid-chain audience mismatch (L1.aud != L2.iss)
            $broken = mintChainAudMismatchMid();
            return [
                'envelope' => buildEnvelope($broken),
                'lsvid_raw' => $broken,
                'extra' => ['mutation' => 'L1.aud=WRONG-HOP mid-chain', 'chain_depth' => 3, 'expect_reject_at' => 'L2 chain continuity'],
            ];

        case 'D03': // foreign-CA intermediate level (L1 signed by rogue CA)
            $foreignMid = mintChainForeignIntermediate();
            return [
                'envelope' => buildEnvelope($foreignMid),
                'lsvid_raw' => $foreignMid,
                'extra' => ['mutation' => 'L1 leaf cert signed by foreign CA', 'chain_depth' => 2, 'expect_reject_at' => 'L1 cert verification'],
            ];

        case 'D04': // outermost-level audience stripped on a deep chain
            $deep = mintLegalDeepChain(2, WK_ID);
            $tampered = tamperJwtSegment($deep, 1, function (string $pl): string {
                $j = json_decode($pl, true);
                unset($j['aud']);
                return json_encode($j);
            });
            return [
                'envelope' => buildEnvelope($tampered),
                'lsvid_raw' => $tampered,
                'extra' => ['mutation' => 'strip aud from outermost payload (depth=3)', 'chain_depth' => 3, 'expect_reject_at' => 'requireAudienceOnAllLevels'],
            ];

        case 'D05': // chain exceeds LSVID::MAX_NESTED_DEPTH (=16)
            $overDeep = mintOverDeepChain();
            return [
                'envelope' => buildEnvelope($overDeep),
                'lsvid_raw' => $overDeep,
                'extra' => ['mutation' => 'chain depth=18 > MAX_NESTED_DEPTH(16)', 'chain_depth' => 18, 'expect_reject_at' => 'parse-time depth limit'],
            ];

        default:
            throw new InvalidArgumentException("Unknown case_id: {$caseId}");
    }
}

/** @return array<string,array{category:string,layer:string,desc:string}> */
function caseMetadata(): array
{
    return [
        'F01' => ['category' => 'token-forgery', 'layer' => 'LSVIDValidator', 'desc' => 'Header tampering: alg downgrade to none'],
        'F02' => ['category' => 'token-forgery', 'layer' => 'LSVIDValidator', 'desc' => 'Payload tampering: sub/aud rewrite'],
        'F03' => ['category' => 'token-forgery', 'layer' => 'LSVIDValidator', 'desc' => 'Signature replacement with random bytes'],
        'F04' => ['category' => 'token-forgery', 'layer' => 'RequestConsumer', 'desc' => 'Missing LSVID when LSVID_REQUIRED=1'],
        'T01' => ['category' => 'trust-domain', 'layer' => 'RequestConsumer', 'desc' => 'Foreign trust domain (spiffe://evil.local/)'],
        'T02' => ['category' => 'trust-domain', 'layer' => 'LSVIDValidator', 'desc' => 'Unauthorized workload audience'],
        'T03' => ['category' => 'trust-domain', 'layer' => 'RequestConsumer', 'desc' => 'Envelope spiffe_id != L0.sub'],
        'C01' => ['category' => 'chain-attack',  'layer' => 'LSVIDValidator', 'desc' => 'Nested.aud != enclosing.iss'],
        'C02' => ['category' => 'chain-attack',  'layer' => 'LSVIDValidator', 'desc' => 'Level skip — fake nested pointer'],
        'C03' => ['category' => 'chain-attack',  'layer' => 'LSVIDValidator', 'desc' => 'Audience stripped from payload'],
        'E01' => ['category' => 'time-attack',   'layer' => 'LSVIDValidator', 'desc' => 'Expired token (exp in past)'],
        'E02' => ['category' => 'time-attack',   'layer' => 'LSVIDValidator', 'desc' => 'Future token (nbf in future, outside skew)'],
        'E03' => ['category' => 'time-attack',   'layer' => 'ACCEPT (control)','desc' => 'Within clock skew — should accept'],
        'R01' => ['category' => 'replay',        'layer' => 'JtiReplayCache', 'desc' => 'Same JTI sent twice sequentially'],
        'R02' => ['category' => 'replay',        'layer' => 'JtiReplayCache', 'desc' => 'Same JTI sent twice concurrently'],
        'M01' => ['category' => 'mtls',          'layer' => 'Downstream mTLS','desc' => 'No client cert to downstream'],
        'M02' => ['category' => 'mtls',          'layer' => 'Downstream mTLS','desc' => 'Foreign client cert to downstream'],
        'M03' => ['category' => 'mtls',          'layer' => 'SHM seqlock',    'desc' => 'SVID rotation mid-request'],
        'Q01' => ['category' => 'amqp-inject',   'layer' => 'RequestConsumer', 'desc' => 'Direct AMQP publish of forged envelope'],
        'Q02' => ['category' => 'amqp-inject',   'layer' => 'EventConsumer',   'desc' => 'Forged rollback event injection'],
        'S01' => ['category' => 'shm-tamper',    'layer' => 'SpiffeTableStore','desc' => 'SHM file tamper (expected to succeed — design limit)'],
        'S02' => ['category' => 'shm-tamper',    'layer' => 'SpiffeTableReader','desc' => 'SHM stuck on odd seqlock version'],
        'HAPPY' => ['category' => 'baseline',    'layer' => 'N/A',            'desc' => 'Legitimate request (control)'],
        'D01' => ['category' => 'chain-depth',   'layer' => 'ACCEPT (control)','desc' => 'Legal 4-level chain (L0..L3)'],
        'D02' => ['category' => 'chain-depth',   'layer' => 'LSVIDValidator', 'desc' => 'Mid-chain audience mismatch (L1.aud != L2.iss)'],
        'D03' => ['category' => 'chain-depth',   'layer' => 'LSVIDValidator', 'desc' => 'Foreign-CA intermediate level (L1 signed by rogue CA)'],
        'D04' => ['category' => 'chain-depth',   'layer' => 'LSVIDValidator', 'desc' => 'Outermost aud stripped on depth-3 chain'],
        'D05' => ['category' => 'chain-depth',   'layer' => 'LSVID::parse',   'desc' => 'Over-deep chain (18 > MAX_NESTED_DEPTH=16)'],
    ];
}

// ── CLI entry point ─────────────────────────────────────────────────────────
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? __FILE__) !== __FILE__) {
    return;
}

$caseId = $argv[1] ?? '';
if ($caseId === '' || $caseId === '--list') {
    $meta = caseMetadata();
    foreach ($meta as $id => $m) {
        printf("%-6s %-16s %-22s %s\n", $id, $m['category'], $m['layer'], $m['desc']);
    }
    exit(0);
}

$meta = caseMetadata()[$caseId] ?? null;
if ($meta === null) {
    fwrite(STDERR, "Unknown case_id: {$caseId}\n");
    exit(2);
}

$built = buildCase($caseId);
$out = [
    'case_id'        => $caseId,
    'category'       => $meta['category'],
    'description'    => $meta['desc'],
    'layer_expected' => $meta['layer'],
    'envelope'       => $built['envelope'],
    'lsvid_raw'      => $built['lsvid_raw'],
    'extra'          => $built['extra'],
];
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
