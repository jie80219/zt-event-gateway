<?php

declare(strict_types=1);

namespace Spiffe\Source;

/**
 * State machine states for X509Source and JwtSource.
 *
 * Lifecycle:
 *
 *     ┌──────────────────────────────────────────────────────────────┐
 *     │                                                              │
 *     │   ┌──────┐    start()    ┌──────────────┐   1st response    │
 *     │   │ Idle │──────────────▶│ Initializing │──────────────────┐ │
 *     │   └──────┘               └──────┬───────┘                 │ │
 *     │                                 │ error                   ▼ │
 *     │                                 ▼                  ┌───────┐│
 *     │                          ┌───────────┐   recover   │ Ready ││
 *     │                          │   Error   │────────────▶│       ││
 *     │                          └─────┬─────┘             └───┬───┘│
 *     │                                │ ▲                     │    │
 *     │                                │ │ error               │    │
 *     │                    max retries │ │              update  │    │
 *     │                                │ │  ┌──────────┐       │    │
 *     │                                │ └──│ Rotating │◀──────┘    │
 *     │                                │    └────┬─────┘            │
 *     │                                │         │ done             │
 *     │                                │         ▼                  │
 *     │                                │    ┌───────┐               │
 *     │                                │    │ Ready │               │
 *     │                                │    └───────┘               │
 *     │                                ▼                            │
 *     │   ┌────────┐   close()  ┌──────────┐                       │
 *     │   │ Closed │◀───────────│ any state│◀──────────────────────┘│
 *     │   └────────┘            └──────────┘                        │
 *     └──────────────────────────────────────────────────────────────┘
 */
enum SourceState: string
{
    /** Initial state — not yet started. */
    case Idle = 'idle';

    /** Connecting to SPIRE Agent and waiting for the first response. */
    case Initializing = 'initializing';

    /** Has valid, cached SVID material; actively watching for updates. */
    case Ready = 'ready';

    /** Received new material from the stream; applying and validating. */
    case Rotating = 'rotating';

    /** Connection lost or validation failed; will retry with backoff. */
    case Error = 'error';

    /** Shut down — no longer watching, resources released. */
    case Closed = 'closed';

    /**
     * Legal state transitions.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Idle         => [self::Initializing, self::Closed],
            self::Initializing => [self::Ready, self::Error, self::Closed],
            self::Ready        => [self::Rotating, self::Error, self::Closed],
            self::Rotating     => [self::Ready, self::Error, self::Closed],
            self::Error        => [self::Initializing, self::Closed],
            self::Closed       => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether the source holds usable material in this state.
     */
    public function hasMaterial(): bool
    {
        return match ($this) {
            self::Ready, self::Rotating => true,
            default => false,
        };
    }

    /**
     * Whether the source is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    /**
     * Whether the source is actively connected/watching.
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::Initializing, self::Ready, self::Rotating => true,
            default => false,
        };
    }
}
