<?php

declare(strict_types=1);

namespace Spiffe\Runtime;

/**
 * Resolves the coroutine runtime (OpenSwoole or Swow).
 * Priority: OpenSwoole > Swow.
 */
final class RuntimeDetector
{
    private static ?RuntimeInterface $instance = null;

    public static function detect(): RuntimeInterface
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        if (self::hasSwoole()) {
            self::$instance = new SwooleRuntime();
            return self::$instance;
        }

        if (self::hasSwow()) {
            self::$instance = new SwowRuntime();
            return self::$instance;
        }

        throw new \RuntimeException(
            'No coroutine runtime detected. Install ext-openswoole (>=22.0) or ext-swow (>=1.2).'
        );
    }

    public static function use(RuntimeInterface $runtime): void
    {
        self::$instance = $runtime;
    }

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
}
