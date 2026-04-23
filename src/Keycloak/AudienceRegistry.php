<?php

declare(strict_types=1);

namespace Keycloak;

/**
 * Maps downstream service URLs → the Keycloak client_id to request as the
 * `aud` claim on the outgoing access token.
 *
 * When a service calls another service via Anser, the KeycloakBearerFilter
 * consults this registry to pick the right audience for the bearer token.
 */
final class AudienceRegistry
{
    /** @var array<string, string> url → client_id */
    private static array $map = [];

    private function __construct() {}

    public static function register(string $url, string $clientId): void
    {
        self::$map[rtrim($url, '/')] = $clientId;
    }

    public static function resolve(string $url): ?string
    {
        $url = rtrim($url, '/');
        if (isset(self::$map[$url])) {
            return self::$map[$url];
        }

        foreach (self::$map as $prefix => $clientId) {
            if (str_starts_with($url, $prefix)) {
                return $clientId;
            }
        }
        return null;
    }

    public static function reset(): void
    {
        self::$map = [];
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::$map;
    }
}
