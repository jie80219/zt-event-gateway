<?php

declare(strict_types=1);

namespace Keycloak;

/**
 * Process-wide accessor for the JwtValidator.
 */
final class JwtValidatorRegistry
{
    private static ?JwtValidator $instance = null;

    private function __construct() {}

    public static function set(JwtValidator $validator): void
    {
        self::$instance = $validator;
    }

    public static function get(): JwtValidator
    {
        if (self::$instance === null) {
            throw new \RuntimeException('JwtValidator not initialised — call JwtValidatorRegistry::set() at bootstrap');
        }
        return self::$instance;
    }

    public static function tryGet(): ?JwtValidator
    {
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
