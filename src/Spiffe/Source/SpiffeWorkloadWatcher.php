<?php

declare(strict_types=1);

namespace Spiffe\Source;

use Spiffe\Bundle\JwtBundle;
use Spiffe\Bundle\X509Bundle;
use Spiffe\SharedMemory\SpiffeTableStore;
use Spiffe\X509Svid;

/**
 * Top-level orchestrator for the SPIFFE Workload API watcher.
 *
 * Manages both X509Source and JwtSource within a Swoole coroutine runtime,
 * providing a single entry point for the entire SPIFFE credential subsystem.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │                  SpiffeWorkloadWatcher                           │
 * │                                                                  │
 * │  ┌──────────────────┐         ┌──────────────────┐              │
 * │  │   X509Source      │         │   JwtSource       │             │
 * │  │ (stream coroutine)│         │ (stream coroutine)│             │
 * │  │                   │         │                   │             │
 * │  │ FetchX509SVID ◀──┼── UDS ──┼──▶ FetchJWTBundles│             │
 * │  │ server stream     │  SPIRE  │   server stream   │             │
 * │  └────────┬──────────┘  Agent  └────────┬──────────┘             │
 * │           │                              │                       │
 * │     ┌─────▼──────┐                ┌──────▼─────┐                │
 * │     │ PEM writer │                │ On-demand   │                │
 * │     │ (optional)  │               │ FetchJWTSVID│                │
 * │     └─────────────┘               └─────────────┘                │
 * │                                                                  │
 * │  ┌──────────────────────────────────────────────────────┐       │
 * │  │              Signal handler (SIGTERM/SIGINT)          │       │
 * │  │              Graceful shutdown → close() both sources │       │
 * │  └──────────────────────────────────────────────────────┘       │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * Lifecycle:
 *
 *   $watcher = new SpiffeWorkloadWatcher($config);
 *   $watcher->onReady(function ($x509, $jwt) { ... });
 *   $watcher->run();   // blocks — enters Swoole coroutine runtime
 *
 * For non-blocking integration (inside an existing Swoole runtime):
 *
 *   $watcher->start();           // spawns coroutines, returns immediately
 *   $watcher->awaitReady();      // blocks current coroutine until both sources ready
 *   $watcher->x509Source();      // access X.509 material
 *   $watcher->jwtSource();       // access JWT material
 *   $watcher->shutdown();        // graceful close
 */
final class SpiffeWorkloadWatcher
{
    private SourceConfig $config;
    private ?X509Source $x509Source = null;
    private ?JwtSource $jwtSource = null;
    private bool $running = false;

    // ── PEM file output ──────────────────────────────────────────────
    private ?string $pemDir = null;

    // ── Shared memory (Swoole Table) ─────────────────────────────────
    private ?SpiffeTableStore $tableStore = null;

    // ── Observer callbacks ───────────────────────────────────────────
    /** @var list<callable(X509Source, JwtSource): void> */
    private array $onReady = [];

    /** @var list<callable(string, SourceState, SourceState): void> */
    private array $onStateChange = [];

    /** @var list<callable(string, \Throwable): void> */
    private array $onError = [];

    /** @var list<callable(): void> */
    private array $onShutdown = [];

    /** @var callable(string): void */
    private $logger;

    public function __construct(?SourceConfig $config = null)
    {
        $this->config = $config ?? new SourceConfig();
        $this->logger = static function (string $msg): void {
            fwrite(STDOUT, sprintf("[spiffe-watcher] %s %s\n", date('Y-m-d\TH:i:s'), $msg));
        };
    }

    // ══════════════════════════════════════════════════════════════════
    //  Configuration
    // ══════════════════════════════════════════════════════════════════

    /**
     * Enable automatic PEM file writing on each rotation.
     *
     * When set, the watcher writes:
     *   {dir}/svid.pem       — leaf certificate chain
     *   {dir}/svid_key.pem   — private key (mode 0600)
     *   {dir}/bundle.pem     — CA trust bundle
     *
     * These files are atomically replaced (write-to-temp + rename) so
     * downstream consumers (Envoy, nginx, curl) never see partial writes.
     */
    public function withPemOutput(string $directory): self
    {
        $this->pemDir = rtrim($directory, '/');
        return $this;
    }

    /**
     * Enable cross-process credential sharing via Swoole Table.
     *
     * When set, the watcher atomically publishes credentials to shared
     * memory on each rotation. Worker processes use SpiffeTableReader
     * to access the same tables without running their own gRPC streams.
     *
     * IMPORTANT: The Swoole Tables must be created BEFORE Server::start()
     * or process forking. Pass tables created by SpiffeTableSchema::createAll().
     */
    public function withSharedMemory(SpiffeTableStore $store): self
    {
        $this->tableStore = $store;
        return $this;
    }

    /**
     * @param callable(string): void $logger
     */
    public function withLogger(callable $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Observer registration
    // ══════════════════════════════════════════════════════════════════

    /**
     * Called once when BOTH sources have reached Ready state for the first time.
     *
     * @param callable(X509Source, JwtSource): void $callback
     */
    public function onReady(callable $callback): self
    {
        $this->onReady[] = $callback;
        return $this;
    }

    /**
     * Called on state changes from either source.
     *
     * @param callable(string $sourceName, SourceState $from, SourceState $to): void $callback
     */
    public function onStateChange(callable $callback): self
    {
        $this->onStateChange[] = $callback;
        return $this;
    }

    /**
     * Called on errors from either source.
     *
     * @param callable(string $sourceName, \Throwable $error): void $callback
     */
    public function onError(callable $callback): self
    {
        $this->onError[] = $callback;
        return $this;
    }

    /**
     * Called after graceful shutdown completes.
     *
     * @param callable(): void $callback
     */
    public function onShutdown(callable $callback): self
    {
        $this->onShutdown[] = $callback;
        return $this;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Blocking entry point (creates Swoole runtime)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Run the watcher as a standalone blocking process.
     *
     * This creates a Swoole coroutine runtime, starts both sources,
     * installs signal handlers, and blocks until SIGTERM/SIGINT.
     *
     * Use this from bin/spiffe-watcher.php.
     */
    public function run(): void
    {
        \Swoole\Coroutine\run(function () {
            $this->start();
            $this->awaitReady();

            $this->log('Both sources ready — watching for updates');

            // Install signal handlers for graceful shutdown
            $shutdownOnce = false;
            $signalHandler = function () use (&$shutdownOnce) {
                if ($shutdownOnce) {
                    return;
                }
                $shutdownOnce = true;

                $this->log('Received shutdown signal');
                $this->shutdown();
            };

            \Swoole\Coroutine::create(function () use ($signalHandler) {
                // Use Swoole's process signal handling inside coroutine
                $chan = new \Swoole\Coroutine\Channel(1);

                pcntl_signal(SIGTERM, function () use ($chan) {
                    $chan->push(true);
                });
                pcntl_signal(SIGINT, function () use ($chan) {
                    $chan->push(true);
                });

                // Tick loop for signal dispatch
                while ($this->running) {
                    pcntl_signal_dispatch();
                    if (!$chan->isEmpty()) {
                        $signalHandler();
                        break;
                    }
                    \Swoole\Coroutine::sleep(0.5);
                }
            });

            // Block the main coroutine until shutdown
            while ($this->running) {
                \Swoole\Coroutine::sleep(1.0);
            }

            $this->log('Watcher stopped');
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  Non-blocking entry point (for existing Swoole runtimes)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Start both sources within the current Swoole coroutine context.
     *
     * Returns immediately — the sources spawn their own watcher coroutines.
     * Call awaitReady() if you need to block until material is available.
     */
    public function start(): void
    {
        if ($this->running) {
            throw new \LogicException('Watcher is already running');
        }

        $this->running = true;

        $this->x509Source = new X509Source($this->config);
        $this->jwtSource = new JwtSource($this->config);

        // Wire up internal observers
        $this->wireObservers();

        // Wire up PEM file writer if configured
        if ($this->pemDir !== null) {
            $this->wirePemWriter();
        }

        // Wire up shared memory store if configured
        if ($this->tableStore !== null) {
            $this->wireTableStore();
        }

        $this->log(sprintf(
            'Starting SPIFFE watcher — socket: %s, validate: %s',
            $this->config->socketPath,
            $this->config->validateOnRotation ? 'yes' : 'no',
        ));

        $this->x509Source->start();
        $this->jwtSource->start();
    }

    /**
     * Block the current coroutine until both sources are Ready.
     *
     * @param float $timeout Maximum wait time in seconds (0 = unlimited)
     * @throws \RuntimeException if either source fails to initialize
     */
    public function awaitReady(float $timeout = 0): void
    {
        if ($this->x509Source === null || $this->jwtSource === null) {
            throw new \LogicException('Watcher has not been started');
        }

        $deadline = $timeout > 0 ? microtime(true) + $timeout : PHP_FLOAT_MAX;

        // Poll both sources until ready (Swoole coroutine-friendly)
        while (!$this->x509Source->isReady() || !$this->jwtSource->isReady()) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException(sprintf(
                    'Timed out waiting for sources to become ready (x509: %s, jwt: %s)',
                    $this->x509Source->state()->value,
                    $this->jwtSource->state()->value,
                ));
            }

            if (!$this->running) {
                throw new \RuntimeException('Watcher was shut down before sources became ready');
            }

            \Swoole\Coroutine::sleep(0.1);
        }

        // Notify onReady observers
        foreach ($this->onReady as $cb) {
            try {
                $cb($this->x509Source, $this->jwtSource);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Gracefully shut down both sources.
     */
    public function shutdown(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;

        $this->log('Shutting down sources...');

        if ($this->x509Source !== null) {
            $this->x509Source->close();
        }

        if ($this->jwtSource !== null) {
            $this->jwtSource->close();
        }

        foreach ($this->onShutdown as $cb) {
            try {
                $cb();
            } catch (\Throwable) {
            }
        }

        $this->log('Shutdown complete');
    }

    // ══════════════════════════════════════════════════════════════════
    //  Source accessors
    // ══════════════════════════════════════════════════════════════════

    public function x509Source(): X509Source
    {
        if ($this->x509Source === null) {
            throw new \LogicException('Watcher has not been started');
        }
        return $this->x509Source;
    }

    public function jwtSource(): JwtSource
    {
        if ($this->jwtSource === null) {
            throw new \LogicException('Watcher has not been started');
        }
        return $this->jwtSource;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Health check — returns a structured status for monitoring.
     *
     * @return array{
     *     running: bool,
     *     x509: array{state: string, ready: bool, error: string|null},
     *     jwt: array{state: string, ready: bool, error: string|null},
     * }
     */
    public function healthCheck(): array
    {
        return [
            'running' => $this->running,
            'x509' => [
                'state' => $this->x509Source?->state()->value ?? 'not_started',
                'ready' => $this->x509Source?->isReady() ?? false,
                'error' => $this->x509Source?->lastError()?->getMessage(),
            ],
            'jwt' => [
                'state' => $this->jwtSource?->state()->value ?? 'not_started',
                'ready' => $this->jwtSource?->isReady() ?? false,
                'error' => $this->jwtSource?->lastError()?->getMessage(),
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  Internal: observer wiring
    // ══════════════════════════════════════════════════════════════════

    private function wireObservers(): void
    {
        // X509Source observers
        $this->x509Source->onStateChange(function (SourceState $from, SourceState $to) {
            $this->log("X509Source: {$from->value} → {$to->value}");
            foreach ($this->onStateChange as $cb) {
                try {
                    $cb('x509', $from, $to);
                } catch (\Throwable) {
                }
            }
        });

        $this->x509Source->onError(function (\Throwable $e) {
            $this->log("X509Source error: {$e->getMessage()}");
            foreach ($this->onError as $cb) {
                try {
                    $cb('x509', $e);
                } catch (\Throwable) {
                }
            }
        });

        $this->x509Source->onRotated(function (array $svids, array $bundles) {
            $ids = array_map(fn(X509Svid $s) => (string) $s->spiffeId(), $svids);
            $this->log('X509 rotated: ' . implode(', ', $ids));
        });

        // JwtSource observers
        $this->jwtSource->onStateChange(function (SourceState $from, SourceState $to) {
            $this->log("JwtSource: {$from->value} → {$to->value}");
            foreach ($this->onStateChange as $cb) {
                try {
                    $cb('jwt', $from, $to);
                } catch (\Throwable) {
                }
            }
        });

        $this->jwtSource->onError(function (\Throwable $e) {
            $this->log("JwtSource error: {$e->getMessage()}");
            foreach ($this->onError as $cb) {
                try {
                    $cb('jwt', $e);
                } catch (\Throwable) {
                }
            }
        });

        $this->jwtSource->onBundleUpdated(function (array $bundles) {
            $domains = array_keys($bundles);
            $this->log('JWT bundles updated: ' . implode(', ', $domains));
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  Internal: PEM file writer
    // ══════════════════════════════════════════════════════════════════

    /**
     * Wire the PEM file writer to X509Source rotation events.
     *
     * On each rotation, writes:
     *   {pemDir}/svid.pem       — certificate chain (leaf first)
     *   {pemDir}/svid_key.pem   — PKCS#8 private key
     *   {pemDir}/bundle.pem     — CA trust bundle
     *
     * Uses atomic write (temp file + rename) to prevent partial reads.
     */
    private function wirePemWriter(): void
    {
        if (!is_dir($this->pemDir)) {
            mkdir($this->pemDir, 0755, true);
        }

        $pemDir = $this->pemDir;

        $this->x509Source->onRotated(function (array $svids) use ($pemDir) {
            if ($svids === []) {
                return;
            }

            /** @var X509Svid $primary */
            $primary = $svids[0];

            try {
                self::atomicWrite("{$pemDir}/svid.pem", $primary->certChainPem(), 0644);
                self::atomicWrite("{$pemDir}/svid_key.pem", $primary->privateKeyPem(), 0600);
                self::atomicWrite("{$pemDir}/bundle.pem", $primary->bundlePem(), 0644);

                $this->log("PEM files written to {$pemDir}/");
            } catch (\Throwable $e) {
                $this->log("Failed to write PEM files: {$e->getMessage()}");
            }
        });
    }

    /**
     * Write content to a file atomically using temp-file + rename.
     */
    private static function atomicWrite(string $path, string $content, int $mode): void
    {
        $dir = dirname($path);
        $tmp = tempnam($dir, '.spiffe_');

        if ($tmp === false) {
            throw new \RuntimeException("Failed to create temp file in {$dir}");
        }

        file_put_contents($tmp, $content);
        chmod($tmp, $mode);
        rename($tmp, $path);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Internal: Swoole Table shared memory writer
    // ══════════════════════════════════════════════════════════════════

    /**
     * Wire the Swoole Table store to source lifecycle events.
     *
     * On each rotation:
     *   - X.509 SVIDs → serialized as PEM into spiffe_x509 table
     *   - JWT bundles → serialized as JWKS JSON into spiffe_jwt table
     *   - Source states → written to spiffe_meta table
     *
     * All writes use the seqlock protocol (odd = writing, even = done).
     */
    private function wireTableStore(): void
    {
        $store = $this->tableStore;

        // Publish X.509 credentials on each rotation
        $this->x509Source->onRotated(function (array $svids) use ($store) {
            try {
                $store->publishX509($svids);
                $store->clearError();
                $this->log(sprintf(
                    'Shared memory: published %d X.509 SVID(s) (version %d)',
                    count($svids),
                    $store->metaTable()->get('global', 'version'),
                ));
            } catch (\Throwable $e) {
                $store->updateError("X509 publish failed: {$e->getMessage()}");
                $this->log("Shared memory X509 publish error: {$e->getMessage()}");
            }
        });

        // Publish JWT bundles on each update
        $this->jwtSource->onBundleUpdated(function (array $bundles) use ($store) {
            try {
                // Serialize JwtBundle objects to JWKS JSON for table storage
                // We need the raw JWKS bytes, but bundles are already parsed.
                // Re-extract from the table-friendly format:
                // We store the trust domain → JWKS JSON mapping.
                $bundleMap = [];
                foreach ($bundles as $tdName => $bundle) {
                    // Reconstruct a minimal JWKS from the bundle's key data
                    $keys = [];
                    foreach ($bundle->keyIds() as $kid) {
                        $keys[] = [
                            'kid' => $kid,
                            'pem' => $bundle->getKeyPem($kid),
                            'alg' => $bundle->getAlgorithm($kid),
                        ];
                    }
                    $bundleMap[$tdName] = json_encode(['keys' => $keys], JSON_THROW_ON_ERROR);
                }

                $store->publishJwtBundles($bundleMap);
                $store->clearError();
                $this->log(sprintf(
                    'Shared memory: published %d JWT bundle(s) (version %d)',
                    count($bundleMap),
                    $store->metaTable()->get('global', 'version'),
                ));
            } catch (\Throwable $e) {
                $store->updateError("JWT publish failed: {$e->getMessage()}");
                $this->log("Shared memory JWT publish error: {$e->getMessage()}");
            }
        });

        // Track source state changes in meta table
        $this->x509Source->onStateChange(function (SourceState $from, SourceState $to) use ($store) {
            $store->updateX509State($to);
        });

        $this->jwtSource->onStateChange(function (SourceState $from, SourceState $to) use ($store) {
            $store->updateJwtState($to);
        });

        // Track errors
        $this->x509Source->onError(function (\Throwable $e) use ($store) {
            $store->updateError("X509: {$e->getMessage()}");
        });

        $this->jwtSource->onError(function (\Throwable $e) use ($store) {
            $store->updateError("JWT: {$e->getMessage()}");
        });
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }
}
