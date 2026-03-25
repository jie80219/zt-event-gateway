<?php

declare(strict_types=1);

namespace Spiffe\Runtime;

/**
 * Auto-detects and instantiates the available coroutine runtime.
 *
 * Priority: Swoole > Swow (Swoole checked first because it has native HTTP/2).
 */
final class RuntimeDetector
{
    private static ?RuntimeInterface $instance = null;

    /**
     * Get or create the runtime singleton.
     */
    public static function detect(): RuntimeInterface
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::$instance = self::resolve();
        return self::$instance;
    }

    /**
     * Explicitly set the runtime (for testing or forced selection).
     */
    public static function use(RuntimeInterface $runtime): void
    {
        self::$instance = $runtime;
    }

    /**
     * Reset to auto-detection.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public static function hasSwoole(): bool
    {
        return extension_loaded('swoole') || extension_loaded('openswoole');
    }

    public static function hasSwow(): bool
    {
        return extension_loaded('swow');
    }

    public static function available(): string
    {
        if (self::hasSwoole()) {
            return 'swoole';
        }
        if (self::hasSwow()) {
            return 'swow';
        }
        return 'none';
    }

    private static function resolve(): RuntimeInterface
    {
        if (self::hasSwoole()) {
            return new SwooleRuntime();
        }

        if (self::hasSwow()) {
            return new SwowRuntime();
        }

        throw new \RuntimeException(
            'No coroutine runtime detected. Install ext-swoole (>=5.0) or ext-swow (>=1.2).'
        );
    }
}
