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

$pemDir = $env('SPIFFE_PEM_DIR', '');
$shmDir = $env('SPIFFE_SHM_DIR', '/tmp/spiffe-shared');

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

// Log lifecycle events
$watcher->onReady(function ($x509, $jwt) {
    $svid = $x509->currentSvid();
    fwrite(STDOUT, sprintf(
        "[spiffe-watcher] READY — identity: %s, trust domain: %s\n",
        $svid->spiffeId(),
        $svid->trustDomain(),
    ));
});

$watcher->onShutdown(function () {
    fwrite(STDOUT, "[spiffe-watcher] Graceful shutdown complete\n");
});

// Run — this blocks until SIGTERM/SIGINT
$watcher->run();
