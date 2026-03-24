<?php

declare(strict_types=1);

namespace Spiffe\SharedMemory;

use Spiffe\Bundle\JwtBundle;
use Spiffe\Bundle\X509Bundle;
use Spiffe\Source\SourceState;
use Spiffe\X509Svid;
use Swoole\Table;

/**
 * Writer-side adapter: publishes SPIFFE credentials into Swoole Table
 * shared memory.
 *
 * Used exclusively by the watcher process (the single writer). Worker
 * processes use SpiffeTableReader for lock-free reads.
 *
 * Implements a seqlock (sequence lock) protocol for atomic multi-field writes:
 *
 *   1. Increment version to ODD  → signals "write in progress"
 *   2. Write all data fields
 *   3. Increment version to EVEN → signals "write complete"
 *
 * Readers that observe an odd version spin-wait; readers that see an even
 * version read the data and verify the version hasn't changed.
 *
 * This is safe because:
 *   - There is exactly ONE writer (the watcher process)
 *   - Swoole Table column writes are individually atomic
 *   - The version field acts as a memory fence between data updates
 */
final class SpiffeTableStore
{
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
     * Create from the schema bundle returned by SpiffeTableSchema::createAll().
     *
     * @param array{meta: Table, x509: Table, jwt: Table} $tables
     */
    public static function fromTables(array $tables): self
    {
        return new self($tables['meta'], $tables['x509'], $tables['jwt']);
    }

    // ══════════════════════════════════════════════════════════════════
    //  X.509 credentials
    // ══════════════════════════════════════════════════════════════════

    /**
     * Atomically publish a new set of X.509 SVIDs to shared memory.
     *
     * Called by the watcher's onRotated callback. Replaces all slots.
     *
     * @param list<X509Svid> $svids
     */
    public function publishX509(array $svids): void
    {
        $now = time();

        // Step 1: mark write-in-progress (odd version)
        $this->meta->incr('global', 'version', 1);

        // Step 2: write each SVID into its slot
        foreach ($svids as $index => $svid) {
            $slot = (string) $index;
            $this->x509->set($slot, [
                'version'      => $this->meta->get('global', 'version'),
                'spiffe_id'    => (string) $svid->spiffeId(),
                'trust_domain' => (string) $svid->trustDomain(),
                'cert_pem'     => $svid->certChainPem(),
                'key_pem'      => $svid->privateKeyPem(),
                'bundle_pem'   => $svid->bundlePem(),
                'hint'         => $svid->hint(),
                'updated_at'   => $now,
            ]);
        }

        // Clear stale slots beyond current count
        $previousCount = $this->meta->get('global', 'x509_count');
        for ($i = count($svids); $i < $previousCount; $i++) {
            $this->x509->del((string) $i);
        }

        // Update meta
        $this->meta->set('global', [
            'x509_count' => count($svids),
            'updated_at' => $now,
        ]);

        // Step 3: mark write-complete (even version)
        $this->meta->incr('global', 'version', 1);
    }

    // ══════════════════════════════════════════════════════════════════
    //  JWT bundles
    // ══════════════════════════════════════════════════════════════════

    /**
     * Atomically publish new JWT bundles to shared memory.
     *
     * @param array<string, string> $bundleMap trust domain → JWKS JSON
     */
    public function publishJwtBundles(array $bundleMap): void
    {
        $now = time();

        // Step 1: odd version
        $this->meta->incr('global', 'version', 1);

        // Step 2: write each bundle
        $count = 0;
        foreach ($bundleMap as $trustDomain => $jwksJson) {
            $this->jwt->set($trustDomain, [
                'version'      => $this->meta->get('global', 'version'),
                'trust_domain' => $trustDomain,
                'jwks_json'    => $jwksJson,
                'updated_at'   => $now,
            ]);
            $count++;
        }

        // Remove bundles that are no longer present
        foreach ($this->jwt as $key => $row) {
            if (!isset($bundleMap[$key])) {
                $this->jwt->del($key);
            }
        }

        // Update meta
        $this->meta->set('global', [
            'jwt_count'  => $count,
            'updated_at' => $now,
        ]);

        // Step 3: even version
        $this->meta->incr('global', 'version', 1);
    }

    // ══════════════════════════════════════════════════════════════════
    //  State updates
    // ══════════════════════════════════════════════════════════════════

    public function updateX509State(SourceState $state): void
    {
        $this->meta->set('global', ['x509_state' => $state->value]);
    }

    public function updateJwtState(SourceState $state): void
    {
        $this->meta->set('global', ['jwt_state' => $state->value]);
    }

    public function updateError(string $message): void
    {
        $this->meta->set('global', ['error' => substr($message, 0, 512)]);
    }

    public function clearError(): void
    {
        $this->meta->set('global', ['error' => '']);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Raw table access (for advanced use cases)
    // ══════════════════════════════════════════════════════════════════

    public function metaTable(): Table
    {
        return $this->meta;
    }

    public function x509Table(): Table
    {
        return $this->x509;
    }

    public function jwtTable(): Table
    {
        return $this->jwt;
    }
}
