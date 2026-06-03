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
    /**
     * Per-process hot memo of the last valid token record. Collapses the N
     * SHM reads per saga (one per outbound hop via KeycloakBearerFilter /
     * MessageBus::publish) down to a single in-memory check while the token
     * is still valid. Refreshed lazily from SHM (or, as a last resort, the
     * network) once it drops inside the skew window. Same source + TTL bound
     * as the SHM cache, so a rotated/expired token is never served.
     *
     * @var array{access_token:string, expires_at:int, ...}|null
     */
    private ?array $memo = null;

    public function __construct(
        private readonly KeycloakClient $client,
        private readonly TokenCache $cache,
        private readonly string $issuer,
        private readonly int $refreshSkewSeconds = 30,
    ) {}

    public function getAccessToken(): string
    {
        $now = time();

        // 1) Hot path: in-process memo, valid until exp - skew.
        if ($this->memo !== null && ($this->memo['expires_at'] - $this->refreshSkewSeconds) > $now) {
            return $this->memo['access_token'];
        }

        // 2) Warm path: SHM cache kept fresh by the keycloak-watcher daemon.
        $cached = $this->cache->read();
        if ($cached !== null && ($cached['expires_at'] - $this->refreshSkewSeconds) > $now) {
            $this->memo = $cached;
            return $cached['access_token'];
        }

        // 3) Cold path: synchronous network fetch. This blocks the request and
        // under load can stall the whole consumer, so it is gated. Set
        // KEYCLOAK_SYNC_FETCH_FALLBACK=0 to fail-fast and rely solely on the
        // warmed watcher (no validation is skipped either way — downstream
        // still verifies iss/sig/aud/exp).
        if (getenv('KEYCLOAK_SYNC_FETCH_FALLBACK') === '0') {
            throw new \RuntimeException(
                'Keycloak access token unavailable from cache and '
                . 'KEYCLOAK_SYNC_FETCH_FALLBACK=0 disables synchronous fetch'
            );
        }

        $fresh = $this->refresh();
        $this->memo = $fresh;
        return $fresh['access_token'];
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
