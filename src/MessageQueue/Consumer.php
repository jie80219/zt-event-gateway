<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\MessageQueue;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

final class Consumer
{
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
                    fwrite(
                        STDERR,
                        sprintf("[consumer] requeue queue=%s error=%s\n", $queue, $exception->getMessage()),
                    );
                    $message->nack(false, true);
                }
            },
        );
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
