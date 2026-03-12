<?php

declare(strict_types=1);

use PhpAmqpLib\Message\AMQPMessage;
use Ramsey\Uuid\Uuid;
use ZtEventGateway\Config\AppConfig;
use ZtEventGateway\MessageQueue\RabbitMQConnection;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = AppConfig::fromEnv();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($uriPath) ? $uriPath : '/';

if ($path === '/health' && $method === 'GET') {
    respond(200, [
        'status' => 'ok',
        'service' => $config->serviceName(),
        'timestamp' => gmdate(DATE_ATOM),
    ]);
}

if ($path === '/orders' && $method === 'POST') {
    $rawBody = file_get_contents('php://input');

    try {
        $input = json_decode($rawBody ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(400, ['error' => 'Invalid JSON body.']);
    }

    if (!is_array($input)) {
        respond(400, ['error' => 'Invalid request body.']);
    }

    $customerId = isset($input['customerId']) ? trim((string) $input['customerId']) : '';
    $amount = isset($input['amount']) ? (float) $input['amount'] : null;
    $currency = isset($input['currency']) ? strtoupper(trim((string) $input['currency'])) : '';

    if ($customerId === '' || $amount === null || $amount <= 0 || $currency === '') {
        respond(422, ['error' => 'customerId, amount (>0), currency are required.']);
    }

    $event = [
        'eventId' => Uuid::uuid7()->toString(),
        'eventType' => $config->routingKey(),
        'occurredAt' => gmdate(DATE_ATOM),
        'source' => $config->serviceName(),
        'payload' => [
            'orderId' => Uuid::uuid7()->toString(),
            'customerId' => $customerId,
            'amount' => $amount,
            'currency' => $currency,
        ],
    ];

    $connection = null;
    try {
        $connection = new RabbitMQConnection(
            $config->amqpHost(),
            $config->amqpPort(),
            $config->amqpUser(),
            $config->amqpPassword(),
        );
        $channel = $connection->getChannel();
        $channel->exchange_declare($config->exchange(), 'topic', false, true, false);

        $message = new AMQPMessage(
            json_encode($event, JSON_THROW_ON_ERROR),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ],
        );

        $channel->basic_publish($message, $config->exchange(), $config->routingKey());
    } catch (Throwable $exception) {
        if ($connection instanceof RabbitMQConnection) {
            $connection->closeConnection();
        }
        respond(500, ['error' => 'Failed to publish event.', 'detail' => $exception->getMessage()]);
    }

    if ($connection instanceof RabbitMQConnection) {
        $connection->closeConnection();
    }

    respond(202, [
        'status' => 'accepted',
        'eventId' => $event['eventId'],
        'orderId' => $event['payload']['orderId'],
    ]);
}

respond(404, ['error' => 'Not found']);

function respond(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
