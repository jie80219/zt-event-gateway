<?php

require_once __DIR__ . '/vendor/autoload.php';

use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\MessageQueue\Consumer;
use SDPMlab\ZtEventGateway\MessageQueue\RabbitMQConnection;
use SDPMlab\ZtEventGateway\HandlerScanner;
use ZtEventGateway\Worker\EventConsumer;
use ZtEventGateway\Worker\RequestConsumer;

//  **檢查是否有傳入 queue_name**
if ($argc < 2) {
    die(" 輸入監聽的佇列名稱！\n用法: php consumer.php orders_queue\n");
}

$queueName = $argv[1]; // 傳入的佇列名稱
$requestQueue = getenv('REQUEST_QUEUE') ?: 'request_queue';
while (true) {
    $rabbitMQ = null;
    try {
        $rabbitMQ = RabbitMQConnection::fromEnv();
        $channel = $rabbitMQ->getChannel();
        $channel->basic_qos(null, 1000, null);
        $messageBus = new MessageBus($channel);
        $eventBus = new EventBus($messageBus, null);

        $scanner = new HandlerScanner();
        $scanner->scanAndRegisterHandlers('App\Sagas', $eventBus);

        //  **啟動 RabbitMQ 消費者**
        echo " [*] Listening on queue: $queueName\n";
        $consumer = new Consumer($channel);
        $worker = $queueName === $requestQueue
            ? new RequestConsumer($messageBus)
            : new EventConsumer($eventBus);
        $consumer->subscribe($queueName, [$worker, 'process']);
        $consumer->run();
    } catch (\Throwable $e) {
        $message = $e->getMessage();
        $timestamp = date('Y-m-d H:i:s');
        fwrite(STDERR, "{$timestamp} | [!] Consumer disconnected: {$message}\n");
        sleep(3);
    } finally {
        if ($rabbitMQ !== null) {
            try {
                $rabbitMQ->closeConnection();
            } catch (\Throwable $e) {
                // Ignore cleanup errors, we'll reconnect anyway.
            }
        }
    }
}

    
