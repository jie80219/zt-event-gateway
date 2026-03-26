<?php

declare(strict_types=1);

namespace Spiffe\TLS;

/**
 * Immutable snapshot of X.509 TLS material in PEM format, with managed
 * temporary file paths for consumers that require on-disk certificates
 * (Workerman, curl, Guzzle, etc.).
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │                        TlsCredential                             │
 * │                                                                  │
 * │  In-memory PEM strings    Managed temp files (on-disk)           │
 * │  ┌─────────────────┐     ┌────────────────────────────────┐     │
 * │  │ certPem         │────▶│ /tmp/spiffe_cert_xxxxx.pem     │     │
 * │  │ keyPem          │────▶│ /tmp/spiffe_key_xxxxx.pem  0600│     │
 * │  │ bundlePem       │────▶│ /tmp/spiffe_ca_xxxxx.pem       │     │
 * │  └─────────────────┘     └────────────────────────────────┘     │
 * │                                                                  │
 * │  Metadata                                                        │
 * │  ├── spiffeId:     spiffe://zt.local/php-gateway                │
 * │  ├── trustDomain:  zt.local                                     │
 * │  └── version:      42  (seqlock version at read time)           │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * Lifecycle:
 *   - Created by SpiffeTlsContext::refresh() or SpiffeTlsContext::current()
 *   - Temp files written lazily on first call to certFile()/keyFile()/caFile()
 *   - cleanup() removes temp files; also called by __destruct()
 *   - Previous credential is cleaned up when SpiffeTlsContext swaps in a new one
 */
final class TlsCredential
{
    private string $spiffeId;
    private string $trustDomain;
    private string $certPem;
    private string $keyPem;
    private string $bundlePem;
    private int $version;

    /** @var string|null Lazy-initialized temp file paths */
    private ?string $certFilePath = null;
    private ?string $keyFilePath = null;
    private ?string $caFilePath = null;

    public function __construct(
        string $spiffeId,
        string $trustDomain,
        string $certPem,
        string $keyPem,
        string $bundlePem,
        int $version = 0,
    ) {
        $this->spiffeId = $spiffeId;
        $this->trustDomain = $trustDomain;
        $this->certPem = $certPem;
        $this->keyPem = $keyPem;
        $this->bundlePem = $bundlePem;
        $this->version = $version;
    }

    // ── PEM strings (in-memory) ──────────────────────────────────────

    public function spiffeId(): string
    {
        return $this->spiffeId;
    }

    public function trustDomain(): string
    {
        return $this->trustDomain;
    }

    public function certPem(): string
    {
        return $this->certPem;
    }

    public function keyPem(): string
    {
        return $this->keyPem;
    }

    public function bundlePem(): string
    {
        return $this->bundlePem;
    }

    public function version(): int
    {
        return $this->version;
    }

    // ── Temp file paths (on-disk, lazy) ──────────────────────────────

    /**
     * Path to temp file containing the certificate chain PEM.
     * Created on first access.
     */
    public function certFile(): string
    {
        if ($this->certFilePath === null) {
            $this->certFilePath = $this->writeTempFile('spiffe_cert_', $this->certPem, 0644);
        }
        return $this->certFilePath;
    }

    /**
     * Path to temp file containing the private key PEM.
     * Created with 0600 permissions.
     */
    public function keyFile(): string
    {
        if ($this->keyFilePath === null) {
            $this->keyFilePath = $this->writeTempFile('spiffe_key_', $this->keyPem, 0600);
        }
        return $this->keyFilePath;
    }

    /**
     * Path to temp file containing the CA bundle PEM.
     */
    public function caFile(): string
    {
        if ($this->caFilePath === null) {
            $this->caFilePath = $this->writeTempFile('spiffe_ca_', $this->bundlePem, 0644);
        }
        return $this->caFilePath;
    }

    /**
     * Write all temp files at once and return their paths.
     *
     * @return array{cert: string, key: string, ca: string}
     */
    public function materializeFiles(): array
    {
        return [
            'cert' => $this->certFile(),
            'key'  => $this->keyFile(),
            'ca'   => $this->caFile(),
        ];
    }

    /**
     * Whether this credential has usable material.
     */
    public function isValid(): bool
    {
        return $this->certPem !== '' && $this->keyPem !== '';
    }

    // ── Cleanup ──────────────────────────────────────────────────────

    /**
     * Remove all temporary files managed by this credential.
     */
    public function cleanup(): void
    {
        foreach ([$this->certFilePath, $this->keyFilePath, $this->caFilePath] as $path) {
            if ($path !== null && file_exists($path)) {
                @unlink($path);
            }
        }
        $this->certFilePath = null;
        $this->keyFilePath = null;
        $this->caFilePath = null;
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    // ── Internal ─────────────────────────────────────────────────────

    /**
     * Atomic write: temp file → chmod → rename-safe.
     */
    private function writeTempFile(string $prefix, string $content, int $mode): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new \RuntimeException('Failed to create temp file for TLS credential');
        }
        file_put_contents($path, $content);
        chmod($path, $mode);
        return $path;
    }
}
