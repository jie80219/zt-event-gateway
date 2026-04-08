<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\Spiffe\LSVID;

use SDPMlab\LSVID\SvidReader;
use Spiffe\SharedMemory\SpiffeTableReader;

/**
 * Adapter that exposes the package-level {@see SpiffeTableReader}
 * (which lives in its own namespace and is {@code final}) as a
 * {@see SvidReader}. This keeps the LSVID subsystem decoupled from
 * the filesystem-backed implementation and lets tests substitute
 * an ephemeral in-memory reader.
 */
final class SpiffeTableSvidReader implements SvidReader
{
    public function __construct(
        private readonly SpiffeTableReader $reader,
    ) {
    }

    public function readX509Primary(): ?array
    {
        return $this->reader->readX509Primary();
    }
}
