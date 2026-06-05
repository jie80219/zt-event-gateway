<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\Vault\Tls;

/**
 * Central TLS context adapter — bridges Vault-issued X.509 credentials
 * (rendered to disk by a vault-agent sidecar) into the configuration formats
 * required by each PHP network layer.
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │                          VaultTlsContext                              │
 * │                                                                      │
 * │    Credential Source                 Output Adapters                  │
 * │   ┌─────────────────────┐                                           │
 * │   │ vault-agent files   │──┬─▶ forStreamContext()  → PHP streams    │
 * │   │ tls.crt/tls.key/ca  │  ├─▶ forGuzzle()         → Guzzle opts    │
 * │   └─────────────────────┘  ├─▶ forWorkerman()      → Workerman ctx  │
 * │                            └─▶ applyCurl()         → cURL handle    │
 * │   ┌─────────────────────┐                                           │
 * │   │ VaultTlsCredential  │  (current cached snapshot)                 │
 * │   └─────────────────────┘                                           │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * Auto-refresh: the credential version = max(filemtime cert, filemtime key);
 * isStale() compares it against the cached snapshot so re-renders are picked
 * up. refresh() swaps in a new snapshot and cleans up the previous temp files.
 *
 *   $ctx = VaultTlsContext::fromVaultFiles($cert, $key, $ca);
 */
final class VaultTlsContext
{
    /** @var array{cert:string,key:string,ca:string} vault-agent rendered PEM files */
    private array $vaultFiles;

    private ?VaultTlsCredential $credential = null;

    private function __construct(array $vaultFiles)
    {
        $this->vaultFiles = $vaultFiles;
    }

    /**
     * Create from vault-agent rendered PEM files (pure-Vault identity).
     * The cert/key/ca are issued + auto-renewed by a Vault PKI vault-agent
     * sidecar; this context re-reads them when they change (mtime version).
     */
    public static function fromVaultFiles(string $certPath, string $keyPath, string $caPath): self
    {
        return new self(['cert' => $certPath, 'key' => $keyPath, 'ca' => $caPath]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Credential management
    // ══════════════════════════════════════════════════════════════════

    /**
     * Get the current credential, reading from disk on first access.
     */
    public function current(): VaultTlsCredential
    {
        if ($this->credential === null) {
            $this->refresh();
        }
        return $this->credential;
    }

    /**
     * Re-read credentials from the vault-agent files, atomically swapping the
     * in-memory snapshot and cleaning up old temp files.
     *
     * @return bool True if credentials changed (new version)
     */
    public function refresh(): bool
    {
        $new = VaultTlsCredential::fromPemFiles(
            $this->vaultFiles['cert'],
            $this->vaultFiles['key'],
            $this->vaultFiles['ca'],
            $this->vaultFilesVersion(),
        );

        $old = $this->credential;
        $changed = $old === null || $old->version() !== $new->version();

        $this->credential = $new;

        if ($old !== null && $changed) {
            $old->cleanup();
        }

        return $changed;
    }

    /**
     * Check if the vault-agent files are newer than our cached snapshot.
     */
    public function isStale(): bool
    {
        if ($this->credential === null) {
            return true;
        }
        return $this->vaultFilesVersion() !== $this->credential->version();
    }

    public function cleanup(): void
    {
        if ($this->credential !== null) {
            $this->credential->cleanup();
            $this->credential = null;
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    // ══════════════════════════════════════════════════════════════════
    //  PHP stream_context — for fopen(), file_get_contents(), etc.
    // ══════════════════════════════════════════════════════════════════

    /**
     * Options array for stream_context_create().
     *
     * @return array{ssl: array<string, mixed>}
     */
    public function forStreamContext(bool $verifyPeer = true): array
    {
        $files = $this->current()->materializeFiles();

        return [
            'ssl' => [
                'local_cert'        => $files['cert'],
                'local_pk'          => $files['key'],
                'cafile'            => $files['ca'],
                'verify_peer'       => $verifyPeer,
                'verify_peer_name'  => false,  // identity is in the URI SAN, not CN
                'allow_self_signed' => false,
                'capture_peer_cert' => true,
            ],
        ];
    }

    /**
     * Create a configured stream context resource directly.
     *
     * @return resource
     */
    public function createStreamContext(bool $verifyPeer = true)
    {
        return stream_context_create($this->forStreamContext($verifyPeer));
    }

    // ══════════════════════════════════════════════════════════════════
    //  Guzzle HTTP client — RequestOptions for mTLS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Options array for GuzzleHttp\Client constructor or per-request options.
     *
     * @return array<string, mixed>
     */
    public function forGuzzle(bool $verifyPeer = true): array
    {
        $files = $this->current()->materializeFiles();

        $opts = [
            'cert'    => $files['cert'],
            'ssl_key' => $files['key'],
            'verify'  => $verifyPeer ? $files['ca'] : false,
        ];

        if (getenv('PERF_METRIC_ENABLED') === '1') {
            $opts['on_stats'] = static function (\GuzzleHttp\TransferStats $stats): void {
                $h = $stats->getHandlerStats();
                fwrite(STDOUT, sprintf(
                    "[perf-mtls] handshake_ms=%.3f connect_ms=%.3f total_ms=%.3f url=%s\n",
                    (float) ($h['appconnect_time'] ?? 0) * 1000.0,
                    (float) ($h['connect_time'] ?? 0) * 1000.0,
                    (float) $stats->getTransferTime() * 1000.0,
                    (string) $stats->getEffectiveUri()
                ));
            };
        }

        return $opts;
    }

    /**
     * Create a pre-configured Guzzle client with Vault mTLS.
     *
     * @param array<string, mixed> $extraOptions  Merged into the Guzzle config
     */
    public function createGuzzleClient(array $extraOptions = []): \GuzzleHttp\Client
    {
        return new \GuzzleHttp\Client(array_merge($this->forGuzzle(), $extraOptions));
    }

    // ══════════════════════════════════════════════════════════════════
    //  Workerman — SSL context for Worker constructor
    // ══════════════════════════════════════════════════════════════════

    /**
     * SSL context array for the Workerman\Worker constructor's second argument.
     *
     * @return array{ssl: array<string, mixed>}
     */
    public function forWorkerman(bool $verifyPeer = false): array
    {
        $files = $this->current()->materializeFiles();

        $ssl = [
            'local_cert'        => $files['cert'],
            'local_pk'          => $files['key'],
            'verify_peer'       => $verifyPeer,
            'allow_self_signed' => false,
        ];

        if ($verifyPeer) {
            $ssl['cafile'] = $files['ca'];
        }

        return ['ssl' => $ssl];
    }

    // ══════════════════════════════════════════════════════════════════
    //  cURL — for direct curl_setopt usage
    // ══════════════════════════════════════════════════════════════════

    /**
     * Apply Vault mTLS settings to a cURL handle.
     */
    public function applyCurl(\CurlHandle $ch, bool $verifyPeer = true): void
    {
        $files = $this->current()->materializeFiles();

        curl_setopt($ch, CURLOPT_SSLCERT, $files['cert']);
        curl_setopt($ch, CURLOPT_SSLKEY, $files['key']);

        if ($verifyPeer) {
            curl_setopt($ch, CURLOPT_CAINFO, $files['ca']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        // Identity is carried in the URI SAN, not CN — disable hostname check
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Raw accessors
    // ══════════════════════════════════════════════════════════════════

    public function credential(): ?VaultTlsCredential
    {
        return $this->credential;
    }

    /**
     * The service id (spiffe:// URI SAN) of the current credential.
     */
    public function id(): string
    {
        return $this->current()->id();
    }

    public function trustDomain(): string
    {
        return $this->current()->trustDomain();
    }

    // ── Internal ─────────────────────────────────────────────────────

    /** Version marker for vault files = newest mtime of cert/key. */
    private function vaultFilesVersion(): int
    {
        $c = @filemtime($this->vaultFiles['cert']);
        $k = @filemtime($this->vaultFiles['key']);
        return max(is_int($c) ? $c : 0, is_int($k) ? $k : 0);
    }
}
