<?php

declare(strict_types=1);

namespace Spiffe\Runtime;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Event;

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
        // Must yield cooperatively — naked usleep() blocks the whole
        // reactor and starves child coroutines (e.g. X509Source's
        // watchLoop), leaving them stuck in their initial state
        // because the parent's 0.1s poll never releases the runtime.
        $us = (int) max(0, $seconds * 1_000_000);
        Coroutine::usleep($us);
    }

    public function runBlocking(callable $fn): void
    {
        if (Coroutine::getCid() > 0) {
            $fn();
            return;
        }

        // Spawn the entry coroutine via Coroutine::create + Event::wait,
        // rather than Scheduler::start. Scheduler-based execution has
        // subtle issues on OpenSwoole 22.x when the entry coroutine spawns
        // additional child coroutines (e.g. X509Source->start()) — children
        // can be silently starved of scheduling slots, leaving them in
        // the initial state forever.
        Coroutine::create($fn);
        Event::wait();
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
