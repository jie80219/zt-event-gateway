<?php

declare(strict_types=1);

namespace Keycloak;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/**
 * Validates Keycloak-issued JWT access tokens.
 *
 * Checks: RS256 signature against JWKS, iss = configured issuer, aud contains
 * the expected audience (this service's client_id), exp/nbf/iat sanity.
 *
 * The JWKS comes from JwksCache (shared memory; written by keycloak-watcher).
 */
final class JwtValidator
{
    public function __construct(
        private readonly JwksCache $jwksCache,
        private readonly string $expectedIssuer,
        private readonly int $leewaySeconds = 30,
    ) {
        JWT::$leeway = $this->leewaySeconds;
    }

    /**
     * Validate a JWT and return its decoded claims. Throws on any failure.
     *
     * @return array<string, mixed>
     */
    public function validate(string $jwt, string $expectedAudience): array
    {
        $keys = $this->jwksCache->readKeys();
        if ($keys === null || $keys === []) {
            throw new \RuntimeException('JWKS cache is empty — cannot verify JWT');
        }

        $keyMap = JWK::parseKeySet(['keys' => $keys]);

        try {
            $decoded = JWT::decode($jwt, $keyMap);
        } catch (\Throwable $e) {
            throw new \RuntimeException('JWT signature verification failed: ' . $e->getMessage(), 0, $e);
        }

        $claims = (array) $decoded;

        $iss = (string) ($claims['iss'] ?? '');
        if ($iss !== $this->expectedIssuer) {
            throw new \RuntimeException(sprintf('JWT iss mismatch: got %s, expected %s', $iss, $this->expectedIssuer));
        }

        $aud = $claims['aud'] ?? null;
        $audList = is_array($aud) ? array_map('strval', $aud) : [(string) $aud];
        if (!in_array($expectedAudience, $audList, true)) {
            throw new \RuntimeException(sprintf(
                'JWT aud does not include %s (got %s)',
                $expectedAudience,
                implode(',', $audList),
            ));
        }

        $exp = (int) ($claims['exp'] ?? 0);
        if ($exp > 0 && $exp < time() - $this->leewaySeconds) {
            throw new \RuntimeException('JWT expired');
        }

        return $claims;
    }
}
