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
use Keycloak\KeycloakBootstrap;
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
    public ?SpiffeBootstrap $spiffe = null;
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

    // ── 2. SPIFFE bootstrap (SHM via spiffe-watcher daemon) ──────
    //   Reads SVID material from /tmp/spiffe-shared populated by
    //   bin/spiffe-watcher.php. One gRPC stream per host instead of
    //   one per PHP process — amortises FetchX509SVID cost.
    $spiffeEnabled = $env('SPIFFE_ENABLED', '1') !== '0';
    $downstreamSpiffeId = $env('WORKER_SPIFFE_ID', 'spiffe://zt.local/php-worker');
    GatewaySpiffeState::setSpiffeId($spiffeEnabled ? $env('SPIFFE_ID', '') : '');
    GatewaySpiffeState::setDownstreamSpiffeId($spiffeEnabled ? $downstreamSpiffeId : '');

    if (!$spiffeEnabled) {
        fwrite(STDOUT, "[gateway] SPIFFE disabled via SPIFFE_ENABLED=0 — skipping bootstrap\n");
    } else {
        $shmDir = $env('SPIFFE_SHM_DIR', '/tmp/spiffe-shared');
        $awaitTimeout = (float) $env('SPIFFE_AWAIT_TIMEOUT', '30');
        try {
            $boot = SpiffeBootstrap::fromShm($shmDir, [
                'trust_domain'  => $env('SPIFFE_TRUST_DOMAIN', 'zt.local'),
                'await_timeout' => $awaitTimeout,
                'spiffe_id'     => $env('SPIFFE_ID', ''),
            ]);

            $workerState->spiffe = $boot;
            $primary = $boot->primary();
            if ($primary === null) {
                throw new \RuntimeException('SHM ready but primary SVID unavailable');
            }

            GatewaySpiffeState::setSpiffeId((string) $primary['spiffe_id']);

            fwrite(STDOUT, sprintf(
                "[gateway] SPIFFE bootstrap OK via SHM (iss=%s, aud=%s, shm=%s, version=%d)\n",
                $primary['spiffe_id'],
                $downstreamSpiffeId,
                $shmDir,
                $boot->version(),
            ));
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "[gateway] SPIFFE bootstrap FAILED (shm=%s): %s\n",
                $env('SPIFFE_SHM_DIR', '/tmp/spiffe-shared'),
                $e->getMessage(),
            ));
        }
    }

    // ── 3. Keycloak bootstrap (SHM-backed read-only) ──────────────
    //   Reads token + JWKS material from /tmp/keycloak-shared written
    //   by bin/keycloak-watcher.php. Eliminates per-process HTTPS
    //   handshake + JWKS fetch under cold-start load.
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
            'KEYCLOAK_TOKEN_REFRESH_SKEW' => $env('KEYCLOAK_TOKEN_REFRESH_SKEW', '30'),
            'KEYCLOAK_SHM_DIR'            => $env('KEYCLOAK_SHM_DIR', '/tmp/keycloak-shared'),
        ];

        try {
            $kcState = KeycloakBootstrap::fromEnv($kcEnvBag, writable: false);

            GatewayKeycloakState::setTokenProvider($kcState->tokenProvider);
            GatewayKeycloakState::setClientId($kcState->clientId);
            GatewayKeycloakState::setIssuer($kcState->issuer);

            fwrite(STDOUT, sprintf(
                "[gateway] Keycloak bootstrap OK (iss=%s, client_id=%s, realm=%s)\n",
                $kcState->issuer,
                $kcState->clientId,
                $kcState->realm,
            ));
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

// ── Start server ────────────────────────────────────────────────
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║    ZT Event Gateway (OpenSwoole + Anser-Gateway Kernel) ║\n";
echo "╠══════════════════════════════════════════════════════════╣\n";
echo "║  Listening: http://{$host}:{$port}                        ║\n";
echo "║  Workers:   {$workerNum}                                          ║\n";
echo "║  PID:       " . getmypid() . str_repeat(' ', 43 - strlen((string)getmypid())) . "║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";

$server->start();
