<?php

declare(strict_types=1);

namespace Spiffe\Runtime;

/**
 * Coroutine channel abstraction backed by Swow\Channel.
 */
interface ChannelInterface
{
    /**
     * Push a value into the channel. Blocks if the channel is full.
     */
    public function push(mixed $value): void;

    /**
     * Pop a value from the channel. Blocks if the channel is empty.
     *
     * @param float $timeout Seconds (0 = indefinite)
     * @return mixed The value, or false on timeout
     */
    public function pop(float $timeout = 0): mixed;

    /**
     * Whether the channel is empty.
     */
    public function isEmpty(): bool;

    /**
     * Number of items currently in the channel.
     */
    public function count(): int;

    /**
     * Number of coroutines waiting to pop (consumers).
     */
    public function consumerCount(): int;
}
