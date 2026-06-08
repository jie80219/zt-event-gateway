<?php

declare(strict_types=1);

namespace Spiffe\SharedMemory;

use Spiffe\Source\SourceState;
use Spiffe\X509Svid;

/**
 * Writer-side: publishes SPIFFE credentials to the shared filesystem store.
 *
 * Uses atomic file writes (temp + rename) so readers never see partial data.
 * The version counter in meta.json provides a seqlock-style consistency
 * guarantee for multi-file reads.
 *
 *   Writer:  version = odd → write files → version = even
 *   Reader:  read version → if odd, retry → read files → verify version
 */
final class SpiffeTableStore
{
    private string $baseDir;

    public function __construct(string $baseDir = SpiffeTableSchema::DEFAULT_BASE_DIR)
    {
        $this->baseDir = rtrim($baseDir, '/');
    }

    // ── X.509 credentials ────────────────────────────────────────

    /**
     * Atomically publish a new set of X.509 SVIDs.
     *
     * @param list<X509Svid> $svids
     */
    public function publishX509(array $svids): void
    {
        $now = time();
        $meta = $this->readMeta();

        // Step 1: odd version (write in progress)
        $meta['version']++;
        $this->writeMeta($meta);

        // Step 2: write each SVID slot
        foreach ($svids as $index => $svid) {
            SpiffeTableSchema::atomicWrite(
                "{$this->baseDir}/x509/{$index}.json",
                json_encode([
                    'spiffe_id'    => (string) $svid->spiffeId(),
                    'trust_domain' => (string) $svid->trustDomain(),
                    'cert_pem'     => $svid->certChainPem(),
                    'key_pem'      => $svid->privateKeyPem(),
                    'bundle_pem'   => $svid->bundlePem(),
                    'hint'         => $svid->hint(),
                    'updated_at'   => $now,
                ], JSON_THROW_ON_ERROR),
            );
        }

        // Remove stale slots
        $previousCount = $meta['x509_count'] ?? 0;
        for ($i = count($svids); $i < $previousCount; $i++) {
            @unlink("{$this->baseDir}/x509/{$i}.json");
        }

        // Step 3: even version (write complete)
        $meta['version']++;
        $meta['x509_count'] = count($svids);
        $meta['updated_at'] = $now;
        $this->writeMeta($meta);
    }

    // ── JWT bundles ──────────────────────────────────────────────

    /**
     * @param array<string, string> $bundleMap  trust domain → JWKS JSON
     */
    public function publishJwtBundles(array $bundleMap): void
    {
        $now = time();
        $meta = $this->readMeta();

        $meta['version']++;
        $this->writeMeta($meta);

        // Write each bundle
        $count = 0;
        foreach ($bundleMap as $trustDomain => $jwksJson) {
            $safeName = preg_replace('/[^a-z0-9._-]/', '_', strtolower($trustDomain));
            SpiffeTableSchema::atomicWrite(
                "{$this->baseDir}/jwt/{$safeName}.json",
                json_encode([
                    'trust_domain' => $trustDomain,
                    'jwks_json'    => $jwksJson,
                    'updated_at'   => $now,
                ], JSON_THROW_ON_ERROR),
            );
            $count++;
        }

        // Remove bundles no longer present
        $safeNames = [];
        foreach (array_keys($bundleMap) as $td) {
            $safeNames[] = preg_replace('/[^a-z0-9._-]/', '_', strtolower($td)) . '.json';
        }
        foreach (glob("{$this->baseDir}/jwt/*.json") as $file) {
            if (!in_array(basename($file), $safeNames, true)) {
                @unlink($file);
            }
        }

        $meta['version']++;
        $meta['jwt_count'] = $count;
        $meta['updated_at'] = $now;
        $this->writeMeta($meta);
    }

    // ── State updates ────────────────────────────────────────────

    public function updateX509State(SourceState $state): void
    {
        $meta = $this->readMeta();
        $meta['x509_state'] = $state->value;
        $this->writeMeta($meta);
    }

    public function updateJwtState(SourceState $state): void
    {
        $meta = $this->readMeta();
        $meta['jwt_state'] = $state->value;
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

    // ── Internal ─────────────────────────────────────────────────

    private function readMeta(): array
    {
        $path = "{$this->baseDir}/meta.json";
        if (!file_exists($path)) {
            return [
                'version' => 0, 'x509_state' => 'idle', 'jwt_state' => 'idle',
                'x509_count' => 0, 'jwt_count' => 0, 'updated_at' => 0, 'error' => '',
            ];
        }
        return json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
    }

    private function writeMeta(array $meta): void
    {
        SpiffeTableSchema::atomicWrite(
            "{$this->baseDir}/meta.json",
            json_encode($meta, JSON_THROW_ON_ERROR),
        );
    }
}
