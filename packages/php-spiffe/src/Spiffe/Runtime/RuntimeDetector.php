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

        if (extension_loaded('openswoole') || extension_loaded('swoole')) {
            self::$instance = new OpenSwooleRuntime();
            return self::$instance;
        }

        if (extension_loaded('swow')) {
            self::$instance = new SwowRuntime();
            return self::$instance;
        }

        throw new \RuntimeException(
            'A coroutine runtime is required. Install OpenSwoole (ext-openswoole) or Swow (ext-swow).'
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

    public static function available(): string
    {
        if (extension_loaded('openswoole') || extension_loaded('swoole')) {
            return 'openswoole';
        }
        if (extension_loaded('swow')) {
            return 'swow';
        }
        return 'none';
    }
}
