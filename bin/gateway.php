<?php
/**
 * OpenSwoole HTTP Gateway — replaces Workerman + Swow.
 *
 * Features:
 *   - OpenSwoole HTTP Server with coroutine support
 *   - Native gRPC to SPIRE Agent (Swoole\Coroutine\Http2\Client)
 *   - Load Balance algorithms (EntropyScoring, DynamicLoadBalancer, etc.)
 *   - Persistent RabbitMQ connection per worker process
 *   - SPIFFE identity propagation in CloudEvents payloads
 *
 * Usage:
 *   php bin/gateway.php
 */

declare(strict_types=1);

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use SDPMlab\ZtEventGateway\Ingress\CanonicalOrderRequest;
use Spiffe\Source\SourceConfig;
use Spiffe\Source\X509Source;

require dirname(__DIR__) . '/vendor/autoload.php';

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

// ── Routes (simple FastRoute-like mapping) ─────────────────────
$routes = [
    'GET'  => [
        '/api/health' => 'health',
        '/'           => 'health',
    ],
    'POST' => [
        '/api/orders' => 'order_create',
    ],
];

// ── Per-worker state (initialized in onWorkerStart) ────────────
$workerState = new class {
    public ?\PhpAmqpLib\Channel\AMQPChannel $amqpChannel = null;
    public ?\PhpAmqpLib\Connection\AMQPSocketConnection $amqpConn = null;
    public bool $topologyDeclared = false;
    public string $spiffeId = '';
    public ?X509Source $x509Source = null;
};

// ── Worker start: initialize connections ────────────────────────
$server->on('workerStart', function (Server $server, int $workerId) use ($env, $workerState) {
    $workerState->spiffeId = $env('SPIFFE_ID', '');

    // Start X509Source if SPIRE Agent socket is configured
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
            $workerState->x509Source->onRotated(function (array $svids) use ($workerState) {
                if ($svids !== []) {
                    $workerState->spiffeId = (string) $svids[0]->spiffeId();
                    fwrite(STDOUT, sprintf(
                        "[gateway] SVID rotated: %s\n",
                        $workerState->spiffeId,
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
                "[gateway] Worker #%d X509Source failed to start: %s\n",
                $workerId,
                $e->getMessage(),
            ));
        }
    }

    fwrite(STDOUT, sprintf(
        "[gateway] Worker #%d started (pid=%d, spiffe_id=%s)\n",
        $workerId,
        getmypid(),
        $workerState->spiffeId ?: '(none)',
    ));
});

// ── Request handler ─────────────────────────────────────────────
$server->on('request', function (Request $req, Response $res) use ($routes, $env, $workerState) {
    $method = $req->server['request_method'] ?? 'GET';
    $path = $req->server['request_uri'] ?? '/';

    // CORS headers
    $res->header('Content-Type', 'application/json; charset=utf-8');
    $res->header('Access-Control-Allow-Origin', '*');

    // Route matching
    $handler = $routes[$method][$path] ?? null;
    if ($handler === null) {
        $res->status(404);
        $res->end(json_encode(['error' => 'Not Found', 'path' => $path]));
        return;
    }

    try {
        match ($handler) {
            'health'       => handleHealth($res),
            'order_create' => handleOrderCreate($req, $res, $env, $workerState),
            default        => throw new \RuntimeException("Unknown handler: {$handler}"),
        };
    } catch (\Throwable $e) {
        fwrite(STDERR, "[gateway] Error: {$e->getMessage()}\n");
        $res->status(500);
        $res->end(json_encode(['status' => 'Error', 'message' => $e->getMessage()]));
    }
});

// ── Handler: Health Check ───────────────────────────────────────
function handleHealth(Response $res): void
{
    $res->status(200);
    $res->end(json_encode(['status' => 200, 'msg' => 'Gateway is alive.']));
}

// ── Handler: Order Create ───────────────────────────────────────
function handleOrderCreate(Request $req, Response $res, callable $env, object $state): void
{
    $rawBody = $req->rawContent();
    $requestPayload = [];

    if ($rawBody === '' || $rawBody === false) {
        $res->status(400);
        $res->end(json_encode([
            'status' => 'Bad Request',
            'message' => 'Request body must be valid JSON.',
        ]));
        return;
    }

    $requestPayload = json_decode($rawBody, true);
    if (!is_array($requestPayload)) {
        $res->status(400);
        $res->end(json_encode([
            'status' => 'Bad Request',
            'message' => 'Request body must be valid JSON.',
        ]));
        return;
    }

    try {
        $data = CanonicalOrderRequest::normalizeOrderData($requestPayload);
    } catch (\InvalidArgumentException $exception) {
        $res->status(422);
        $res->end(json_encode([
            'status' => 'Unprocessable Entity',
            'message' => $exception->getMessage(),
        ]));
        return;
    }

    $traceId = $req->header['x-correlation-id'] ?? uniqid('txn_', true);
    $routingKey = $env('REQUEST_ROUTING_KEY', 'request.new');
    $targetEvent = $env('REQUEST_EVENT_TYPE', 'OrderCreateRequestedEvent');

    $eventPayload = json_encode([
        'schema_version' => CanonicalOrderRequest::SCHEMA_VERSION,
        'specversion' => '1.0',
        'type'        => CanonicalOrderRequest::ENVELOPE_TYPE,
        'route'       => $targetEvent,
        'source'      => '/gateway/order',
        'id'          => $traceId,
        'time'        => date(DATE_RFC3339),
        'spiffe_id'   => $state->spiffeId,
        'spiffe_path' => $state->spiffeId !== '' ? [$state->spiffeId] : [],
        'data'        => $data,
    ]);

    // Get or create persistent AMQP channel
    $channel = getAmqpChannel($env, $state);

    $msg = new \PhpAmqpLib\Message\AMQPMessage($eventPayload, [
        'delivery_mode' => \PhpAmqpLib\Message\AMQPMessage::DELIVERY_MODE_PERSISTENT,
    ]);
    $channel->basic_publish($msg, 'events', $routingKey);

    $res->status(202);
    $res->end(json_encode([
        'status'   => 'Accepted',
        'message'  => 'Order request queued for processing.',
        'trace_id' => $traceId,
    ]));
}

function getAmqpChannel(callable $env, object $state): \PhpAmqpLib\Channel\AMQPChannel
{
    if ($state->amqpConn !== null && $state->amqpConn->isConnected()
        && $state->amqpChannel !== null && $state->amqpChannel->is_open()) {
        return $state->amqpChannel;
    }

    // Reset
    try { $state->amqpChannel?->close(); } catch (\Throwable) {}
    try { $state->amqpConn?->close(); } catch (\Throwable) {}

    $host = $env('RABBITMQ_HOST', 'rabbitmq');
    $port = (int) $env('RABBITMQ_PORT', '5672');
    $user = $env('RABBITMQ_USER', 'zt');
    $pass = $env('RABBITMQ_PASS', 'ztpass');

    $state->amqpConn = new \PhpAmqpLib\Connection\AMQPSocketConnection(
        $host, $port, $user, $pass,
        '/', false, 'AMQPLAIN', null, 'en_US',
        10.0, 10.0, null, false, 0,
    );
    $state->amqpChannel = $state->amqpConn->channel();
    $state->topologyDeclared = false;

    // Declare topology once
    if (!$state->topologyDeclared) {
        $exchange = $env('REQUEST_EXCHANGE', 'events');
        $queue = $env('REQUEST_QUEUE', 'order_queue');
        $routingKey = $env('REQUEST_ROUTING_KEY', 'request.new');

        $state->amqpChannel->exchange_declare($exchange, 'direct', false, true, false);
        $state->amqpChannel->queue_declare($queue, false, true, false, false);
        $state->amqpChannel->queue_bind($queue, $exchange, $routingKey);
        $state->topologyDeclared = true;
    }

    return $state->amqpChannel;
}

// ── Start server ────────────────────────────────────────────────
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║         ZT Event Gateway (OpenSwoole)                   ║\n";
echo "╠══════════════════════════════════════════════════════════╣\n";
echo "║  Listening: http://{$host}:{$port}                        ║\n";
echo "║  Workers:   {$workerNum}                                          ║\n";
echo "║  PID:       " . getmypid() . str_repeat(' ', 43 - strlen((string)getmypid())) . "║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";

$server->start();
