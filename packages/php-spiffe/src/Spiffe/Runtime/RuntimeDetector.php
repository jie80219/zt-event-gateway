<?php

declare(strict_types=1);

namespace Spiffe\Runtime;

/**
 * Resolves the Swow coroutine runtime.
 */
final class RuntimeDetector
{
    private static ?RuntimeInterface $instance = null;

    public static function detect(): RuntimeInterface
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        if (!extension_loaded('swow')) {
            throw new \RuntimeException(
                'ext-swow is required but not loaded. Install Swow (>=1.2): https://github.com/swow/swow'
            );
        }

        self::$instance = new SwowRuntime();
        return self::$instance;
    }

    public static function use(RuntimeInterface $runtime): void
    {
        self::$instance = $runtime;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public static function available(): string
    {
        return extension_loaded('swow') ? 'swow' : 'none';
    }
}
