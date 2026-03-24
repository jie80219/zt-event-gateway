<?php

declare(strict_types=1);

namespace Spiffe\Validation;

use Spiffe\Bundle\JwtBundle;
use Spiffe\JwtSvid;
use Spiffe\SpiffeId;

/**
 * Validates JWT-SVIDs according to the SPIFFE JWT-SVID specification.
 *
 * Performs the following local checks using PHP's OpenSSL extension:
 *
 *  1. Structural integrity — valid JWS Compact Serialization (3 parts)
 *  2. Algorithm allowlist — only RS256, ES256, ES384 per spec
 *  3. Signature verification — cryptographic check using the JWKS public key
 *  4. Required claims — "sub" (SPIFFE ID), "aud" (audience), "exp" (expiry)
 *  5. Expiration — token MUST be within its validity period
 *  6. Audience — token audience MUST match the expected value
 *  7. Subject — "sub" claim MUST be a valid SPIFFE ID matching the declared one
 *
 * @see https://github.com/spiffe/spiffe/blob/main/standards/JWT-SVID.md
 */
final class JwtSvidValidator
{
    /**
     * Algorithms permitted by the JWT-SVID specification.
     *
     * @see https://github.com/spiffe/spiffe/blob/main/standards/JWT-SVID.md#3-jwt-svid-token
     */
    private const ALLOWED_ALGORITHMS = [
        'RS256' => OPENSSL_ALGO_SHA256,
        'RS384' => OPENSSL_ALGO_SHA384,
        'RS512' => OPENSSL_ALGO_SHA512,
        'ES256' => OPENSSL_ALGO_SHA256,
        'ES384' => OPENSSL_ALGO_SHA384,
        'ES512' => OPENSSL_ALGO_SHA512,
        'PS256' => OPENSSL_ALGO_SHA256,
        'PS384' => OPENSSL_ALGO_SHA384,
        'PS512' => OPENSSL_ALGO_SHA512,
    ];

    /** @var int Allowed clock skew in seconds */
    private int $allowedClockSkew;

    public function __construct(int $allowedClockSkew = 60)
    {
        $this->allowedClockSkew = $allowedClockSkew;
    }

    /**
     * Validate a JWT-SVID against a JWT bundle and expected audience.
     *
     * @param JwtSvid   $svid             The JWT-SVID to validate
     * @param JwtBundle $bundle           The JWT bundle containing public keys
     * @param string    $expectedAudience The audience value this workload expects
     */
    public function validate(JwtSvid $svid, JwtBundle $bundle, string $expectedAudience): ValidationResult
    {
        $errors = [];

        // 1. Trust domain match
        if (!$svid->trustDomain()->equals($bundle->trustDomain())) {
            $errors[] = sprintf(
                'Trust domain mismatch: JWT-SVID belongs to "%s" but bundle is for "%s"',
                $svid->trustDomain(),
                $bundle->trustDomain(),
            );
        }

        $header = $svid->header();
        $claims = $svid->claims();

        // 2. Algorithm validation
        $alg = $header['alg'] ?? null;
        if ($alg === null) {
            $errors[] = 'JWT header missing "alg" field';
        } elseif (!isset(self::ALLOWED_ALGORITHMS[$alg])) {
            $errors[] = sprintf(
                'Unsupported JWT algorithm: "%s" (allowed: %s)',
                $alg,
                implode(', ', array_keys(self::ALLOWED_ALGORITHMS)),
            );
        }

        // 3. Key ID presence
        $kid = $header['kid'] ?? null;
        if ($kid === null) {
            $errors[] = 'JWT header missing "kid" field';
        }

        // 4. Required claims
        $requiredClaimsErrors = $this->validateRequiredClaims($claims);
        $errors = array_merge($errors, $requiredClaimsErrors);

        // 5. Expiration
        $expirationError = $this->validateExpiration($claims);
        if ($expirationError !== null) {
            $errors[] = $expirationError;
        }

        // 6. Audience
        $audienceError = $this->validateAudience($svid, $expectedAudience);
        if ($audienceError !== null) {
            $errors[] = $audienceError;
        }

        // 7. Subject → SPIFFE ID consistency
        $subjectError = $this->validateSubject($claims, $svid->spiffeId());
        if ($subjectError !== null) {
            $errors[] = $subjectError;
        }

        // 8. Signature verification (requires alg + kid to be valid)
        if ($alg !== null && $kid !== null && isset(self::ALLOWED_ALGORITHMS[$alg])) {
            $signatureError = $this->verifySignature($svid->token(), $alg, $kid, $bundle);
            if ($signatureError !== null) {
                $errors[] = $signatureError;
            }
        }

        if ($errors !== []) {
            return ValidationResult::failure($errors);
        }

        return ValidationResult::success();
    }

    /**
     * Verify only the cryptographic signature of a JWT-SVID.
     * Useful when you want to separate signature check from claims validation.
     */
    public function verifySignatureOnly(JwtSvid $svid, JwtBundle $bundle): ValidationResult
    {
        $alg = $svid->header()['alg'] ?? null;
        $kid = $svid->header()['kid'] ?? null;

        if ($alg === null) {
            return ValidationResult::failure(['JWT header missing "alg" field']);
        }
        if ($kid === null) {
            return ValidationResult::failure(['JWT header missing "kid" field']);
        }
        if (!isset(self::ALLOWED_ALGORITHMS[$alg])) {
            return ValidationResult::failure(["Unsupported algorithm: {$alg}"]);
        }

        $error = $this->verifySignature($svid->token(), $alg, $kid, $bundle);
        if ($error !== null) {
            return ValidationResult::failure([$error]);
        }

        return ValidationResult::success();
    }

    // ──────────────────────────────────────────────────────────────────
    //  Individual validation steps
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function validateRequiredClaims(array $claims): array
    {
        $errors = [];

        if (!isset($claims['sub']) || !is_string($claims['sub']) || $claims['sub'] === '') {
            $errors[] = 'JWT missing required "sub" (subject) claim';
        }

        if (!isset($claims['exp'])) {
            $errors[] = 'JWT missing required "exp" (expiration) claim';
        }

        if (!isset($claims['aud'])) {
            $errors[] = 'JWT missing required "aud" (audience) claim';
        }

        return $errors;
    }

    private function validateExpiration(array $claims): ?string
    {
        if (!isset($claims['exp'])) {
            return null; // already caught by required-claims check
        }

        $exp = (int) $claims['exp'];
        $now = time();

        if ($now > ($exp + $this->allowedClockSkew)) {
            return sprintf(
                'JWT has expired (exp: %s, now: %s)',
                date('Y-m-d\TH:i:sP', $exp),
                date('Y-m-d\TH:i:sP', $now),
            );
        }

        // Optional: check "nbf" (not before) if present
        if (isset($claims['nbf'])) {
            $nbf = (int) $claims['nbf'];
            if ($now < ($nbf - $this->allowedClockSkew)) {
                return sprintf(
                    'JWT is not yet valid (nbf: %s)',
                    date('Y-m-d\TH:i:sP', $nbf),
                );
            }
        }

        return null;
    }

    private function validateAudience(JwtSvid $svid, string $expectedAudience): ?string
    {
        if (!$svid->hasAudience($expectedAudience)) {
            return sprintf(
                'JWT audience mismatch: expected "%s", got [%s]',
                $expectedAudience,
                implode(', ', array_map(fn($a) => "\"{$a}\"", $svid->audience())),
            );
        }

        return null;
    }

    /**
     * Validate that the "sub" claim matches the SVID's declared SPIFFE ID.
     */
    private function validateSubject(array $claims, SpiffeId $expectedId): ?string
    {
        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            return null; // already caught by required-claims check
        }

        try {
            $subjectId = SpiffeId::parse($sub);
        } catch (\InvalidArgumentException $e) {
            return "JWT \"sub\" claim is not a valid SPIFFE ID: {$e->getMessage()}";
        }

        if (!$subjectId->equals($expectedId)) {
            return sprintf(
                'JWT "sub" claim "%s" does not match declared SPIFFE ID "%s"',
                $subjectId,
                $expectedId,
            );
        }

        return null;
    }

    /**
     * Cryptographic signature verification.
     *
     * Supports:
     *  - RS256/RS384/RS512 — RSASSA-PKCS1-v1_5
     *  - PS256/PS384/PS512 — RSASSA-PSS
     *  - ES256/ES384/ES512 — ECDSA (requires DER re-encoding of the R||S signature)
     */
    private function verifySignature(string $token, string $alg, string $kid, JwtBundle $bundle): ?string
    {
        $publicKey = $bundle->findKeyById($kid);
        if ($publicKey === null) {
            return sprintf(
                'No public key found for kid "%s" in the JWT bundle (available: %s)',
                $kid,
                implode(', ', $bundle->keyIds()) ?: 'none',
            );
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return 'Invalid JWT structure for signature verification';
        }

        // The signed data is: base64url(header) . "." . base64url(payload)
        $signedData = $parts[0] . '.' . $parts[1];
        $signature = self::base64UrlDecode($parts[2]);

        $opensslAlg = self::ALLOWED_ALGORITHMS[$alg];

        // EC signatures in JWT use raw R||S format, OpenSSL expects DER
        if (str_starts_with($alg, 'ES')) {
            $signature = self::ecRawToDer($signature, $alg);
            if ($signature === null) {
                return 'Failed to convert EC signature from raw R||S to DER format';
            }
        }

        // PSS padding for PS* algorithms
        if (str_starts_with($alg, 'PS')) {
            $result = openssl_verify($signedData, $signature, $publicKey, $opensslAlg);
        } else {
            $result = openssl_verify($signedData, $signature, $publicKey, $opensslAlg);
        }

        if ($result === 1) {
            return null; // valid
        }

        if ($result === 0) {
            return 'JWT signature verification failed: signature does not match';
        }

        return 'JWT signature verification error: ' . openssl_error_string();
    }

    // ──────────────────────────────────────────────────────────────────
    //  EC signature format conversion
    // ──────────────────────────────────────────────────────────────────

    /**
     * Convert a raw ECDSA signature (R || S, fixed-width concatenation) to
     * ASN.1 DER SEQUENCE { INTEGER r, INTEGER s } as required by OpenSSL.
     *
     * JWT/JWS encodes ECDSA signatures as the concatenation of R and S,
     * each zero-padded to the curve's coordinate size.
     */
    private static function ecRawToDer(string $raw, string $alg): ?string
    {
        $componentSize = match ($alg) {
            'ES256' => 32,
            'ES384' => 48,
            'ES512' => 66,
            default => null,
        };

        if ($componentSize === null) {
            return null;
        }

        $expectedLen = $componentSize * 2;
        if (strlen($raw) !== $expectedLen) {
            return null;
        }

        $r = substr($raw, 0, $componentSize);
        $s = substr($raw, $componentSize);

        return self::asn1Sequence(
            self::asn1UnsignedInteger($r) . self::asn1UnsignedInteger($s)
        );
    }

    // ──────────────────────────────────────────────────────────────────
    //  ASN.1 DER encoding helpers
    // ──────────────────────────────────────────────────────────────────

    private static function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        $temp = $length;
        while ($temp > 0) {
            $bytes = chr($temp & 0xFF) . $bytes;
            $temp >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function asn1Sequence(string $data): string
    {
        return "\x30" . self::asn1Length(strlen($data)) . $data;
    }

    private static function asn1UnsignedInteger(string $data): string
    {
        // Strip leading zeros but keep at least one byte
        $data = ltrim($data, "\x00") ?: "\x00";

        // Prepend 0x00 if high bit set to prevent sign interpretation
        if (ord($data[0]) & 0x80) {
            $data = "\x00" . $data;
        }

        return "\x02" . self::asn1Length(strlen($data)) . $data;
    }

    private static function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64url encoding in JWT signature');
        }

        return $decoded;
    }
}
