<?php
namespace AnserGateway\Util;

use Redis;

class RedisClient
{
    private static ?Redis $instance = null;
    private static string $signature = '';

    public static function get(): Redis
    {
        $host = self::env('LOAD_BALANCE_REDIS_HOST', 'redis');
        $port = (int) self::env('LOAD_BALANCE_REDIS_PORT', '6379');
        $db = (int) self::env('LOAD_BALANCE_REDIS_DB', '0');
        $timeout = (float) self::env('LOAD_BALANCE_REDIS_TIMEOUT', '2.5');
        $signature = sprintf('%s:%d/%d', $host, $port, $db);

        if (self::$instance === null || self::$signature !== $signature) {
            if (self::$instance !== null) {
                try {
                    self::$instance->close();
                } catch (\Throwable $e) {
                    // ignore close error
                }
            }

            self::$instance = new Redis();
            self::$instance->connect($host, $port, $timeout);
            self::$instance->select($db);
            self::$signature = $signature;
        }

        return self::$instance;
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
