<?php

/**
 * Keycloak Watcher — standalone long-running daemon.
 *
 * Keeps two caches warm in shared memory so Gateway/Worker request-handling
 * coroutines never need to make a synchronous call to Keycloak:
 *
 *   1. Service-account access token (Client Credentials grant) — refreshed
 *      KEYCLOAK_TOKEN_REFRESH_SKEW seconds before `exp`.
 *   2. Realm JWKS — refreshed every KEYCLOAK_JWKS_REFRESH_INTERVAL seconds
 *      (also on startup).
 *
 * Writes both via KeycloakTableStore in /tmp/keycloak-shared/.
 *
 * Usage:
 *   php bin/keycloak-watcher.php
 *
 * Environment variables:
 *   KEYCLOAK_ISSUER                    — e.g. http://keycloak:8080/realms/zt
 *   KEYCLOAK_TOKEN_URI                 — optional override of token endpoint
 *   KEYCLOAK_JWKS_URI                  — optional override of JWKS endpoint
 *   KEYCLOAK_CLIENT_ID                 — this service's client_id
 *   KEYCLOAK_CLIENT_SECRET             — matching client secret
 *   KEYCLOAK_SHM_DIR                   — SHM base dir (default /tmp/keycloak-shared)
 *   KEYCLOAK_TOKEN_REFRESH_SKEW        — seconds before `exp` to refresh (default 30)
 *   KEYCLOAK_JWKS_REFRESH_INTERVAL     — JWKS refresh cadence seconds (default 300)
 *   KEYCLOAK_WATCHER_HEALTH_ADDR       — health HTTP bind addr (default 127.0.0.1:9901)
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Keycloak\KeycloakBootstrap;
use Keycloak\SharedMemory\KeycloakTableReader;

$env = static function (string $key, string $default = ''): string {
    $v = getenv($key);
    return is_string($v) && $v !== '' ? $v : $default;
};

if ($env('KEYCLOAK_ENABLED', '1') === '0') {
    fwrite(STDERR, "[keycloak-watcher] KEYCLOAK_ENABLED=0 — exiting immediately.\n");
    exit(0);
}

$envBag = [
    'KEYCLOAK_ENABLED'            => $env('KEYCLOAK_ENABLED', '1'),
    'KEYCLOAK_ISSUER'             => $env('KEYCLOAK_ISSUER'),
    'KEYCLOAK_TOKEN_URI'          => $env('KEYCLOAK_TOKEN_URI'),
    'KEYCLOAK_JWKS_URI'           => $env('KEYCLOAK_JWKS_URI'),
    'KEYCLOAK_CLIENT_ID'          => $env('KEYCLOAK_CLIENT_ID'),
    'KEYCLOAK_CLIENT_SECRET'      => $env('KEYCLOAK_CLIENT_SECRET'),
    'KEYCLOAK_SHM_DIR'            => $env('KEYCLOAK_SHM_DIR'),
    'KEYCLOAK_TOKEN_REFRESH_SKEW' => $env('KEYCLOAK_TOKEN_REFRESH_SKEW', '30'),
];

try {
    $state = KeycloakBootstrap::fromEnv($envBag, writable: true);
} catch (\Throwable $e) {
    fwrite(STDERR, "[keycloak-watcher] bootstrap failed: {$e->getMessage()}\n");
    exit(1);
}

$refreshSkew    = (int) $env('KEYCLOAK_TOKEN_REFRESH_SKEW', '30');
$jwksInterval   = (int) $env('KEYCLOAK_JWKS_REFRESH_INTERVAL', '300');
$healthAddr     = $env('KEYCLOAK_WATCHER_HEALTH_ADDR', '127.0.0.1:9901');

fwrite(STDOUT, sprintf(
    "[keycloak-watcher] issuer=%s client_id=%s realm=%s shm_dir=%s\n",
    $state->issuer,
    $state->clientId,
    $state->realm,
    $state->shmDir,
));

$client  = $state->client;
$jwks    = $state->jwksCache;
$token   = $state->tokenProvider;
$store   = $state->store;

$refreshJwks = static function () use ($client, $jwks, $state, $store): void {
    try {
        $raw = $client->fetchJwks();
        $jwks->write($state->issuer, $raw);
        $store->clearError();
        fwrite(STDOUT, sprintf("[keycloak-watcher] JWKS refreshed (%d bytes)\n", strlen($raw)));
    } catch (\Throwable $e) {
        $store->updateError('jwks: ' . $e->getMessage());
        fwrite(STDERR, "[keycloak-watcher] JWKS refresh failed: {$e->getMessage()}\n");
    }
};

$refreshToken = static function () use ($token, $store): void {
    try {
        $rec = $token->refresh();
        $store->clearError();
        fwrite(STDOUT, sprintf(
            "[keycloak-watcher] token refreshed client_id=%s expires_at=%d (in %ds)\n",
            $rec['client_id'],
            $rec['expires_at'],
            $rec['expires_at'] - time(),
        ));
    } catch (\Throwable $e) {
        $store->updateError('token: ' . $e->getMessage());
        fwrite(STDERR, "[keycloak-watcher] token refresh failed: {$e->getMessage()}\n");
    }
};

// Initial warm-up with retry so the daemon survives Keycloak starting after us.
$attempt = 0;
$maxAttempts = (int) $env('KEYCLOAK_WATCHER_MAX_ATTEMPTS', '0');
$backoff = 1;
while (true) {
    try {
        $refreshJwks();
        $refreshToken();
        if ($state->reader->hasToken() && $state->reader->hasJwks()) {
            break;
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "[keycloak-watcher] warm-up attempt failed: {$e->getMessage()}\n");
    }
    $attempt++;
    if ($maxAttempts > 0 && $attempt >= $maxAttempts) {
        fwrite(STDERR, "[keycloak-watcher] giving up after {$attempt} attempts\n");
        exit(1);
    }
    sleep($backoff);
    $backoff = min($backoff * 2, 30);
}

$running = true;
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running) {
        $running = false;
        fwrite(STDOUT, "[keycloak-watcher] SIGTERM received — shutting down\n");
    });
    pcntl_signal(SIGINT, static function () use (&$running) {
        $running = false;
        fwrite(STDOUT, "[keycloak-watcher] SIGINT received — shutting down\n");
    });
}

$lastJwksRefresh = time();

while ($running) {
    if ($token->needsRefresh()) {
        $refreshToken();
    }

    if ((time() - $lastJwksRefresh) >= $jwksInterval) {
        $refreshJwks();
        $lastJwksRefresh = time();
    }

    sleep(1);
}

fwrite(STDOUT, "[keycloak-watcher] Graceful shutdown complete\n");
