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

if (in_array($path, ['/health', '/api/health'], true) && $method === 'GET') {
    respond(200, [
        'status' => 'ok',
        'service' => $config->serviceName(),
        'timestamp' => gmdate(DATE_ATOM),
    ]);
}

if (in_array($path, ['/orders', '/api/orders'], true) && $method === 'POST') {
    $rawBody = file_get_contents('php://input');

    try {
        $input = json_decode($rawBody ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(400, ['error' => 'Invalid JSON body.']);
    }

    if (!is_array($input)) {
        respond(400, ['error' => 'Invalid request body.']);
    }

    [$customerId, $amount, $currency, $extraPayload] = normalizeOrderInput($input);

    $event = [
        'eventId' => Uuid::uuid7()->toString(),
        'eventType' => $config->routingKey(),
        'occurredAt' => gmdate(DATE_ATOM),
        'source' => $config->serviceName(),
        'payload' => array_merge(
            [
                'orderId' => Uuid::uuid7()->toString(),
                'customerId' => $customerId,
                'amount' => $amount,
                'currency' => $currency,
            ],
            $extraPayload,
        ),
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

/**
 * @return array{0:string,1:float,2:string,3:array<string,mixed>}
 */
function normalizeOrderInput(array $input): array
{
    $hasNewFormat = array_key_exists('user_id', $input) || array_key_exists('product_list', $input);
    if ($hasNewFormat) {
        $userId = isset($input['user_id']) ? (int) $input['user_id'] : 0;
        $productList = $input['product_list'] ?? null;

        if ($userId <= 0 || !is_array($productList) || $productList === []) {
            respond(422, ['error' => 'user_id (>0) and non-empty product_list are required.']);
        }

        $normalizedProducts = [];
        $totalAmount = 0.0;

        foreach ($productList as $item) {
            if (!is_array($item)) {
                respond(422, ['error' => 'Each item in product_list must be an object.']);
            }

            $productKey = isset($item['p_key']) ? (int) $item['p_key'] : 0;
            $itemAmount = isset($item['amount']) ? (int) $item['amount'] : 0;
            if ($productKey <= 0 || $itemAmount <= 0) {
                respond(422, ['error' => 'Each product requires p_key (>0) and amount (>0).']);
            }

            $normalizedProducts[] = [
                'p_key' => $productKey,
                'amount' => $itemAmount,
            ];
            $totalAmount += $itemAmount;
        }

        $currency = isset($input['currency']) ? strtoupper(trim((string) $input['currency'])) : 'TWD';
        if ($currency === '') {
            $currency = 'TWD';
        }

        return [
            (string) $userId,
            $totalAmount,
            $currency,
            [
                'user_id' => $userId,
                'product_list' => $normalizedProducts,
            ],
        ];
    }

    $customerId = isset($input['customerId']) ? trim((string) $input['customerId']) : '';
    $amount = isset($input['amount']) ? (float) $input['amount'] : 0.0;
    $currency = isset($input['currency']) ? strtoupper(trim((string) $input['currency'])) : '';

    if ($customerId === '' || $amount <= 0 || $currency === '') {
        respond(422, ['error' => 'customerId, amount (>0), currency are required.']);
    }

    return [$customerId, $amount, $currency, []];
}

function respond(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
