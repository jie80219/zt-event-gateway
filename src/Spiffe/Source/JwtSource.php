<?php

declare(strict_types=1);

namespace Spiffe\Source;

use Spiffe\Bundle\JwtBundle;
use Spiffe\JwtSvid;
use Spiffe\Runtime\ChannelInterface;
use Spiffe\Runtime\RuntimeDetector;
use Spiffe\Runtime\RuntimeInterface;
use Spiffe\SpiffeWorkloadAPIClient;
use Spiffe\TrustDomain;
use Spiffe\Validation\JwtSvidValidator;
use Spiffe\Workload\JWTBundlesResponse;
use Spiffe\Workload\JWTSVIDRequest;

/**
 * Managed JWT-SVID source with state-machine lifecycle.
 *
 * JwtSource has a dual-mode design:
 *
 *  1. **Bundle watcher** (background stream) — subscribes to FetchJWTBundles
 *     to keep the JWKS trust material always fresh. This is the stream that
 *     drives the state machine.
 *
 *  2. **Token fetcher** (on-demand unary) — calls FetchJWTSVID when a
 *     consumer needs a fresh JWT-SVID for a specific audience. Tokens are
 *     short-lived and audience-scoped, so they are NOT cached by default.
 *
 * State machine (same as X509Source):
 *
 *   Idle ──▶ Initializing ──▶ Ready ⇄ Rotating
 *                │                        │
 *                ▼                        ▼
 *              Error ◀────────────────  Error
 *                │                        │
 *                ▼                        ▼
 *              Closed ◀── (any state via close())
 *
 * Usage (inside Swoole\Coroutine\run):
 *
 *   $source = new JwtSource();
 *   $source->start();                                 // spawns bundle watcher
 *   $svid = $source->fetchSvid(['my-service']);       // on-demand token fetch
 *   $bundle = $source->bundleForTrustDomain($td);     // cached bundle
 *   $source->close();
 */
final class JwtSource
{
    private SourceState $state = SourceState::Idle;
    private SourceConfig $config;
    private RuntimeInterface $runtime;

    private ?SpiffeWorkloadAPIClient $streamClient = null;
    private ?SpiffeWorkloadAPIClient $fetchClient = null;

    /** @var array<string, JwtBundle> Keyed by trust domain name */
    private array $bundles = [];

    private int $consecutiveErrors = 0;
    private ?\Throwable $lastError = null;

    /** @var list<callable(array<string, JwtBundle>): void> */
    private array $onBundleUpdated = [];

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
     * Start the background bundle watcher coroutine.
     *
     * Transitions: Idle → Initializing
     *
     * @throws \LogicException if called from a non-Idle state
     */
    public function start(): void
    {
        $this->assertTransition(SourceState::Initializing);
        $this->transition(SourceState::Initializing);

        $this->readyChannel = $this->runtime->createChannel(1);

        $this->watcherCid = $this->runtime->spawn(function () {
            $this->bundleWatchLoop();
        });
    }

    /**
     * Shut down the source and release all resources.
     */
    public function close(): void
    {
        if ($this->state === SourceState::Closed) {
            return;
        }

        $this->transition(SourceState::Closed);

        if ($this->readyChannel !== null && $this->readyChannel->consumerCount() > 0) {
            $this->readyChannel->push(false);
        }

        if ($this->streamClient !== null) {
            $this->streamClient->close();
            $this->streamClient = null;
        }

        if ($this->fetchClient !== null) {
            $this->fetchClient->close();
            $this->fetchClient = null;
        }

        $this->bundles = [];
    }

    // ══════════════════════════════════════════════════════════════════
    //  Token fetcher (on-demand unary RPC)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Fetch a fresh JWT-SVID for the given audience.
     *
     * This is a synchronous (coroutine-blocking) call to the SPIRE Agent's
     * FetchJWTSVID RPC. The returned token is NOT cached — each call
     * produces a freshly-minted JWT.
     *
     * @param list<string> $audience  One or more audience values
     * @param string|null  $spiffeId  Optional: request a token for a specific SPIFFE ID
     * @param bool         $validate  Whether to validate the token against the cached bundle
     *
     * @return JwtSvid
     * @throws \RuntimeException if the source is not ready or the fetch fails
     */
    public function fetchSvid(array $audience, ?string $spiffeId = null, bool $validate = true): JwtSvid
    {
        $this->awaitReady();

        $this->ensureFetchClient();

        $request = new JWTSVIDRequest();
        $request->setAudience($audience);
        if ($spiffeId !== null) {
            $request->setSpiffeId($spiffeId);
        }

        $response = $this->fetchClient->fetchJwtSvid($request);

        $protoSvids = $response->getSvids();
        if ($protoSvids === null || count($protoSvids) === 0) {
            throw new \RuntimeException('FetchJWTSVID returned no SVIDs');
        }

        $jwtSvid = JwtSvid::fromProto($protoSvids[0]);

        // Optional local validation against the cached bundle
        if ($validate && $audience !== []) {
            $tdName = $jwtSvid->trustDomain()->name();
            $bundle = $this->bundles[$tdName] ?? null;

            if ($bundle !== null) {
                $validator = new JwtSvidValidator($this->config->allowedClockSkew);
                $result = $validator->validate($jwtSvid, $bundle, $audience[0]);
                if (!$result->isValid()) {
                    throw new \RuntimeException(
                        'JWT-SVID validation failed: ' . implode('; ', $result->errors())
                    );
                }
            }
        }

        return $jwtSvid;
    }

    /**
     * Fetch all JWT-SVIDs for the given audience.
     *
     * @param list<string> $audience
     * @return list<JwtSvid>
     */
    public function fetchSvids(array $audience, ?string $spiffeId = null): array
    {
        $this->awaitReady();

        $this->ensureFetchClient();

        $request = new JWTSVIDRequest();
        $request->setAudience($audience);
        if ($spiffeId !== null) {
            $request->setSpiffeId($spiffeId);
        }

        $response = $this->fetchClient->fetchJwtSvid($request);

        $svids = [];
        foreach ($response->getSvids() as $proto) {
            $svids[] = JwtSvid::fromProto($proto);
        }

        return $svids;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Bundle accessors (coroutine-safe reads)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Get the JWT bundle for a given trust domain.
     */
    public function bundleForTrustDomain(TrustDomain $td): ?JwtBundle
    {
        $this->awaitReady();
        return $this->bundles[$td->name()] ?? null;
    }

    /**
     * Get all currently cached JWT bundles.
     *
     * @return array<string, JwtBundle>
     */
    public function bundles(): array
    {
        $this->awaitReady();
        return $this->bundles;
    }

    /**
     * Validate an externally-received JWT-SVID against cached bundles.
     *
     * Useful for verifying tokens received from peer workloads.
     *
     * @param string $rawToken  The raw JWT string
     * @param string $audience  The expected audience
     *
     * @return JwtSvid The validated JWT-SVID entity
     * @throws \RuntimeException if validation fails
     */
    public function validateToken(string $rawToken, string $audience): JwtSvid
    {
        $this->awaitReady();

        // Build a temporary proto to parse the token
        $proto = new \Spiffe\Workload\JWTSVID();

        // Decode the JWT to extract the sub claim for SPIFFE ID
        $parts = explode('.', $rawToken);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid JWT token structure');
        }

        $payload = json_decode(
            base64_decode(strtr($parts[1], '-_', '+/'), true),
            true,
        );

        $sub = $payload['sub'] ?? '';
        if ($sub === '') {
            throw new \RuntimeException('JWT token missing "sub" claim');
        }

        $proto->setSpiffeId($sub);
        $proto->setSvid($rawToken);

        $jwtSvid = JwtSvid::fromProto($proto);

        // Find the bundle for this token's trust domain
        $tdName = $jwtSvid->trustDomain()->name();
        $bundle = $this->bundles[$tdName] ?? null;

        if ($bundle === null) {
            throw new \RuntimeException("No JWT bundle available for trust domain: {$tdName}");
        }

        $validator = new JwtSvidValidator($this->config->allowedClockSkew);
        $result = $validator->validate($jwtSvid, $bundle, $audience);
        $result->throwOnFailure();

        return $jwtSvid;
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
     * Called after JWT bundles are updated.
     *
     * @param callable(array<string, JwtBundle>): void $callback
     */
    public function onBundleUpdated(callable $callback): self
    {
        $this->onBundleUpdated[] = $callback;
        return $this;
    }

    /**
     * @param callable(SourceState, SourceState): void $callback
     */
    public function onStateChange(callable $callback): self
    {
        $this->onStateChange[] = $callback;
        return $this;
    }

    /**
     * @param callable(\Throwable): void $callback
     */
    public function onError(callable $callback): self
    {
        $this->onError[] = $callback;
        return $this;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Internal: bundle watch loop
    // ══════════════════════════════════════════════════════════════════

    private function bundleWatchLoop(): void
    {
        while ($this->state !== SourceState::Closed) {
            try {
                $this->streamClient = new SpiffeWorkloadAPIClient(
                    $this->config->socketPath,
                    $this->config->connectTimeout,
                    $this->config->streamTimeout > 0 ? $this->config->streamTimeout : 30.0,
                );

                $isFirst = !$this->state->hasMaterial();

                $this->streamClient->watchJwtBundles(function (JWTBundlesResponse $response) use (&$isFirst): bool {
                    if ($this->state === SourceState::Closed) {
                        return false;
                    }

                    try {
                        $this->applyBundleResponse($response, $isFirst);
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

            if ($this->state === SourceState::Closed) {
                break;
            }

            $this->consecutiveErrors++;

            if ($this->config->maxRetries > 0 && $this->consecutiveErrors > $this->config->maxRetries) {
                if ($this->readyChannel !== null && $this->readyChannel->consumerCount() > 0) {
                    $this->readyChannel->push(false);
                }
                break;
            }

            $delay = $this->config->backoffDelay($this->consecutiveErrors);
            $this->runtime->sleep($delay);

            if ($this->state === SourceState::Error) {
                $this->transition(SourceState::Initializing);
            }
        }
    }

    /**
     * Parse and atomically swap in new JWT bundles from a stream response.
     */
    private function applyBundleResponse(JWTBundlesResponse $response, bool $isFirst): void
    {
        if (!$isFirst && $this->state === SourceState::Ready) {
            $this->transition(SourceState::Rotating);
        }

        $newBundles = [];
        $bundleMap = $response->getBundles();

        if ($bundleMap !== null) {
            foreach ($bundleMap as $tdUri => $jwksBytes) {
                $td = TrustDomain::parse($tdUri);
                $newBundles[$td->name()] = JwtBundle::fromJwks($td, $jwksBytes);
            }
        }

        if ($newBundles === []) {
            throw new \RuntimeException('JWTBundlesResponse contains no bundles');
        }

        // Atomic swap
        $this->bundles = $newBundles;

        $this->transition(SourceState::Ready);

        // Signal first-ready waiters
        if ($isFirst && $this->readyChannel !== null) {
            $this->readyChannel->push(true);
        }

        // Notify observers
        foreach ($this->onBundleUpdated as $cb) {
            try {
                $cb($this->bundles);
            } catch (\Throwable) {
                // Swallow observer errors
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
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  Internal: fetch client management
    // ══════════════════════════════════════════════════════════════════

    /**
     * Lazily create the on-demand fetch client (separate from the stream client).
     */
    private function ensureFetchClient(): void
    {
        if ($this->fetchClient !== null && $this->fetchClient->isConnected()) {
            return;
        }

        $this->fetchClient = new SpiffeWorkloadAPIClient(
            $this->config->socketPath,
            $this->config->connectTimeout,
            $this->config->streamTimeout > 0 ? $this->config->streamTimeout : 30.0,
        );
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

    private function awaitReady(): void
    {
        if ($this->state->hasMaterial()) {
            return;
        }

        if ($this->state === SourceState::Closed) {
            throw new \RuntimeException('JwtSource is closed');
        }

        if ($this->state === SourceState::Idle) {
            throw new \RuntimeException('JwtSource has not been started — call start() first');
        }

        if ($this->readyChannel !== null) {
            $result = $this->readyChannel->pop();
            if ($result !== true) {
                throw new \RuntimeException(
                    'JwtSource failed to initialize: '
                    . ($this->lastError?->getMessage() ?? 'unknown error')
                );
            }
        }
    }
}
