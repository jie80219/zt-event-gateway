<?php

declare(strict_types=1);

namespace Spiffe\Source;

/**
 * Shared configuration for X509Source and JwtSource.
 *
 * Controls retry strategy, timeouts, and validation parameters.
 * Immutable once constructed — use the fluent with*() methods to derive variants.
 */
final class SourceConfig
{
    public function __construct(
        /** SPIRE Agent socket path */
        public readonly string $socketPath = 'unix:/tmp/spire-agent/public/api.sock',

        /** Maximum consecutive reconnect attempts before entering permanent Error state. 0 = unlimited. */
        public readonly int $maxRetries = 0,

        /** Initial backoff delay between retries (seconds). Doubles on each attempt up to maxBackoff. */
        public readonly float $initialBackoff = 1.0,

        /** Maximum backoff delay (seconds). */
        public readonly float $maxBackoff = 30.0,

        /** gRPC connection timeout (seconds). */
        public readonly float $connectTimeout = 5.0,

        /** gRPC stream read timeout (seconds). */
        public readonly float $streamTimeout = 0.0,

        /** Allowed clock skew for certificate/token expiration checks (seconds). */
        public readonly int $allowedClockSkew = 60,

        /** Whether to validate received SVIDs against the trust bundle locally. */
        public readonly bool $validateOnRotation = true,
    ) {}

    public function withSocketPath(string $path): self
    {
        return new self($path, $this->maxRetries, $this->initialBackoff, $this->maxBackoff, $this->connectTimeout, $this->streamTimeout, $this->allowedClockSkew, $this->validateOnRotation);
    }

    public function withMaxRetries(int $maxRetries): self
    {
        return new self($this->socketPath, $maxRetries, $this->initialBackoff, $this->maxBackoff, $this->connectTimeout, $this->streamTimeout, $this->allowedClockSkew, $this->validateOnRotation);
    }

    public function withBackoff(float $initial, float $max): self
    {
        return new self($this->socketPath, $this->maxRetries, $initial, $max, $this->connectTimeout, $this->streamTimeout, $this->allowedClockSkew, $this->validateOnRotation);
    }

    /**
     * Calculate backoff delay for a given attempt number (exponential with cap).
     */
    public function backoffDelay(int $attempt): float
    {
        $delay = $this->initialBackoff * (2 ** ($attempt - 1));
        return min($delay, $this->maxBackoff);
    }
}
