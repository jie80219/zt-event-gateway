<?php

namespace SDPMlab\ZtEventGateway\MessageQueue;

use PhpAmqpLib\Connection\AMQPSocketConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;

class RabbitMQConnection
{
    private AMQPSocketConnection $connection;
    private AMQPChannel $channel;

    public function __construct(string $host, int $port, string $user, string $password)
    {
        // ✅ **建立 RabbitMQ 連線**
        $this->connection = new AMQPSocketConnection($host, $port, $user, $password);
        $this->channel = $this->connection->channel();
    }

    /**
     * Build a connection using environment variables with sensible fallbacks.
     * Retries with guest/guest if ACCESS_REFUSED is returned for root/root.
     */
    public static function fromEnv(): self
    {
        $host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('RABBITMQ_PORT') ?: 5672);

        $candidates = [];
        $envUser = getenv('RABBITMQ_USER');
        $envPass = getenv('RABBITMQ_PASS');
        if ($envUser !== false && $envPass !== false) {
            $candidates[] = ['user' => $envUser, 'pass' => $envPass];
        }
        $candidates[] = ['user' => 'root', 'pass' => 'root'];
        $candidates[] = ['user' => 'guest', 'pass' => 'guest'];

        $lastException = null;

        foreach ($candidates as $credentials) {
            try {
                return new self($host, $port, $credentials['user'], $credentials['pass']);
            } catch (\Throwable $e) {
                if (!self::shouldRetryWithFallback($e)) {
                    throw $e;
                }
                $lastException = $e;
            }
        }

        throw $lastException ?? new \RuntimeException('Unable to connect to RabbitMQ with any credentials.');
    }

    private static function shouldRetryWithFallback(\Throwable $e): bool
    {
        return $e instanceof AMQPConnectionClosedException
            && str_contains($e->getMessage(), 'ACCESS_REFUSED');
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
