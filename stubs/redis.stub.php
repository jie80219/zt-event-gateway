<?php

// IDE stub for the ext-redis class when the extension is not installed.
if (!class_exists('Redis')) {
    class Redis
    {
        public function connect(string $host, int $port = 6379, float $timeout = 0.0): bool
        {
            return false;
        }

        public function select(int $db): bool
        {
            return false;
        }

        public function get(string $key)
        {
            return null;
        }

        public function incr(string $key)
        {
            return 0;
        }

        public function decr(string $key)
        {
            return 0;
        }

        public function keys(string $pattern): array
        {
            return [];
        }

        public function ttl(string $key): int
        {
            return -2;
        }

        public function hGetAll(string $key): array
        {
            return [];
        }

        public function multi()
        {
            return $this;
        }

        public function del(string ...$keys)
        {
            return 0;
        }

        public function hMSet(string $key, array $members): bool
        {
            return false;
        }

        public function exec(): array
        {
            return [];
        }

        public function close(): bool
        {
            return true;
        }
    }
}
