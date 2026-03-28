<?php
namespace App\Controllers;

use App\Controllers\BaseController;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSocketConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Workerman\Protocols\Http\Response;

class Order extends BaseController
{
    /**
     * Persistent connection shared across all requests within this
     * Workerman worker process. Survives thousands of requests without
     * opening new TCP sockets.
     */
    private static ?AMQPSocketConnection $persistentConn = null;
    private static ?AMQPChannel $persistentCh = null;
    private static bool $topologyDeclared = false;

    public function create()
    {
        $request = $this->request;
        $rawBody = $request->rawBody();
        $data = [];

        if ($rawBody !== '') {
            $data = json_decode($rawBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->jsonResponse([
                    'status' => 'Bad Request',
                    'message' => 'Request body must be valid JSON.',
                ], 400);
            }
        }

        $traceId = $request->header('X-Correlation-ID') ?: uniqid('txn_', true);

        $routingKey = $this->env('REQUEST_ROUTING_KEY', 'request.new');
        $targetEvent = $this->env('REQUEST_EVENT_TYPE', 'OrderCreateRequestedEvent');

        $spiffeId = $this->env('SPIFFE_ID', '');

        $eventPayload = json_encode([
            'specversion' => '1.0',
            'type' => 'gateway.request',
            'route' => $targetEvent,
            'source' => '/gateway/order',
            'id' => $traceId,
            'time' => date(DATE_RFC3339),
            'spiffe_id' => $spiffeId,
            'spiffe_path' => [$spiffeId],
            'data' => $data,
        ]);

        try {
            $channel = $this->getChannel();

            $msg = new AMQPMessage($eventPayload, [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]);
            $channel->basic_publish($msg, 'events', $routingKey);

            return $this->jsonResponse([
                'status' => 'Accepted',
                'message' => 'Order request queued for processing.',
                'trace_id' => $traceId,
            ], 202);
        } catch (\Exception $e) {
            // Connection broken — reset so next request reconnects
            $this->resetConnection();
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
     * Get or create the persistent AMQP channel.
     *
     * One TCP connection + one AMQP channel per Workerman worker process,
     * reused across all HTTP requests. Reconnects automatically if the
     * connection drops.
     */
    private function getChannel(): AMQPChannel
    {
        // Fast path: reuse existing connection
        if (self::$persistentConn !== null && self::$persistentConn->isConnected()
            && self::$persistentCh !== null && self::$persistentCh->is_open()) {
            return self::$persistentCh;
        }

        // Connection lost or first call — (re)connect
        $this->resetConnection();

        $host = $this->envAny(['RABBITMQ_HOST', 'AMQP_HOST'], 'rabbitmq');
        $port = (int) $this->envAny(['RABBITMQ_PORT', 'AMQP_PORT'], '5672');

        self::$persistentConn = $this->connectRabbitMq($host, $port);
        self::$persistentCh = self::$persistentConn->channel();
        self::$topologyDeclared = false;

        // Declare topology once per connection
        $this->ensureTopology(self::$persistentCh);

        return self::$persistentCh;
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
        try {
            self::$persistentCh?->close();
        } catch (\Throwable) {}

        try {
            self::$persistentConn?->close();
        } catch (\Throwable) {}

        self::$persistentCh = null;
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
