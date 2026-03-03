<?php

namespace SDPMlab\AnserEDA\MessageQueue;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;

class MessageBus
{
    private AMQPChannel $channel;

    public function __construct(AMQPChannel $channel)
    {
        $this->channel = $channel;
    }

    /**
     * 設置 RabbitMQ 交換機
     */
    public function setupExchange(string $exchange)
    {
        $this->channel->exchange_declare($exchange, 'fanout', false, true, false);
    }

    /**
     * 設置 RabbitMQ 隊列
     */
    public function setupQueue(string $queue, string $exchange, string $routingKey = '')
    {
        $this->channel->queue_declare($queue, false, true, false, false);
        $this->channel->queue_bind($queue, $exchange, $routingKey);
    }
    /**
     *發送一般訊息
     */
    public function publishMessage(string $exchange, string $message)
    {
        $msg = new AMQPMessage($message, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
        $this->channel->basic_publish($msg, $exchange,'OrderCreateRequestedEvent');
    }

    /**
     *發送事件 (Event Bus)
     */
    public function publishEvent(string $eventType, array $eventData)
    {
        $routingKey = substr(strrchr($eventType, '\\'), 1);
    
        $message = new AMQPMessage(json_encode([
            'type' => $eventType,
            'data' => $eventData
        ]), ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
    
        $this->channel->basic_publish($message, 'events', $routingKey);
    }
    
}