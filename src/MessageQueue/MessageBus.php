<?php

namespace SDPMlab\ZtEventGateway\MessageQueue;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;

class MessageBus
{
    private AMQPChannel $channel;
    private string $defaultExchange;
    private string $spiffeId;

    public function __construct(AMQPChannel $channel, string $defaultExchange = 'events')
    {
        $this->channel = $channel;
        $this->defaultExchange = $defaultExchange;
        $this->spiffeId = getenv('SPIFFE_ID') ?: '';
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

    /**
     * Publish an event with SPIFFE identity chain.
     *
     * @param string      $eventType  Fully-qualified event class name
     * @param array       $eventData  Event payload
     * @param string|null $exchange   Override exchange (default: $defaultExchange)
     * @param array       $spiffePath Previous SPIFFE identity chain from upstream
     */
    public function publishEvent(string $eventType, array $eventData, ?string $exchange = null, array $spiffePath = []): void
    {
        $routingKey = substr(strrchr($eventType, '\\'), 1);

        // Append current service's SPIFFE ID to the identity path
        if ($this->spiffeId !== '') {
            $spiffePath[] = $this->spiffeId;
        }

        $message = new AMQPMessage(json_encode([
            'type'        => $eventType,
            'data'        => $eventData,
            'spiffe_id'   => $this->spiffeId,
            'spiffe_path' => $spiffePath,
            'timestamp'   => date(DATE_RFC3339),
        ]), ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);

        $this->channel->basic_publish($message, $exchange ?? $this->defaultExchange, $routingKey);
    }

    public function getSpiffeId(): string
    {
        return $this->spiffeId;
    }
}
