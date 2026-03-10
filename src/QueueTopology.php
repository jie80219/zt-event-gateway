<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway;

use PhpAmqpLib\Channel\AMQPChannel;

final class QueueTopology
{
    /**
     * @param array<string> $eventQueues
     * @return array<string, array<string>>
     */
    public static function setupRequestAndEventQueues(
        AMQPChannel $channel,
        string $exchange,
        string $exchangeType,
        string $requestQueue,
        string $requestRoutingKey,
        array $eventQueues
    ): array {
        $channel->exchange_declare($exchange, $exchangeType, false, true, false);

        self::declareDurableQueue($channel, $requestQueue);
        $channel->queue_bind($requestQueue, $exchange, $requestRoutingKey);

        foreach (array_unique($eventQueues) as $queueName) {
            self::declareDurableQueue($channel, $queueName);
            $channel->queue_bind($queueName, $exchange, $queueName);
        }

        return [
            'request_queue' => [$requestQueue],
            'request_bindings' => [$requestRoutingKey],
            'event_queues' => array_values(array_unique($eventQueues)),
        ];
    }

    public static function declareDurableQueue(AMQPChannel $channel, string $queue): void
    {
        $channel->queue_declare($queue, false, true, false, false);
    }
}
