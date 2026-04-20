<?php

declare(strict_types=1);

namespace Tests\Unit;

use PhpAmqpLib\Channel\AMQPChannel;
use PHPUnit\Framework\TestCase;
use SDPMlab\ZtEventGateway\QueueTopology;

class QueueTopologyTest extends TestCase
{
    private AMQPChannel $channel;

    protected function setUp(): void
    {
        $this->channel = $this->createMock(AMQPChannel::class);
    }

    // ── setupRequestAndEventQueues ──────────────────────────

    public function testSetupDeclaresExchange(): void
    {
        $this->channel->expects($this->once())
            ->method('exchange_declare')
            ->with('events', 'topic', false, true, false);

        $this->channel->method('queue_declare');
        $this->channel->method('queue_bind');

        QueueTopology::setupRequestAndEventQueues(
            $this->channel,
            'events',
            'topic',
            'order_queue',
            'order.create',
            ['EventA'],
        );
    }

    public function testSetupDeclaresRequestQueue(): void
    {
        $declaredQueues = [];
        $boundQueues = [];

        $this->channel->method('exchange_declare');
        $this->channel->method('queue_declare')
            ->willReturnCallback(function (string $queue) use (&$declaredQueues) {
                $declaredQueues[] = $queue;
            });
        $this->channel->method('queue_bind')
            ->willReturnCallback(function (string $queue, string $exchange, string $routingKey) use (&$boundQueues) {
                $boundQueues[] = ['queue' => $queue, 'exchange' => $exchange, 'key' => $routingKey];
            });

        QueueTopology::setupRequestAndEventQueues(
            $this->channel,
            'events',
            'topic',
            'order_queue',
            'order.create',
            [],
        );

        $this->assertContains('order_queue', $declaredQueues);
        $this->assertContains(
            ['queue' => 'order_queue', 'exchange' => 'events', 'key' => 'order.create'],
            $boundQueues,
        );
    }

    public function testSetupDeclaresEventQueues(): void
    {
        $declaredQueues = [];
        $boundQueues = [];

        $this->channel->method('exchange_declare');
        $this->channel->method('queue_declare')
            ->willReturnCallback(function (string $queue) use (&$declaredQueues) {
                $declaredQueues[] = $queue;
            });
        $this->channel->method('queue_bind')
            ->willReturnCallback(function (string $queue, string $exchange, string $routingKey) use (&$boundQueues) {
                $boundQueues[] = ['queue' => $queue, 'key' => $routingKey];
            });

        QueueTopology::setupRequestAndEventQueues(
            $this->channel,
            'events',
            'topic',
            'order_queue',
            'order.create',
            ['OrderCreatedEvent', 'PaymentProcessedEvent'],
        );

        $this->assertContains('OrderCreatedEvent', $declaredQueues);
        $this->assertContains('PaymentProcessedEvent', $declaredQueues);
        $this->assertContains(['queue' => 'OrderCreatedEvent', 'key' => 'OrderCreatedEvent'], $boundQueues);
        $this->assertContains(['queue' => 'PaymentProcessedEvent', 'key' => 'PaymentProcessedEvent'], $boundQueues);
    }

    public function testSetupDeduplicatesEventQueues(): void
    {
        $declareCount = 0;

        $this->channel->method('exchange_declare');
        $this->channel->method('queue_declare')
            ->willReturnCallback(function () use (&$declareCount) {
                $declareCount++;
            });
        $this->channel->method('queue_bind');

        QueueTopology::setupRequestAndEventQueues(
            $this->channel,
            'events',
            'topic',
            'order_queue',
            'order.create',
            ['EventA', 'EventB', 'EventA'],
        );

        // 1 request queue + 2 unique event queues = 3
        $this->assertSame(3, $declareCount);
    }

    public function testSetupReturnsCorrectStructure(): void
    {
        $this->channel->method('exchange_declare');
        $this->channel->method('queue_declare');
        $this->channel->method('queue_bind');

        $result = QueueTopology::setupRequestAndEventQueues(
            $this->channel,
            'events',
            'topic',
            'order_queue',
            'order.create',
            ['EventA', 'EventB', 'EventA'],
        );

        $this->assertSame(['order_queue'], $result['order_queue']);
        $this->assertSame(['order.create'], $result['request_bindings']);
        $this->assertSame(['EventA', 'EventB'], $result['event_queues']);
    }

    // ── declareDurableQueue ─────────────────────────────────

    public function testDeclareDurableQueue(): void
    {
        // queue_declare(queue, passive=false, durable=true, exclusive=false, auto_delete=false)
        $this->channel->expects($this->once())
            ->method('queue_declare')
            ->with('my-queue', false, true, false, false);

        QueueTopology::declareDurableQueue($this->channel, 'my-queue');
    }
}
