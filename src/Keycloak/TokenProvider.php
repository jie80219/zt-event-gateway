<?php

declare(strict_types=1);

namespace Keycloak;

/**
 * Provides the current service-account access token.
 *
 * Read-path (Gateway/Worker request-handling coroutines):
 *   - Reads from TokenCache (shared memory, refreshed by the watcher daemon).
 *   - If the cache is empty or stale, falls back to synchronous fetch via
 *     KeycloakClient — this should only happen at cold-start.
 *
 * Write-path (keycloak-watcher daemon):
 *   - Calls refresh() on a timer to keep TokenCache warm before expiry.
 */
final class TokenProvider
{
    public function __construct(
        private readonly KeycloakClient $client,
        private readonly TokenCache $cache,
        private readonly string $issuer,
        private readonly int $refreshSkewSeconds = 30,
    ) {}

    public function getAccessToken(): string
    {
        $cached = $this->cache->read();
        if ($cached !== null && ($cached['expires_at'] - $this->refreshSkewSeconds) > time()) {
            return $cached['access_token'];
        }
        return $this->refresh()['access_token'];
    }

    public function getClientId(): string
    {
        return $this->client->getClientId();
    }

    public function getIssuer(): string
    {
        return $this->issuer;
    }

    /**
     * @return array{access_token:string, token_type:string, expires_at:int, client_id:string, issuer:string, updated_at:int}
     */
    public function refresh(): array
    {
        $fresh = $this->client->fetchClientCredentialsToken();
        $record = [
            'access_token' => $fresh['access_token'],
            'token_type'   => $fresh['token_type'],
            'expires_at'   => $fresh['expires_at'],
            'client_id'    => $this->client->getClientId(),
            'issuer'       => $this->issuer,
        ];
        $this->cache->write($record);
        return $record + ['updated_at' => time()];
    }

    public function needsRefresh(): bool
    {
        return $this->cache->needsRefresh($this->refreshSkewSeconds);
    }
}
