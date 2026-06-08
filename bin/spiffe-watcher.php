<?php

/**
 * SPIFFE Workload API Watcher — standalone long-running process.
 *
 * Maintains persistent gRPC server-stream connections to the local SPIRE Agent
 * via Unix Domain Socket, keeping X.509 and JWT credentials always fresh.
 *
 * Architecture:
 *
 *   ┌─────────────────────────────────────────────────────────┐
 *   │                   Swoole Coroutine Runtime               │
 *   │                                                          │
 *   │  ┌─────────────────┐       ┌─────────────────┐         │
 *   │  │  Coroutine #1   │       │  Coroutine #2   │         │
 *   │  │  X509Source      │       │  JwtSource       │        │
 *   │  │  watchX509Svid() │       │  watchJwtBundles()│        │
 *   │  │  (server stream) │       │  (server stream)  │        │
 *   │  └────────┬─────────┘       └────────┬──────────┘        │
 *   │           │                           │                  │
 *   │           │    SPIRE Agent UDS        │                  │
 *   │           └───────────┬───────────────┘                  │
 *   │                       │                                  │
 *   │              /run/spire/sockets/agent.sock               │
 *   │                                                          │
 *   │  ┌─────────────────┐       ┌─────────────────┐         │
 *   │  │  Coroutine #3   │       │  PEM Writer      │         │
 *   │  │  Signal handler │       │  (on rotation)    │         │
 *   │  │  SIGTERM/SIGINT │       │  svid.pem         │         │
 *   │  └─────────────────┘       │  svid_key.pem     │         │
 *   │                            │  bundle.pem       │         │
 *   │                            └─────────────────┘          │
 *   └─────────────────────────────────────────────────────────┘
 *
 * Usage:
 *   php bin/spiffe-watcher.php
 *
 * Environment variables:
 *   SPIFFE_ENDPOINT_SOCKET  — UDS path (default: unix:/run/spire/sockets/agent.sock)
 *   SPIFFE_PEM_DIR          — PEM output directory (optional, e.g. /tmp/spiffe-certs)
 *   SPIFFE_MAX_RETRIES      — Max reconnect attempts, 0=unlimited (default: 0)
 *   SPIFFE_BACKOFF_INITIAL  — Initial backoff seconds (default: 1)
 *   SPIFFE_BACKOFF_MAX      — Max backoff seconds (default: 30)
 *   SPIFFE_CONNECT_TIMEOUT  — gRPC connect timeout seconds (default: 5)
 *   SPIFFE_VALIDATE         — Validate SVIDs on rotation: 1/0 (default: 1)
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Spiffe\Source\SourceConfig;
use Spiffe\Source\SpiffeWorkloadWatcher;
use Spiffe\SharedMemory\SpiffeTableSchema;
use Spiffe\SharedMemory\SpiffeTableStore;

// ──────────────────────────────────────────────────────────────────
//  Configuration from environment
// ──────────────────────────────────────────────────────────────────

$env = static function (string $key, string $default): string {
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : $default;
};

// Master toggle guard — the primary control is the docker-compose `zt`
// profile which simply doesn't start this container; this check is a
// belt-and-suspenders safety net for direct invocations (e.g. manual
// `php bin/spiffe-watcher.php` in a dev shell with SPIFFE_ENABLED=0).
if ($env('SPIFFE_ENABLED', '1') === '0') {
    fwrite(STDERR, "[spiffe-watcher] SPIFFE_ENABLED=0 — exiting immediately.\n");
    exit(0);
}

$config = new SourceConfig(
    socketPath:         $env('SPIFFE_ENDPOINT_SOCKET', 'unix:/run/spire/sockets/agent.sock'),
    maxRetries:         (int) $env('SPIFFE_MAX_RETRIES', '0'),
    initialBackoff:     (float) $env('SPIFFE_BACKOFF_INITIAL', '1'),
    maxBackoff:         (float) $env('SPIFFE_BACKOFF_MAX', '30'),
    connectTimeout:     (float) $env('SPIFFE_CONNECT_TIMEOUT', '5'),
    streamTimeout:      0.0,  // no timeout — keep stream open indefinitely
    allowedClockSkew:   60,
    validateOnRotation: (bool) (int) $env('SPIFFE_VALIDATE', '1'),
);

$pemDir     = $env('SPIFFE_PEM_DIR', '');
$shmDir     = $env('SPIFFE_SHM_DIR', '/tmp/spiffe-shared');
$healthAddr = $env('SPIFFE_WATCHER_HEALTH_ADDR', '127.0.0.1:9901');

// ──────────────────────────────────────────────────────────────────
//  Build and run the watcher
// ──────────────────────────────────────────────────────────────────

$watcher = new SpiffeWorkloadWatcher($config);

// Shared memory store — enables Gateway + Worker to read X.509-SVID
// for LSVID signing and mTLS credential injection.
SpiffeTableSchema::createAll($shmDir);
$watcher->withSharedMemory(new SpiffeTableStore($shmDir));

fwrite(STDOUT, sprintf("[spiffe-watcher] SHM enabled (dir=%s)\n", $shmDir));

// Optional: write PEM files for downstream consumers (Envoy, nginx, curl)
if ($pemDir !== '') {
    $watcher->withPemOutput($pemDir);
}

// ──────────────────────────────────────────────────────────────────
//  HTTP health endpoint — spawned once both sources reach Ready.
//
//  GET /health   → 200 JSON  if x509 is ready
//                  503 JSON  otherwise (still returns the JSON body so
//                            supervisors can read the failure reason)
//  GET /metrics  → 200 plain text — minimal Prometheus-style counters
//
//  Bound to 127.0.0.1 by default. Override via SPIFFE_WATCHER_HEALTH_ADDR
//  (e.g. 0.0.0.0:9901 for cross-container Docker healthcheck).
// ──────────────────────────────────────────────────────────────────
$watcher->onReady(function ($x509, $jwt) use ($watcher, $healthAddr, $shmDir) {
    $svid = $x509->currentSvid();
    fwrite(STDOUT, sprintf(
        "[spiffe-watcher] READY — identity: %s, trust domain: %s\n",
        $svid->spiffeId(),
        $svid->trustDomain(),
    ));

    if ($healthAddr === '') {
        return;
    }

    // The coroutine HTTP server is part of OpenSwoole's HTTP build;
    // some flavors ship without it. The compose healthcheck reads
    // /tmp/spiffe-shared/meta.json directly as a fallback, so we just
    // skip the endpoint silently when the class isn't available.
    if (!class_exists(\OpenSwoole\Coroutine\Http\Server::class)) {
        fwrite(STDOUT, "[spiffe-watcher] Health endpoint disabled (OpenSwoole HTTP server not built); using SHM-based healthcheck\n");
        return;
    }

    [$host, $port] = array_pad(explode(':', $healthAddr, 2), 2, '9901');
    $port = (int) $port;

    // Spawn a coroutine HTTP server. We're inside the runtime here
    // (onReady fires from awaitReady, which runs in the watcher's
    // coroutine context).
    \OpenSwoole\Coroutine::create(static function () use ($watcher, $host, $port, $shmDir) {
        try {
            $reader = new \Spiffe\SharedMemory\SpiffeTableReader($shmDir);
            $server = new \OpenSwoole\Coroutine\Http\Server($host, $port, false);

            $server->handle('/health', static function ($req, $res) use ($watcher) {
                $health = $watcher->healthCheck();
                $code = ($health['x509']['ready'] ?? false) ? 200 : 503;
                $res->status($code);
                $res->header('Content-Type', 'application/json');
                $res->end(json_encode($health, JSON_UNESCAPED_SLASHES));
            });

            $server->handle('/metrics', static function ($req, $res) use ($watcher, $reader) {
                $h = $watcher->healthCheck();
                $lines = [
                    sprintf('spiffe_watcher_running %d', $h['running'] ? 1 : 0),
                    sprintf('spiffe_watcher_x509_ready %d', $h['x509']['ready'] ? 1 : 0),
                    sprintf('spiffe_watcher_jwt_ready %d', $h['jwt']['ready'] ? 1 : 0),
                    sprintf('spiffe_shm_version %d', $reader->version()),
                    sprintf('spiffe_shm_seconds_since_update %d', $reader->secondsSinceLastUpdate()),
                ];
                $res->status(200);
                $res->header('Content-Type', 'text/plain; version=0.0.4');
                $res->end(implode("\n", $lines) . "\n");
            });

            fwrite(STDOUT, sprintf(
                "[spiffe-watcher] Health endpoint listening on http://%s:%d\n",
                $host,
                $port,
            ));
            $server->start();
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "[spiffe-watcher] Health endpoint failed: %s\n",
                $e->getMessage(),
            ));
        }
    });
});

$watcher->onShutdown(function () {
    fwrite(STDOUT, "[spiffe-watcher] Graceful shutdown complete\n");
});

// ──────────────────────────────────────────────────────────────────
//  Self-monitor: workaround for the gRPC stream reconnect bug where
//  the watcher silently stops publishing fresh SVIDs after a stream
//  interrupt (recvTimeout fires every ~30s of idle, but on resubscribe
//  no new rotation event reaches the SHM publisher). Without this,
//  the SVID in SHM ages out and gateway 500s with "Identity token
//  creation failed". We probe meta.json's updated_at and exit(1) so
//  docker `restart: unless-stopped` brings up a fresh process — the
//  initial subscribe always publishes, which gives downstream callers
//  a fresh SVID immediately.
// ──────────────────────────────────────────────────────────────────
$selfHealStaleSecs = (int) $env('SPIFFE_WATCHER_SELFHEAL_STALE_SECS', '400');
$selfHealEnabled   = $env('SPIFFE_WATCHER_SELFHEAL', '1') !== '0';

if ($selfHealEnabled) {
    $watcher->onReady(function () use ($shmDir, $selfHealStaleSecs) {
        $metaPath = $shmDir . '/meta.json';
        \OpenSwoole\Coroutine::create(static function () use ($metaPath, $selfHealStaleSecs) {
            // Wait one full grace period before first check so we don't race
            // a freshly-started watcher's initial publish.
            \OpenSwoole\Coroutine::sleep($selfHealStaleSecs);
            while (true) {
                $meta = @file_get_contents($metaPath);
                if ($meta !== false) {
                    $data = @json_decode($meta, true);
                    $updatedAt = is_array($data) ? (int) ($data['updated_at'] ?? 0) : 0;
                    $age = time() - $updatedAt;
                    if ($updatedAt > 0 && $age >= $selfHealStaleSecs) {
                        fwrite(STDERR, sprintf(
                            "[spiffe-watcher] SHM stale (age=%ds, threshold=%ds) — exiting for restart\n",
                            $age,
                            $selfHealStaleSecs,
                        ));
                        exit(1);
                    }
                }
                \OpenSwoole\Coroutine::sleep(30);
            }
        });
        fwrite(STDOUT, sprintf(
            "[spiffe-watcher] Self-heal armed (threshold=%ds)\n",
            $selfHealStaleSecs,
        ));
    });
}

// Run — this blocks until SIGTERM/SIGINT
$watcher->run();
