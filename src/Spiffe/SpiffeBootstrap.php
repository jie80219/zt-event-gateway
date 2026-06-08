<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\Spiffe;

use Spiffe\Source\SourceConfig;
use Spiffe\Source\X509Source;
use Spiffe\X509Svid;

/**
 * SPIFFE bootstrap for Gateway and Worker — direct Workload API (gRPC) variant.
 *
 * This is the naïve baseline used by the SPIFFE+KC stack (no LSVID, no SHM).
 * It opens an X509Source against the SPIRE Agent's UDS endpoint, which keeps
 * the SVID material always-fresh via the FetchX509SVID server stream.
 *
 * Usage:
 *   $boot = SpiffeBootstrap::fromUds($socket, ['await_timeout' => 30.0]);
 *   $primary = $boot->primary();    // ['spiffe_id', 'trust_domain', 'cert_pem', 'key_pem', 'bundle_pem', ...]
 *   $source  = $boot->source();     // for onRotated() hooks
 */
final class SpiffeBootstrap
{
    private function __construct(
        private readonly X509Source $source,
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

        return new self($source, $options);
    }

    public function source(): X509Source
    {
        return $this->source;
    }

    /**
     * Project the current SVID to the legacy SHM-row shape so callers don't
     * need to reach into X509Source internals.
     *
     * @return array{spiffe_id:string, trust_domain:string, cert_pem:string, key_pem:string, bundle_pem:string, hint:string, updated_at:int}|null
     */
    public function primary(): ?array
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
