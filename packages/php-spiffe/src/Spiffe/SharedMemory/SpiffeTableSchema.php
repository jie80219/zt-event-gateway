<?php

declare(strict_types=1);

namespace Spiffe\SharedMemory;

use Swoole\Table;

/**
 * Defines and creates the Swoole Table schema for cross-process SPIFFE
 * credential sharing.
 *
 * Three tables work together:
 *
 *  ┌─────────────────────────────────────────────────────────────────┐
 *  │                        Swoole Shared Memory                     │
 *  │                                                                 │
 *  │  ┌─ spiffe_meta ──────────────────────────────────────────────┐ │
 *  │  │  row "global"                                              │ │
 *  │  │  version | x509_state | jwt_state | x509_count | updated  │ │
 *  │  └───────────────────────────────────────────────────────────┘ │
 *  │                                                                 │
 *  │  ┌─ spiffe_x509 ────────────────────────────────────────────┐  │
 *  │  │  row "0" — primary SVID                                   │  │
 *  │  │  version | spiffe_id | cert_pem | key_pem | bundle_pem   │  │
 *  │  │  hint | trust_domain | updated_at                         │  │
 *  │  │                                                           │  │
 *  │  │  row "1" — secondary SVID (if any)                        │  │
 *  │  │  ...                                                      │  │
 *  │  └──────────────────────────────────────────────────────────┘  │
 *  │                                                                 │
 *  │  ┌─ spiffe_jwt_bundles ─────────────────────────────────────┐  │
 *  │  │  row "zt.local" — keyed by trust domain                   │  │
 *  │  │  version | jwks_json | updated_at                         │  │
 *  │  │                                                           │  │
 *  │  │  row "other.domain" — federated                           │  │
 *  │  │  ...                                                      │  │
 *  │  └──────────────────────────────────────────────────────────┘  │
 *  └─────────────────────────────────────────────────────────────────┘
 *
 * Atomic versioning protocol (seqlock):
 *
 *   Writer:  version = odd  →  write data  →  version = even
 *   Reader:  read version → if odd, spin-wait → read data → verify version unchanged
 *
 * Swoole Table MUST be created BEFORE Server::start() / fork().
 * After fork, every worker process shares the same physical memory pages.
 */
final class SpiffeTableSchema
{
    /** Default maximum PEM size per field (bytes). 32 KB covers RSA-4096 chains. */
    public const DEFAULT_PEM_SIZE = 32768;

    /** Default maximum JWKS JSON size (bytes). */
    public const DEFAULT_JWKS_SIZE = 32768;

    /** Maximum number of SVID slots (multi-identity support). */
    public const DEFAULT_X509_SLOTS = 8;

    /** Maximum number of trust domains in JWT bundle table. */
    public const DEFAULT_JWT_SLOTS = 16;

    private function __construct() {}

    /**
     * Create the metadata table.
     *
     * Stores global state: version counter, source states, SVID counts.
     */
    public static function createMetaTable(): Table
    {
        $table = new Table(4); // only 1 row needed, but min is 2^n
        $table->column('version',     Table::TYPE_INT);
        $table->column('x509_state',  Table::TYPE_STRING, 16);
        $table->column('jwt_state',   Table::TYPE_STRING, 16);
        $table->column('x509_count',  Table::TYPE_INT);
        $table->column('jwt_count',   Table::TYPE_INT);
        $table->column('updated_at',  Table::TYPE_INT);
        $table->column('error',       Table::TYPE_STRING, 512);
        $table->create();

        // Initialize
        $table->set('global', [
            'version'    => 0,
            'x509_state' => 'idle',
            'jwt_state'  => 'idle',
            'x509_count' => 0,
            'jwt_count'  => 0,
            'updated_at' => 0,
            'error'      => '',
        ]);

        return $table;
    }

    /**
     * Create the X.509 credential table.
     *
     * Each row stores one SVID's PEM material, keyed by slot index ("0", "1", ...).
     */
    public static function createX509Table(
        int $slots = self::DEFAULT_X509_SLOTS,
        int $pemSize = self::DEFAULT_PEM_SIZE,
    ): Table {
        // Swoole Table size must be power of 2
        $tableSize = self::nextPow2($slots);

        $table = new Table($tableSize);
        $table->column('version',      Table::TYPE_INT);
        $table->column('spiffe_id',    Table::TYPE_STRING, 256);
        $table->column('trust_domain', Table::TYPE_STRING, 256);
        $table->column('cert_pem',     Table::TYPE_STRING, $pemSize);
        $table->column('key_pem',      Table::TYPE_STRING, $pemSize);
        $table->column('bundle_pem',   Table::TYPE_STRING, $pemSize);
        $table->column('hint',         Table::TYPE_STRING, 128);
        $table->column('updated_at',   Table::TYPE_INT);
        $table->create();

        return $table;
    }

    /**
     * Create the JWT bundle table.
     *
     * Each row stores one trust domain's JWKS, keyed by trust domain name.
     */
    public static function createJwtBundleTable(
        int $slots = self::DEFAULT_JWT_SLOTS,
        int $jwksSize = self::DEFAULT_JWKS_SIZE,
    ): Table {
        $tableSize = self::nextPow2($slots);

        $table = new Table($tableSize);
        $table->column('version',      Table::TYPE_INT);
        $table->column('trust_domain', Table::TYPE_STRING, 256);
        $table->column('jwks_json',    Table::TYPE_STRING, $jwksSize);
        $table->column('updated_at',   Table::TYPE_INT);
        $table->create();

        return $table;
    }

    /**
     * Create all three tables as a bundle.
     *
     * @return array{meta: Table, x509: Table, jwt: Table}
     */
    public static function createAll(
        int $x509Slots = self::DEFAULT_X509_SLOTS,
        int $jwtSlots = self::DEFAULT_JWT_SLOTS,
        int $pemSize = self::DEFAULT_PEM_SIZE,
        int $jwksSize = self::DEFAULT_JWKS_SIZE,
    ): array {
        return [
            'meta' => self::createMetaTable(),
            'x509' => self::createX509Table($x509Slots, $pemSize),
            'jwt'  => self::createJwtBundleTable($jwtSlots, $jwksSize),
        ];
    }

    private static function nextPow2(int $n): int
    {
        $n--;
        $n |= $n >> 1;
        $n |= $n >> 2;
        $n |= $n >> 4;
        $n |= $n >> 8;
        $n |= $n >> 16;
        return $n + 1;
    }
}
