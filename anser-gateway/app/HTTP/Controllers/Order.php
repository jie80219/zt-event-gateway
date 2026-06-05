<?php
namespace App\Controllers;

use App\Controllers\BaseController;
use PhpAmqpLib\Connection\AMQPSocketConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Workerman\Protocols\Http\Response;

class Order extends BaseController
{
    public function create()
    {
        $perfEnabled = getenv('PERF_METRIC_ENABLED') === '1';
        $perfStart = microtime(true);
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

        $eventPayload = json_encode([
            'specversion' => '1.0',
            'type' => 'gateway.request',
            'route' => $targetEvent,
            'source' => '/gateway/order',
            'id' => $traceId,
            'time' => date(DATE_RFC3339),
            'data' => $data,
        ]);

        try {
            $rabbitHost = $this->envAny(['RABBITMQ_HOST', 'AMQP_HOST'], 'rabbitmq');
            $rabbitPort = (int) $this->envAny(['RABBITMQ_PORT', 'AMQP_PORT'], '5672');
            $rabbitUser = $this->envAny(['RABBITMQ_USER', 'AMQP_USER'], 'guest');
            $rabbitPass = $this->envAny(['RABBITMQ_PASS', 'RABBITMQ_PASSWORD', 'AMQP_PASSWORD'], 'guest');

            $connection = new AMQPSocketConnection(
                $rabbitHost,
                $rabbitPort,
                $rabbitUser,
                $rabbitPass
            );
            $channel = $connection->channel();

            $exchangeName = $this->env('REQUEST_EXCHANGE', 'events');
            $queueName = $this->env('REQUEST_QUEUE', 'request_queue');

            $channel->exchange_declare($exchangeName, 'direct', false, true, false);
            $channel->queue_declare($queueName, false, true, false, false);
            $channel->queue_bind($queueName, $exchangeName, $routingKey);

            $msg = new AMQPMessage($eventPayload, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
            $channel->basic_publish($msg, $exchangeName, $routingKey);

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

            $channel->close();
            $connection->close();

            return $this->jsonResponse([
                'status' => 'Accepted',
                'message' => 'Order request queued for processing.',
                'trace_id' => $traceId,
            ], 202);
        } catch (\Exception $e) {
            log_message('error', '[RabbitMQ Error] ' . $e->getMessage());
            return $this->jsonResponse([
                'status' => 'Error',
                'message' => 'Queue Service Unavailable',
            ], 500);
        }
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

    /**
     * Return the first non-empty environment value from candidate names.
     *
     * @param array<int, string> $names
     */
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
}
