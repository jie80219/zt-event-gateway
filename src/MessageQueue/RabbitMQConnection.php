<?php

namespace SDPMlab\AnserEDA\MessageQueue;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;

class RabbitMQConnection
{
    private AMQPStreamConnection $connection;
    private AMQPChannel $channel;

    public function __construct(string $host, int $port, string $user, string $password)
    {
        // ✅ **建立 RabbitMQ 連線**
        $this->connection = new AMQPStreamConnection($host, $port, $user, $password);
        $this->channel = $this->connection->channel();
    }

    /**
     * ✅ **初始化 Exchange & Queue**
     *
     * @param string $exchangeName 交換機名稱
     * @param string $queueName 佇列名稱
     * @return void
     */
    public function setupQueue(string $queue, string $exchange, string $routingKey)
    {
        // 確保 Queue 存在
        $this->channel->queue_declare($queue, false, true, false, false);
        
        // 綁定 Queue 到 Exchange，並指定 Routing Key
        $this->channel->queue_bind($queue, $exchange, $routingKey);
    }

    /**
     * ✅ **取得 Channel**
     * @return AMQPChannel
     */
    public function getChannel(): AMQPChannel
    {
        return $this->channel;
    }

    /**
     * ✅ **關閉連線**
     */
    public function closeConnection()
    {
        $this->channel->close();
        $this->connection->close();
    }
}
