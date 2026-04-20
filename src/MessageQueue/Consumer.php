<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\MessageQueue;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

final class Consumer
{
    private const MAX_RETRIES = 3;

    private AMQPChannel $channel;

    public function __construct(AMQPChannel $channel)
    {
        $this->channel = $channel;
    }

    public function subscribe(string $queue, callable $handler): void
    {
        $this->channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($handler, $queue): void {
                try {
                    $handler($message);
                    $message->ack();
                } catch (UnrecoverableMessageException $exception) {
                    fwrite(
                        STDERR,
                        sprintf("[consumer] dropped queue=%s error=%s\n", $queue, $exception->getMessage()),
                    );
                    $message->reject(false);
                } catch (\Throwable $exception) {
                    $deliveryCount = $this->getDeliveryCount($message);

                    if ($deliveryCount >= self::MAX_RETRIES) {
                        fwrite(
                            STDERR,
                            sprintf(
                                "[consumer] exhausted retries (%d/%d) queue=%s error=%s\n",
                                $deliveryCount,
                                self::MAX_RETRIES,
                                $queue,
                                $exception->getMessage(),
                            ),
                        );
                        $message->reject(false);
                    } else {
                        fwrite(
                            STDERR,
                            sprintf(
                                "[consumer] requeue (%d/%d) queue=%s error=%s\n",
                                $deliveryCount,
                                self::MAX_RETRIES,
                                $queue,
                                $exception->getMessage(),
                            ),
                        );
                        $message->nack(false, true);
                    }
                }
            },
        );
    }

    private function getDeliveryCount(AMQPMessage $message): int
    {
        $headers = $message->has('application_headers')
            ? $message->get('application_headers')
            : null;

        if ($headers === null) {
            return 1;
        }

        $nativeData = $headers->getNativeData();

        // RabbitMQ 3.10+ quorum queues provide x-delivery-count automatically.
        if (isset($nativeData['x-delivery-count'])) {
            return (int) $nativeData['x-delivery-count'];
        }

        // Classic queues track redelivery via x-death header entries.
        if (isset($nativeData['x-death']) && is_array($nativeData['x-death'])) {
            $totalCount = 0;
            foreach ($nativeData['x-death'] as $death) {
                $totalCount += (int) ($death['count'] ?? 0);
            }
            return $totalCount > 0 ? $totalCount : 1;
        }

        return 1;
    }

    public function run(): void
    {
        while ($this->channel->is_consuming()) {
            try {
                $this->channel->wait(null, false, 5);
            } catch (AMQPTimeoutException) {
                // Keep the consumer alive while waiting for the next message.
            }
        }
    }
}
