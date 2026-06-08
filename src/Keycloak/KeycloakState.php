<?php

declare(strict_types=1);

namespace Keycloak;

/**
 * Assembled Keycloak identity state for a process. Returned by
 * KeycloakBootstrap::fromEnv(). Readable by health endpoints and
 * controllers that need the local token/validator.
 */
final class KeycloakState
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $issuer = '',
        public readonly string $realm = '',
        public readonly string $clientId = '',
        public readonly ?KeycloakClient $client = null,
        public readonly ?TokenProvider $tokenProvider = null,
        public readonly ?JwtValidator $jwtValidator = null,
        public readonly ?TokenCache $tokenCache = null,
        public readonly ?JwksCache $jwksCache = null,
    ) {}
}
