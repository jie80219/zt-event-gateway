<?php

declare(strict_types=1);

namespace Keycloak;

/**
 * Process-wide accessor for the current TokenProvider.
 */
final class TokenProviderRegistry
{
    private static ?TokenProvider $instance = null;

    private function __construct() {}

    public static function set(TokenProvider $provider): void
    {
        self::$instance = $provider;
    }

    public static function get(): TokenProvider
    {
        if (self::$instance === null) {
            throw new \RuntimeException('TokenProvider not initialised — call TokenProviderRegistry::set() at bootstrap');
        }
        return self::$instance;
    }

    public static function tryGet(): ?TokenProvider
    {
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
