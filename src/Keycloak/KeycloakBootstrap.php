<?php

declare(strict_types=1);

namespace Keycloak;

use Keycloak\SharedMemory\KeycloakTableReader;
use Keycloak\SharedMemory\KeycloakTableSchema;
use Keycloak\SharedMemory\KeycloakTableStore;

/**
 * Bootstraps the Keycloak identity plane for a process (Gateway or Worker).
 *
 * Reads env, constructs KeycloakClient + TokenProvider + JwtValidator +
 * JwksCache + TokenCache, wires them into their process-wide registries,
 * and returns the assembled state bag.
 *
 * The watcher daemon (bin/keycloak-watcher.php) uses fromEnvForWatcher() to
 * build the writer-capable caches.
 */
final class KeycloakBootstrap
{
    /**
     * @param array<string, string> $env  key → value (getenv-style bag)
     */
    public static function fromEnv(array $env, bool $writable = false): KeycloakState
    {
        $enabled = ($env['KEYCLOAK_ENABLED'] ?? '1') !== '0';
        if (!$enabled) {
            return new KeycloakState(enabled: false);
        }

        $issuer       = self::requireEnv($env, 'KEYCLOAK_ISSUER');
        $tokenUri     = $env['KEYCLOAK_TOKEN_URI']   ?? rtrim($issuer, '/') . '/protocol/openid-connect/token';
        $jwksUri      = $env['KEYCLOAK_JWKS_URI']    ?? rtrim($issuer, '/') . '/protocol/openid-connect/certs';
        $clientId     = self::requireEnv($env, 'KEYCLOAK_CLIENT_ID');
        $clientSecret = self::requireEnv($env, 'KEYCLOAK_CLIENT_SECRET');
        $shmDir       = $env['KEYCLOAK_SHM_DIR']     ?? KeycloakTableSchema::DEFAULT_BASE_DIR;
        $refreshSkew  = (int) ($env['KEYCLOAK_TOKEN_REFRESH_SKEW'] ?? '30');
        $realm        = self::realmFromIssuer($issuer);

        if ($writable) {
            KeycloakTableSchema::createAll($shmDir);
        }

        $reader = new KeycloakTableReader($shmDir);
        $store  = $writable ? new KeycloakTableStore($shmDir) : null;

        $tokenCache = new TokenCache($reader, $store);
        $jwksCache  = new JwksCache($realm, $reader, $store);
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
            shmDir: $shmDir,
            client: $client,
            tokenProvider: $provider,
            jwtValidator: $validator,
            tokenCache: $tokenCache,
            jwksCache: $jwksCache,
            reader: $reader,
            store: $store,
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
