<?php
namespace App\Controllers;

use App\Controllers\BaseController;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSocketConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Workerman\Protocols\Http\Response;
use SDPMlab\ZtEventGateway\Ingress\CanonicalOrderRequest;
use AnserGateway\Keycloak\GatewayKeycloakState;

class Order extends BaseController
{
    /**
     * Persistent connection shared across all requests within this
     * worker process. A coroutine-level lock serializes access so concurrent
     * Swoole coroutines don't interleave AMQP frames on the same channel.
     */
    private static ?AMQPSocketConnection $persistentConn = null;
    private static ?AMQPChannel $persistentCh = null;
    private static bool $topologyDeclared = false;
    private static ?\Swoole\Lock $channelLock = null;

    public function create()
    {
        $request = $this->request;
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

        $keycloakEnabled = GatewayKeycloakState::isEnabled();
        $clientId = $keycloakEnabled ? GatewayKeycloakState::getClientId() : '';

        $envelope = [
            'schema_version' => CanonicalOrderRequest::SCHEMA_VERSION,
            'type'           => CanonicalOrderRequest::ENVELOPE_TYPE,
            'route'          => $targetEvent,
            'id'             => $traceId,
            'token_path'     => $clientId !== '' ? [$clientId] : [],
            'data'           => $data,
        ];

        if ($keycloakEnabled) {
            $provider = GatewayKeycloakState::getTokenProvider();
            if ($provider === null) {
                return $this->jsonResponse([
                    'status' => 'Service Unavailable',
                    'message' => 'Keycloak token provider is not available.',
                ], 503);
            }
            try {
                $jwt = $provider->getAccessToken();
            } catch (\Throwable $e) {
                fwrite(STDERR, sprintf(
                    "[gateway] Keycloak token mint failed (trace=%s): %s\n",
                    $traceId,
                    $e->getMessage(),
                ));
                return $this->jsonResponse([
                    'status' => 'Internal Server Error',
                    'message' => 'Identity token creation failed.',
                ], 500);
            }

            $envelope['authorization'] = [
                'jwt'       => $jwt,
                'client_id' => $clientId,
            ];
        }

        $lock = self::getLock();
        $lock->lock();
        try {
            $channel = $this->getChannel();

            $msg = new AMQPMessage(json_encode($envelope), [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]);
            $channel->basic_publish($msg, 'events', $routingKey);

            return $this->jsonResponse([
                'status' => 'Accepted',
                'message' => 'Order request queued for processing.',
                'trace_id' => $traceId,
            ], 202);
        } catch (\Exception $e) {
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
        } finally {
            $lock->unlock();
        }
    }

    private function getChannel(): AMQPChannel
    {
        if (self::$persistentConn !== null && self::$persistentConn->isConnected()
            && self::$persistentCh !== null && self::$persistentCh->is_open()) {
            return self::$persistentCh;
        }

        $this->resetConnection();

        $host = $this->envAny(['RABBITMQ_HOST', 'AMQP_HOST'], 'rabbitmq');
        $port = (int) $this->envAny(['RABBITMQ_PORT', 'AMQP_PORT'], '5672');

        self::$persistentConn = $this->connectRabbitMq($host, $port);
        self::$persistentCh = self::$persistentConn->channel();
        self::$topologyDeclared = false;

        $this->ensureTopology(self::$persistentCh);

        return self::$persistentCh;
    }

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

    private static function getLock(): \Swoole\Lock
    {
        if (self::$channelLock === null) {
            self::$channelLock = new \Swoole\Lock(SWOOLE_MUTEX);
        }
        return self::$channelLock;
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

        $lastException = null;

        foreach ($candidates as [$user, $pass]) {
            try {
                return new AMQPSocketConnection(
                    $host, $port, $user, $pass,
                    '/',
                    false,
                    'AMQPLAIN',
                    null,
                    'en_US',
                    10.0,
                    10.0,
                    null,
                    false,
                    0,
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
