<?php
namespace App\Controllers;

use App\Controllers\BaseController;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSocketConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Workerman\Protocols\Http\Response;
use SDPMlab\ZtEventGateway\Ingress\CanonicalOrderRequest;

class Order extends BaseController
{
    /**
     * Per worker-process AMQP connection + channel pool. Coroutines pop a
     * channel for the duration of a single basic_publish and push it back
     * when done — letting independent coroutines publish in parallel
     * instead of serialising through one channel behind a Swoole\Lock.
     *
     * One AMQPSocketConnection multiplexes the pool; the socket write
     * itself is still serial at the kernel level, but the AMQP frame
     * assembly and coroutine yields are not — so the publish latency
     * tail drops compared to lock + single-channel.
     */
    private static ?AMQPSocketConnection $persistentConn = null;
    private static ?\Swoole\Coroutine\Channel $channelPool = null;
    private static int $poolSize = 0;
    private static bool $topologyDeclared = false;

    public function create()
    {
        $request = $this->request;
        $perfEnabled = getenv('PERF_METRIC_ENABLED') === '1';
        $perfStart = $perfEnabled ? microtime(true) : 0.0;
        $rawBody = $request->rawBody();

        if ($rawBody === '' || $rawBody === false) {
            return $this->jsonResponse([
                'status' => 'Bad Request',
                'message' => 'Request body must be valid JSON.',
            ], 400);
        }

        $requestPayload = json_decode($rawBody, true);
        if (!is_array($requestPayload)) {
            return $this->jsonResponse([
                'status' => 'Bad Request',
                'message' => 'Request body must be valid JSON.',
            ], 400);
        }

        try {
            $data = CanonicalOrderRequest::normalizeOrderData($requestPayload);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonResponse([
                'status' => 'Unprocessable Entity',
                'message' => $e->getMessage(),
            ], 422);
        }

        $traceId = $request->header('X-Correlation-ID') ?: uniqid('txn_', true);
        $routingKey = $this->env('REQUEST_ROUTING_KEY', 'request.new');
        $targetEvent = $this->env('REQUEST_EVENT_TYPE', 'OrderCreateRequestedEvent');

        // Canonical request envelope. Service identity is established at the
        // transport layer via Vault PKI mTLS, so the envelope carries no
        // application-layer identity token (no LSVID, no JWT) — only the
        // structural fields the consumers validate.
        $envelope = [
            'schema_version' => CanonicalOrderRequest::SCHEMA_VERSION,
            'type'        => CanonicalOrderRequest::ENVELOPE_TYPE,
            'route'       => $targetEvent,
            'id'          => $traceId,
            'data'        => $data,
        ];

        // Pop one channel out of the worker-process pool for this publish.
        // The pool is bootstrapped lazily; subsequent requests are O(1).
        $this->ensurePool();

        $deliveryMode = getenv('AMQP_PERSISTENT') === '1'
            ? AMQPMessage::DELIVERY_MODE_PERSISTENT
            : AMQPMessage::DELIVERY_MODE_NON_PERSISTENT;

        $msg = new AMQPMessage(json_encode($envelope), [
            'delivery_mode' => $deliveryMode,
        ]);

        $channel = self::$channelPool->pop();
        $returned = false;
        try {
            $channel->basic_publish($msg, 'events', $routingKey);
            self::$channelPool->push($channel);
            $returned = true;

            if ($perfEnabled) {
                $perfEnd = microtime(true);
                fwrite(STDOUT, sprintf(
                    "[perf-request-in] ts_in=%.6f ts_out=%.6f gw_proc_ms=%.3f traceId=%s\n",
                    $perfStart,
                    $perfEnd,
                    ($perfEnd - $perfStart) * 1000.0,
                    $traceId
                ));
            }

            return $this->jsonResponse([
                'status' => 'Accepted',
                'message' => 'Order request queued for processing.',
                'trace_id' => $traceId,
            ], 202);
        } catch (\Exception $e) {
            // Don't push a poisoned channel back into the pool. If the
            // socket is gone, drop the whole pool so the next request
            // rebuilds it; otherwise just replace the lost channel.
            if (!$returned) {
                try { $channel->close(); } catch (\Throwable) {}
            }
            if (self::$persistentConn === null || !self::$persistentConn->isConnected()) {
                $this->resetConnection();
            } else {
                try {
                    self::$channelPool->push(self::$persistentConn->channel());
                } catch (\Throwable) {
                    $this->resetConnection();
                }
            }
            fwrite(STDERR, '[RabbitMQ Error] ' . $e->getMessage() . "\n");

            $payload = [
                'status' => 'Error',
                'message' => 'Queue Service Unavailable',
            ];
            if ($this->isDebugEnabled()) {
                $payload['debug_error'] = $e->getMessage();
            }

            return $this->jsonResponse($payload, 500);
        }
    }

    /**
     * Lazily bootstrap one AMQPSocketConnection plus a pool of channels
     * for this worker process. Idempotent — only the very first publish
     * per worker pays the bootstrap cost; subsequent ones are O(1).
     *
     * Pool size defaults to 8 (overridable via GATEWAY_AMQP_POOL_SIZE);
     * that's roughly the number of in-flight publish coroutines a single
     * gateway worker tends to see at our peak SCALES, and going higher
     * just costs broker channel state.
     */
    private function ensurePool(): void
    {
        if (self::$channelPool !== null
            && self::$persistentConn !== null
            && self::$persistentConn->isConnected()) {
            return;
        }

        // State is partially gone — wipe everything and rebuild cleanly.
        $this->resetConnection();

        $host = $this->envAny(['RABBITMQ_HOST', 'AMQP_HOST'], 'rabbitmq');
        $port = (int) $this->envAny(['RABBITMQ_PORT', 'AMQP_PORT'], '5672');

        self::$persistentConn = $this->connectRabbitMq($host, $port);

        $poolSize = (int) $this->env('GATEWAY_AMQP_POOL_SIZE', '8');
        if ($poolSize < 1) {
            $poolSize = 1;
        }
        self::$poolSize = $poolSize;
        self::$channelPool = new \Swoole\Coroutine\Channel($poolSize);
        for ($i = 0; $i < $poolSize; $i++) {
            $ch = self::$persistentConn->channel();
            if ($i === 0) {
                $this->ensureTopology($ch);
            }
            self::$channelPool->push($ch);
        }
    }

    /**
     * Declare exchange + queue + binding once per connection lifetime.
     */
    private function ensureTopology(AMQPChannel $channel): void
    {
        if (self::$topologyDeclared) {
            return;
        }

        $exchangeName = $this->env('REQUEST_EXCHANGE', 'events');
        $queueName = $this->env('REQUEST_QUEUE', 'order_queue');
        $routingKey = $this->env('REQUEST_ROUTING_KEY', 'request.new');

        $channel->exchange_declare($exchangeName, 'direct', false, true, false);
        $channel->queue_declare($queueName, false, true, false, false);
        $channel->queue_bind($queueName, $exchangeName, $routingKey);

        self::$topologyDeclared = true;
    }

    private function resetConnection(): void
    {
        if (self::$channelPool !== null) {
            // Drain anything sitting in the pool before tearing down the
            // socket. Channels in-flight (popped but not yet pushed back)
            // are owned by their coroutine; that coroutine's catch block
            // is responsible for not putting them back.
            while (!self::$channelPool->isEmpty()) {
                $ch = self::$channelPool->pop(0.001);
                if ($ch === false) {
                    break;
                }
                try { $ch->close(); } catch (\Throwable) {}
            }
            self::$channelPool = null;
        }
        self::$poolSize = 0;

        try {
            self::$persistentConn?->close();
        } catch (\Throwable) {}

        self::$persistentConn = null;
        self::$topologyDeclared = false;
    }

    private function connectRabbitMq(string $host, int $port): AMQPSocketConnection
    {
        $candidates = [];

        $envUser = $this->envAny(['RABBITMQ_USER', 'AMQP_USER'], '');
        $envPass = $this->envAny(['RABBITMQ_PASS', 'RABBITMQ_PASSWORD', 'AMQP_PASSWORD'], '');
        if ($envUser !== '' && $envPass !== '') {
            $candidates[] = [$envUser, $envPass];
        }

        $candidates[] = ['zt', 'ztpass'];
        // No guest fallback — use 'zt'/'ztpass' or env vars only

        $lastException = null;

        foreach ($candidates as [$user, $pass]) {
            try {
                return new AMQPSocketConnection(
                    $host, $port, $user, $pass,
                    '/',          // vhost
                    false,        // insist
                    'AMQPLAIN',   // login_method
                    null,         // login_response
                    'en_US',      // locale
                    10.0,         // connection_timeout
                    10.0,         // read_write_timeout
                    null,         // context
                    false,        // keepalive
                    0,            // heartbeat=0 for Swow compatibility
                );
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        if ($lastException instanceof \Exception) {
            throw $lastException;
        }

        throw new \RuntimeException('Unable to connect to RabbitMQ.');
    }

    private function jsonResponse(array $payload, int $status): Response
    {
        /** @var Response $response */
        $response = $this->response;

        return $response
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status)
            ->withBody(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);
        return $value === false ? $default : $value;
    }

    private function envAny(array $names, string $default): string
    {
        foreach ($names as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return $default;
    }

    private function isDebugEnabled(): bool
    {
        $value = getenv('APP_DEBUG');
        return is_string($value) && in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
