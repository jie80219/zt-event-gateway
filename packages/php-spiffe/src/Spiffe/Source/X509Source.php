<?php

declare(strict_types=1);

namespace Spiffe\Source;

use Spiffe\Bundle\X509Bundle;
use Spiffe\Runtime\ChannelInterface;
use Spiffe\Runtime\RuntimeDetector;
use Spiffe\Runtime\RuntimeInterface;
use Spiffe\SpiffeWorkloadAPIClient;
use Spiffe\TrustDomain;
use Spiffe\Validation\X509SvidValidator;
use Spiffe\X509Svid;
use Spiffe\Workload\X509SVIDResponse;

/**
 * Managed X.509-SVID source with state-machine lifecycle.
 *
 * X509Source subscribes to the SPIRE Agent's FetchX509SVID server stream
 * within a Swow coroutine, maintaining always-fresh X.509 material:
 *
 *  - Automatic rotation: when the Agent pushes a new X509SVIDResponse,
 *    the source validates and atomically swaps the cached material
 *  - Resilient reconnection: on stream interruption, backs off and
 *    re-establishes the watch
 *  - Safe reads: callers access the current SVID/bundle snapshot via
 *    lock-free reads (Swow single-thread coroutine model)
 *  - Observer hooks: register callbacks for rotation, state changes,
 *    and errors
 *
 * State machine:
 *
 *   Idle ──▶ Initializing ──▶ Ready ⇄ Rotating
 *                │                        │
 *                ▼                        ▼
 *              Error ◀────────────────  Error
 *                │                        │
 *                ▼                        ▼
 *              Closed ◀── (any state via close())
 *
 * Usage (inside a Swow coroutine context):
 *
 *   $source = new X509Source();
 *   $source->start();                       // non-blocking, spawns watcher coroutine
 *   $svid = $source->currentSvid();         // blocks until Ready on first call
 *   $bundle = $source->currentBundle();
 *   // ... use $svid->certChainPem() for mTLS ...
 *   $source->close();
 */
final class X509Source
{
    private SourceState $state = SourceState::Idle;
    private SourceConfig $config;
    private RuntimeInterface $runtime;
    private ?SpiffeWorkloadAPIClient $client = null;

    /** @var list<X509Svid> Current X.509 SVIDs (primary + any extras) */
    private array $svids = [];

    /** @var array<string, X509Bundle> Keyed by trust domain name */
    private array $bundles = [];

    /** @var array<string, X509Bundle> Federated bundles keyed by trust domain */
    private array $federatedBundles = [];

    private int $consecutiveErrors = 0;
    private ?\Throwable $lastError = null;

    /** @var list<callable(list<X509Svid>, array<string, X509Bundle>): void> */
    private array $onRotated = [];

    /** @var list<callable(SourceState, SourceState): void> */
    private array $onStateChange = [];

    /** @var list<callable(\Throwable): void> */
    private array $onError = [];

    private ?ChannelInterface $readyChannel = null;
    private ?int $watcherCid = null;

    public function __construct(?SourceConfig $config = null, ?RuntimeInterface $runtime = null)
    {
        $this->config = $config ?? new SourceConfig();
        $this->runtime = $runtime ?? RuntimeDetector::detect();
    }

    // ══════════════════════════════════════════════════════════════════
    //  Lifecycle
    // ══════════════════════════════════════════════════════════════════

    /**
     * Start the background watcher coroutine.
     *
     * Transitions: Idle → Initializing
     *
     * This method returns immediately. The watcher coroutine will:
     *  1. Connect to the SPIRE Agent
     *  2. Send FetchX509SVID
     *  3. On first response: validate, cache, transition → Ready
     *  4. On subsequent responses: transition Ready → Rotating → Ready
     *  5. On error: transition → Error, backoff, retry
     *
     * @throws \LogicException if called from a non-Idle state
     */
    public function start(): void
    {
        $this->assertTransition(SourceState::Initializing);
        $this->transition(SourceState::Initializing);

        $this->readyChannel = $this->runtime->createChannel(1);

        $this->watcherCid = $this->runtime->spawn(function () {
            $this->watchLoop();
        });
    }

    /**
     * Shut down the source and release all resources.
     *
     * Transitions: (any) → Closed
     */
    public function close(): void
    {
        if ($this->state === SourceState::Closed) {
            return;
        }

        $this->transition(SourceState::Closed);

        // Signal any waiters in awaitReady()
        if ($this->readyChannel !== null && $this->readyChannel->consumerCount() > 0) {
            $this->readyChannel->push(false);
        }

        // Close the gRPC client
        if ($this->client !== null) {
            $this->client->close();
            $this->client = null;
        }

        $this->svids = [];
        $this->bundles = [];
        $this->federatedBundles = [];
    }

    // ══════════════════════════════════════════════════════════════════
    //  Material accessors (coroutine-safe reads)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Get the current primary X.509-SVID.
     *
     * If the source is still initializing, this blocks (in a coroutine-
     * friendly way) until the first material arrives.
     *
     * @throws \RuntimeException if the source is closed or permanently errored
     */
    public function currentSvid(): X509Svid
    {
        $this->awaitReady();

        if ($this->svids === []) {
            throw new \RuntimeException('No X.509-SVID available');
        }

        return $this->svids[0];
    }

    /**
     * Get all current X.509-SVIDs (multiple identities).
     *
     * @return list<X509Svid>
     */
    public function currentSvids(): array
    {
        $this->awaitReady();
        return $this->svids;
    }

    /**
     * Find a specific SVID by its SPIFFE ID hint.
     */
    public function svidByHint(string $hint): ?X509Svid
    {
        $this->awaitReady();

        foreach ($this->svids as $svid) {
            if ($svid->hint() === $hint) {
                return $svid;
            }
        }

        return null;
    }

    /**
     * Get the X.509 bundle for a given trust domain.
     */
    public function bundleForTrustDomain(TrustDomain $td): ?X509Bundle
    {
        $this->awaitReady();
        return $this->bundles[$td->name()] ?? $this->federatedBundles[$td->name()] ?? null;
    }

    /**
     * Get the current primary bundle (same trust domain as the primary SVID).
     */
    public function currentBundle(): X509Bundle
    {
        $this->awaitReady();
        $svid = $this->currentSvid();
        $tdName = $svid->trustDomain()->name();

        return $this->bundles[$tdName]
            ?? throw new \RuntimeException("No X.509 bundle for trust domain {$tdName}");
    }

    // ══════════════════════════════════════════════════════════════════
    //  State queries
    // ══════════════════════════════════════════════════════════════════

    public function state(): SourceState
    {
        return $this->state;
    }

    public function lastError(): ?\Throwable
    {
        return $this->lastError;
    }

    public function isReady(): bool
    {
        return $this->state->hasMaterial();
    }

    // ══════════════════════════════════════════════════════════════════
    //  Observer registration
    // ══════════════════════════════════════════════════════════════════

    /**
     * Called after successful rotation with the new SVID set and bundles.
     *
     * @param callable(list<X509Svid>, array<string, X509Bundle>): void $callback
     */
    public function onRotated(callable $callback): self
    {
        $this->onRotated[] = $callback;
        return $this;
    }

    /**
     * Called on every state transition.
     *
     * @param callable(SourceState, SourceState): void $callback
     */
    public function onStateChange(callable $callback): self
    {
        $this->onStateChange[] = $callback;
        return $this;
    }

    /**
     * Called when an error occurs (connection loss, validation failure).
     *
     * @param callable(\Throwable): void $callback
     */
    public function onError(callable $callback): self
    {
        $this->onError[] = $callback;
        return $this;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Internal: watch loop
    // ══════════════════════════════════════════════════════════════════

    private function watchLoop(): void
    {
        while ($this->state !== SourceState::Closed) {
            try {
                $this->client = new SpiffeWorkloadAPIClient(
                    $this->config->socketPath,
                    $this->config->connectTimeout,
                    $this->config->streamTimeout > 0 ? $this->config->streamTimeout : 30.0,
                );

                $isFirst = !$this->state->hasMaterial();

                $this->client->watchX509Svid(function (X509SVIDResponse $response) use (&$isFirst): bool {
                    if ($this->state === SourceState::Closed) {
                        return false; // stop watching
                    }

                    try {
                        $this->applyResponse($response, $isFirst);
                        $isFirst = false;
                        $this->consecutiveErrors = 0;
                    } catch (\Throwable $e) {
                        $this->handleError($e);
                    }

                    return $this->state !== SourceState::Closed;
                });

            } catch (\Throwable $e) {
                if ($this->state === SourceState::Closed) {
                    break;
                }
                $this->handleError($e);
            }

            // If we get here, the stream ended or errored — retry unless closed
            if ($this->state === SourceState::Closed) {
                break;
            }

            // Backoff before reconnecting
            $this->consecutiveErrors++;

            if ($this->config->maxRetries > 0 && $this->consecutiveErrors > $this->config->maxRetries) {
                if ($this->readyChannel !== null && $this->readyChannel->consumerCount() > 0) {
                    $this->readyChannel->push(false);
                }
                break;
            }

            $delay = $this->config->backoffDelay($this->consecutiveErrors);
            $this->runtime->sleep($delay);

            // Re-enter Initializing for reconnect
            if ($this->state === SourceState::Error) {
                $this->transition(SourceState::Initializing);
            }
        }
    }

    /**
     * Parse, validate, and atomically swap in new material from a stream response.
     */
    private function applyResponse(X509SVIDResponse $response, bool $isFirst): void
    {
        // If we already have material, signal we're rotating
        if (!$isFirst && $this->state === SourceState::Ready) {
            $this->transition(SourceState::Rotating);
        }

        // Parse SVIDs
        $newSvids = [];
        foreach ($response->getSvids() as $proto) {
            $newSvids[] = X509Svid::fromProto($proto);
        }

        if ($newSvids === []) {
            throw new \RuntimeException('X509SVIDResponse contains no SVIDs');
        }

        // Parse bundles from each SVID's embedded bundle
        $newBundles = [];
        foreach ($newSvids as $svid) {
            $tdName = $svid->trustDomain()->name();
            if (!isset($newBundles[$tdName]) && $svid->bundleDer() !== '') {
                $newBundles[$tdName] = X509Bundle::fromDer($svid->trustDomain(), $svid->bundleDer());
            }
        }

        // Parse federated bundles
        $newFederated = [];
        $federatedMap = $response->getFederatedBundles();
        if ($federatedMap !== null) {
            foreach ($federatedMap as $tdUri => $derBytes) {
                $td = TrustDomain::parse($tdUri);
                $newFederated[$td->name()] = X509Bundle::fromDer($td, $derBytes);
            }
        }

        // Optional: validate each SVID against its bundle
        if ($this->config->validateOnRotation) {
            $validator = new X509SvidValidator($this->config->allowedClockSkew);
            foreach ($newSvids as $svid) {
                $tdName = $svid->trustDomain()->name();
                $bundle = $newBundles[$tdName] ?? null;
                if ($bundle !== null) {
                    $result = $validator->validate($svid, $bundle);
                    if (!$result->isValid()) {
                        throw new \RuntimeException(
                            'X.509 SVID validation failed during rotation: '
                            . implode('; ', $result->errors())
                        );
                    }
                }
            }
        }

        // Atomic swap
        $this->svids = $newSvids;
        $this->bundles = $newBundles;
        $this->federatedBundles = $newFederated;

        // Transition to Ready
        $this->transition(SourceState::Ready);

        // Signal first-ready waiters
        if ($isFirst && $this->readyChannel !== null) {
            $this->readyChannel->push(true);
        }

        // Notify observers
        foreach ($this->onRotated as $cb) {
            try {
                $cb($this->svids, $this->bundles);
            } catch (\Throwable) {
                // Don't let observer errors break the source
            }
        }
    }

    private function handleError(\Throwable $e): void
    {
        $this->lastError = $e;

        if ($this->state !== SourceState::Closed) {
            $this->transition(SourceState::Error);
        }

        foreach ($this->onError as $cb) {
            try {
                $cb($e);
            } catch (\Throwable) {
                // Swallow observer errors
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  Internal: state machine
    // ══════════════════════════════════════════════════════════════════

    private function transition(SourceState $target): void
    {
        $from = $this->state;

        if ($from === $target) {
            return;
        }

        if (!$from->canTransitionTo($target)) {
            throw new \LogicException(
                "Invalid state transition: {$from->value} → {$target->value}"
            );
        }

        $this->state = $target;

        foreach ($this->onStateChange as $cb) {
            try {
                $cb($from, $target);
            } catch (\Throwable) {
                // Don't break state machine on observer errors
            }
        }
    }

    private function assertTransition(SourceState $target): void
    {
        if (!$this->state->canTransitionTo($target)) {
            throw new \LogicException(
                "Cannot transition from {$this->state->value} to {$target->value}"
            );
        }
    }

    /**
     * Block the current coroutine until the source has material or fails permanently.
     */
    private function awaitReady(): void
    {
        if ($this->state->hasMaterial()) {
            return;
        }

        if ($this->state === SourceState::Closed) {
            throw new \RuntimeException('X509Source is closed');
        }

        if ($this->state === SourceState::Idle) {
            throw new \RuntimeException('X509Source has not been started — call start() first');
        }

        // Block on the ready channel (coroutine-friendly)
        if ($this->readyChannel !== null) {
            $result = $this->readyChannel->pop();
            if ($result !== true) {
                throw new \RuntimeException(
                    'X509Source failed to initialize: '
                    . ($this->lastError?->getMessage() ?? 'unknown error')
                );
            }
        }
    }
}
