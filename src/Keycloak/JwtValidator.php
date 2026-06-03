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
    /**
     * Memoized parsed key set, keyed by a hash of the raw JWKS read from SHM.
     * JWK::parseKeySet() is pure CPU (decode each JWK into an OpenSSL key) and
     * was re-run on every validate(); the key material only changes on
     * rotation, so we re-parse only when the JWKS content hash changes.
     *
     * @var array{hash: string|null, map: array<string, mixed>|null}
     */
    private array $keyMapMemo = ['hash' => null, 'map' => null];

    public function __construct(
        private readonly JwksCache $jwksCache,
        private readonly string $expectedIssuer,
        private readonly int $leewaySeconds = 30,
    ) {
        JWT::$leeway = $this->leewaySeconds;
    }

    /**
     * Parse the JWKS into a key map, reusing the last result while the JWKS
     * content is unchanged. Behaviour-preserving: the same keys are used to
     * verify every signature; only redundant re-parsing is skipped, and any
     * rotation (content change) invalidates the memo.
     *
     * @param  array<int, array<string, mixed>>  $keys
     * @return array<string, mixed>
     */
    private function parseKeySetMemoized(array $keys): array
    {
        $hash = hash('sha256', (string) json_encode($keys));
        if ($this->keyMapMemo['hash'] === $hash && $this->keyMapMemo['map'] !== null) {
            return $this->keyMapMemo['map'];
        }
        $map = JWK::parseKeySet(['keys' => $keys]);
        $this->keyMapMemo = ['hash' => $hash, 'map' => $map];
        return $map;
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

        $keyMap = $this->parseKeySetMemoized($keys);

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
