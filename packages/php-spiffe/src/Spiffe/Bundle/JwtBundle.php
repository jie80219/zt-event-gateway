<?php

declare(strict_types=1);

namespace Spiffe\Bundle;

use Spiffe\TrustDomain;

/**
 * JWT trust bundle for a single trust domain.
 *
 * Holds a JWKS (JSON Web Key Set) containing the public keys used to
 * verify JWT-SVIDs issued under this trust domain. Bundles are received
 * from the SPIRE Agent as JWKS-encoded JSON bytes.
 *
 * Supports RSA and EC key types (RS256, ES256, ES384 per SPIFFE spec).
 *
 * @see https://github.com/spiffe/spiffe/blob/main/standards/JWT-SVID.md
 */
final class JwtBundle
{
    private TrustDomain $trustDomain;

    /**
     * @var array<string, array{
     *     kty: string,
     *     kid: string,
     *     pem: string,
     *     key: \OpenSSLAsymmetricKey,
     *     alg?: string,
     * }> Keyed by kid (Key ID)
     */
    private array $keys;

    /**
     * @param array<string, array{kty: string, kid: string, pem: string, key: \OpenSSLAsymmetricKey, alg?: string}> $keys
     */
    private function __construct(TrustDomain $trustDomain, array $keys)
    {
        $this->trustDomain = $trustDomain;
        $this->keys = $keys;
    }

    /**
     * Parse a JWT bundle from the JWKS JSON bytes returned by the Workload API.
     *
     * @param string $jwksBytes Raw JWKS JSON (from JWTBundlesResponse)
     * @throws \RuntimeException if no usable keys are found
     */
    public static function fromJwks(TrustDomain $trustDomain, string $jwksBytes): self
    {
        $jwks = json_decode($jwksBytes, true, 16, JSON_THROW_ON_ERROR);

        if (!isset($jwks['keys']) || !is_array($jwks['keys'])) {
            throw new \RuntimeException(
                "Invalid JWKS for trust domain {$trustDomain}: missing 'keys' array"
            );
        }

        $keys = [];
        foreach ($jwks['keys'] as $jwk) {
            if (!is_array($jwk) || !isset($jwk['kty'], $jwk['kid'])) {
                continue;
            }

            $parsed = self::parseJwk($jwk);
            if ($parsed !== null) {
                $keys[$jwk['kid']] = $parsed;
            }
        }

        if ($keys === []) {
            throw new \RuntimeException(
                "No usable keys found in JWKS for trust domain {$trustDomain}"
            );
        }

        return new self($trustDomain, $keys);
    }

    public function trustDomain(): TrustDomain
    {
        return $this->trustDomain;
    }

    /**
     * Look up a public key by its Key ID.
     *
     * @return \OpenSSLAsymmetricKey|null
     */
    public function findKeyById(string $kid): ?\OpenSSLAsymmetricKey
    {
        return $this->keys[$kid]['key'] ?? null;
    }

    /**
     * Get the algorithm for a given Key ID.
     */
    public function getAlgorithm(string $kid): ?string
    {
        return $this->keys[$kid]['alg'] ?? null;
    }

    /**
     * Get the PEM-encoded public key for a given Key ID.
     */
    public function getKeyPem(string $kid): ?string
    {
        return $this->keys[$kid]['pem'] ?? null;
    }

    /**
     * All available Key IDs in this bundle.
     *
     * @return list<string>
     */
    public function keyIds(): array
    {
        return array_keys($this->keys);
    }

    public function hasKey(string $kid): bool
    {
        return isset($this->keys[$kid]);
    }

    // ──────────────────────────────────────────────────────────────────
    //  JWK → OpenSSL key parsing
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return array{kty: string, kid: string, pem: string, key: \OpenSSLAsymmetricKey, alg?: string}|null
     */
    private static function parseJwk(array $jwk): ?array
    {
        $kty = $jwk['kty'];

        $key = match ($kty) {
            'RSA'  => self::parseRsaJwk($jwk),
            'EC'   => self::parseEcJwk($jwk),
            default => null,
        };

        if ($key === null) {
            return null;
        }

        $pem = '';
        openssl_pkey_export_public($key, $pem);

        return [
            'kty' => $kty,
            'kid' => $jwk['kid'],
            'alg' => $jwk['alg'] ?? null,
            'pem' => $pem,
            'key' => $key,
        ];
    }

    /**
     * Parse an RSA JWK into an OpenSSL public key.
     */
    private static function parseRsaJwk(array $jwk): ?\OpenSSLAsymmetricKey
    {
        if (!isset($jwk['n'], $jwk['e'])) {
            return null;
        }

        $n = self::base64UrlDecode($jwk['n']);
        $e = self::base64UrlDecode($jwk['e']);

        // Build DER-encoded RSAPublicKey, then wrap in SubjectPublicKeyInfo
        $modulus = self::asn1UnsignedInteger($n);
        $exponent = self::asn1UnsignedInteger($e);
        $rsaPublicKey = self::asn1Sequence($modulus . $exponent);

        // SubjectPublicKeyInfo wrapping
        $algorithmIdentifier = self::asn1Sequence(
            // OID 1.2.840.113549.1.1.1 (rsaEncryption) + NULL
            "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"
        );
        $bitString = "\x00" . $rsaPublicKey; // prepend unused-bits byte
        $subjectPublicKeyInfo = self::asn1Sequence(
            $algorithmIdentifier . self::asn1BitString($bitString)
        );

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        $key = openssl_pkey_get_public($pem);
        return $key !== false ? $key : null;
    }

    /**
     * Parse an EC JWK into an OpenSSL public key.
     *
     * Supports P-256 (ES256) and P-384 (ES384) per the JWT-SVID specification.
     */
    private static function parseEcJwk(array $jwk): ?\OpenSSLAsymmetricKey
    {
        if (!isset($jwk['crv'], $jwk['x'], $jwk['y'])) {
            return null;
        }

        $x = self::base64UrlDecode($jwk['x']);
        $y = self::base64UrlDecode($jwk['y']);

        // OID for the named curve
        $curveOid = match ($jwk['crv']) {
            'P-256' => "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07",  // prime256v1
            'P-384' => "\x06\x05\x2b\x81\x04\x00\x22",               // secp384r1
            default => null,
        };

        if ($curveOid === null) {
            return null;
        }

        // Expected coordinate size per curve
        $coordSize = match ($jwk['crv']) {
            'P-256' => 32,
            'P-384' => 48,
            default => 0,
        };

        // Pad coordinates to the expected size
        $x = str_pad($x, $coordSize, "\x00", STR_PAD_LEFT);
        $y = str_pad($y, $coordSize, "\x00", STR_PAD_LEFT);

        // Uncompressed EC point: 0x04 || x || y
        $ecPoint = "\x04" . $x . $y;

        // AlgorithmIdentifier: OID ecPublicKey + curve OID
        $algorithmIdentifier = self::asn1Sequence(
            "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01" . $curveOid
        );

        // SubjectPublicKeyInfo
        $subjectPublicKeyInfo = self::asn1Sequence(
            $algorithmIdentifier . self::asn1BitString("\x00" . $ecPoint)
        );

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        $key = openssl_pkey_get_public($pem);
        return $key !== false ? $key : null;
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

    private static function asn1BitString(string $data): string
    {
        return "\x03" . self::asn1Length(strlen($data)) . $data;
    }

    /**
     * Encode a big-endian unsigned integer as an ASN.1 INTEGER.
     * Prepends 0x00 if the high bit is set (to prevent sign interpretation).
     */
    private static function asn1UnsignedInteger(string $data): string
    {
        // Strip leading zero bytes (but keep at least one)
        $data = ltrim($data, "\x00") ?: "\x00";

        // Prepend 0x00 if high bit set
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
            throw new \InvalidArgumentException('Invalid base64url encoding in JWK');
        }

        return $decoded;
    }
}
