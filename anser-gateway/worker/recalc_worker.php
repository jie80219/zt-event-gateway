<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPSocketConnection;
use AnserGateway\ServiceDiscovery\LoadBalance\EntropyScoring;

bootstrapEnv();

$mqHost = envValue('LOAD_BALANCE_AMQP_HOST', envValue('AMQP_HOST', 'rabbitmq'));
$mqPort = (int) envValue('LOAD_BALANCE_AMQP_PORT', envValue('AMQP_PORT', '5672'));
$mqUser = envValue('LOAD_BALANCE_AMQP_USER', envValue('AMQP_USER', 'zt'));
$mqPass = envValue('LOAD_BALANCE_AMQP_PASSWORD', envValue('AMQP_PASSWORD', 'ztpass'));
$mqQueue = envValue('LOAD_BALANCE_RECALC_QUEUE', 'recalc_weight');

$conn = new AMQPSocketConnection($mqHost, $mqPort, $mqUser, $mqPass);
$ch = $conn->channel();
$ch->queue_declare($mqQueue, false, true, false, false);

$ch->basic_consume($mqQueue, '', false, true, false, false, function () {
    $scorer = new EntropyScoring();
    $scorer->recalculateScores();
});

while ($ch->is_consuming()) {
    $ch->wait();
}

function envValue(string $key, string $default): string
{
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : $default;
}

function bootstrapEnv(): void
{
    $root = dirname(__DIR__);
    $envPath = $root . '/env';
    if (!is_file($envPath)) {
        return;
    }

    try {
        (new \AnserGateway\Config\DotEnv($root, 'env'))->load();
    } catch (\Throwable $e) {
        fwrite(STDERR, "[recalc] failed to load env: {$e->getMessage()}\n");
    }
}
