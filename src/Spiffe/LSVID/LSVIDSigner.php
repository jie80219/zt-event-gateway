<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\Spiffe\LSVID;

use Spiffe\SharedMemory\SpiffeTableReader;

/**
 * Signs Nested LSVIDs using the workload's current X.509-SVID private key.
 *
 * Each call to {@see sign()} produces one level:
 *   - If $priorRawToken is null, the result is a base level (L0).
 *   - Otherwise the prior raw token is embedded in the payload's `nested`
 *     claim, producing an extension level (L1, L2, …).
 *
 * The signer reads the current primary X.509-SVID from the shared-memory
 * store on every call, so rotations are picked up without reconstruction.
 * The leaf certificate (DER) is embedded in the header's `x5c` claim so
 * verifiers can self-contain the trust check against the CA bundle.
 */
final class LSVIDSigner
{
    public function __construct(
        private readonly SpiffeTableReader $reader,
        private readonly int $defaultTtlSeconds = 300,
    ) {
    }

    /**
     * @param array<string, mixed> $claims  Claims to merge into the payload.
     *                                      `iss`, `iat`, `exp` are set automatically
     *                                      from the current SVID when absent.
     * @param string|null          $priorRawToken Prior-level compact LSVID to nest.
     * @param string|null          $audience      Optional `aud` claim (next hop SPIFFE ID).
     */
    public function sign(array $claims, ?string $priorRawToken = null, ?string $audience = null): LSVID
    {
        $svid = $this->reader->readX509Primary();
        if ($svid === null) {
            throw new LSVIDException('No X.509-SVID available: shared-memory store is empty.');
        }

        $privateKey = openssl_pkey_get_private($svid['key_pem']);
        if ($privateKey === false) {
            throw new LSVIDException('Failed to load private key from SPIFFE store: ' . self::lastOpensslError());
        }

        $details = openssl_pkey_get_details($privateKey);
        if ($details === false) {
            throw new LSVIDException('Failed to inspect SVID private key.');
        }

        [$alg, $opensslAlg] = self::pickAlg($details);

        $leafDer = self::leafDerFromPem($svid['cert_pem']);

        $header = [
            'alg' => $alg,
            'typ' => 'LSVID',
            'x5c' => [base64_encode($leafDer)],
        ];

        $now = time();
        $payload = $claims;
        $payload['iss'] = $claims['iss'] ?? $svid['spiffe_id'];
        $payload['iat'] = $claims['iat'] ?? $now;
        $payload['exp'] = $claims['exp'] ?? ($now + $this->defaultTtlSeconds);
        if ($audience !== null && $audience !== '') {
            $payload['aud'] = $audience;
        }
        if ($priorRawToken !== null && $priorRawToken !== '') {
            $payload['nested'] = $priorRawToken;
        }

        $headerB64  = LSVID::b64UrlEncode(self::jsonEncode($header));
        $payloadB64 = LSVID::b64UrlEncode(self::jsonEncode($payload));
        $signingInput = $headerB64 . '.' . $payloadB64;

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $privateKey, $opensslAlg);
        if ($ok !== true) {
            throw new LSVIDException('openssl_sign failed: ' . self::lastOpensslError());
        }

        // ECDSA: OpenSSL returns DER-encoded (r,s). JWS ES* format requires
        // raw concatenated (r||s) of fixed width. Convert if needed.
        if (str_starts_with($alg, 'ES')) {
            $signature = self::derEcdsaToJose($signature, (int) substr($alg, 2));
        }

        $sigB64 = LSVID::b64UrlEncode($signature);
        $raw = $signingInput . '.' . $sigB64;

        return LSVID::fromParts(
            raw:          $raw,
            signingInput: $signingInput,
            header:       $header,
            payload:      $payload,
            signature:    $signature,
            nested:       $priorRawToken !== null ? LSVID::parse($priorRawToken) : null,
        );
    }

    /**
     * Extend a prior-level raw token by one level — sugar over sign().
     */
    public function extend(string $priorRawToken, array $claims, ?string $audience = null): LSVID
    {
        return $this->sign($claims, $priorRawToken, $audience);
    }

    /**
     * @param array<string, mixed> $details  Output of openssl_pkey_get_details
     * @return array{0: string, 1: int}      [JWS alg, OPENSSL_ALGO_* constant]
     */
    private static function pickAlg(array $details): array
    {
        $type = $details['type'] ?? null;

        if ($type === OPENSSL_KEYTYPE_RSA) {
            return ['RS256', OPENSSL_ALGO_SHA256];
        }

        if ($type === OPENSSL_KEYTYPE_EC) {
            $curve = $details['ec']['curve_name'] ?? '';
            return match ($curve) {
                'prime256v1', 'secp256r1' => ['ES256', OPENSSL_ALGO_SHA256],
                'secp384r1'               => ['ES384', OPENSSL_ALGO_SHA384],
                'secp521r1'               => ['ES512', OPENSSL_ALGO_SHA512],
                default => throw new LSVIDException(sprintf('Unsupported EC curve: %s', $curve)),
            };
        }

        throw new LSVIDException('Unsupported SVID key type for LSVID signing.');
    }

    private static function leafDerFromPem(string $pem): string
    {
        if (!preg_match(
            '/-----BEGIN CERTIFICATE-----\s*([A-Za-z0-9+\/=\s]+?)\s*-----END CERTIFICATE-----/',
            $pem,
            $m,
        )) {
            throw new LSVIDException('SVID cert_pem does not contain a valid CERTIFICATE block.');
        }

        $der = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);
        if ($der === false) {
            throw new LSVIDException('SVID leaf certificate is not valid base64.');
        }

        return $der;
    }

    /**
     * Convert OpenSSL's DER-encoded ECDSA signature (SEQUENCE{r,s}) into
     * the JWS raw fixed-width (r||s) form.
     */
    private static function derEcdsaToJose(string $der, int $algBits): string
    {
        $partLen = match ($algBits) {
            256 => 32,
            384 => 48,
            512 => 66,
            default => throw new LSVIDException('Unsupported ECDSA bit length.'),
        };

        $offset = 0;
        if (($der[$offset++] ?? '') !== "\x30") {
            throw new LSVIDException('Invalid ECDSA DER: missing SEQUENCE.');
        }
        $seqLen = ord($der[$offset++]);
        if ($seqLen & 0x80) {
            $n = $seqLen & 0x7f;
            $offset += $n;
        }

        $readInt = static function (string $der, int &$offset) use ($partLen): string {
            if (($der[$offset++] ?? '') !== "\x02") {
                throw new LSVIDException('Invalid ECDSA DER: missing INTEGER.');
            }
            $len = ord($der[$offset++]);
            $int = substr($der, $offset, $len);
            $offset += $len;
            // Strip leading zero that DER adds for sign bit.
            $int = ltrim($int, "\x00");
            if (strlen($int) > $partLen) {
                throw new LSVIDException('ECDSA integer longer than curve size.');
            }
            return str_pad($int, $partLen, "\x00", STR_PAD_LEFT);
        };

        $r = $readInt($der, $offset);
        $s = $readInt($der, $offset);

        return $r . $s;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function jsonEncode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new LSVIDException('Failed to JSON-encode LSVID component: ' . json_last_error_msg());
        }
        return $json;
    }

    private static function lastOpensslError(): string
    {
        $messages = [];
        while (($err = openssl_error_string()) !== false) {
            $messages[] = $err;
        }
        return $messages === [] ? '(no details)' : implode('; ', $messages);
    }
}
