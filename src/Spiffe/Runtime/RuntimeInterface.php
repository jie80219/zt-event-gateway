<?php

declare(strict_types=1);

namespace Spiffe\Runtime;

/**
 * Abstracts coroutine runtime primitives so the SPIFFE library works
 * on both Swoole and Swow without code changes.
 *
 *   ┌─────────────────────────────────┐
 *   │       RuntimeInterface          │
 *   │  createChannel()                │
 *   │  spawn(callable)                │
 *   │  sleep(float seconds)           │
 *   │  runBlocking(callable)          │
 *   └──────────┬──────────────────────┘
 *              │
 *     ┌────────┴────────┐
 *     ▼                 ▼
 *  SwooleRuntime    SwowRuntime
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
     * For Swoole this wraps in `Coroutine\run()`.
     * For Swow this just calls the callable directly (always in coroutine mode).
     */
    public function runBlocking(callable $fn): void;

    /**
     * Runtime engine name ('swoole' or 'swow').
     */
    public function name(): string;
}
