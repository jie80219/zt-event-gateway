<?php

declare(strict_types=1);

namespace Spiffe\Runtime;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Coroutine\Scheduler;

/**
 * Coroutine runtime backed by OpenSwoole.
 */
final class OpenSwooleRuntime implements RuntimeInterface
{
    public function createChannel(int $capacity = 1): ChannelInterface
    {
        return new OpenSwooleChannel($capacity);
    }

    public function spawn(callable $fn): int
    {
        return Coroutine::create($fn);
    }

    public function sleep(float $seconds): void
    {
        // OpenSwoole 26.x Coroutine::sleep() only accepts int (seconds).
        // For sub-second sleeps, use usleep via Coroutine context.
        if ($seconds < 1.0) {
            usleep((int) ($seconds * 1_000_000));
        } else {
            Coroutine::sleep((int) ceil($seconds));
        }
    }

    public function runBlocking(callable $fn): void
    {
        if (Coroutine::getCid() > 0) {
            $fn();
        } else {
            $scheduler = new Scheduler();
            $scheduler->add($fn);
            $scheduler->start();
        }
    }

    public function name(): string
    {
        return 'openswoole';
    }
}

/**
 * @internal
 */
final class OpenSwooleChannel implements ChannelInterface
{
    private Channel $chan;

    public function __construct(int $capacity)
    {
        $this->chan = new Channel($capacity);
    }

    public function push(mixed $value): void
    {
        $this->chan->push($value);
    }

    public function pop(float $timeout = 0): mixed
    {
        // OpenSwoole Channel::pop() takes seconds (-1 = indefinite)
        $timeoutSec = $timeout > 0 ? $timeout : -1;
        $result = $this->chan->pop($timeoutSec);
        return $result === false ? false : $result;
    }

    public function isEmpty(): bool
    {
        return $this->chan->isEmpty();
    }

    public function count(): int
    {
        return $this->chan->length();
    }

    public function consumerCount(): int
    {
        return $this->chan->stats()['consumer_num'] ?? 0;
    }
}
