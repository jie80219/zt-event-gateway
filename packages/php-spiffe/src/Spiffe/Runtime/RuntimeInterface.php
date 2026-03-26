<?php

declare(strict_types=1);

namespace Spiffe\Runtime;

/**
 * Coroutine runtime primitives backed by Swow.
 *
 *   ┌─────────────────────────────────┐
 *   │       RuntimeInterface          │
 *   │  createChannel()                │
 *   │  spawn(callable)                │
 *   │  sleep(float seconds)           │
 *   │  runBlocking(callable)          │
 *   └──────────┬──────────────────────┘
 *              │
 *              ▼
 *         SwowRuntime
 */
interface RuntimeInterface
{
    /**
     * Create a typed channel for coroutine communication.
     *
     * @param int $capacity  Buffer size (0 = unbuffered)
     */
    public function createChannel(int $capacity = 1): ChannelInterface;

    /**
     * Spawn a new coroutine. Returns immediately.
     *
     * @return int Coroutine ID
     */
    public function spawn(callable $fn): int;

    /**
     * Non-blocking sleep for the given number of seconds.
     */
    public function sleep(float $seconds): void;

    /**
     * Execute a callable inside a coroutine-enabled entry point.
     * Swow is always in coroutine mode, so this calls the callable directly.
     */
    public function runBlocking(callable $fn): void;

    /**
     * Runtime engine name (e.g. 'swow').
     */
    public function name(): string;
}
