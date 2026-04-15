<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\Spiffe;

use SDPMlab\LSVID\JtiReplayCache;
use SDPMlab\LSVID\LSVIDSigner;
use SDPMlab\LSVID\LSVIDValidator;
use SDPMlab\LSVID\SvidReader;
use SDPMlab\ZtEventGateway\Spiffe\LSVID\SpiffeTableSvidReader;
use Spiffe\SharedMemory\SpiffeTableReader;

/**
 * @internal Adapter that picks a specific SVID out of the SHM reader
 *           based on the caller's own SPIFFE ID. Since the watcher
 *           stores every attested SVID in a shared slot table, each
 *           process must scan for its own identity instead of trusting
 *           slot 0.
 */
final class _SpiffeIdFilteredSvidReader implements SvidReader
{
    public function __construct(
        private readonly SpiffeTableReader $reader,
        private readonly string $spiffeId,
    ) {
    }

    public function readX509Primary(): ?array
    {
        foreach ($this->reader->readAllX509() as $row) {
            if (($row['spiffe_id'] ?? '') === $this->spiffeId) {
                return $row;
            }
        }
        return null;
    }
}

/**
 * Unified SPIFFE / LSVID bootstrap for Gateway and Worker.
 *
 * Single source of truth: the shared-memory store populated by the
 * spiffe-watcher daemon. This facade replaces the per-binary bootstrap
 * that used to read PEM files via FileSvidReader — those files are kept
 * only as a compatibility output for external tools (Envoy, curl).
 *
 * Usage:
 *
 *   $boot = SpiffeBootstrap::fromShm($shmDir, [
 *       'trust_domain'                  => 'zt.local',
 *       'await_timeout'                 => 30.0,
 *       'clock_skew_seconds'            => 30,
 *       'require_audience_on_all_levels'=> true,
 *   ]);
 *
 *   $signer            = $boot->signer();
 *   $consumerValidator = $boot->validator(withJtiCache: true);
 *   $filterValidator   = $boot->validator(withJtiCache: false);
 *   $reader            = $boot->shmReader();        // for watchVersion()
 *   $primary           = $boot->primary();          // initial SVID snapshot
 */
final class SpiffeBootstrap
{
    private readonly SpiffeTableReader $shm;
    private readonly SvidReader $svidReader;
    private readonly array $options;

    private function __construct(SpiffeTableReader $shm, SvidReader $svidReader, array $options)
    {
        $this->shm = $shm;
        $this->svidReader = $svidReader;
        $this->options = $options;
    }

    /**
     * @param array{
     *     trust_domain?: string,
     *     await_timeout?: float,
     *     clock_skew_seconds?: int,
     *     require_nbf?: bool,
     *     require_audience_on_all_levels?: bool,
     *     spiffe_id?: string,
     * } $options
     */
    public static function fromShm(string $shmDir, array $options = []): self
    {
        $shm = new SpiffeTableReader($shmDir);
        $shm->awaitReady(timeout: (float) ($options['await_timeout'] ?? 30.0));

        // If the caller knows its own SPIFFE ID, hand out a reader that
        // returns that specific SVID (not whatever lives in slot 0). This
        // matters when the watcher attests multiple workloads — slot 0
        // might belong to a sibling like php-gateway while the caller is
        // actually php-worker.
        $preferredId = (string) ($options['spiffe_id'] ?? '');
        $svidReader = $preferredId !== ''
            ? new _SpiffeIdFilteredSvidReader($shm, $preferredId)
            : new SpiffeTableSvidReader($shm);

        return new self($shm, $svidReader, $options);
    }

    public function shmReader(): SpiffeTableReader
    {
        return $this->shm;
    }

    public function svidReader(): SvidReader
    {
        return $this->svidReader;
    }

    /**
     * @return array{spiffe_id:string, trust_domain:string, cert_pem:string, key_pem:string, bundle_pem:string, hint:string, updated_at:int}|null
     */
    public function primary(): ?array
    {
        return $this->svidReader->readX509Primary();
    }

    public function version(): int
    {
        return $this->shm->version();
    }

    public function signer(): LSVIDSigner
    {
        $ttlEnv = getenv('LSVID_TTL_SECONDS');
        $ttl = ($ttlEnv !== false && ctype_digit($ttlEnv) && (int) $ttlEnv > 0)
            ? (int) $ttlEnv
            : 1800;
        return new LSVIDSigner($this->svidReader, $ttl);
    }

    /**
     * Build a validator. Pass `withJtiCache: true` for the consumer path
     * (RequestConsumer / EventConsumer) so replayed envelopes are rejected;
     * pass `false` for the outbound-extend path (SpiffeLsvidFilter) where
     * jtiCache would false-positive on re-validation of the inbound token.
     */
    public function validator(bool $withJtiCache, ?JtiReplayCache $cache = null): LSVIDValidator
    {
        return new LSVIDValidator(
            $this->svidReader,
            clockSkewSeconds:            (int) ($this->options['clock_skew_seconds'] ?? 30),
            jtiCache:                    $withJtiCache ? ($cache ?? new JtiReplayCache()) : null,
            trustDomain:                 (string) ($this->options['trust_domain'] ?? 'zt.local'),
            requireNbf:                  (bool) ($this->options['require_nbf'] ?? false),
            requireAudienceOnAllLevels:  (bool) ($this->options['require_audience_on_all_levels'] ?? true),
        );
    }
}
