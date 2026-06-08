<?php

declare(strict_types=1);

namespace Keycloak;

/**
 * Bootstraps the Keycloak identity plane for a process (Gateway or Worker).
 *
 * Reads env, constructs KeycloakClient + TokenProvider + JwtValidator
 * + in-process JwksCache + TokenCache, wires them into their process-wide
 * registries, and returns the assembled state bag. No SHM watcher — each
 * worker fetches tokens and JWKS directly from Keycloak.
 */
final class KeycloakBootstrap
{
    /**
     * @param array<string, string> $env  key → value (getenv-style bag)
     */
    public static function fromEnv(array $env): KeycloakState
    {
        $enabled = ($env['KEYCLOAK_ENABLED'] ?? '1') !== '0';
        if (!$enabled) {
            return new KeycloakState(enabled: false);
        }

        $issuer       = self::requireEnv($env, 'KEYCLOAK_ISSUER');
        $tokenUri     = self::optionalEnv($env, 'KEYCLOAK_TOKEN_URI', rtrim($issuer, '/') . '/protocol/openid-connect/token');
        $jwksUri      = self::optionalEnv($env, 'KEYCLOAK_JWKS_URI',  rtrim($issuer, '/') . '/protocol/openid-connect/certs');
        $clientId     = self::requireEnv($env, 'KEYCLOAK_CLIENT_ID');
        $clientSecret = self::requireEnv($env, 'KEYCLOAK_CLIENT_SECRET');
        $refreshSkew  = (int) ($env['KEYCLOAK_TOKEN_REFRESH_SKEW'] ?? '30');
        $realm        = self::realmFromIssuer($issuer);

        $tokenCache = new TokenCache();
        $jwksCache  = new JwksCache($realm, $jwksUri);
        $client     = new KeycloakClient($tokenUri, $jwksUri, $clientId, $clientSecret);
        $provider   = new TokenProvider($client, $tokenCache, $issuer, $refreshSkew);
        $validator  = new JwtValidator($jwksCache, $issuer);

        TokenProviderRegistry::set($provider);
        JwtValidatorRegistry::set($validator);

        return new KeycloakState(
            enabled: true,
            issuer: $issuer,
            realm: $realm,
            clientId: $clientId,
            client: $client,
            tokenProvider: $provider,
            jwtValidator: $validator,
            tokenCache: $tokenCache,
            jwksCache: $jwksCache,
        );
    }

    private static function requireEnv(array $env, string $key): string
    {
        $v = $env[$key] ?? '';
        if (!is_string($v) || $v === '') {
            throw new \RuntimeException("Required env var {$key} is not set");
        }
        return $v;
    }

    private static function optionalEnv(array $env, string $key, string $default): string
    {
        $v = $env[$key] ?? '';
        return is_string($v) && $v !== '' ? $v : $default;
    }

    /**
     * Derive the realm name from the issuer URL, e.g.
     *   http://keycloak:8080/realms/zt  →  zt
     */
    public static function realmFromIssuer(string $issuer): string
    {
        $path = parse_url($issuer, PHP_URL_PATH) ?: '';
        $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn($s) => $s !== ''));
        $idx = array_search('realms', $parts, true);
        if ($idx !== false && isset($parts[$idx + 1])) {
            return $parts[$idx + 1];
        }
        return end($parts) ?: 'master';
    }
}
