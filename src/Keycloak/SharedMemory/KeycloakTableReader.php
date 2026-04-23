<?php

declare(strict_types=1);

namespace Keycloak\SharedMemory;

/**
 * Reader-side for Keycloak token + JWKS cache.
 *
 * Seqlock protocol: read meta version → if odd, retry; read data; re-verify version.
 */
final class KeycloakTableReader
{
    private const MAX_SPIN = 200;
    private const SPIN_SLEEP_US = 500;

    private string $baseDir;

    public function __construct(string $baseDir = KeycloakTableSchema::DEFAULT_BASE_DIR)
    {
        $this->baseDir = rtrim($baseDir, '/');
    }

    /**
     * @return array{access_token:string, token_type:string, expires_at:int, client_id:string, issuer:string, updated_at:int}|null
     */
    public function readTokenPrimary(): ?array
    {
        $path = "{$this->baseDir}/token/primary.json";
        return $this->consistentRead(fn() => $this->readJsonFile($path));
    }

    /**
     * @return array{realm:string, issuer:string, jwks_json:string, updated_at:int}|null
     */
    public function readJwks(string $realm): ?array
    {
        $safeName = preg_replace('/[^a-z0-9._-]/', '_', strtolower($realm));
        $path = "{$this->baseDir}/jwks/{$safeName}.json";
        return $this->consistentRead(fn() => $this->readJsonFile($path));
    }

    public function readMeta(): array
    {
        return $this->readMetaRaw();
    }

    public function isReady(): bool
    {
        $meta = $this->readMetaRaw();
        return $meta['token_state'] === 'ready' && $meta['jwks_state'] === 'ready';
    }

    public function hasToken(): bool
    {
        return ($this->readMetaRaw()['token_count'] ?? 0) > 0;
    }

    public function hasJwks(): bool
    {
        return ($this->readMetaRaw()['jwks_count'] ?? 0) > 0;
    }

    public function version(): int
    {
        return $this->readMetaRaw()['version'] ?? 0;
    }

    public function secondsSinceLastUpdate(): int
    {
        $updatedAt = $this->readMetaRaw()['updated_at'] ?? 0;
        return $updatedAt > 0 ? time() - $updatedAt : -1;
    }

    public function isStale(int $maxAgeSeconds): bool
    {
        $elapsed = $this->secondsSinceLastUpdate();
        return $elapsed < 0 || $elapsed > $maxAgeSeconds;
    }

    /**
     * Block-poll meta version; invoke $onChange on advance. Coroutine-friendly
     * — pass a cooperative sleeper (e.g. Swoole\Coroutine::sleep).
     *
     * @param callable(int $newVersion, int $oldVersion): void $onChange
     * @param callable(): bool|null                            $running
     * @param callable(float): void|null                       $sleeper
     */
    public function watchVersion(
        callable $onChange,
        float $pollInterval = 0.5,
        ?callable $running = null,
        ?callable $sleeper = null,
    ): void {
        $last = $this->version();
        $sleep = $sleeper ?? static fn(float $s) => usleep((int) max(1_000, $s * 1_000_000));

        while ($running === null || $running() !== false) {
            $current = $this->version();
            if ($current !== $last && $current > 0) {
                try {
                    $onChange($current, $last);
                } catch (\Throwable) {
                }
                $last = $current;
            }
            $sleep($pollInterval);
        }
    }

    public function awaitReady(float $timeout = 30.0): void
    {
        $deadline = $timeout > 0 ? microtime(true) + $timeout : PHP_FLOAT_MAX;
        $sleep = 10_000;

        while (!$this->hasToken()) {
            if (microtime(true) > $deadline) {
                $meta = $this->readMetaRaw();
                throw new \RuntimeException(sprintf(
                    'Timed out waiting for Keycloak token (token_state: %s, jwks_state: %s, error: %s)',
                    $meta['token_state'] ?? 'unknown',
                    $meta['jwks_state'] ?? 'unknown',
                    $meta['error'] ?? 'none',
                ));
            }
            usleep($sleep);
            $sleep = min($sleep * 2, 1_000_000);
        }
    }

    private function readMetaRaw(): array
    {
        $defaults = [
            'version' => 0, 'token_state' => 'idle', 'jwks_state' => 'idle',
            'token_count' => 0, 'jwks_count' => 0, 'updated_at' => 0, 'error' => '',
        ];

        $data = $this->readLocked("{$this->baseDir}/meta.json");
        if ($data === null) {
            return $defaults;
        }

        $decoded = json_decode($data, true, 8);
        return is_array($decoded) ? $decoded + $defaults : $defaults;
    }

    private function readJsonFile(string $path): ?array
    {
        $data = $this->readLocked($path);
        if ($data === null) {
            return null;
        }
        return json_decode($data, true, 16) ?: null;
    }

    private function readLocked(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }

        try {
            @flock($fh, LOCK_SH);
            $data = @stream_get_contents($fh);
            @flock($fh, LOCK_UN);
        } finally {
            @fclose($fh);
        }

        return $data === false ? null : $data;
    }

    /**
     * @template T
     * @param callable(): T $readFn
     * @return T|null
     */
    private function consistentRead(callable $readFn): mixed
    {
        for ($spin = 0; $spin < self::MAX_SPIN; $spin++) {
            $v1 = $this->readMetaRaw()['version'] ?? 0;

            if ($v1 & 1) {
                usleep(self::SPIN_SLEEP_US);
                continue;
            }

            $result = $readFn();

            $v2 = $this->readMetaRaw()['version'] ?? 0;
            if ($v1 === $v2) {
                return $result;
            }

            usleep(self::SPIN_SLEEP_US);
        }

        return null;
    }
}
