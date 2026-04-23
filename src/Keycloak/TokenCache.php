<?php

declare(strict_types=1);

namespace Keycloak;

use Keycloak\SharedMemory\KeycloakTableReader;
use Keycloak\SharedMemory\KeycloakTableStore;

/**
 * Cross-process cache for this service's current Client Credentials token.
 */
final class TokenCache
{
    public function __construct(
        private readonly KeycloakTableReader $reader,
        private readonly ?KeycloakTableStore $store = null,
    ) {}

    /**
     * @return array{access_token:string, token_type:string, expires_at:int, client_id:string, issuer:string, updated_at:int}|null
     */
    public function read(): ?array
    {
        return $this->reader->readTokenPrimary();
    }

    /**
     * @param array{access_token:string, token_type:string, expires_at:int, client_id:string, issuer:string} $token
     */
    public function write(array $token): void
    {
        if ($this->store === null) {
            throw new \RuntimeException('TokenCache is read-only (no store configured)');
        }
        $this->store->publishToken($token);
        $this->store->updateTokenState('ready');
    }

    public function needsRefresh(int $skewSeconds): bool
    {
        $token = $this->read();
        if ($token === null) {
            return true;
        }
        return ($token['expires_at'] - $skewSeconds) <= time();
    }
}
