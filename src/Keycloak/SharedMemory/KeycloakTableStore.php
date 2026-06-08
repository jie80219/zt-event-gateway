<?php

declare(strict_types=1);

namespace Keycloak\SharedMemory;

/**
 * Writer-side for Keycloak token + JWKS cache.
 *
 *   Writer:  version = odd → write files → version = even
 *   Reader:  read version → if odd, retry → read files → verify version
 */
final class KeycloakTableStore
{
    private string $baseDir;

    public function __construct(string $baseDir = KeycloakTableSchema::DEFAULT_BASE_DIR)
    {
        $this->baseDir = rtrim($baseDir, '/');
    }

    /**
     * Publish the current service-account access token.
     *
     * @param array{access_token:string, token_type:string, expires_at:int, client_id:string, issuer:string} $token
     */
    public function publishToken(array $token): void
    {
        $now = time();
        $meta = $this->readMeta();

        $meta['version']++;
        $this->writeMeta($meta);

        KeycloakTableSchema::atomicWrite(
            "{$this->baseDir}/token/primary.json",
            json_encode($token + ['updated_at' => $now], JSON_THROW_ON_ERROR),
        );

        $meta['version']++;
        $meta['token_count'] = 1;
        $meta['updated_at'] = $now;
        $this->writeMeta($meta);
    }

    /**
     * @param array<string, array{issuer:string, jwks_json:string}> $bundleMap realm → JWKS bundle
     */
    public function publishJwks(array $bundleMap): void
    {
        $now = time();
        $meta = $this->readMeta();

        $meta['version']++;
        $this->writeMeta($meta);

        $count = 0;
        $safeNames = [];
        foreach ($bundleMap as $realm => $bundle) {
            $safeName = preg_replace('/[^a-z0-9._-]/', '_', strtolower($realm));
            $safeNames[] = "{$safeName}.json";
            KeycloakTableSchema::atomicWrite(
                "{$this->baseDir}/jwks/{$safeName}.json",
                json_encode([
                    'realm'      => $realm,
                    'issuer'     => $bundle['issuer'],
                    'jwks_json'  => $bundle['jwks_json'],
                    'updated_at' => $now,
                ], JSON_THROW_ON_ERROR),
            );
            $count++;
        }

        foreach (glob("{$this->baseDir}/jwks/*.json") as $file) {
            if (!in_array(basename($file), $safeNames, true)) {
                @unlink($file);
            }
        }

        $meta['version']++;
        $meta['jwks_count'] = $count;
        $meta['updated_at'] = $now;
        $this->writeMeta($meta);
    }

    public function updateTokenState(string $state): void
    {
        $meta = $this->readMeta();
        $meta['token_state'] = $state;
        $this->writeMeta($meta);
    }

    public function updateJwksState(string $state): void
    {
        $meta = $this->readMeta();
        $meta['jwks_state'] = $state;
        $this->writeMeta($meta);
    }

    public function updateError(string $message): void
    {
        $meta = $this->readMeta();
        $meta['error'] = substr($message, 0, 512);
        $this->writeMeta($meta);
    }

    public function clearError(): void
    {
        $meta = $this->readMeta();
        $meta['error'] = '';
        $this->writeMeta($meta);
    }

    private function readMeta(): array
    {
        $path = "{$this->baseDir}/meta.json";
        if (!file_exists($path)) {
            return [
                'version' => 0, 'token_state' => 'idle', 'jwks_state' => 'idle',
                'token_count' => 0, 'jwks_count' => 0, 'updated_at' => 0, 'error' => '',
            ];
        }
        return json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
    }

    private function writeMeta(array $meta): void
    {
        KeycloakTableSchema::atomicWrite(
            "{$this->baseDir}/meta.json",
            json_encode($meta, JSON_THROW_ON_ERROR),
        );
    }
}
