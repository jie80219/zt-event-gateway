<?php

declare(strict_types=1);

use PhpAmqpLib\Connection\AMQPSocketConnection;

require_once __DIR__ . '/vendor/autoload.php';

final class CliOut
{
    public static function line(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    public static function error(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }
}

final class RabbitMqInitializer
{
    private const MAIN_EXCHANGE = 'events';
    private const EXCHANGE_TYPE = 'direct';
    private const ENTRY_QUEUE_NAME = 'order_queue';
    private const ENTRY_ROUTING_KEY = 'request.new';

    /** @var array<int, string> */
    private array $eventQueues = [
        'OrderCreateRequestedEvent',
        'InventoryDeductedEvent',
        'PaymentProcessedEvent',
        'OrderCreatedEvent',
        'RollbackInventoryEvent',
        'RollbackOrderEvent',
    ];

    public function run(bool $fresh): int
    {
        CliOut::line('[Anser-Gateway] Initializing Hybrid Topology...');

        $host = $this->envAny(['RABBITMQ_HOST', 'AMQP_HOST'], 'rabbitmq');
        $port = (int) $this->envAny(['RABBITMQ_PORT', 'AMQP_PORT'], '5672');

        $connection = null;
        $channel = null;

        try {
            $connection = $this->connectRabbitMq($host, $port);
            $channel = $connection->channel();

            if ($fresh) {
                $this->teardown($channel);
            }

            $this->setup($channel);
            CliOut::line('Initialization completed.');

            return 0;
        } catch (\Throwable $e) {
            CliOut::error('Initialization failed: ' . $e->getMessage());
            return 1;
        } finally {
            if ($channel !== null) {
                $channel->close();
            }
            if ($connection !== null) {
                $connection->close();
            }
        }
    }

    private function setup($channel): void
    {
        $channel->exchange_declare(self::MAIN_EXCHANGE, self::EXCHANGE_TYPE, false, true, false);

        $channel->queue_declare(self::ENTRY_QUEUE_NAME, false, true, false, false);
        $channel->queue_bind(self::ENTRY_QUEUE_NAME, self::MAIN_EXCHANGE, self::ENTRY_ROUTING_KEY);
        CliOut::line('Created queue: ' . self::ENTRY_QUEUE_NAME . ' -> ' . self::ENTRY_ROUTING_KEY);

        foreach ($this->eventQueues as $eventName) {
            $channel->queue_declare($eventName, false, true, false, false);
            $channel->queue_bind($eventName, self::MAIN_EXCHANGE, $eventName);
            CliOut::line('Created queue: ' . $eventName . ' -> ' . $eventName);
        }
    }

    private function teardown($channel): void
    {
        CliOut::line('[Fresh Mode] cleaning old topology...');

        try {
            $channel->queue_delete(self::ENTRY_QUEUE_NAME);
        } catch (\Throwable $e) {
        }

        foreach ($this->eventQueues as $queueName) {
            try {
                $channel->queue_delete($queueName);
            } catch (\Throwable $e) {
            }
        }

        try {
            $channel->exchange_delete(self::MAIN_EXCHANGE);
        } catch (\Throwable $e) {
        }
    }

    private function connectRabbitMq(string $host, int $port): AMQPSocketConnection
    {
        $candidates = [];

        $envUser = $this->envAny(['RABBITMQ_USER', 'AMQP_USER'], '');
        $envPass = $this->envAny(['RABBITMQ_PASS', 'RABBITMQ_PASSWORD', 'AMQP_PASSWORD'], '');
        if ($envUser !== '' && $envPass !== '') {
            $candidates[] = [$envUser, $envPass];
        }

        $candidates[] = ['zt', 'ztpass'];
        // No guest fallback — use 'zt'/'ztpass' or env vars only

        $lastException = null;

        foreach ($candidates as [$user, $pass]) {
            try {
                return new AMQPSocketConnection($host, $port, $user, $pass);
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        if ($lastException instanceof \Throwable) {
            throw $lastException;
        }

        throw new \RuntimeException('Unable to connect to RabbitMQ with any credential candidate.');
    }

    private function envAny(array $names, string $default): string
    {
        foreach ($names as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $default;
    }
}

$fresh = in_array('-f', $argv, true);
$initializer = new RabbitMqInitializer();
exit($initializer->run($fresh));
