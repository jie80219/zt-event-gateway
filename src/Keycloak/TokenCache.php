<?php

declare(strict_types=1);

namespace Keycloak;

/**
 * In-process cache for this service's current Client Credentials token.
 * Each worker process owns its own cache — no cross-process sharing.
 */
final class TokenCache
{
    /** @var array{access_token:string, token_type:string, expires_at:int, client_id:string, issuer:string, updated_at:int}|null */
    private ?array $token = null;

    /**
     * @return array{access_token:string, token_type:string, expires_at:int, client_id:string, issuer:string, updated_at:int}|null
     */
    public function read(): ?array
    {
        return $this->token;
    }

    /**
     * @param array{access_token:string, token_type:string, expires_at:int, client_id:string, issuer:string} $token
     */
    public function write(array $token): void
    {
        $this->token = $token + ['updated_at' => time()];
    }

    public function needsRefresh(int $skewSeconds): bool
    {
        if ($this->token === null) {
            return true;
        }
        return ($this->token['expires_at'] - $skewSeconds) <= time();
    }
}
