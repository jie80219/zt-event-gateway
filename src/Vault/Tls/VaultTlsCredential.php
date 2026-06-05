<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\Vault\Tls;

/**
 * Immutable snapshot of X.509 TLS material (PEM strings) issued by the Vault
 * PKI engine and rendered to disk by a vault-agent sidecar, with managed
 * temporary file paths for consumers that require on-disk certificates
 * (Guzzle, curl, Workerman, PHP streams).
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │                      VaultTlsCredential                          │
 * │                                                                  │
 * │  In-memory PEM strings    Managed temp files (on-disk)           │
 * │  ┌─────────────────┐     ┌────────────────────────────────┐     │
 * │  │ certPem         │────▶│ /tmp/vault_cert_xxxxx.pem 0644 │     │
 * │  │ keyPem          │────▶│ /tmp/vault_key_xxxxx.pem  0600 │     │
 * │  │ bundlePem       │────▶│ /tmp/vault_ca_xxxxx.pem   0644 │     │
 * │  └─────────────────┘     └────────────────────────────────┘     │
 * │                                                                  │
 * │  Metadata (parsed from the cert URI SAN)                         │
 * │  ├── id:           spiffe://zt.local/php-gateway                │
 * │  ├── trustDomain:  spiffe://zt.local                            │
 * │  └── version:      <max mtime of cert/key at read time>         │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * Lifecycle:
 *   - Created by VaultTlsContext::refresh()/current() via fromPemFiles()
 *   - Temp files written lazily on first materializeFiles() call
 *   - cleanup() removes temp files; also called by __destruct()
 */
final class VaultTlsCredential
{
    private string $id;
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
        string $id,
        string $trustDomain,
        string $certPem,
        string $keyPem,
        string $bundlePem,
        int $version = 0,
    ) {
        $this->id = $id;
        $this->trustDomain = $trustDomain;
        $this->certPem = $certPem;
        $this->keyPem = $keyPem;
        $this->bundlePem = $bundlePem;
        $this->version = $version;
    }

    /**
     * Build from vault-agent rendered PEM files (pure-Vault identity).
     * The service id + trust domain are parsed from the cert's URI SAN
     * (spiffe://<trust-domain>/<service>), issued by the Vault PKI engine.
     *
     * @param int $version monotonic marker (e.g. file mtime) so the TLS
     *                      context can detect vault-agent re-renders.
     */
    public static function fromPemFiles(
        string $certPath,
        string $keyPath,
        string $caPath,
        int $version = 0,
    ): self {
        $certPem = @file_get_contents($certPath);
        $keyPem  = @file_get_contents($keyPath);
        $caPem   = @file_get_contents($caPath);
        if (!is_string($certPem) || $certPem === '' || !is_string($keyPem) || $keyPem === '' || !is_string($caPem)) {
            throw new \RuntimeException(
                "Vault TLS files not readable (cert={$certPath} key={$keyPath} ca={$caPath})"
            );
        }

        $cert = openssl_x509_read($certPem);
        if ($cert === false) {
            throw new \RuntimeException("Failed to parse Vault-issued certificate: {$certPath}");
        }
        $info = openssl_x509_parse($cert);

        $id = '';
        foreach (explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')) as $san) {
            $san = trim($san);
            if (str_starts_with($san, 'URI:spiffe://')) {
                $id = substr($san, 4); // strip "URI:"
                break;
            }
        }
        if ($id === '') {
            throw new \RuntimeException("Vault certificate has no spiffe:// URI SAN: {$certPath}");
        }

        $host = parse_url($id, PHP_URL_HOST);
        $trustDomain = is_string($host) && $host !== '' ? "spiffe://{$host}" : '';

        return new self($id, $trustDomain, $certPem, $keyPem, $caPem, $version);
    }

    // ── PEM strings (in-memory) ──────────────────────────────────────

    public function id(): string
    {
        return $this->id;
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

    public function certFile(): string
    {
        if ($this->certFilePath === null) {
            $this->certFilePath = $this->writeTempFile('vault_cert_', $this->certPem, 0644);
        }
        return $this->certFilePath;
    }

    public function keyFile(): string
    {
        if ($this->keyFilePath === null) {
            $this->keyFilePath = $this->writeTempFile('vault_key_', $this->keyPem, 0600);
        }
        return $this->keyFilePath;
    }

    public function caFile(): string
    {
        if ($this->caFilePath === null) {
            $this->caFilePath = $this->writeTempFile('vault_ca_', $this->bundlePem, 0644);
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

    public function isValid(): bool
    {
        return $this->certPem !== '' && $this->keyPem !== '';
    }

    // ── Cleanup ──────────────────────────────────────────────────────

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

    private function writeTempFile(string $prefix, string $content, int $mode): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new \RuntimeException('Failed to create temp file for Vault TLS credential');
        }
        file_put_contents($path, $content);
        chmod($path, $mode);
        return $path;
    }
}
