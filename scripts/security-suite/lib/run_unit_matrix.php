<?php

declare(strict_types=1);

ini_set('display_errors', 'stderr');

/**
 * Unit-level attack matrix runner.
 *
 * For each case produced by attack_client.php, instantiate a local
 * LSVIDValidator (configured like the production worker) and feed the
 * tampered token. Record accept/reject, exception class, reason string
 * and detection latency (microseconds).
 */

require_once __DIR__ . '/attack_client.php';

use SDPMlab\LSVID\JtiReplayCache;
use SDPMlab\LSVID\LSVIDException;
use SDPMlab\LSVID\LSVIDValidator;
use SDPMlab\LSVID\Tests\TestSvidReader;

// ── CLI options ─────────────────────────────────────────────────────────────
$opts = getopt('', ['profile:', 'out:']);
$profile = is_string($opts['profile'] ?? null) ? $opts['profile'] : 'D-full-zt';
$outFile = is_string($opts['out'] ?? null) ? $opts['out'] : (getcwd() . '/unit-results.json');

// Cases that can be evaluated at unit level (no Docker / network).
const UNIT_CASES = [
    'HAPPY',
    'F01','F02','F03','F04',
    'T01','T02','T03',
    'C01','C02','C03',
    'E01','E02','E03',
    'R01','R02',
    // A2 chain-depth cases — validate that L3+ chains, mid-chain audience
    // breaks, foreign intermediates, and over-deep chains are handled.
    'D01','D02','D03','D04','D05',
];

/**
 * Build a validator matching worker.php production config.
 * We also share the jtiCache across cases so R01/R02 can observe replay state.
 */
function buildValidator(JtiReplayCache $jtiCache): LSVIDValidator
{
    // A reader sharing the same fake CA isn't possible across independent
    // attack factories — each mint* call creates its own CA. That means the
    // validator will see "wrong CA" for tokens from different factories.
    // For the unit layer we therefore rebuild validator per case with the
    // reader produced for that case. buildCase() doesn't expose the reader,
    // so we override via category — see evaluateUnit() below.
    throw new LogicException('Use evaluateUnit() which builds validator per case');
}

/**
 * @param string $caseId
 * @param array<string,mixed> $built  from buildCase()
 * @param JtiReplayCache $jtiCache     shared across cases within profile run
 * @return array<string,mixed>
 */
function evaluateUnit(string $caseId, array $built, JtiReplayCache $jtiCache, string $profile): array
{
    $meta = caseMetadata()[$caseId];
    $lsvidRequired = in_array($profile, ['C-lsvid-only', 'D-full-zt'], true);
    $spiffeIdentityRequired = $profile !== 'A-baseline';
    $expectAccept = $built['extra']['expect_accept'] ?? false;

    $start = hrtime(true);
    $result = [
        'status'           => 'rejected',
        'is_expected'      => true,
        'http_code'        => null,
        'amqp_ack'         => null,
        'rejected_by'      => null,
        'reject_reason'    => null,
        'exception'        => null,
        'detect_latency_us'=> 0,
    ];

    // Profile A: no SPIFFE / LSVID enforcement at all — everything accepted.
    if (!$spiffeIdentityRequired) {
        $elapsedUs = (int) ((hrtime(true) - $start) / 1000);
        $result['status'] = 'accepted';
        $result['detect_latency_us'] = $elapsedUs;
        $result['is_expected'] = $expectAccept; // accept is correct only for HAPPY-type
        $result['rejected_by'] = 'N/A (profile bypass)';
        return $result;
    }

    // Profile B: mTLS only, LSVID off — validator not invoked, envelope prefix
    // check still enforced. We only emulate the envelope-prefix check here.
    // Token-level attacks are EXPECTED violations in this profile because B
    // protects the connection, not the token — this is a real security gap
    // that the suite must surface, not gloss over.
    if (!$lsvidRequired) {
        $envelope = $built['envelope'];
        $sid = $envelope['spiffe_id'] ?? '';
        $ok = is_string($sid) && str_starts_with($sid, 'spiffe://zt.local/');
        $elapsedUs = (int) ((hrtime(true) - $start) / 1000);
        $result['detect_latency_us'] = $elapsedUs;
        if ($ok) {
            $result['status'] = 'accepted';
            // Only HAPPY/E03 controls should legitimately be accepted here;
            // everything else is a security violation (B's known limitation).
            $result['is_expected'] = $expectAccept;
            $result['rejected_by'] = 'N/A (LSVID disabled)';
        } else {
            $result['rejected_by'] = 'RequestConsumer (prefix)';
            $result['reject_reason'] = 'trust_domain_prefix_mismatch';
            $result['is_expected'] = !$expectAccept;
        }
        return $result;
    }

    // Profiles C & D — full LSVID + prefix enforcement.
    // Build a validator that trusts the CA used to mint this particular case.
    // The envelope already embeds the CA bundle via x5c header in each level,
    // so the validator's "trust anchor" reader is only needed for key type
    // introspection — TestSvidReader::create() is fine.
    // Share the same CA singleton as the mint* factories so legal tokens verify.
    $readerForValidator = legalReader('spiffe://zt.local/worker');
    // Use a 10s clock-skew window so E01 (TTL=1s + sleep(11)) falls cleanly
    // outside tolerance while E03 (nbf=now+5) remains inside it.
    $validator = new LSVIDValidator(
        $readerForValidator,
        clockSkewSeconds: 10,
        jtiCache: $jtiCache,
        trustDomain: 'zt.local',
        requireNbf: false,
        requireAudienceOnAllLevels: true,
    );

    $envelope = $built['envelope'];
    $sid = $envelope['spiffe_id'] ?? '';
    $lsvid = $built['lsvid_raw'];
    $expectedAudience = 'spiffe://zt.local/worker';

    // Emulate RequestConsumer prefix check first.
    if (!is_string($sid) || !str_starts_with($sid, 'spiffe://zt.local/')) {
        $elapsedUs = (int) ((hrtime(true) - $start) / 1000);
        $result['rejected_by'] = 'RequestConsumer (prefix)';
        $result['reject_reason'] = 'trust_domain_prefix_mismatch';
        $result['detect_latency_us'] = $elapsedUs;
        $result['is_expected'] = !$expectAccept;
        return $result;
    }

    // Missing LSVID when required
    if ($lsvid === null) {
        $elapsedUs = (int) ((hrtime(true) - $start) / 1000);
        $result['rejected_by'] = 'RequestConsumer (lsvidRequired)';
        $result['reject_reason'] = 'lsvid_missing_but_required';
        $result['detect_latency_us'] = $elapsedUs;
        $result['is_expected'] = !$expectAccept;
        return $result;
    }

    // Full LSVID validation.
    try {
        $parsed = $validator->validate($lsvid, expectedAudience: $expectedAudience);
        // Reconcile L0.sub with envelope source.
        $chain = $parsed->chain();
        if ($chain !== []) {
            $l0Subject = $chain[0]->subject();
            if ($l0Subject !== null && $l0Subject !== $sid) {
                throw new LSVIDException(sprintf(
                    'L0 subject %s does not match envelope source %s.',
                    $l0Subject, $sid,
                ));
            }
        }
        // R01 / R02 replay: caller loops this function twice with same token,
        // JtiReplayCache is shared, so the second call throws.
        $elapsedUs = (int) ((hrtime(true) - $start) / 1000);
        $result['status'] = 'accepted';
        $result['rejected_by'] = null;
        $result['detect_latency_us'] = $elapsedUs;
        $result['is_expected'] = $expectAccept;
    } catch (LSVIDException $e) {
        $elapsedUs = (int) ((hrtime(true) - $start) / 1000);
        $result['rejected_by'] = 'LSVIDValidator';
        $result['reject_reason'] = classifyReason($e->getMessage());
        $result['exception'] = get_class($e);
        $result['detect_latency_us'] = $elapsedUs;
        $result['is_expected'] = !$expectAccept;
    } catch (\Throwable $e) {
        $elapsedUs = (int) ((hrtime(true) - $start) / 1000);
        $result['status'] = 'error';
        $result['rejected_by'] = 'LSVIDValidator (unexpected)';
        $result['reject_reason'] = 'unhandled: ' . $e->getMessage();
        $result['exception'] = get_class($e);
        $result['detect_latency_us'] = $elapsedUs;
        $result['is_expected'] = false;
    }

    return $result;
}

/** Map an LSVIDException message into a stable taxonomy label. */
function classifyReason(string $msg): string
{
    $msg = strtolower($msg);
    // Order matters: more specific phrases (nesting, chain, nested.aud) come
    // before broader ones (aud, audience) so chain-continuity failures don't
    // get classified as plain audience mismatches.
    $map = [
        'nesting depth'                => 'chain_too_deep',
        'nested audience'              => 'chain_broken',
        'nested.aud'                   => 'chain_broken',
        'chain'                        => 'chain_broken',
        'signature'                    => 'signature_verification_failed',
        'trust domain'                 => 'trust_domain_mismatch',
        'not yet valid'                => 'token_not_yet_valid',
        'nbf'                          => 'token_not_yet_valid',
        'expired'                      => 'token_expired',
        'replay'                       => 'jti_replay',
        'jti'                          => 'jti_replay',
        'audience'                     => 'audience_mismatch',
        'aud'                          => 'audience_mismatch',
        'subject'                      => 'subject_mismatch',
        'sub'                          => 'subject_mismatch',
        'ca'                           => 'ca_mismatch',
        'certificate'                  => 'cert_invalid',
        'alg'                          => 'alg_not_allowed',
    ];
    foreach ($map as $needle => $label) {
        if (str_contains($msg, $needle)) {
            return $label;
        }
    }
    return 'other: ' . substr($msg, 0, 60);
}

// ── Main loop ───────────────────────────────────────────────────────────────
$jtiCache = new JtiReplayCache();
$records = [];

foreach (UNIT_CASES as $caseId) {
    try {
        $built = buildCase($caseId);
    } catch (Throwable $e) {
        fwrite(STDERR, "[stage1] buildCase({$caseId}) failed: {$e->getMessage()}\n");
        continue;
    }

    $meta = caseMetadata()[$caseId];
    $record = [
        'case_id'        => $caseId,
        'category'       => $meta['category'],
        'description'    => $meta['desc'],
        'layer_expected' => $meta['layer'],
        'profile'        => $profile,
        'attempt' => [
            'sent_at'       => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'request_kind'  => 'unit',
            'payload_sha256'=> hash('sha256', (string) ($built['lsvid_raw'] ?? '')),
        ],
    ];

    // R01: legitimate first send, then second send with same JTI (expect reject on 2nd)
    if (in_array($caseId, ['R01', 'R02'], true)) {
        // First send — should accept
        evaluateUnit($caseId, $built, $jtiCache, $profile);
        // Second send — should reject as replay
        $record['outcome'] = evaluateUnit($caseId, $built, $jtiCache, $profile);
    } else {
        $record['outcome'] = evaluateUnit($caseId, $built, $jtiCache, $profile);
    }

    $record['log_snippet'] = sprintf(
        '[unit] case=%s profile=%s status=%s by=%s reason=%s',
        $caseId, $profile,
        $record['outcome']['status'],
        $record['outcome']['rejected_by'] ?? '-',
        $record['outcome']['reject_reason'] ?? '-',
    );

    $records[] = $record;
}

file_put_contents($outFile, json_encode([
    'stage'    => 'unit',
    'profile'  => $profile,
    'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
    'cases'    => $records,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

printf("[stage1] wrote %d cases to %s\n", count($records), $outFile);
