<?php

declare(strict_types=1);

namespace Spiffe\SharedMemory;

use Swoole\Table;

/**
 * Reader-side adapter: reads SPIFFE credentials from Swoole Table shared
 * memory with seqlock consistency guarantees.
 *
 * Designed for worker processes that do NOT run the watcher. This class
 * is lightweight — no gRPC client, no coroutine, no Source dependency.
 * Just shared memory reads.
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │                    Multi-Process Architecture                        │
 * │                                                                      │
 * │  ┌─────────────┐                    ┌─────────────┐                 │
 * │  │  Watcher     │                    │  Worker #1  │                 │
 * │  │  Process     │   Swoole Table     │  Process    │                 │
 * │  │              │   (shared memory)  │             │                 │
 * │  │ X509Source ──┼──▶ spiffe_x509  ──▶│ TableReader │                 │
 * │  │ JwtSource  ──┼──▶ spiffe_jwt   ──▶│             │                 │
 * │  │              │   spiffe_meta      │             │                 │
 * │  └─────────────┘                    └─────────────┘                 │
 * │                                      ┌─────────────┐                │
 * │                                      │  Worker #2  │                │
 * │                                      │  Process    │                │
 * │                                      │ TableReader │                │
 * │                                      └─────────────┘                │
 * │                                            ⋮                        │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * Seqlock read protocol:
 *
 *   1. Read version → if odd, spin-wait (writer is mid-update)
 *   2. Read all data fields
 *   3. Read version again → if changed, goto 1
 *   4. Return data
 */
final class SpiffeTableReader
{
    /** Maximum spin-wait iterations before giving up. */
    private const MAX_SPIN = 1000;

    /** Microseconds to sleep between spin iterations. */
    private const SPIN_SLEEP_US = 100;

    private Table $meta;
    private Table $x509;
    private Table $jwt;

    public function __construct(Table $meta, Table $x509, Table $jwt)
    {
        $this->meta = $meta;
        $this->x509 = $x509;
        $this->jwt = $jwt;
    }

    /**
     * @param array{meta: Table, x509: Table, jwt: Table} $tables
     */
    public static function fromTables(array $tables): self
    {
        return new self($tables['meta'], $tables['x509'], $tables['jwt']);
    }

    // ══════════════════════════════════════════════════════════════════
    //  X.509 credential reads
    // ══════════════════════════════════════════════════════════════════

    /**
     * Read the primary (slot 0) X.509 SVID PEM material.
     *
     * Returns null if no credentials have been published yet.
     *
     * @return array{
     *     spiffe_id: string,
     *     trust_domain: string,
     *     cert_pem: string,
     *     key_pem: string,
     *     bundle_pem: string,
     *     hint: string,
     *     updated_at: int,
     * }|null
     */
    public function readX509Primary(): ?array
    {
        return $this->readX509Slot(0);
    }

    /**
     * Read a specific X.509 SVID slot with seqlock consistency.
     *
     * @return array{
     *     spiffe_id: string,
     *     trust_domain: string,
     *     cert_pem: string,
     *     key_pem: string,
     *     bundle_pem: string,
     *     hint: string,
     *     updated_at: int,
     * }|null
     */
    public function readX509Slot(int $slot): ?array
    {
        $key = (string) $slot;

        for ($spin = 0; $spin < self::MAX_SPIN; $spin++) {
            $v1 = $this->meta->get('global', 'version');

            // Odd version → writer is in the middle of an update
            if ($v1 & 1) {
                usleep(self::SPIN_SLEEP_US);
                continue;
            }

            $row = $this->x509->get($key);
            if ($row === false) {
                return null;
            }

            $v2 = $this->meta->get('global', 'version');

            // Version unchanged → consistent snapshot
            if ($v1 === $v2) {
                unset($row['version']);
                return $row;
            }

            // Version changed → writer was active, retry
            usleep(self::SPIN_SLEEP_US);
        }

        // Exceeded max spins — return best-effort or null
        return null;
    }

    /**
     * Read all X.509 SVID slots with seqlock consistency.
     *
     * Returns a consistent snapshot of ALL slots within a single version window.
     *
     * @return list<array{
     *     spiffe_id: string,
     *     trust_domain: string,
     *     cert_pem: string,
     *     key_pem: string,
     *     bundle_pem: string,
     *     hint: string,
     *     updated_at: int,
     * }>
     */
    public function readAllX509(): array
    {
        for ($spin = 0; $spin < self::MAX_SPIN; $spin++) {
            $v1 = $this->meta->get('global', 'version');

            if ($v1 & 1) {
                usleep(self::SPIN_SLEEP_US);
                continue;
            }

            $count = $this->meta->get('global', 'x509_count');
            $svids = [];

            for ($i = 0; $i < $count; $i++) {
                $row = $this->x509->get((string) $i);
                if ($row !== false) {
                    unset($row['version']);
                    $svids[] = $row;
                }
            }

            $v2 = $this->meta->get('global', 'version');

            if ($v1 === $v2) {
                return $svids;
            }

            usleep(self::SPIN_SLEEP_US);
        }

        return [];
    }

    /**
     * Find an X.509 SVID by hint value.
     *
     * @return array{
     *     spiffe_id: string,
     *     trust_domain: string,
     *     cert_pem: string,
     *     key_pem: string,
     *     bundle_pem: string,
     *     hint: string,
     *     updated_at: int,
     * }|null
     */
    public function readX509ByHint(string $hint): ?array
    {
        $all = $this->readAllX509();

        foreach ($all as $svid) {
            if ($svid['hint'] === $hint) {
                return $svid;
            }
        }

        return null;
    }

    // ══════════════════════════════════════════════════════════════════
    //  JWT bundle reads
    // ══════════════════════════════════════════════════════════════════

    /**
     * Read the JWT bundle (JWKS JSON) for a specific trust domain.
     *
     * @return array{
     *     trust_domain: string,
     *     jwks_json: string,
     *     updated_at: int,
     * }|null
     */
    public function readJwtBundle(string $trustDomain): ?array
    {
        for ($spin = 0; $spin < self::MAX_SPIN; $spin++) {
            $v1 = $this->meta->get('global', 'version');

            if ($v1 & 1) {
                usleep(self::SPIN_SLEEP_US);
                continue;
            }

            $row = $this->jwt->get($trustDomain);
            if ($row === false) {
                return null;
            }

            $v2 = $this->meta->get('global', 'version');

            if ($v1 === $v2) {
                unset($row['version']);
                return $row;
            }

            usleep(self::SPIN_SLEEP_US);
        }

        return null;
    }

    /**
     * Read all JWT bundles with seqlock consistency.
     *
     * @return array<string, array{
     *     trust_domain: string,
     *     jwks_json: string,
     *     updated_at: int,
     * }>  Keyed by trust domain name
     */
    public function readAllJwtBundles(): array
    {
        for ($spin = 0; $spin < self::MAX_SPIN; $spin++) {
            $v1 = $this->meta->get('global', 'version');

            if ($v1 & 1) {
                usleep(self::SPIN_SLEEP_US);
                continue;
            }

            $bundles = [];
            foreach ($this->jwt as $key => $row) {
                unset($row['version']);
                $bundles[$key] = $row;
            }

            $v2 = $this->meta->get('global', 'version');

            if ($v1 === $v2) {
                return $bundles;
            }

            usleep(self::SPIN_SLEEP_US);
        }

        return [];
    }

    // ══════════════════════════════════════════════════════════════════
    //  Metadata / health queries
    // ══════════════════════════════════════════════════════════════════

    /**
     * Get the current metadata snapshot.
     *
     * @return array{
     *     version: int,
     *     x509_state: string,
     *     jwt_state: string,
     *     x509_count: int,
     *     jwt_count: int,
     *     updated_at: int,
     *     error: string,
     * }
     */
    public function readMeta(): array
    {
        return $this->meta->get('global') ?: [
            'version'    => 0,
            'x509_state' => 'idle',
            'jwt_state'  => 'idle',
            'x509_count' => 0,
            'jwt_count'  => 0,
            'updated_at' => 0,
            'error'      => '',
        ];
    }

    /**
     * Check if both X.509 and JWT sources are in Ready state.
     */
    public function isReady(): bool
    {
        $meta = $this->readMeta();
        return $meta['x509_state'] === 'ready' && $meta['jwt_state'] === 'ready';
    }

    /**
     * Check if any credentials have been published.
     */
    public function hasCredentials(): bool
    {
        $meta = $this->readMeta();
        return $meta['x509_count'] > 0;
    }

    /**
     * Get the global version counter (even = stable, odd = write in progress).
     */
    public function version(): int
    {
        return $this->meta->get('global', 'version') ?: 0;
    }

    /**
     * Seconds since the last credential update. Returns -1 if never updated.
     */
    public function secondsSinceLastUpdate(): int
    {
        $updatedAt = $this->meta->get('global', 'updated_at') ?: 0;
        if ($updatedAt === 0) {
            return -1;
        }
        return time() - $updatedAt;
    }

    /**
     * Block until credentials are available, with timeout.
     *
     * Uses polling with exponential backoff. Suitable for worker process
     * startup when the watcher may not have fetched credentials yet.
     *
     * @param float $timeout Seconds to wait (0 = indefinite)
     * @throws \RuntimeException if timeout expires
     */
    public function awaitReady(float $timeout = 30.0): void
    {
        $deadline = $timeout > 0 ? microtime(true) + $timeout : PHP_FLOAT_MAX;
        $sleep = 10_000; // start at 10ms

        while (!$this->hasCredentials()) {
            if (microtime(true) > $deadline) {
                $meta = $this->readMeta();
                throw new \RuntimeException(sprintf(
                    'Timed out waiting for SPIFFE credentials in shared memory '
                    . '(x509_state: %s, jwt_state: %s, error: %s)',
                    $meta['x509_state'],
                    $meta['jwt_state'],
                    $meta['error'] ?: 'none',
                ));
            }

            usleep($sleep);
            $sleep = min($sleep * 2, 1_000_000); // cap at 1s
        }
    }
}
