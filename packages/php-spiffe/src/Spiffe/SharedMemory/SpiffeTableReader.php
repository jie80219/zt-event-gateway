<?php

declare(strict_types=1);

namespace Spiffe\SharedMemory;

/**
 * Reader-side: reads SPIFFE credentials from the shared filesystem store
 * with seqlock-style consistency via the version counter in meta.json.
 *
 * Designed for worker processes — lightweight, no gRPC or coroutine dependency.
 *
 * Seqlock read protocol:
 *   1. Read meta.json version → if odd, retry (writer is mid-update)
 *   2. Read data file(s)
 *   3. Read meta.json version again → if changed, retry
 *   4. Return data
 */
final class SpiffeTableReader
{
    private const MAX_SPIN = 200;
    private const SPIN_SLEEP_US = 500;

    private string $baseDir;

    public function __construct(string $baseDir = SpiffeTableSchema::DEFAULT_BASE_DIR)
    {
        $this->baseDir = rtrim($baseDir, '/');
    }

    // ── X.509 credential reads ───────────────────────────────────

    /**
     * @return array{spiffe_id:string, trust_domain:string, cert_pem:string, key_pem:string, bundle_pem:string, hint:string, updated_at:int}|null
     */
    public function readX509Primary(): ?array
    {
        return $this->readX509Slot(0);
    }

    public function readX509Slot(int $slot): ?array
    {
        $path = "{$this->baseDir}/x509/{$slot}.json";
        return $this->consistentRead(fn() => $this->readJsonFile($path));
    }

    /**
     * @return list<array{spiffe_id:string, trust_domain:string, cert_pem:string, key_pem:string, bundle_pem:string, hint:string, updated_at:int}>
     */
    public function readAllX509(): array
    {
        return $this->consistentRead(function () {
            $meta = $this->readMetaRaw();
            $count = $meta['x509_count'] ?? 0;
            $svids = [];
            for ($i = 0; $i < $count; $i++) {
                $row = $this->readJsonFile("{$this->baseDir}/x509/{$i}.json");
                if ($row !== null) {
                    $svids[] = $row;
                }
            }
            return $svids;
        }) ?? [];
    }

    public function readX509ByHint(string $hint): ?array
    {
        foreach ($this->readAllX509() as $svid) {
            if ($svid['hint'] === $hint) {
                return $svid;
            }
        }
        return null;
    }

    // ── JWT bundle reads ─────────────────────────────────────────

    /**
     * @return array{trust_domain:string, jwks_json:string, updated_at:int}|null
     */
    public function readJwtBundle(string $trustDomain): ?array
    {
        $safeName = preg_replace('/[^a-z0-9._-]/', '_', strtolower($trustDomain));
        $path = "{$this->baseDir}/jwt/{$safeName}.json";
        return $this->consistentRead(fn() => $this->readJsonFile($path));
    }

    /**
     * @return array<string, array{trust_domain:string, jwks_json:string, updated_at:int}>
     */
    public function readAllJwtBundles(): array
    {
        return $this->consistentRead(function () {
            $bundles = [];
            foreach (glob("{$this->baseDir}/jwt/*.json") as $file) {
                $row = $this->readJsonFile($file);
                if ($row !== null && isset($row['trust_domain'])) {
                    $bundles[$row['trust_domain']] = $row;
                }
            }
            return $bundles;
        }) ?? [];
    }

    // ── Metadata / health ────────────────────────────────────────

    public function readMeta(): array
    {
        return $this->readMetaRaw();
    }

    public function isReady(): bool
    {
        $meta = $this->readMetaRaw();
        return $meta['x509_state'] === 'ready' && $meta['jwt_state'] === 'ready';
    }

    public function hasCredentials(): bool
    {
        return ($this->readMetaRaw()['x509_count'] ?? 0) > 0;
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

    /**
     * Block until credentials are available.
     *
     * @param float $timeout Seconds (0 = indefinite)
     * @throws \RuntimeException on timeout
     */
    public function awaitReady(float $timeout = 30.0): void
    {
        $deadline = $timeout > 0 ? microtime(true) + $timeout : PHP_FLOAT_MAX;
        $sleep = 10_000; // 10ms

        while (!$this->hasCredentials()) {
            if (microtime(true) > $deadline) {
                $meta = $this->readMetaRaw();
                throw new \RuntimeException(sprintf(
                    'Timed out waiting for SPIFFE credentials (x509_state: %s, jwt_state: %s, error: %s)',
                    $meta['x509_state'] ?? 'unknown',
                    $meta['jwt_state'] ?? 'unknown',
                    $meta['error'] ?? 'none',
                ));
            }
            usleep($sleep);
            $sleep = min($sleep * 2, 1_000_000);
        }
    }

    // ── Internal ─────────────────────────────────────────────────

    private function readMetaRaw(): array
    {
        $path = "{$this->baseDir}/meta.json";
        if (!file_exists($path)) {
            return [
                'version' => 0, 'x509_state' => 'idle', 'jwt_state' => 'idle',
                'x509_count' => 0, 'jwt_count' => 0, 'updated_at' => 0, 'error' => '',
            ];
        }
        $data = @file_get_contents($path);
        if ($data === false) {
            return ['version' => 0, 'x509_state' => 'idle', 'jwt_state' => 'idle',
                'x509_count' => 0, 'jwt_count' => 0, 'updated_at' => 0, 'error' => ''];
        }
        return json_decode($data, true, 8) ?? ['version' => 0];
    }

    private function readJsonFile(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }
        $data = @file_get_contents($path);
        if ($data === false) {
            return null;
        }
        return json_decode($data, true, 16) ?: null;
    }

    /**
     * Seqlock consistent read: ensures the version didn't change during the read.
     *
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
