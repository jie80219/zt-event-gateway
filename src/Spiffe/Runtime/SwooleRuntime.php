<?php

declare(strict_types=1);

namespace Spiffe\Runtime;

final class SwooleRuntime implements RuntimeInterface
{
    public function createChannel(int $capacity = 1): ChannelInterface
    {
        return new SwooleChannel($capacity);
    }

    public function spawn(callable $fn): int
    {
        return \Swoole\Coroutine::create($fn);
    }

    public function sleep(float $seconds): void
    {
        \Swoole\Coroutine::sleep($seconds);
    }

    public function runBlocking(callable $fn): void
    {
        \Swoole\Coroutine\run($fn);
    }

    public function name(): string
    {
        return 'swoole';
    }
}

/**
 * @internal
 */
final class SwooleChannel implements ChannelInterface
{
    private \Swoole\Coroutine\Channel $chan;

    public function __construct(int $capacity)
    {
        $this->chan = new \Swoole\Coroutine\Channel($capacity);
    }

    public function push(mixed $value): void
    {
        $this->chan->push($value);
    }

    public function pop(float $timeout = 0): mixed
    {
        return $timeout > 0
            ? $this->chan->pop($timeout)
            : $this->chan->pop();
    }

    public function isEmpty(): bool
    {
        return $this->chan->isEmpty();
    }

    public function count(): int
    {
        return $this->chan->stats()['queue_num'] ?? 0;
    }

    public function consumerCount(): int
    {
        return $this->chan->stats()['consumer_num'] ?? 0;
    }
}
