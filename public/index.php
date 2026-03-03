<?php

declare(strict_types=1);

use Ramsey\Uuid\Uuid;
use ZtEventGateway\Config\AppConfig;
use ZtEventGateway\Event\EventEnvelope;
use ZtEventGateway\Event\RabbitMqEventBus;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = AppConfig::fromEnv();
$eventBus = null;

try {
    $eventBus = new RabbitMqEventBus($config);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    if ($method === 'GET' && in_array($path, ['/health', '/api/health'], true)) {
        respondJson(200, [
            'ok' => true,
            'service' => $config->serviceName(),
            'timestamp' => gmdate(DATE_ATOM),
        ]);
    }

    if ($method === 'POST' && in_array($path, ['/orders', '/api/orders'], true)) {
        $input = readJsonBody();
        validateOrderRequest($input);

        $orderId = (string) ($input['orderId'] ?? Uuid::uuid7()->toString());
        $eventPayload = [
            'orderId' => $orderId,
            'customerId' => (string) $input['customerId'],
            'amount' => (float) $input['amount'],
            'currency' => (string) ($input['currency'] ?? 'TWD'),
            'items' => is_array($input['items'] ?? null) ? $input['items'] : [],
            'verifiedSpiffeId' => requestHeader('x-spiffe-id'),
        ];

        $event = EventEnvelope::orderCreated($eventPayload, $config->serviceName());
        $eventBus->publish($event);

        respondJson(202, [
            'accepted' => true,
            'orderId' => $orderId,
            'eventId' => $event->eventId(),
            'eventType' => $event->eventType(),
        ]);
    }

    respondJson(404, [
        'error' => 'not_found',
        'message' => 'Use POST /orders or GET /health',
    ]);
} catch (InvalidArgumentException $exception) {
    respondJson(422, [
        'error' => 'validation_error',
        'message' => $exception->getMessage(),
    ]);
} catch (Throwable $exception) {
    respondJson(500, [
        'error' => 'internal_error',
        'message' => $exception->getMessage(),
    ]);
} finally {
    if ($eventBus !== null) {
        $eventBus->close();
    }
}

/**
 * @return array<string, mixed>
 */
function readJsonBody(): array
{
    $rawBody = file_get_contents('php://input');

    if (!is_string($rawBody) || trim($rawBody) === '') {
        throw new InvalidArgumentException('Request body is required');
    }

    $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
        throw new InvalidArgumentException('Request body must be a JSON object');
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $input
 */
function validateOrderRequest(array $input): void
{
    if (!isset($input['customerId']) || trim((string) $input['customerId']) === '') {
        throw new InvalidArgumentException('customerId is required');
    }

    if (!isset($input['amount']) || !is_numeric($input['amount'])) {
        throw new InvalidArgumentException('amount must be numeric');
    }

    if ((float) $input['amount'] <= 0) {
        throw new InvalidArgumentException('amount must be greater than 0');
    }
}

function requestHeader(string $name): ?string
{
    $normalized = strtoupper(str_replace('-', '_', $name));
    $direct = $_SERVER['HTTP_' . $normalized] ?? null;

    if (is_string($direct) && trim($direct) !== '') {
        return $direct;
    }

    return null;
}

/**
 * @param array<string, mixed> $payload
 */
function respondJson(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
