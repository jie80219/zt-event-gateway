<?php

declare(strict_types=1);

namespace Spiffe\TLS;

use Spiffe\Source\X509Source;

/**
 * Central TLS context adapter — bridges SPIFFE credentials from the in-process
 * X509Source into the configuration formats required by each PHP network layer
 * (stream context, Guzzle, Workerman, cURL).
 *
 * Auto-refresh: call refresh() to re-read from the source and swap temp
 * files atomically. The previous TlsCredential's temp files are cleaned up.
 */
final class SpiffeTlsContext
{
    private X509Source $source;
    private ?TlsCredential $credential = null;

    /** @var string|null If set, selects SVID by hint */
    private ?string $hint;

    private function __construct(X509Source $source, ?string $hint = null)
    {
        $this->source = $source;
        $this->hint = $hint;
    }

    /**
     * Create from X509Source (coroutine context, in-process).
     */
    public static function fromSource(X509Source $source): self
    {
        return new self($source);
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

        if ($old !== null && $changed) {
            $old->cleanup();
        }

        return $changed;
    }

    /**
     * Source-based contexts let X509Source manage its own cache, so we never
     * appear stale from here — refresh() is driven by rotation callbacks.
     */
    public function isStale(): bool
    {
        return $this->credential === null;
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
    // ══════════════════════════════════════════════════════════════════

    /**
     * Options array for stream_context_create().
     *
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
                'verify_peer_name'  => false,
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

    public function credential(): ?TlsCredential
    {
        return $this->credential;
    }

    public function spiffeId(): string
    {
        return $this->current()->spiffeId();
    }

    public function trustDomain(): string
    {
        return $this->current()->trustDomain();
    }

    // ══════════════════════════════════════════════════════════════════
    //  Internal: read from source
    // ══════════════════════════════════════════════════════════════════

    private function readFromSource(): ?TlsCredential
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
            version:     0,
        );
    }
}
