<?php

declare(strict_types=1);

namespace Keycloak\SharedMemory;

/**
 * Creates the shared directory structure for cross-process Keycloak token
 * and JWKS sharing via atomic file operations.
 *
 *   {baseDir}/
 *   ├── meta.json              version + state + timestamps
 *   ├── token/
 *   │   └── primary.json       current service-account access token
 *   └── jwks/
 *       └── {realm}.json       realm JWKS cache
 */
final class KeycloakTableSchema
{
    public const DEFAULT_BASE_DIR = '/tmp/keycloak-shared';

    private function __construct() {}

    public static function createAll(string $baseDir = self::DEFAULT_BASE_DIR): string
    {
        $baseDir = rtrim($baseDir, '/');

        foreach (["$baseDir", "$baseDir/token", "$baseDir/jwks"] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $metaPath = "$baseDir/meta.json";
        if (!file_exists($metaPath)) {
            self::atomicWrite($metaPath, json_encode([
                'version'      => 0,
                'token_state'  => 'idle',
                'jwks_state'   => 'idle',
                'token_count'  => 0,
                'jwks_count'   => 0,
                'updated_at'   => 0,
                'error'        => '',
            ], JSON_THROW_ON_ERROR));
        }

        return $baseDir;
    }

    public static function cleanup(string $baseDir = self::DEFAULT_BASE_DIR): void
    {
        if (!is_dir($baseDir)) {
            return;
        }

        foreach (['token', 'jwks'] as $sub) {
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
        $tmp = tempnam($dir, '.keycloak_');
        if ($tmp === false) {
            throw new \RuntimeException("Failed to create temp file in {$dir}");
        }

        $fh = @fopen($tmp, 'wb');
        if ($fh === false) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to open temp file {$tmp}");
        }

        try {
            $written = @fwrite($fh, $content);
            if ($written === false || $written !== strlen($content)) {
                throw new \RuntimeException("Short write to {$tmp}");
            }
            @fflush($fh);
            if (function_exists('fsync')) {
                @fsync($fh);
            }
        } finally {
            @fclose($fh);
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Rename {$tmp} → {$path} failed");
        }

        if (function_exists('fsync')) {
            $dh = @fopen($dir, 'r');
            if ($dh !== false) {
                @fsync($dh);
                @fclose($dh);
            }
        }
    }
}
