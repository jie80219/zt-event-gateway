<?php

declare(strict_types=1);

namespace Tests\Unit\MessageQueue;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\TestCase;
use SDPMlab\ZtEventGateway\MessageQueue\Consumer;
use SDPMlab\ZtEventGateway\MessageQueue\UnrecoverableMessageException;

class ConsumerTest extends TestCase
{
    private AMQPChannel $channel;

    protected function setUp(): void
    {
        $this->channel = $this->createMock(AMQPChannel::class);
    }

    /**
     * Capture the callback registered via basic_consume and invoke it with
     * a message, so we can test Consumer behaviour without a running broker.
     */
    private function invokeSubscribeCallback(Consumer $consumer, AMQPMessage $message): void
    {
        $capturedCallback = null;

        $this->channel->method('basic_consume')
            ->willReturnCallback(function (
                $queue, $tag, $noLocal, $noAck, $exclusive, $noWait, $callback,
            ) use (&$capturedCallback) {
                $capturedCallback = $callback;
            });

        $consumer->subscribe('test-queue', function (AMQPMessage $msg) {
            // default no-op handler — individual tests override via message mock expectations
        });

        $this->assertNotNull($capturedCallback, 'basic_consume callback was not captured');

        // Invoke the captured callback directly
        $capturedCallback($message);
    }

    // ── Happy Path ──────────────────────────────────────────

    public function testAcksOnSuccess(): void
    {
        $consumer = new Consumer($this->channel);

        $message = $this->createMock(AMQPMessage::class);
        $message->expects($this->once())->method('ack');
        $message->expects($this->never())->method('reject');
        $message->expects($this->never())->method('nack');
        $message->method('getBody')->willReturn('{}');

        $handlerCalled = false;

        $this->channel->method('basic_consume')
            ->willReturnCallback(function ($q, $t, $nL, $nA, $e, $nW, $callback) use (&$handlerCalled) {
                $callback($this->createMock(AMQPMessage::class));
            });

        // We need to test through the actual callback mechanism.
        // Let's use a simpler approach: capture the callback.
        $capturedCallback = null;
        $this->channel->method('basic_consume')
            ->willReturnCallback(function ($q, $t, $nL, $nA, $e, $nW, $cb) use (&$capturedCallback) {
                $capturedCallback = $cb;
            });

        $consumer->subscribe('test-queue', function () use (&$handlerCalled) {
            $handlerCalled = true;
        });

        $capturedCallback($message);

        $this->assertTrue($handlerCalled);
    }

    // ── Unrecoverable → reject(false) ───────────────────────

    public function testRejectsUnrecoverableWithoutRequeue(): void
    {
        $consumer = new Consumer($this->channel);

        $message = $this->createMock(AMQPMessage::class);
        $message->expects($this->never())->method('ack');
        $message->expects($this->once())->method('reject')->with(false);
        $message->expects($this->never())->method('nack');

        $capturedCallback = null;
        $this->channel->method('basic_consume')
            ->willReturnCallback(function ($q, $t, $nL, $nA, $e, $nW, $cb) use (&$capturedCallback) {
                $capturedCallback = $cb;
            });

        $consumer->subscribe('test-queue', function () {
            throw new UnrecoverableMessageException('bad schema');
        });

        $capturedCallback($message);
    }

    // ── Recoverable Exception (first attempt) → nack+requeue ─

    public function testRequeuesRecoverableOnFirstAttempt(): void
    {
        $consumer = new Consumer($this->channel);

        $message = $this->createMock(AMQPMessage::class);
        $message->expects($this->never())->method('ack');
        $message->expects($this->never())->method('reject');
        $message->expects($this->once())->method('nack')->with(false, true);
        $message->method('has')->with('application_headers')->willReturn(false);

        $capturedCallback = null;
        $this->channel->method('basic_consume')
            ->willReturnCallback(function ($q, $t, $nL, $nA, $e, $nW, $cb) use (&$capturedCallback) {
                $capturedCallback = $cb;
            });

        $consumer->subscribe('test-queue', function () {
            throw new \RuntimeException('temporary error');
        });

        $capturedCallback($message);
    }

    // ── Exhausted retries → reject(false) ───────────────────

    public function testRejectsAfterMaxRetries(): void
    {
        $consumer = new Consumer($this->channel);

        $headers = new AMQPTable(['x-delivery-count' => 3]);

        $message = $this->createMock(AMQPMessage::class);
        $message->expects($this->never())->method('ack');
        $message->expects($this->once())->method('reject')->with(false);
        $message->expects($this->never())->method('nack');
        $message->method('has')->with('application_headers')->willReturn(true);
        $message->method('get')->with('application_headers')->willReturn($headers);

        $capturedCallback = null;
        $this->channel->method('basic_consume')
            ->willReturnCallback(function ($q, $t, $nL, $nA, $e, $nW, $cb) use (&$capturedCallback) {
                $capturedCallback = $cb;
            });

        $consumer->subscribe('test-queue', function () {
            throw new \RuntimeException('persistent error');
        });

        $capturedCallback($message);
    }

    public function testRequeuesWhenBelowMaxRetries(): void
    {
        $consumer = new Consumer($this->channel);

        $headers = new AMQPTable(['x-delivery-count' => 2]);

        $message = $this->createMock(AMQPMessage::class);
        $message->expects($this->never())->method('ack');
        $message->expects($this->never())->method('reject');
        $message->expects($this->once())->method('nack')->with(false, true);
        $message->method('has')->with('application_headers')->willReturn(true);
        $message->method('get')->with('application_headers')->willReturn($headers);

        $capturedCallback = null;
        $this->channel->method('basic_consume')
            ->willReturnCallback(function ($q, $t, $nL, $nA, $e, $nW, $cb) use (&$capturedCallback) {
                $capturedCallback = $cb;
            });

        $consumer->subscribe('test-queue', function () {
            throw new \RuntimeException('temporary error');
        });

        $capturedCallback($message);
    }
}
