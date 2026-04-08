<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\Spiffe;

/**
 * Static registry mapping service base URLs to SPIFFE IDs.
 *
 * When the Anser SpiffeLsvidFilter extends the LSVID chain for an
 * outgoing HTTP call, it needs to know the target service's SPIFFE ID
 * (the `aud` claim). Since ActionInterface doesn't expose `serviceName`,
 * we match via the base URL returned by `getRequestSetting()->url`.
 *
 * Example wiring in bin/worker.php:
 *
 *   SpiffeAudienceRegistry::register(
 *       'http://host.docker.internal:8082',
 *       'spiffe://zt.local/order-service',
 *   );
 */
final class SpiffeAudienceRegistry
{
    /** @var array<string, string> baseUrl => SPIFFE ID */
    private static array $map = [];

    private static string $fallback = '';

    public static function register(string $baseUrl, string $spiffeId): void
    {
        self::$map[rtrim($baseUrl, '/')] = $spiffeId;
    }

    /**
     * Set a fallback SPIFFE ID used when no URL match is found.
     */
    public static function setFallback(string $spiffeId): void
    {
        self::$fallback = $spiffeId;
    }

    /**
     * Resolve the target SPIFFE ID from a request URL.
     *
     * Matches by checking if the URL starts with any registered base URL.
     */
    public static function resolve(string $url): ?string
    {
        $normalized = rtrim($url, '/');

        // Exact match first.
        if (isset(self::$map[$normalized])) {
            return self::$map[$normalized];
        }

        // Prefix match (base_url is a prefix of the full request URL).
        foreach (self::$map as $baseUrl => $spiffeId) {
            if (str_starts_with($normalized, $baseUrl)) {
                return $spiffeId;
            }
        }

        return self::$fallback !== '' ? self::$fallback : null;
    }
}
