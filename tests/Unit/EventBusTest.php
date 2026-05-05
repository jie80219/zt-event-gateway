<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\EventStore\EventStoreDB;

class EventBusTest extends TestCase
{
    private EventBus $eventBus;
    private MessageBus $messageBus;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBus::class);
        $this->eventBus = new EventBus($this->messageBus);
    }

    // ── registerHandler ─────────────────────────────────────

    public function testRegisterHandler_addsHandler(): void
    {
        $handler = fn(object $e) => null;
        $this->eventBus->registerHandler('TestEvent', $handler);

        $this->assertTrue($this->eventBus->hasHandlers('TestEvent'));
        $this->assertSame(1, $this->eventBus->getHandlerCount('TestEvent'));
    }

    public function testRegisterHandler_preventsDuplicate(): void
    {
        $handler = fn(object $e) => null;
        $this->eventBus->registerHandler('TestEvent', $handler);
        $this->eventBus->registerHandler('TestEvent', $handler);

        $this->assertSame(1, $this->eventBus->getHandlerCount('TestEvent'));
    }

    public function testRegisterHandler_multipleHandlers(): void
    {
        $h1 = fn(object $e) => null;
        $h2 = fn(object $e) => null;
        $this->eventBus->registerHandler('TestEvent', $h1);
        $this->eventBus->registerHandler('TestEvent', $h2);

        $this->assertSame(2, $this->eventBus->getHandlerCount('TestEvent'));
    }

    // ── removeHandler ───────────────────────────────────────

    public function testRemoveHandler_removesExisting(): void
    {
        $handler = fn(object $e) => null;
        $this->eventBus->registerHandler('TestEvent', $handler);
        $this->eventBus->removeHandler('TestEvent', $handler);

        $this->assertFalse($this->eventBus->hasHandlers('TestEvent'));
    }

    public function testRemoveHandler_nonExistentEventType_noError(): void
    {
        $handler = fn(object $e) => null;
        $this->eventBus->removeHandler('NonExistent', $handler);

        $this->assertFalse($this->eventBus->hasHandlers('NonExistent'));
    }

    // ── hasHandlers / getHandlerCount ────────────────────────

    public function testHasHandlers_falseWhenEmpty(): void
    {
        $this->assertFalse($this->eventBus->hasHandlers('Foo'));
    }

    public function testGetHandlerCount_zeroWhenNoHandlers(): void
    {
        $this->assertSame(0, $this->eventBus->getHandlerCount('Foo'));
    }

    // ── getRegisteredEventTypes ──────────────────────────────

    public function testGetRegisteredEventTypes_returnsAllTypes(): void
    {
        $this->eventBus->registerHandler('EventA', fn($e) => null);
        $this->eventBus->registerHandler('EventB', fn($e) => null);

        $types = $this->eventBus->getRegisteredEventTypes();
        $this->assertContains('EventA', $types);
        $this->assertContains('EventB', $types);
        $this->assertCount(2, $types);
    }

    // ── dispatch ─────────────────────────────────────────────

    public function testDispatch_callsMatchingHandlers(): void
    {
        $called = false;
        $event = new \stdClass();
        $this->eventBus->registerHandler(\stdClass::class, function ($e) use (&$called) {
            $called = true;
        });

        $this->eventBus->dispatch($event);

        $this->assertTrue($called);
    }

    public function testDispatch_doesNothingWhenNoHandlers(): void
    {
        // Should not throw
        $this->eventBus->dispatch(new \stdClass());
        $this->assertEmpty($this->eventBus->getLastDispatchErrors());
    }

    public function testDispatch_callsAllHandlersInOrder(): void
    {
        $order = [];
        $this->eventBus->registerHandler(\stdClass::class, function () use (&$order) {
            $order[] = 1;
        });
        $this->eventBus->registerHandler(\stdClass::class, function () use (&$order) {
            $order[] = 2;
        });

        $this->eventBus->dispatch(new \stdClass());

        $this->assertSame([1, 2], $order);
    }

    public function testDispatch_errorIsolation_continuesAfterOneFailure(): void
    {
        $secondCalled = false;
        $this->eventBus->registerHandler(\stdClass::class, function () {
            throw new \RuntimeException('handler 1 failed');
        });
        $this->eventBus->registerHandler(\stdClass::class, function () use (&$secondCalled) {
            $secondCalled = true;
        });

        $this->eventBus->dispatch(new \stdClass());

        $this->assertTrue($secondCalled, 'Second handler should still be called after first one fails');
        $this->assertCount(1, $this->eventBus->getLastDispatchErrors());
        $this->assertSame('handler 1 failed', $this->eventBus->getLastDispatchErrors()[0]['exception']->getMessage());
    }

    public function testDispatch_allHandlersFail_throwsLastException(): void
    {
        $this->eventBus->registerHandler(\stdClass::class, function () {
            throw new \RuntimeException('fail 1');
        });
        $this->eventBus->registerHandler(\stdClass::class, function () {
            throw new \RuntimeException('fail 2');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fail 2');

        $this->eventBus->dispatch(new \stdClass());
    }

    public function testDispatch_clearsErrorsBetweenCalls(): void
    {
        $this->eventBus->registerHandler(\stdClass::class, function () {
            throw new \RuntimeException('fail');
        });
        $this->eventBus->registerHandler(\stdClass::class, fn() => null);

        $this->eventBus->dispatch(new \stdClass());
        $this->assertCount(1, $this->eventBus->getLastDispatchErrors());

        // 第二次 dispatch 用不同的事件類型（無 handler），errors 應被清空
        $this->eventBus->dispatch(new class {});
        $this->assertCount(0, $this->eventBus->getLastDispatchErrors());
    }

    // ── publish ──────────────────────────────────────────────

    public function testPublish_callsMessageBusPublishEvent(): void
    {
        $this->messageBus->expects($this->once())
            ->method('publishEvent')
            ->with('App\\Events\\TestEvent', ['key' => 'value']);

        $this->eventBus->publish('App\\Events\\TestEvent', ['key' => 'value']);
    }

    public function testPublish_writesToEventStoreWhenConfigured(): void
    {
        $eventStore = $this->createMock(EventStoreDB::class);
        $eventStore->expects($this->once())
            ->method('appendEvent')
            ->with(
                'TestStream',
                $this->callback(function (array $data) {
                    return $data['eventType'] === 'TestEvent'
                        && $data['data'] === ['foo' => 'bar']
                        && array_key_exists('metadata', $data);
                }),
            );

        $this->messageBus->method('publishEvent');

        $bus = new EventBus($this->messageBus, $eventStore);
        $bus->publish('App\\Events\\TestEvent', ['foo' => 'bar'], 'TestStream');
    }

    public function testPublish_skipsEventStoreWhenNull(): void
    {
        // Should not throw even without EventStoreDB
        $this->messageBus->method('publishEvent');
        $this->eventBus->publish('App\\Events\\TestEvent', []);
        $this->assertTrue(true); // No exception = pass
    }
}
