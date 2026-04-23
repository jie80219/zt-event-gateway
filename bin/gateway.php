<?php
/**
 * OpenSwoole HTTP Gateway with Anser-Gateway Kernel.
 *
 * Features:
 *   - OpenSwoole HTTP Server with coroutine support
 *   - Anser-Gateway Kernel: Router (FastRoute) → Filter → Controller → Filter
 *   - Keycloak service-account token minted per request via TokenProvider
 *     (cached in SHM by keycloak-watcher for cross-coroutine reuse)
 *
 * Usage:
 *   php bin/gateway.php
 */

declare(strict_types=1);

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Keycloak\KeycloakBootstrap;
use Keycloak\SharedMemory\KeycloakTableReader;
use AnserGateway\AnserGateway;
use AnserGateway\Router\Router;
use AnserGateway\Router\RouteCollector;
use AnserGateway\Adapter\SwooleRequestAdapter;
use AnserGateway\Adapter\SwooleResponseAdapter;
use AnserGateway\Keycloak\GatewayKeycloakState;

require dirname(__DIR__) . '/vendor/autoload.php';

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
$workerNum = (int) $env('GATEWAY_WORKERS', '2');

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

$workerState = new class {
    public ?Router $router = null;
    public ?KeycloakTableReader $shmReader = null;
};

$server->on('workerStart', function (Server $server, int $workerId) use ($env, $workerState) {
    try {
        $routesFile = dirname(__DIR__) . '/anser-gateway/config/Routes.php';
        RouteCollector::resetDiscover();
        $routeList = RouteCollector::loadRoutes($routesFile);
        $workerState->router = new Router($routeList);
        fwrite(STDOUT, "[gateway] Router initialized from {$routesFile}\n");
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf("[gateway] Router init failed: %s\n", $e->getMessage()));
    }

    // ── Keycloak bootstrap (SHM-backed) ──────────────────────────
    //   Reads cached token + JWKS from /tmp/keycloak-shared/ (populated
    //   by the keycloak-watcher daemon). The TokenProvider we get back
    //   falls back to a synchronous refresh if the cache is cold.
    $keycloakEnabled = $env('KEYCLOAK_ENABLED', '1') !== '0';
    GatewayKeycloakState::setEnabled($keycloakEnabled);

    if (!$keycloakEnabled) {
        fwrite(STDOUT, "[gateway] Keycloak disabled via KEYCLOAK_ENABLED=0 — running without auth\n");
    } else {
        $envBag = [
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
            $state = KeycloakBootstrap::fromEnv($envBag, writable: false);
            $workerState->shmReader = $state->reader;

            GatewayKeycloakState::setTokenProvider($state->tokenProvider);
            GatewayKeycloakState::setClientId($state->clientId);
            GatewayKeycloakState::setIssuer($state->issuer);

            fwrite(STDOUT, sprintf(
                "[gateway] Keycloak bootstrap OK (iss=%s, client_id=%s, realm=%s, shm=%s)\n",
                $state->issuer,
                $state->clientId,
                $state->realm,
                $state->shmDir,
            ));

            // ── Token-cache rotation watcher coroutine ──────────
            //   Logs whenever the watcher publishes a fresh token so
            //   operators can confirm the cross-process refresh path
            //   is alive. Actual token read is lazy: controllers call
            //   TokenProvider->getAccessToken() which re-reads SHM
            //   under the seqlock on every call.
            $pollSec = (float) max(0.1, (float) (getenv('KEYCLOAK_SHM_POLL_MS') ?: 500) / 1000.0);
            $coSleep = static function (float $s): void {
                if ($s < 1.0) {
                    \OpenSwoole\Coroutine::usleep((int) ($s * 1_000_000));
                } else {
                    \OpenSwoole\Coroutine::sleep($s);
                }
            };

            \go(static function () use ($state, $workerId, $pollSec, $coSleep) {
                $state->reader->watchVersion(
                    static function (int $newV, int $oldV) use ($workerId) {
                        fwrite(STDOUT, sprintf(
                            "[gateway] Worker #%d Keycloak cache bumped v%d→v%d\n",
                            $workerId,
                            $oldV,
                            $newV,
                        ));
                    },
                    pollInterval: $pollSec,
                    sleeper: $coSleep,
                );
            });

            $staleThreshold = (int) $env('KEYCLOAK_STALE_THRESHOLD_SECS', '900');
            \go(static function () use ($state, $workerId, $staleThreshold) {
                while (true) {
                    if ($state->reader->isStale($staleThreshold)) {
                        fwrite(STDERR, sprintf(
                            "[gateway] Worker #%d ERROR: Keycloak SHM stale — last update %ds ago (threshold %ds)\n",
                            $workerId,
                            $state->reader->secondsSinceLastUpdate(),
                            $staleThreshold,
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

    fwrite(STDOUT, sprintf(
        "[gateway] Worker #%d started (pid=%d, client_id=%s)\n",
        $workerId,
        getmypid(),
        GatewayKeycloakState::getClientId() ?: '(none)',
    ));
});

$server->on('request', function (Request $req, Response $res) use ($workerState) {
    $res->header('Access-Control-Allow-Origin', '*');

    if (!$workerState->router instanceof Router) {
        $res->status(503);
        $res->header('Content-Type', 'application/json; charset=utf-8');
        $res->end(json_encode(['status' => 503, 'message' => 'Gateway router not initialized']));
        return;
    }

    try {
        $adaptedRequest = new SwooleRequestAdapter($req);
        $gateway = new AnserGateway($workerState->router);

        /** @var SwooleResponseAdapter|\Workerman\Protocols\Http\Response $workermanResponse */
        $workermanResponse = $gateway->handleRequest($adaptedRequest);

        $res->status($workermanResponse->getStatusCode());
        foreach ($workermanResponse->getHeaders() as $name => $value) {
            $res->header($name, (string) $value);
        }
        $res->end($workermanResponse->rawBody());
    } catch (\Throwable $e) {
        fwrite(STDERR, "[gateway] Error: {$e->getMessage()}\n");
        $res->status(500);
        $res->header('Content-Type', 'application/json; charset=utf-8');
        $res->end(json_encode(['status' => 'Error', 'message' => $e->getMessage()]));
    }
});

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║    ZT Event Gateway (OpenSwoole + Anser-Gateway Kernel) ║\n";
echo "╠══════════════════════════════════════════════════════════╣\n";
echo "║  Listening: http://{$host}:{$port}                        ║\n";
echo "║  Workers:   {$workerNum}                                          ║\n";
echo "║  PID:       " . getmypid() . str_repeat(' ', 43 - strlen((string)getmypid())) . "║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";

$server->start();
