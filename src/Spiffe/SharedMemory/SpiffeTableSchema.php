<?php

declare(strict_types=1);

namespace Spiffe\SharedMemory;

/**
 * Creates the shared directory structure for cross-process SPIFFE
 * credential sharing via atomic file operations.
 *
 * Each "table" is a directory containing JSON files that are atomically
 * written (temp + rename) and read with flock().
 *
 *   {baseDir}/
 *   ├── meta.json              global state (version, source states)
 *   ├── x509/
 *   │   ├── 0.json             slot 0 (primary SVID PEM material)
 *   │   └── 1.json             slot 1 (secondary, if any)
 *   └── jwt/
 *       ├── zt.local.json      JWT bundle keyed by trust domain
 *       └── partner.json       federated trust domain
 */
final class SpiffeTableSchema
{
    public const DEFAULT_BASE_DIR = '/tmp/spiffe-shared';

    private function __construct() {}

    /**
     * Create the directory structure and initialize meta.json.
     *
     * MUST be called BEFORE forking worker processes.
     *
     * @return string The base directory path
     */
    public static function createAll(string $baseDir = self::DEFAULT_BASE_DIR): string
    {
        $baseDir = rtrim($baseDir, '/');

        // Create directories
        foreach (["$baseDir", "$baseDir/x509", "$baseDir/jwt"] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // Initialize meta.json if not exists
        $metaPath = "$baseDir/meta.json";
        if (!file_exists($metaPath)) {
            self::atomicWrite($metaPath, json_encode([
                'version'     => 0,
                'x509_state'  => 'idle',
                'jwt_state'   => 'idle',
                'x509_count'  => 0,
                'jwt_count'   => 0,
                'updated_at'  => 0,
                'error'       => '',
            ], JSON_THROW_ON_ERROR));
        }

        return $baseDir;
    }

    /**
     * Remove all shared memory files.
     */
    public static function cleanup(string $baseDir = self::DEFAULT_BASE_DIR): void
    {
        if (!is_dir($baseDir)) {
            return;
        }

        foreach (['x509', 'jwt'] as $sub) {
            $dir = "$baseDir/$sub";
            if (is_dir($dir)) {
                foreach (glob("$dir/*.json") as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
            }
        }
        @unlink("$baseDir/meta.json");
        @rmdir($baseDir);
    }

    public static function atomicWrite(string $path, string $content): void
    {
        $dir = dirname($path);
        $tmp = tempnam($dir, '.spiffe_');
        if ($tmp === false) {
            throw new \RuntimeException("Failed to create temp file in {$dir}");
        }
        file_put_contents($tmp, $content);
        rename($tmp, $path);
    }
}
