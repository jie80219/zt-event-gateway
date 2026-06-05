<?php

declare(strict_types=1);

namespace Spiffe\TLS;

use Spiffe\SharedMemory\SpiffeTableReader;
use Spiffe\Source\X509Source;
use Spiffe\SpiffeId;
use Spiffe\X509Svid;

/**
 * Central TLS context adapter — bridges SPIFFE credentials from shared memory
 * (or in-process Source) into the configuration formats required by each
 * PHP network layer.
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │                         SpiffeTlsContext                              │
 * │                                                                      │
 * │    Credential Source                 Output Adapters                  │
 * │   ┌─────────────────┐                                               │
 * │   │ SpiffeTableReader│──┐   ┌─▶ forStreamContext()  → PHP streams   │
 * │   │ (shared files)   │  │   │                                        │
 * │   └─────────────────┘  ├───┼─▶ forGuzzle()         → Guzzle opts   │
 * │           OR           │   │                                        │
 * │   ┌─────────────────┐  │   ├─▶ forWorkerman()      → Workerman ctx │
 * │   │ X509Source       │──┘   │                                        │
 * │   │ (coroutine)      │      └─▶ applyCurl()         → cURL handle  │
 * │   └─────────────────┘                                               │
 * │   ┌─────────────────┐                                               │
 * │   │ TlsCredential   │  (current cached snapshot)                    │
 * │   │ (PEM + files)    │                                               │
 * │   └─────────────────┘                                               │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * Auto-refresh: call refresh() to re-read from the source and swap temp
 * files atomically. The previous TlsCredential's temp files are cleaned up.
 *
 * Two construction modes:
 *
 *   // Worker process — reads from shared filesystem store
 *   $ctx = SpiffeTlsContext::fromReader($reader);
 *
 *   // Coroutine context — reads directly from X509Source
 *   $ctx = SpiffeTlsContext::fromSource($x509Source);
 */
final class SpiffeTlsContext
{
    private ?SpiffeTableReader $reader;
    private ?X509Source $source;
    private ?TlsCredential $credential = null;

    /** @var array{cert:string,key:string,ca:string}|null Vault-agent rendered PEM files */
    private ?array $vaultFiles = null;

    /** @var int Slot index for multi-SVID sources */
    private int $slot;

    /** @var string|null If set, selects SVID by hint instead of slot */
    private ?string $hint;

    private function __construct(
        ?SpiffeTableReader $reader,
        ?X509Source $source,
        int $slot = 0,
        ?string $hint = null,
    ) {
        $this->reader = $reader;
        $this->source = $source;
        $this->slot = $slot;
        $this->hint = $hint;
    }

    /**
     * Create from SpiffeTableReader (worker process, cross-process shared memory).
     */
    public static function fromReader(SpiffeTableReader $reader, int $slot = 0): self
    {
        return new self($reader, null, $slot);
    }

    /**
     * Create from X509Source (coroutine context, in-process).
     */
    public static function fromSource(X509Source $source): self
    {
        return new self(null, $source);
    }

    /**
     * Create from vault-agent rendered PEM files (pure-Vault identity, P2).
     * The cert/key/ca are issued + auto-renewed by a Vault PKI vault-agent
     * sidecar; this context re-reads them when they change (mtime version).
     */
    public static function fromVaultFiles(string $certPath, string $keyPath, string $caPath): self
    {
        $ctx = new self(null, null);
        $ctx->vaultFiles = ['cert' => $certPath, 'key' => $keyPath, 'ca' => $caPath];
        return $ctx;
    }

    /**
     * Select a specific SVID by hint (e.g. "internal", "external").
     */
    public function withHint(string $hint): self
    {
        $clone = clone $this;
        $clone->hint = $hint;
        $clone->credential = null;
        return $clone;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Credential management
    // ══════════════════════════════════════════════════════════════════

    /**
     * Get the current TlsCredential, reading from source on first access.
     */
    public function current(): TlsCredential
    {
        if ($this->credential === null) {
            $this->refresh();
        }
        return $this->credential;
    }

    /**
     * Re-read credentials from the source, atomically swapping the
     * in-memory snapshot and cleaning up old temp files.
     *
     * @return bool True if credentials changed (new version)
     */
    public function refresh(): bool
    {
        $new = $this->readFromSource();

        if ($new === null) {
            throw new \RuntimeException('No SPIFFE X.509 credentials available');
        }

        $old = $this->credential;
        $changed = $old === null || $old->version() !== $new->version();

        $this->credential = $new;

        // Clean up old credential's temp files
        if ($old !== null && $changed) {
            $old->cleanup();
        }

        return $changed;
    }

    /**
     * Check if the source has newer credentials than our cached snapshot.
     */
    public function isStale(): bool
    {
        if ($this->credential === null) {
            return true;
        }

        if ($this->reader !== null) {
            return $this->reader->version() !== $this->credential->version();
        }

        if ($this->vaultFiles !== null) {
            return $this->vaultFilesVersion() !== $this->credential->version();
        }

        // Source-based: always considered fresh (Source handles its own cache)
        return false;
    }

    /**
     * Clean up all temp files. Call when done with this context.
     */
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
    //  (Swow hooks PHP streams, so this is the primary SSL API)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Options array for stream_context_create().
     *
     * Usage:
     *   $ctx = stream_context_create($spiffeTls->forStreamContext());
     *   $data = file_get_contents('https://peer:8443/path', false, $ctx);
     *
     * @param bool $verifyPeer  Whether to verify the remote certificate
     * @return array{ssl: array<string, mixed>}
     */
    public function forStreamContext(bool $verifyPeer = true): array
    {
        $cred = $this->current();
        $files = $cred->materializeFiles();

        return [
            'ssl' => [
                'local_cert'        => $files['cert'],
                'local_pk'          => $files['key'],
                'cafile'            => $files['ca'],
                'verify_peer'       => $verifyPeer,
                'verify_peer_name'  => false,  // SPIFFE uses URI SAN, not CN
                'allow_self_signed' => false,
                'capture_peer_cert' => true,    // for post-handshake SPIFFE ID extraction
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
     * Usage:
     *   $client = new \GuzzleHttp\Client($ctx->forGuzzle());
     *   // or per-request:
     *   $response = $client->get('/path', $ctx->forGuzzle());
     *
     * @param bool $verifyPeer  Whether to verify the remote certificate
     * @return array<string, mixed>
     */
    public function forGuzzle(bool $verifyPeer = true): array
    {
        $cred = $this->current();
        $files = $cred->materializeFiles();

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
     * Create a pre-configured Guzzle client with SPIFFE mTLS.
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
     * Usage:
     *   $worker = new Worker('https://0.0.0.0:8443', $ctx->forWorkerman());
     *
     * @param bool $verifyPeer  Whether to verify client certs (mTLS)
     * @return array{ssl: array<string, mixed>}
     */
    public function forWorkerman(bool $verifyPeer = false): array
    {
        $cred = $this->current();
        $files = $cred->materializeFiles();

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
     * Apply SPIFFE mTLS settings to a cURL handle.
     *
     * @param \CurlHandle $ch
     */
    public function applyCurl(\CurlHandle $ch, bool $verifyPeer = true): void
    {
        $cred = $this->current();
        $files = $cred->materializeFiles();

        curl_setopt($ch, CURLOPT_SSLCERT, $files['cert']);
        curl_setopt($ch, CURLOPT_SSLKEY, $files['key']);

        if ($verifyPeer) {
            curl_setopt($ch, CURLOPT_CAINFO, $files['ca']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        // SPIFFE uses URI SANs, not CN — disable hostname check
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Raw accessors
    // ══════════════════════════════════════════════════════════════════

    /**
     * Get the underlying TlsCredential snapshot (may be null before first refresh).
     */
    public function credential(): ?TlsCredential
    {
        return $this->credential;
    }

    /**
     * The SPIFFE ID of the current credential.
     */
    public function spiffeId(): string
    {
        return $this->current()->spiffeId();
    }

    /**
     * The trust domain of the current credential.
     */
    public function trustDomain(): string
    {
        return $this->current()->trustDomain();
    }

    // ══════════════════════════════════════════════════════════════════
    //  Internal: read from source
    // ══════════════════════════════════════════════════════════════════

    private function readFromSource(): ?TlsCredential
    {
        // Mode 1: SpiffeTableReader (shared memory, cross-process)
        if ($this->reader !== null) {
            return $this->readFromReader();
        }

        // Mode 2: X509Source (in-process coroutine)
        if ($this->source !== null) {
            return $this->readFromX509Source();
        }

        // Mode 3: vault-agent rendered PEM files (pure-Vault identity)
        if ($this->vaultFiles !== null) {
            return TlsCredential::fromPemFiles(
                $this->vaultFiles['cert'],
                $this->vaultFiles['key'],
                $this->vaultFiles['ca'],
                $this->vaultFilesVersion(),
            );
        }

        return null;
    }

    /** Version marker for vault files = newest mtime of cert/key. */
    private function vaultFilesVersion(): int
    {
        $c = @filemtime($this->vaultFiles['cert']);
        $k = @filemtime($this->vaultFiles['key']);
        return max(is_int($c) ? $c : 0, is_int($k) ? $k : 0);
    }

    private function readFromReader(): ?TlsCredential
    {
        $row = $this->hint !== null
            ? $this->reader->readX509ByHint($this->hint)
            : $this->reader->readX509Slot($this->slot);

        if ($row === null) {
            return null;
        }

        return new TlsCredential(
            spiffeId:    $row['spiffe_id'],
            trustDomain: $row['trust_domain'],
            certPem:     $row['cert_pem'],
            keyPem:      $row['key_pem'],
            bundlePem:   $row['bundle_pem'],
            version:     $this->reader->version(),
        );
    }

    private function readFromX509Source(): ?TlsCredential
    {
        $svid = $this->hint !== null
            ? $this->source->svidByHint($this->hint)
            : $this->source->currentSvid();

        if ($svid === null) {
            return null;
        }

        return new TlsCredential(
            spiffeId:    (string) $svid->spiffeId(),
            trustDomain: (string) $svid->trustDomain(),
            certPem:     $svid->certChainPem(),
            keyPem:      $svid->privateKeyPem(),
            bundlePem:   $svid->bundlePem(),
            version:     0, // Source-based has no seqlock version
        );
    }
}
