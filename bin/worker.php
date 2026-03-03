<?php

declare(strict_types=1);

use ZtEventGateway\Config\AppConfig;
use ZtEventGateway\Event\RabbitMqEventBus;
use ZtEventGateway\Worker\OrderCreatedWorker;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = AppConfig::fromEnv();
$eventBus = new RabbitMqEventBus($config);
$worker = new OrderCreatedWorker($eventBus, $config);

try {
    $worker->run();
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("[worker] fatal: %s\n", $exception->getMessage()));
    exit(1);
} finally {
    $eventBus->close();
}
