<?php

declare(strict_types=1);

namespace Spiffe\Runtime;

use Swow\Channel;
use Swow\Coroutine;

final class SwowRuntime implements RuntimeInterface
{
    public function createChannel(int $capacity = 1): ChannelInterface
    {
        return new SwowChannel($capacity);
    }

    public function spawn(callable $fn): int
    {
        $coroutine = Coroutine::run($fn);
        return $coroutine->getId();
    }

    public function sleep(float $seconds): void
    {
        // Swow sleep() takes milliseconds
        Coroutine::sleep((int) ($seconds * 1000));
    }

    public function runBlocking(callable $fn): void
    {
        // Swow is always in coroutine mode — just call directly.
        // If we need a dedicated coroutine, wrap it.
        $fn();
    }

    public function name(): string
    {
        return 'swow';
    }
}

/**
 * @internal
 */
final class SwowChannel implements ChannelInterface
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
        try {
            // Swow Channel::pop() takes milliseconds timeout (-1 = indefinite)
            $timeoutMs = $timeout > 0 ? (int) ($timeout * 1000) : -1;
            return $this->chan->pop($timeoutMs);
        } catch (\Swow\ChannelException) {
            return false;
        }
    }

    public function isEmpty(): bool
    {
        return $this->chan->isEmpty();
    }

    public function count(): int
    {
        return $this->chan->getLength();
    }

    public function consumerCount(): int
    {
        // Swow doesn't expose consumer count directly
        return 0;
    }
}
