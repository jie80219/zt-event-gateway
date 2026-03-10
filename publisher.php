<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/init.php';

use App\Events\OrderCreateRequestedEvent;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\MessageQueue\RabbitMQConnection;

$env = static function (string $key, string $default): string {
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : $default;
};

$host = $env('AMQP_HOST', $env('RABBITMQ_HOST', '127.0.0.1'));
$port = (int) $env('AMQP_PORT', $env('RABBITMQ_PORT', '5672'));
$user = $env('AMQP_USER', $env('RABBITMQ_USER', 'guest'));
$password = $env('AMQP_PASSWORD', $env('RABBITMQ_PASS', 'guest'));
$exchange = $env('AMQP_EXCHANGE', 'events');

$input = [];
$raw = file_get_contents('php://stdin');
if (is_string($raw) && trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

if ($input === [] && isset($argv[1])) {
    $decoded = json_decode((string) $argv[1], true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

if ($input === []) {
    $input = [
        'orderId' => uniqid('order_', true),
        'userKey' => 'demo-user',
        'productList' => [
            ['p_key' => 1, 'price' => 100, 'amount' => 2],
        ],
        'total' => 200,
    ];
}

$rabbitMQ = new RabbitMQConnection($host, $port, $user, $password);
$channel = $rabbitMQ->getChannel();
$messageBus = new MessageBus($channel, $exchange);
$eventBus = new EventBus($messageBus, null);

$eventBus->publish(OrderCreateRequestedEvent::class, $input);

fwrite(STDOUT, json_encode([
    'message' => 'Event sent successfully',
    'eventType' => 'OrderCreateRequestedEvent',
    'data' => $input,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);

$rabbitMQ->closeConnection();
