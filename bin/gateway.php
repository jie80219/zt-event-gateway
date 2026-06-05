<?php
/**
 * OpenSwoole HTTP Gateway with Anser-Gateway Kernel.
 *
 * Features:
 *   - OpenSwoole HTTP Server with coroutine support
 *   - Anser-Gateway Kernel: Router (FastRoute) → Filter → Controller → Filter
 *   - Canonical request envelope minting (schema_version / type=gateway.request)
 *
 * Service identity is transport-layer Vault PKI mTLS only — there is no
 * SPIFFE Workload API, no SPIRE, no LSVID, and no Keycloak JWT at this layer.
 *
 * Usage:
 *   php bin/gateway.php
 */

declare(strict_types=1);

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use AnserGateway\AnserGateway;
use AnserGateway\Router\Router;
use AnserGateway\Router\RouteCollector;
use AnserGateway\Adapter\SwooleRequestAdapter;
use AnserGateway\Adapter\SwooleResponseAdapter;
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
};

// ── Worker start: initialize framework ────────────────────────────
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

    fwrite(STDOUT, sprintf(
        "[gateway] Worker #%d started (pid=%d)\n",
        $workerId,
        getmypid(),
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
