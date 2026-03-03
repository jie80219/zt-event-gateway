<?php

declare(strict_types=1);

namespace ZtEventGateway\Event;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;
use ZtEventGateway\Config\AppConfig;

final class RabbitMqEventBus implements EventBusInterface
{
    private AMQPStreamConnection $connection;

    private AMQPChannel $channel;

    public function __construct(private readonly AppConfig $config)
    {
        $this->connection = new AMQPStreamConnection(
            host: $this->config->amqpHost(),
            port: $this->config->amqpPort(),
            user: $this->config->amqpUser(),
            password: $this->config->amqpPassword(),
            vhost: $this->config->amqpVhost(),
        );

        $this->channel = $this->connection->channel();
        $this->channel->exchange_declare(
            exchange: $this->config->exchange(),
            type: 'topic',
            passive: false,
            durable: true,
            auto_delete: false,
        );
    }

    public function publish(EventEnvelope $event): void
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);

        $message = new AMQPMessage(
            body: $body,
            properties: [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $event->eventId(),
                'type' => $event->eventType(),
                'timestamp' => time(),
            ],
        );

        $this->channel->basic_publish(
            msg: $message,
            exchange: $this->config->exchange(),
            routing_key: $event->routingKey(),
        );
    }

    public function ensureQueue(string $queueName, string $routingKey): void
    {
        $this->channel->queue_declare(
            queue: $queueName,
            passive: false,
            durable: true,
            exclusive: false,
            auto_delete: false,
        );

        $this->channel->queue_bind(
            queue: $queueName,
            exchange: $this->config->exchange(),
            routing_key: $routingKey,
        );

        $this->channel->basic_qos(null, 1, null);
    }

    /**
     * @param callable(EventEnvelope):void $handler
     */
    public function consume(string $queueName, callable $handler): void
    {
        $this->channel->basic_consume(
            queue: $queueName,
            consumer_tag: '',
            no_local: false,
            no_ack: false,
            exclusive: false,
            nowait: false,
            callback: function (AMQPMessage $message) use ($handler): void {
                try {
                    $payload = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
                    $handler(EventEnvelope::fromArray($payload));
                    $message->ack();
                } catch (Throwable $exception) {
                    fwrite(STDERR, sprintf("[consumer] failed: %s\n", $exception->getMessage()));
                    $message->nack(false, false);
                }
            },
        );

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    public function close(): void
    {
        if (isset($this->channel)) {
            $this->channel->close();
        }

        if (isset($this->connection)) {
            $this->connection->close();
        }
    }
}
