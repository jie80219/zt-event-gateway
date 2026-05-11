<?php
/**
 * OpenSwoole HTTP Gateway with Anser-Gateway Kernel.
 *
 * Features:
 *   - OpenSwoole HTTP Server with coroutine support
 *   - Native gRPC to SPIRE Agent (Swoole\Coroutine\Http2\Client)
 *   - Anser-Gateway Kernel: Router (FastRoute) → Filter → Controller → Filter
 *   - SPIFFE identity propagation in event envelope payloads
 *   - Optional Keycloak service-account token minting (independent of SPIFFE)
 *
 * Usage:
 *   php bin/gateway.php
 */

declare(strict_types=1);

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use SDPMlab\ZtEventGateway\Spiffe\SpiffeBootstrap;
use Spiffe\SharedMemory\SpiffeTableReader;
use Keycloak\KeycloakBootstrap;
use Keycloak\SharedMemory\KeycloakTableReader;
use AnserGateway\AnserGateway;
use AnserGateway\Router\Router;
use AnserGateway\Router\RouteCollector;
use AnserGateway\Adapter\SwooleRequestAdapter;
use AnserGateway\Adapter\SwooleResponseAdapter;
use AnserGateway\Spiffe\GatewaySpiffeState;
use AnserGateway\Keycloak\GatewayKeycloakState;
use SDPMlab\Anser\Service\ServiceList;

require dirname(__DIR__) . '/vendor/autoload.php';

// Anser-Gateway framework constants (normally set by anser-gateway/anser bootstrap)
if (!defined('PROJECT_APP')) {
    define('PROJECT_APP', dirname(__DIR__) . '/anser-gateway/app/');
}
if (!defined('PROJECT_SYSTEM')) {
    define('PROJECT_SYSTEM', dirname(__DIR__) . '/anser-gateway/system/');
}
if (!defined('PROJECT_TEST')) {
    define('PROJECT_TEST', dirname(__DIR__) . '/anser-gateway/tests/');
}
if (!defined('PROJECT_VENDOR')) {
    define('PROJECT_VENDOR', dirname(__DIR__) . '/vendor/');
}

$env = static function (string $key, string $default): string {
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : $default;
};

$host = $env('GATEWAY_HOST', '0.0.0.0');
$port = (int) $env('GATEWAY_PORT', '8080');
$workerNum = (int) $env('GATEWAY_WORKERS', '32');

$server = new Server($host, $port);
$server->set([
    'worker_num'             => $workerNum,
    'enable_coroutine'       => true,
    'max_request'            => 0,
    'max_conn'               => 10000,
    'buffer_output_size'     => 2 * 1024 * 1024,
    'package_max_length'     => 10 * 1024 * 1024,
    'log_level'              => SWOOLE_LOG_INFO,
]);

// ── Per-worker state ──────────────────────────────────────────────
$workerState = new class {
    public ?Router $router = null;
    public ?SpiffeTableReader $shmReader = null;
    public ?KeycloakTableReader $kcShmReader = null;
};

// ── Worker start: initialize framework + SPIFFE ───────────────────
$server->on('workerStart', function (Server $server, int $workerId) use ($env, $workerState) {
    // ── 1. Anser-Gateway Router initialization ───────────────────
    try {
        $routesFile = dirname(__DIR__) . '/anser-gateway/config/Routes.php';
        RouteCollector::resetDiscover();
        $routeList = RouteCollector::loadRoutes($routesFile);
        $workerState->router = new Router($routeList);
        fwrite(STDOUT, "[gateway] Router initialized from {$routesFile}\n");
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf("[gateway] Router init failed: %s\n", $e->getMessage()));
    }

    // ── 1b. Downstream service registration for HTTP-proxy routes ──
    // OpenSwoole runtime does not auto-include anser-gateway/config/Service.php
    // (that's Workerman/GatewayWorker behaviour). Wire the service list here
    // so controllers using `new Action(serviceName: ...)` can resolve hosts.
    try {
        $productHost = $env('PRODUCTION_SERVICE_HOST', '10.1.1.207');
        $productPort = (int) $env('PRODUCTION_SERVICE_PORT', '8083');
        $productHttps = filter_var($env('PRODUCTION_SERVICE_HTTPS', '0'), FILTER_VALIDATE_BOOLEAN);
        ServiceList::addLocalService('product_service', $productHost, $productPort, $productHttps);
        fwrite(STDOUT, sprintf(
            "[gateway] registered product_service => %s://%s:%d\n",
            $productHttps ? 'https' : 'http',
            $productHost,
            $productPort,
        ));
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf("[gateway] service registration failed: %s\n", $e->getMessage()));
    }

    // ── 2. SPIFFE / LSVID bootstrap (SHM-backed) ─────────────────
    //
    //   Single source of truth: the shared-memory store populated by the
    //   spiffe-watcher daemon. We NO LONGER spawn our own X509Source here
    //   — that would duplicate the watcher's gRPC stream and risk
    //   two-sided disagreement on the "current" SVID. Instead we:
    //
    //     1. Read the primary SVID from SHM (seqlock-consistent).
    //     2. Build LSVIDSigner via SpiffeTableSvidReader (adapter).
    //     3. Launch a coroutine that polls meta.json.version and refreshes
    //        the GatewaySpiffeState singleton whenever the watcher
    //        publishes a new rotation.
    $spiffeEnabled = $env('SPIFFE_ENABLED', '1') !== '0';
    $downstreamSpiffeId = $env('WORKER_SPIFFE_ID', 'spiffe://zt.local/php-worker');
    GatewaySpiffeState::setSpiffeId($spiffeEnabled ? $env('SPIFFE_ID', '') : '');
    GatewaySpiffeState::setDownstreamSpiffeId($spiffeEnabled ? $downstreamSpiffeId : '');

    if (!$spiffeEnabled) {
        fwrite(STDOUT, "[gateway] SPIFFE disabled via SPIFFE_ENABLED=0 — skipping LSVID bootstrap\n");
    } elseif ($env('LSVID_ENABLED', '1') !== '0') {
        $shmDir = $env('SPIFFE_SHM_DIR', '/tmp/spiffe-shared');
        $awaitTimeout = (float) $env('SPIFFE_AWAIT_TIMEOUT', '30');
        try {
            $boot = SpiffeBootstrap::fromShm($shmDir, [
                'trust_domain'                   => $env('SPIFFE_TRUST_DOMAIN', 'zt.local'),
                'await_timeout'                  => $awaitTimeout,
                'clock_skew_seconds'             => 30,
                'require_audience_on_all_levels' => true,
                'spiffe_id'                      => $env('SPIFFE_ID', ''),
            ]);

            $workerState->shmReader = $boot->shmReader();
            $primary = $boot->primary();
            if ($primary === null) {
                throw new \RuntimeException('SHM ready but primary SVID slot empty');
            }

            GatewaySpiffeState::setLsvidSigner($boot->signer());
            GatewaySpiffeState::setSpiffeId((string) $primary['spiffe_id']);

            fwrite(STDOUT, sprintf(
                "[gateway] LSVID bootstrap OK (iss=%s, aud=%s, shm=%s, version=%d)\n",
                $primary['spiffe_id'],
                $downstreamSpiffeId,
                $shmDir,
                $boot->version(),
            ));

            // ── Rotation watcher coroutine ──────────────────────
            //   On every SHM version bump, rebuild the signer so the
            //   new X.509 key is used for the next mint. watchVersion()
            //   is cooperative — it usleep()s between polls and will
            //   yield to other coroutines.
            $pollSec = (float) max(0.1, (float) (getenv('SPIFFE_SHM_POLL_MS') ?: 500) / 1000.0);
            $coSleep = static function (float $s): void {
                if ($s < 1.0) {
                    \OpenSwoole\Coroutine::usleep((int) ($s * 1_000_000));
                } else {
                    \OpenSwoole\Coroutine::sleep($s);
                }
            };

            \go(static function () use ($boot, $workerId, $pollSec, $coSleep) {
                $boot->shmReader()->watchVersion(
                    static function (int $newV, int $oldV) use ($boot, $workerId) {
                        $primary = $boot->primary();
                        if ($primary === null) {
                            return;
                        }
                        GatewaySpiffeState::setLsvidSigner($boot->signer());
                        GatewaySpiffeState::setSpiffeId((string) $primary['spiffe_id']);
                        fwrite(STDOUT, sprintf(
                            "[gateway] Worker #%d SVID rotated v%d→v%d (iss=%s)\n",
                            $workerId,
                            $oldV,
                            $newV,
                            $primary['spiffe_id'],
                        ));
                    },
                    pollInterval: $pollSec,
                    sleeper: $coSleep,
                );
            });

            // ── Staleness monitor coroutine (every 30s) ─────────
            //   If the watcher wedges, updated_at stops advancing even
            //   though files still exist. Threshold defaults to 2×
            //   typical SVID TTL (3600s) but can be tuned.
            $staleThreshold = (int) $env('SPIFFE_STALE_THRESHOLD_SECS', '7200');
            \go(static function () use ($boot, $workerId, $staleThreshold) {
                while (true) {
                    if ($boot->shmReader()->isStale($staleThreshold)) {
                        fwrite(STDERR, sprintf(
                            "[gateway] Worker #%d ERROR: SPIFFE SHM stale — "
                            . "last update %ds ago (threshold %ds)\n",
                            $workerId,
                            $boot->shmReader()->secondsSinceLastUpdate(),
                            $staleThreshold,
                        ));
                    }
                    \OpenSwoole\Coroutine::sleep(30);
                }
            });
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "[gateway] LSVID bootstrap FAILED (shm=%s): %s\n",
                $env('SPIFFE_SHM_DIR', '/tmp/spiffe-shared'),
                $e->getMessage(),
            ));
        }
    } else {
        fwrite(STDOUT, "[gateway] LSVID disabled via LSVID_ENABLED=0\n");
    }

    // ── 3. Keycloak bootstrap (SHM-backed, independent of SPIFFE) ──
    //
    //   Reads cached token + JWKS from $KEYCLOAK_SHM_DIR (populated by
    //   the keycloak-watcher daemon). The TokenProvider returned falls
    //   back to a synchronous refresh on cold start.
    //
    //   Disabled by default (KEYCLOAK_ENABLED=0). Enabling it adds an
    //   `authorization` block to outbound RabbitMQ envelopes alongside
    //   any existing SPIFFE identity — the two stacks are orthogonal.
    $keycloakEnabled = $env('KEYCLOAK_ENABLED', '0') !== '0';
    GatewayKeycloakState::setEnabled($keycloakEnabled);

    if (!$keycloakEnabled) {
        fwrite(STDOUT, "[gateway] Keycloak disabled via KEYCLOAK_ENABLED=0\n");
    } else {
        $kcEnvBag = [
            'KEYCLOAK_ENABLED'            => $env('KEYCLOAK_ENABLED', '1'),
            'KEYCLOAK_ISSUER'             => $env('KEYCLOAK_ISSUER', ''),
            'KEYCLOAK_TOKEN_URI'          => $env('KEYCLOAK_TOKEN_URI', ''),
            'KEYCLOAK_JWKS_URI'           => $env('KEYCLOAK_JWKS_URI', ''),
            'KEYCLOAK_CLIENT_ID'          => $env('KEYCLOAK_CLIENT_ID', ''),
            'KEYCLOAK_CLIENT_SECRET'      => $env('KEYCLOAK_CLIENT_SECRET', ''),
            'KEYCLOAK_SHM_DIR'            => $env('KEYCLOAK_SHM_DIR', ''),
            'KEYCLOAK_TOKEN_REFRESH_SKEW' => $env('KEYCLOAK_TOKEN_REFRESH_SKEW', '30'),
        ];

        try {
            $kcState = KeycloakBootstrap::fromEnv($kcEnvBag, writable: false);
            $workerState->kcShmReader = $kcState->reader;

            GatewayKeycloakState::setTokenProvider($kcState->tokenProvider);
            GatewayKeycloakState::setClientId($kcState->clientId);
            GatewayKeycloakState::setIssuer($kcState->issuer);

            fwrite(STDOUT, sprintf(
                "[gateway] Keycloak bootstrap OK (iss=%s, client_id=%s, realm=%s, shm=%s)\n",
                $kcState->issuer,
                $kcState->clientId,
                $kcState->realm,
                $kcState->shmDir,
            ));

            $kcPollSec = (float) max(0.1, (float) (getenv('KEYCLOAK_SHM_POLL_MS') ?: 500) / 1000.0);
            $kcCoSleep = static function (float $s): void {
                if ($s < 1.0) {
                    \OpenSwoole\Coroutine::usleep((int) ($s * 1_000_000));
                } else {
                    \OpenSwoole\Coroutine::sleep($s);
                }
            };

            \go(static function () use ($kcState, $workerId, $kcPollSec, $kcCoSleep) {
                $kcState->reader->watchVersion(
                    static function (int $newV, int $oldV) use ($workerId) {
                        fwrite(STDOUT, sprintf(
                            "[gateway] Worker #%d Keycloak cache bumped v%d→v%d\n",
                            $workerId,
                            $oldV,
                            $newV,
                        ));
                    },
                    pollInterval: $kcPollSec,
                    sleeper: $kcCoSleep,
                );
            });

            $kcStaleThreshold = (int) $env('KEYCLOAK_STALE_THRESHOLD_SECS', '900');
            \go(static function () use ($kcState, $workerId, $kcStaleThreshold) {
                while (true) {
                    if ($kcState->reader->isStale($kcStaleThreshold)) {
                        fwrite(STDERR, sprintf(
                            "[gateway] Worker #%d ERROR: Keycloak SHM stale — last update %ds ago (threshold %ds)\n",
                            $workerId,
                            $kcState->reader->secondsSinceLastUpdate(),
                            $kcStaleThreshold,
                        ));
                    }
                    \OpenSwoole\Coroutine::sleep(30);
                }
            });
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "[gateway] Keycloak bootstrap FAILED: %s\n",
                $e->getMessage(),
            ));
        }
    }

    $ingressOn = (\AnserGateway\Filters\KeycloakIngressJwtFilter::isEnabled());
    fwrite(STDOUT, sprintf(
        "[gateway] Worker #%d started (pid=%d, spiffe_id=%s, kc_client_id=%s, ingress_jwt=%s)\n",
        $workerId,
        getmypid(),
        GatewaySpiffeState::getSpiffeId() ?: '(none)',
        GatewayKeycloakState::getClientId() ?: '(none)',
        $ingressOn ? 'on(aud=' . (\AnserGateway\Filters\KeycloakIngressJwtFilter::expectedAudience() ?: '?') . ')' : 'off',
    ));
});

// ── Request handler — delegates to Anser-Gateway Kernel ───────────
$server->on('request', function (Request $req, Response $res) use ($workerState) {
    // CORS
    $res->header('Access-Control-Allow-Origin', '*');

    if (!$workerState->router instanceof Router) {
        $res->status(503);
        $res->header('Content-Type', 'application/json; charset=utf-8');
        $res->end(json_encode(['status' => 503, 'message' => 'Gateway router not initialized']));
        return;
    }

    try {
        // Adapter: wrap Swoole types into Workerman-compatible types
        $adaptedRequest = new SwooleRequestAdapter($req);
        $gateway = new AnserGateway($workerState->router);

        /** @var SwooleResponseAdapter|\Workerman\Protocols\Http\Response $workermanResponse */
        $workermanResponse = $gateway->handleRequest($adaptedRequest);

        // Transfer framework response → Swoole response
        $res->status($workermanResponse->getStatusCode());
        foreach ($workermanResponse->getHeaders() as $name => $value) {
            $res->header($name, (string) $value);
        }
        $res->end($workermanResponse->rawBody());
    } catch (\SDPMlab\LSVID\LSVIDException $e) {
        fwrite(STDERR, "[gateway] LSVID error (cert may be expired): {$e->getMessage()}\n");
        $res->status(503);
        $res->header('Content-Type', 'application/json; charset=utf-8');
        $res->end(json_encode([
            'status' => 503,
            'message' => 'SPIFFE credentials unavailable — please retry later',
        ]));
    } catch (\Throwable $e) {
        fwrite(STDERR, "[gateway] Error: {$e->getMessage()}\n");
        $res->status(500);
        $res->header('Content-Type', 'application/json; charset=utf-8');
        $res->end(json_encode(['status' => 'Error', 'message' => $e->getMessage()]));
    }
});

// ── Start server ────────────────────────────────────────────────
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║    ZT Event Gateway (OpenSwoole + Anser-Gateway Kernel) ║\n";
echo "╠══════════════════════════════════════════════════════════╣\n";
echo "║  Listening: http://{$host}:{$port}                        ║\n";
echo "║  Workers:   {$workerNum}                                          ║\n";
echo "║  PID:       " . getmypid() . str_repeat(' ', 43 - strlen((string)getmypid())) . "║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";

$server->start();
