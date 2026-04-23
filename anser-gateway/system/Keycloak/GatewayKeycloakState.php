<?php

namespace AnserGateway\Keycloak;

use Keycloak\TokenProvider;

/**
 * Static registry holding per-worker Keycloak state for the Gateway.
 *
 * Set once during OpenSwoole onWorkerStart (after KeycloakBootstrap has
 * wired the process-wide registries), read by Order Controller to mint
 * the `authorization` block on outbound RabbitMQ envelopes.
 */
final class GatewayKeycloakState
{
    private static bool $enabled = true;
    private static string $clientId = '';
    private static string $issuer = '';
    private static ?TokenProvider $tokenProvider = null;

    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    public static function setClientId(string $id): void
    {
        self::$clientId = $id;
    }

    public static function getClientId(): string
    {
        return self::$clientId;
    }

    public static function setIssuer(string $issuer): void
    {
        self::$issuer = $issuer;
    }

    public static function getIssuer(): string
    {
        return self::$issuer;
    }

    public static function setTokenProvider(?TokenProvider $provider): void
    {
        self::$tokenProvider = $provider;
    }

    public static function getTokenProvider(): ?TokenProvider
    {
        return self::$tokenProvider;
    }
}
