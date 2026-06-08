<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\Spiffe;

use Spiffe\SharedMemory\SpiffeTableReader;
use Spiffe\Source\SourceConfig;
use Spiffe\Source\X509Source;
use Spiffe\X509Svid;

/**
 * SPIFFE bootstrap for Gateway and Worker — supports two delivery modes.
 *
 * Mode A — fromUds() : opens an X509Source against the SPIRE Agent UDS
 *                      endpoint directly. Always-fresh, but every PHP
 *                      process holds its own gRPC stream.
 *
 * Mode B — fromShm() : reads SVID material from the shared-memory store
 *                      populated by bin/spiffe-watcher.php. One watcher
 *                      attests for the host; gateway/worker processes
 *                      only memcpy from /tmp/spiffe-shared.
 *
 * Both modes expose primary() returning the canonical SHM-row shape.
 *
 * Usage (SHM mode — the SPIFFE+KC+SHM stack):
 *   $boot = SpiffeBootstrap::fromShm($shmDir, [
 *       'spiffe_id'     => 'spiffe://zt.local/php-worker',
 *       'await_timeout' => 30.0,
 *   ]);
 *   $primary = $boot->primary();
 *   $reader  = $boot->shmReader();
 *
 * Usage (UDS mode — legacy / fallback):
 *   $boot = SpiffeBootstrap::fromUds($socket, ['spiffe_id' => '...']);
 *   $primary = $boot->primary();
 *   $source  = $boot->source();
 */
final class SpiffeBootstrap
{
    private function __construct(
        private readonly ?X509Source $source,
        private readonly ?SpiffeTableReader $shm,
        private readonly array $options,
    ) {
    }

    /**
     * @param array{
     *     trust_domain?: string,
     *     await_timeout?: float,
     *     spiffe_id?: string,
     * } $options
     */
    public static function fromUds(string $socket, array $options = []): self
    {
        $config = new SourceConfig(socketPath: $socket);
        $source = new X509Source($config);
        $source->start();

        $source->currentSvids();

        return new self($source, null, $options);
    }

    /**
     * @param array{
     *     trust_domain?: string,
     *     await_timeout?: float,
     *     spiffe_id?: string,
     * } $options
     */
    public static function fromShm(string $shmDir, array $options = []): self
    {
        $shm = new SpiffeTableReader($shmDir);
        $shm->awaitReady(timeout: (float) ($options['await_timeout'] ?? 30.0));

        return new self(null, $shm, $options);
    }

    public function source(): ?X509Source
    {
        return $this->source;
    }

    public function shmReader(): ?SpiffeTableReader
    {
        return $this->shm;
    }

    public function version(): int
    {
        return $this->shm !== null ? $this->shm->version() : 0;
    }

    /**
     * Project the current SVID to the legacy SHM-row shape so callers don't
     * need to reach into X509Source or SpiffeTableReader internals.
     *
     * @return array{spiffe_id:string, trust_domain:string, cert_pem:string, key_pem:string, bundle_pem:string, hint:string, updated_at:int}|null
     */
    public function primary(): ?array
    {
        if ($this->shm !== null) {
            return $this->primaryFromShm();
        }
        return $this->primaryFromSource();
    }

    private function primaryFromShm(): ?array
    {
        $preferredId = (string) ($this->options['spiffe_id'] ?? '');
        if ($preferredId === '') {
            return $this->shm->readX509Primary();
        }
        foreach ($this->shm->readAllX509() as $row) {
            if (($row['spiffe_id'] ?? '') === $preferredId) {
                return $row;
            }
        }
        return null;
    }

    private function primaryFromSource(): ?array
    {
        try {
            $svid = $this->selectSvid();
            if ($svid === null) {
                return null;
            }
            $bundle = $this->source->bundleForTrustDomain($svid->trustDomain());
            return [
                'spiffe_id'    => $svid->spiffeId()->toString(),
                'trust_domain' => $svid->trustDomain()->name(),
                'cert_pem'     => $svid->certChainPem(),
                'key_pem'      => $svid->privateKeyPem(),
                'bundle_pem'   => $bundle !== null ? $bundle->pem() : '',
                'hint'         => $svid->hint(),
                'updated_at'   => time(),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function selectSvid(): ?X509Svid
    {
        $preferredId = (string) ($this->options['spiffe_id'] ?? '');
        if ($preferredId === '') {
            return $this->source->currentSvid();
        }
        foreach ($this->source->currentSvids() as $svid) {
            if ($svid->spiffeId()->toString() === $preferredId) {
                return $svid;
            }
        }
        return null;
    }
}
