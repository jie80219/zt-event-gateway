<?php
/**
 * OpenSwoole HTTP Gateway with Anser-Gateway Kernel.
 *
 * Features:
 *   - OpenSwoole HTTP Server with coroutine support
 *   - Native gRPC to SPIRE Agent (Swoole\Coroutine\Http2\Client)
 *   - Anser-Gateway Kernel: Router (FastRoute) → Filter → Controller → Filter
 *   - SPIFFE identity propagation in event envelope payloads
 *
 * Usage:
 *   php bin/gateway.php
 */

declare(strict_types=1);

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use SDPMlab\LSVID\LSVIDSigner;
use SDPMlab\ZtEventGateway\Spiffe\LSVID\SpiffeTableSvidReader;
use Spiffe\SharedMemory\SpiffeTableReader;
use Spiffe\Source\SourceConfig;
use Spiffe\Source\X509Source;
use AnserGateway\AnserGateway;
use AnserGateway\Router\Router;
use AnserGateway\Router\RouteCollector;
use AnserGateway\Adapter\SwooleRequestAdapter;
use AnserGateway\Adapter\SwooleResponseAdapter;
use AnserGateway\Spiffe\GatewaySpiffeState;

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

// ── Per-worker state ──────────────────────────────────────────────
$workerState = new class {
    public ?Router $router = null;
    public ?X509Source $x509Source = null;
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

    // ── 2. SPIFFE / LSVID initialization ─────────────────────────
    $spiffeId = $env('SPIFFE_ID', '');
    $downstreamSpiffeId = $env('WORKER_SPIFFE_ID', 'spiffe://zt.local/php-worker');

    GatewaySpiffeState::setSpiffeId($spiffeId);
    GatewaySpiffeState::setDownstreamSpiffeId($downstreamSpiffeId);

    if ($env('LSVID_ENABLED', '1') !== '0') {
        try {
            $shmDir = $env('SPIFFE_SHM_DIR', '');
            $reader = $shmDir !== ''
                ? new SpiffeTableReader($shmDir)
                : new SpiffeTableReader();

            $primary = $reader->readX509Primary();
            if ($primary !== null && !empty($primary['key_pem']) && !empty($primary['cert_pem'])) {
                $signer = new LSVIDSigner(new SpiffeTableSvidReader($reader));
                GatewaySpiffeState::setLsvidSigner($signer);

                if (!empty($primary['spiffe_id'])) {
                    GatewaySpiffeState::setSpiffeId((string) $primary['spiffe_id']);
                }
                fwrite(STDOUT, sprintf(
                    "[gateway] LSVID Step 1 enabled (iss=%s, aud=%s)\n",
                    GatewaySpiffeState::getSpiffeId(),
                    $downstreamSpiffeId,
                ));
            } else {
                fwrite(STDERR, sprintf(
                    "[gateway] LSVID DEGRADED — no primary X.509-SVID in SHM (dir=%s). "
                    . "Ingress envelopes will be unsigned.\n",
                    $shmDir !== '' ? $shmDir : '(default)',
                ));
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf("[gateway] LSVID signer init failed: %s\n", $e->getMessage()));
        }
    } else {
        fwrite(STDOUT, "[gateway] LSVID disabled via LSVID_ENABLED=0\n");
    }

    // ── 3. X509Source (gRPC to SPIRE Agent) ──────────────────────
    $spiffeSocket = $env('SPIFFE_ENDPOINT_SOCKET', '');
    if ($spiffeSocket !== '') {
        try {
            $sourceConfig = new SourceConfig(
                socketPath: $spiffeSocket,
                maxRetries: 5,
                initialBackoff: 1.0,
                maxBackoff: 15.0,
                connectTimeout: 5.0,
                streamTimeout: 0.0,
            );

            $workerState->x509Source = new X509Source($sourceConfig);
            $workerState->x509Source->onRotated(function (array $svids) {
                if ($svids !== []) {
                    GatewaySpiffeState::setSpiffeId((string) $svids[0]->spiffeId());
                    fwrite(STDOUT, sprintf(
                        "[gateway] SVID rotated: %s\n",
                        GatewaySpiffeState::getSpiffeId(),
                    ));
                }
            });
            $workerState->x509Source->onError(function (\Throwable $e) {
                fwrite(STDERR, sprintf("[gateway] X509Source error: %s\n", $e->getMessage()));
            });
            $workerState->x509Source->start();

            fwrite(STDOUT, sprintf(
                "[gateway] Worker #%d X509Source started (socket=%s)\n",
                $workerId,
                $spiffeSocket,
            ));
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "[gateway] Worker #%d X509Source failed: %s\n",
                $workerId,
                $e->getMessage(),
            ));
        }
    }

    fwrite(STDOUT, sprintf(
        "[gateway] Worker #%d started (pid=%d, spiffe_id=%s)\n",
        $workerId,
        getmypid(),
        GatewaySpiffeState::getSpiffeId() ?: '(none)',
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
