<?php

declare(strict_types=1);

use ZtEventGateway\Config\AppConfig;
use ZtEventGateway\MessageQueue\RabbitMQConnection;
use ZtEventGateway\Worker\OrderCreatedWorker;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = AppConfig::fromEnv();
$connection = new RabbitMQConnection(
    $config->amqpHost(),
    $config->amqpPort(),
    $config->amqpUser(),
    $config->amqpPassword(),
);
$worker = new OrderCreatedWorker($connection, $config);

try {
    $worker->run();
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("[worker] fatal: %s\n", $exception->getMessage()));
    exit(1);
} finally {
    $worker->close();
}
