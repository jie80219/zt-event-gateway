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
        $msg = new AMQPMessage($message, ['delivery_mode' => $this->deliveryMode()]);
        $this->channel->basic_publish($msg, $exchange, $routingKey);
    }

    /**
     * Per-message delivery mode toggle. Defaults to NON_PERSISTENT
     * (latency-optimised) — set AMQP_PERSISTENT=1 to fall back to the
     * old "survive broker restart" semantics. Queue durability is
     * declared independently in setupQueue() and is not affected here.
     */
    private function deliveryMode(): int
    {
        return getenv('AMQP_PERSISTENT') === '1'
            ? AMQPMessage::DELIVERY_MODE_PERSISTENT
            : AMQPMessage::DELIVERY_MODE_NON_PERSISTENT;
    }

    /**
     * Publish an event onto the message bus.
     *
     * The envelope carries only the canonical structural fields — service
     * identity is established at the transport layer via Vault PKI mTLS, so
     * there is no application-layer identity token (no LSVID, no JWT).
     *
     * @param string $eventType  Fully-qualified event class name
     * @param array  $eventData  Event payload
     * @param string|null $exchange Override exchange (default: $defaultExchange)
     */
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
            ['delivery_mode' => $this->deliveryMode()],
        );

        $this->channel->basic_publish($message, $exchange ?? $this->defaultExchange, $routingKey);
    }
}
