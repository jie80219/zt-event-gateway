<?php

namespace SDPMlab\ZtEventGateway\MessageQueue;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;

class MessageBus
{
    private AMQPChannel $channel;
    private string $defaultExchange;

    public function __construct(
        AMQPChannel $channel,
        string $defaultExchange = 'events',
    ) {
        $this->channel = $channel;
        $this->defaultExchange = $defaultExchange;
    }

    public function setupExchange(string $exchange, string $exchangeType = 'topic'): void
    {
        $this->channel->exchange_declare($exchange, $exchangeType, false, true, false);
    }

    public function setupQueue(string $queue, string $exchange, string $routingKey = '#'): void
    {
        $this->channel->queue_declare($queue, false, true, false, false);
        $this->channel->queue_bind($queue, $exchange, $routingKey);
    }

    public function publishMessage(string $exchange, string $message, string $routingKey = 'OrderCreateRequestedEvent'): void
    {
        $msg = new AMQPMessage($message, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
        $this->channel->basic_publish($msg, $exchange, $routingKey);
    }

    public function publishEvent(
        string $eventType,
        array $eventData,
        ?string $exchange = null,
    ): void {
        $routingKey = substr(strrchr($eventType, '\\'), 1);

        $envelope = [
            'type'      => $eventType,
            'data'      => $eventData,
            'timestamp' => date(DATE_RFC3339),
        ];

        $message = new AMQPMessage(
            json_encode($envelope),
            ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT],
        );

        $this->channel->basic_publish($message, $exchange ?? $this->defaultExchange, $routingKey);
    }
}
