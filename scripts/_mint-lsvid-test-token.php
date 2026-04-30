<?php

declare(strict_types=1);

/**
 * Test helper: mint LSVID tokens with deliberately-bad properties.
 *
 * Designed to be executed INSIDE the gateway container during E2E health
 * probes — relies on the SHM SVID at /tmp/spiffe-shared and the LSVID
 * library autoloaded by the project. Output is the raw compact-JWS token
 * on stdout (single line), or "ERROR: ..." on failure (non-zero exit).
 *
 * Variants:
 *   --variant=expired      TTL = -500s, exp well in the past
 *   --variant=wrong-aud    aud = spiffe://zt.local/wrong-target
 *   --variant=tampered     Valid token whose final signature segment is
 *                          overwritten with 'A's so verification fails
 *   --variant=happy        Reference-good token (sanity check the helper)
 *
 * Optional:
 *   --audience=<spiffe-id>  Override audience (default depends on variant)
 *   --subject=<spiffe-id>   Override L0 subject (defaults to signer SVID)
 *
 * Exit code 0 on success, 1 on argument error, 2 on signing failure.
 */

require_once __DIR__ . '/../init.php';

use SDPMlab\LSVID\LSVIDSigner;
use SDPMlab\ZtEventGateway\Spiffe\SpiffeBootstrap;

$opts = getopt('', ['variant:', 'audience::', 'subject::']);
$variant = $opts['variant'] ?? '';
$audience = isset($opts['audience']) && $opts['audience'] !== '' ? $opts['audience'] : null;
$subject = isset($opts['subject']) && $opts['subject'] !== '' ? $opts['subject'] : null;

$workerId = getenv('SPIFFE_ID') ?: 'spiffe://zt.local/php-worker';

switch ($variant) {
    case 'expired':
        $ttl = -500;
        $audience ??= $workerId;
        break;
    case 'wrong-aud':
        $ttl = 300;
        $audience ??= 'spiffe://zt.local/wrong-target';
        break;
    case 'tampered':
    case 'happy':
        $ttl = 300;
        $audience ??= $workerId;
        break;
    default:
        fwrite(STDERR, "ERROR: --variant required (expired|wrong-aud|tampered|happy)\n");
        exit(1);
}

try {
    $shmDir = getenv('SPIFFE_SHM_DIR') ?: '/tmp/spiffe-shared';
    $boot = SpiffeBootstrap::fromShm($shmDir, ['spiffe_id' => $workerId]);
    $signer = new LSVIDSigner($boot->svidReader(), defaultTtlSeconds: $ttl);
    $token = $signer->createBase(
        audience: $audience,
        subject: $subject,
        extraClaims: ['traceId' => 'forge-' . bin2hex(random_bytes(4)), 'route' => 'OrderCreateRequestedEvent', 'level' => 'L0'],
    );
    $raw = $token->raw;
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: signing failed: ' . $e->getMessage() . "\n");
    exit(2);
}

if ($variant === 'tampered') {
    $parts = explode('.', $raw);
    if (count($parts) !== 3) {
        fwrite(STDERR, "ERROR: expected compact-JWS with 3 segments\n");
        exit(2);
    }
    $parts[2] = str_repeat('A', strlen($parts[2]));
    $raw = implode('.', $parts);
}

echo $raw, "\n";
exit(0);
