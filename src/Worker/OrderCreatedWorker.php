<?php

declare(strict_types=1);

namespace ZtEventGateway\Worker;

use ZtEventGateway\Config\AppConfig;
use ZtEventGateway\Event\EventEnvelope;
use ZtEventGateway\Event\RabbitMqEventBus;

final class OrderCreatedWorker
{
    private const QUEUE_NAME = 'orders.created.v1';

    public function __construct(
        private readonly RabbitMqEventBus $eventBus,
        private readonly AppConfig $config,
    ) {
    }

    public function run(): void
    {
        $this->eventBus->ensureQueue(self::QUEUE_NAME, $this->config->routingKey());
        fwrite(STDOUT, sprintf("[worker] listening queue=%s\n", self::QUEUE_NAME));

        $this->eventBus->consume(self::QUEUE_NAME, function (EventEnvelope $event): void {
            $payload = $event->payload();
            $orderId = (string) ($payload['orderId'] ?? 'unknown');
            $customerId = (string) ($payload['customerId'] ?? 'unknown');

            fwrite(
                STDOUT,
                sprintf(
                    "[worker] processed event=%s orderId=%s customerId=%s\n",
                    $event->eventType(),
                    $orderId,
                    $customerId,
                ),
            );
        });
    }
}
