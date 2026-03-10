<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use SDPMlab\ZtEventGateway\HandlerScanner;
use SDPMlab\ZtEventGateway\MessageQueue\RabbitMQConnection;

$env = static function (string $key, string $default): string {
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : $default;
};

$host = $env('AMQP_HOST', $env('RABBITMQ_HOST', '127.0.0.1'));
$port = (int) $env('AMQP_PORT', $env('RABBITMQ_PORT', '5672'));
$user = $env('AMQP_USER', $env('RABBITMQ_USER', 'guest'));
$password = $env('AMQP_PASSWORD', $env('RABBITMQ_PASS', 'guest'));
$requestQueue = $env('REQUEST_QUEUE', 'request_queue');

$scanner = new HandlerScanner();
$eventQueues = $scanner->scanEventTypesFromFile(__DIR__ . '/Sagas/OrderSaga.php');

$queues = [
    $requestQueue,
    ...$eventQueues,
];

$rabbitMQ = new RabbitMQConnection($host, $port, $user, $password);
$channel = $rabbitMQ->getChannel();

foreach ($queues as $queue) {
    $channel->queue_delete($queue);
    fwrite(STDOUT, sprintf("Deleted queue: %s\n", $queue));
}

$rabbitMQ->closeConnection();
