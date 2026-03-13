<?php

declare(strict_types=1);

namespace ZtEventGateway\Worker;

use JsonException;
use PhpAmqpLib\Message\AMQPMessage;
use ZtEventGateway\Config\AppConfig;
use ZtEventGateway\MessageQueue\RabbitMQConnection;

final class OrderCreatedWorker
{
    private const QUEUE_NAME = 'orders.created.v1';

    public function __construct(
        private readonly RabbitMQConnection $connection,
        private readonly AppConfig $config,
    ) {
    }

    public function run(): void
    {
        $channel = $this->connection->getChannel();

        $channel->exchange_declare($this->config->exchange(), 'topic', false, true, false);
        $this->connection->setupQueue(self::QUEUE_NAME, $this->config->exchange(), $this->config->routingKey());
        fwrite(STDOUT, sprintf("[worker] listening queue=%s\n", self::QUEUE_NAME));

        $channel->basic_qos(0, 1, false);
        $channel->basic_consume(
            queue: self::QUEUE_NAME,
            consumer_tag: '',
            no_local: false,
            no_ack: false,
            exclusive: false,
            nowait: false,
            callback: function (AMQPMessage $message): void {
                $deliveryTag = $message->has('delivery_tag') ? $message->get('delivery_tag') : null;

                try {
                    $event = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($event)) {
                        throw new JsonException('Message payload is not a JSON object.');
                    }

                    $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
                    $orderId = (string) ($payload['orderId'] ?? 'unknown');
                    $customerId = (string) ($payload['customerId'] ?? 'unknown');
                    $eventType = (string) ($event['eventType'] ?? 'unknown');

                    fwrite(
                        STDOUT,
                        sprintf(
                            "[worker] processed event=%s orderId=%s customerId=%s\n",
                            $eventType,
                            $orderId,
                            $customerId,
                        ),
                    );

                    if ($deliveryTag !== null) {
                        $this->connection->getChannel()->basic_ack($deliveryTag);
                    }
                } catch (\Throwable $exception) {
                    fwrite(STDERR, sprintf("[worker] message error: %s\n", $exception->getMessage()));

                    if ($deliveryTag !== null) {
                        $this->connection->getChannel()->basic_nack($deliveryTag, false, false);
                    }
                }
            },
        );

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }

    public function close(): void
    {
        $this->connection->closeConnection();
    }
}
