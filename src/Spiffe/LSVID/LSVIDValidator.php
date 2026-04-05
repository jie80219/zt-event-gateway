<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\Spiffe\LSVID;

use Spiffe\SharedMemory\SpiffeTableReader;

/**
 * Verifies Nested LSVIDs against the trust domain's CA bundle.
 *
 * For every level in the chain (root L0 → leaf Ln) the validator:
 *   1. Parses the segment and re-derives the signing input.
 *   2. Pulls the leaf X.509 certificate from the `x5c` header claim and
 *      checks it against a CA from the trust bundle (openssl_x509_verify).
 *   3. Verifies the JWS signature with the leaf certificate's public key.
 *   4. Asserts iat/exp are within an allowed clock-skew window.
 *   5. Asserts the cert's URI SAN matches the payload's `iss` claim.
 *   6. (extensions only) Asserts chain continuity: the nested level's
 *      audience — when present — matches the enclosing level's issuer.
 *
 * Usage:
 *   $validator = new LSVIDValidator(new SpiffeTableReader());
 *   $lsvid = $validator->validate($rawToken, expectedAudience: 'spiffe://zt.local/php-worker');
 */
final class LSVIDValidator
{
    public function __construct(
        private readonly SpiffeTableReader $reader,
        private readonly int $clockSkewSeconds = 30,
    ) {
    }

    public function validate(string $rawToken, ?string $expectedAudience = null): LSVID
    {
        $lsvid = LSVID::parse($rawToken);
        $caCerts = $this->loadTrustBundleCaCerts();
        if ($caCerts === []) {
            throw new LSVIDException('Trust bundle is empty — cannot verify LSVID.');
        }

        // Walk root-first so we verify L0 before its nester.
        $chain = $lsvid->chain();
        foreach ($chain as $i => $level) {
            $this->verifyLevel($level, $caCerts);

            if ($i > 0) {
                // Chain continuity: nested.aud, if set, must equal this level's iss.
                $nested = $level->nested;
                if ($nested !== null) {
                    $nestedAud = $nested->audience();
                    if ($nestedAud !== null && $nestedAud !== $level->issuer()) {
                        throw new LSVIDException(sprintf(
                            'LSVID chain broken: nested audience %s ≠ enclosing issuer %s.',
                            $nestedAud,
                            $level->issuer(),
                        ));
                    }
                }
            }
        }

        if ($expectedAudience !== null) {
            $aud = $lsvid->audience();
            if ($aud !== null && $aud !== $expectedAudience) {
                throw new LSVIDException(sprintf(
                    'LSVID audience mismatch: got %s, expected %s.',
                    $aud,
                    $expectedAudience,
                ));
            }
        }

        return $lsvid;
    }

    /**
     * @param list<\OpenSSLCertificate> $caCerts
     */
    private function verifyLevel(LSVID $level, array $caCerts): void
    {
        // 1. Pull leaf cert from header.
        $leafPem = $level->leafCertificatePem();
        if ($leafPem === null) {
            throw new LSVIDException('LSVID header is missing x5c claim.');
        }
        $leafCert = openssl_x509_read($leafPem);
        if ($leafCert === false) {
            throw new LSVIDException('LSVID x5c leaf certificate is not parseable.');
        }

        // 2. Verify leaf against at least one CA in the trust bundle.
        $trusted = false;
        foreach ($caCerts as $ca) {
            $caKey = openssl_pkey_get_public($ca);
            if ($caKey === false) {
                continue;
            }
            if (openssl_x509_verify($leafCert, $caKey) === 1) {
                $trusted = true;
                break;
            }
        }
        if (!$trusted) {
            throw new LSVIDException('LSVID leaf certificate is not signed by any trusted CA.');
        }

        // 3. Verify signature with leaf public key.
        $leafPubKey = openssl_pkey_get_public($leafCert);
        if ($leafPubKey === false) {
            throw new LSVIDException('Failed to extract leaf public key for LSVID verification.');
        }

        $alg = (string) ($level->header['alg'] ?? '');
        [$opensslAlg, $isEcdsa, $ecBits] = self::algFromHeader($alg);

        $signature = $level->signature;
        if ($isEcdsa) {
            // Convert JWS raw (r||s) back to DER for openssl_verify.
            $signature = self::joseEcdsaToDer($signature, $ecBits);
        }

        $ok = openssl_verify($level->signingInput, $signature, $leafPubKey, $opensslAlg);
        if ($ok !== 1) {
            throw new LSVIDException('LSVID signature verification failed.');
        }

        // 4. Temporal checks.
        $now = time();
        $iat = $level->issuedAt();
        $exp = $level->expiresAt();
        if ($iat !== null && $iat > $now + $this->clockSkewSeconds) {
            throw new LSVIDException('LSVID iat is in the future.');
        }
        if ($exp !== null && $exp + $this->clockSkewSeconds < $now) {
            throw new LSVIDException('LSVID has expired.');
        }

        // 5. Issuer ↔ cert SAN continuity.
        $sanUri = self::certUriSan($leafPem);
        if ($sanUri === null) {
            throw new LSVIDException('Leaf certificate lacks a URI SAN.');
        }
        if ($level->issuer() !== $sanUri) {
            throw new LSVIDException(sprintf(
                'LSVID iss=%s does not match cert SAN URI=%s.',
                $level->issuer(),
                $sanUri,
            ));
        }
    }

    /**
     * @return list<\OpenSSLCertificate>
     */
    private function loadTrustBundleCaCerts(): array
    {
        $svid = $this->reader->readX509Primary();
        if ($svid === null || !isset($svid['bundle_pem']) || $svid['bundle_pem'] === '') {
            return [];
        }

        $certs = [];
        if (preg_match_all(
            '/-----BEGIN CERTIFICATE-----[^-]+-----END CERTIFICATE-----/s',
            $svid['bundle_pem'],
            $matches,
        )) {
            foreach ($matches[0] as $pem) {
                $cert = openssl_x509_read($pem);
                if ($cert !== false) {
                    $certs[] = $cert;
                }
            }
        }

        return $certs;
    }

    /**
     * @return array{0: int, 1: bool, 2: int}  [OPENSSL_ALGO_*, isEcdsa, ecdsaBits]
     */
    private static function algFromHeader(string $alg): array
    {
        return match ($alg) {
            'RS256' => [OPENSSL_ALGO_SHA256, false, 0],
            'RS384' => [OPENSSL_ALGO_SHA384, false, 0],
            'RS512' => [OPENSSL_ALGO_SHA512, false, 0],
            'ES256' => [OPENSSL_ALGO_SHA256, true, 256],
            'ES384' => [OPENSSL_ALGO_SHA384, true, 384],
            'ES512' => [OPENSSL_ALGO_SHA512, true, 512],
            default => throw new LSVIDException(sprintf('Unsupported LSVID alg: %s', $alg)),
        };
    }

    private static function certUriSan(string $leafPem): ?string
    {
        $parsed = openssl_x509_parse($leafPem);
        if ($parsed === false) {
            return null;
        }
        $san = $parsed['extensions']['subjectAltName'] ?? '';
        if (!is_string($san) || $san === '') {
            return null;
        }
        foreach (explode(',', $san) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'URI:')) {
                return substr($entry, 4);
            }
        }
        return null;
    }

    /**
     * Convert JWS raw (r||s) ECDSA signature back to DER SEQUENCE{r,s}.
     */
    private static function joseEcdsaToDer(string $jose, int $algBits): string
    {
        $partLen = match ($algBits) {
            256 => 32,
            384 => 48,
            512 => 66,
            default => throw new LSVIDException('Unsupported ECDSA bit length.'),
        };

        if (strlen($jose) !== 2 * $partLen) {
            throw new LSVIDException('Malformed ECDSA JWS signature length.');
        }

        $r = ltrim(substr($jose, 0, $partLen), "\x00");
        $s = ltrim(substr($jose, $partLen), "\x00");
        // Re-add leading zero if high bit is set (DER sign-bit rule).
        if ($r !== '' && (ord($r[0]) & 0x80) !== 0) {
            $r = "\x00" . $r;
        }
        if ($s !== '' && (ord($s[0]) & 0x80) !== 0) {
            $s = "\x00" . $s;
        }

        $encodeInt = static fn(string $int): string => "\x02" . chr(strlen($int)) . $int;
        $rEnc = $encodeInt($r);
        $sEnc = $encodeInt($s);
        $body = $rEnc . $sEnc;

        return "\x30" . chr(strlen($body)) . $body;
    }
}
