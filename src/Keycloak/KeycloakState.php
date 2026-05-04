<?php

declare(strict_types=1);

namespace Keycloak;

use Keycloak\SharedMemory\KeycloakTableReader;
use Keycloak\SharedMemory\KeycloakTableStore;

/**
 * Assembled Keycloak identity state for a process. Returned by
 * KeycloakBootstrap::fromEnv(). Readable by rotation watchers, health
 * endpoints, and controllers that need the local token/validator.
 */
final class KeycloakState
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $issuer = '',
        public readonly string $realm = '',
        public readonly string $clientId = '',
        public readonly string $shmDir = '',
        public readonly ?KeycloakClient $client = null,
        public readonly ?TokenProvider $tokenProvider = null,
        public readonly ?JwtValidator $jwtValidator = null,
        public readonly ?TokenCache $tokenCache = null,
        public readonly ?JwksCache $jwksCache = null,
        public readonly ?KeycloakTableReader $reader = null,
        public readonly ?KeycloakTableStore $store = null,
    ) {}
}
