<?php

declare(strict_types=1);

namespace Tests\Unit\EventStore;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;
use PHPUnit\Framework\TestCase;
use SDPMlab\ZtEventGateway\EventStore\EventStoreDB;

class EventStoreDBTest extends TestCase
{
    private MockHandler $mockHandler;
    private EventStoreDB $eventStore;

    protected function setUp(): void
    {
        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        // 使用 Reflection 注入 mock client
        $this->eventStore = new EventStoreDB('localhost', 2113, 'admin', 'changeit');
        $ref = new \ReflectionProperty($this->eventStore, 'httpClient');
        $ref->setAccessible(true);
        $ref->setValue($this->eventStore, $mockClient);
    }

    // ── appendEvent ─────────────────────────────────────────

    public function testAppendEvent_success(): void
    {
        $this->mockHandler->append(new Response(201));

        $result = $this->eventStore->appendEvent('test-stream', [
            'eventType' => 'OrderCreated',
            'data' => ['orderId' => '123'],
        ]);

        $this->assertTrue($result);
    }

    public function testAppendEvent_mergesMetadata(): void
    {
        $this->mockHandler->append(new Response(201));

        $result = $this->eventStore->appendEvent('test-stream', [
            'eventType' => 'OrderCreated',
            'data' => ['orderId' => '123'],
            'metadata' => [
                'caller' => 'worker',
                'origin' => 'gateway',
            ],
        ]);

        $this->assertTrue($result);
        // 驗證請求已發送（mock 已消耗）
        $this->assertSame(0, $this->mockHandler->count());
    }

    public function testAppendEvent_failure_returnsFalse(): void
    {
        $this->mockHandler->append(
            new RequestException('Connection refused', new Request('POST', '/streams/test'))
        );

        $result = $this->eventStore->appendEvent('test-stream', [
            'eventType' => 'OrderCreated',
            'data' => [],
        ]);

        $this->assertFalse($result);
    }

    public function testAppendEvent_non201_returnsFalse(): void
    {
        $this->mockHandler->append(new Response(400));

        $result = $this->eventStore->appendEvent('test-stream', [
            'eventType' => 'OrderCreated',
            'data' => [],
        ]);

        $this->assertFalse($result);
    }

    // ── appendEvents (batch) ────────────────────────────────

    public function testAppendEvents_success(): void
    {
        $this->mockHandler->append(new Response(201));

        $result = $this->eventStore->appendEvents('test-stream', [
            ['eventType' => 'EventA', 'data' => ['a' => 1]],
            ['eventType' => 'EventB', 'data' => ['b' => 2]],
        ]);

        $this->assertTrue($result);
    }

    public function testAppendEvents_failure_returnsFalse(): void
    {
        $this->mockHandler->append(
            new RequestException('Timeout', new Request('POST', '/streams/test'))
        );

        $result = $this->eventStore->appendEvents('test-stream', [
            ['eventType' => 'EventA', 'data' => []],
        ]);

        $this->assertFalse($result);
    }

    // ── readEvents ──────────────────────────────────────────

    public function testReadEvents_parsesAtomEntries(): void
    {
        $body = json_encode([
            'entries' => [
                [
                    'eventId' => 'abc-123',
                    'eventType' => 'OrderCreated',
                    'eventNumber' => 0,
                    'data' => json_encode(['orderId' => '001']),
                    'metaData' => json_encode(['timestamp' => '2026-04-10']),
                    'updated' => '2026-04-10T00:00:00Z',
                ],
                [
                    'eventId' => 'def-456',
                    'eventType' => 'OrderCompleted',
                    'eventNumber' => 1,
                    'data' => json_encode(['orderId' => '001']),
                    'metaData' => null,
                    'updated' => '2026-04-10T00:01:00Z',
                ],
            ],
        ]);

        $this->mockHandler->append(new Response(200, [], $body));

        $events = $this->eventStore->readEvents('order-stream');

        $this->assertCount(2, $events);
        $this->assertSame('abc-123', $events[0]['eventId']);
        $this->assertSame('OrderCreated', $events[0]['eventType']);
        $this->assertSame(0, $events[0]['eventNumber']);
        $this->assertSame(['orderId' => '001'], $events[0]['data']);
        $this->assertSame(['timestamp' => '2026-04-10'], $events[0]['metadata']);
    }

    public function testReadEvents_failure_returnsEmpty(): void
    {
        $this->mockHandler->append(
            new RequestException('Not found', new Request('GET', '/streams/x'))
        );

        $events = $this->eventStore->readEvents('non-existent');
        $this->assertEmpty($events);
    }

    // ── readEventsForward ───────────────────────────────────

    public function testReadEventsForward_success(): void
    {
        $body = json_encode([
            'entries' => [
                ['eventId' => 'a', 'eventType' => 'E1', 'eventNumber' => 0, 'data' => '{}'],
            ],
        ]);
        $this->mockHandler->append(new Response(200, [], $body));

        $events = $this->eventStore->readEventsForward('stream', 0, 10);
        $this->assertCount(1, $events);
        $this->assertSame('E1', $events[0]['eventType']);
    }

    // ── readEventsBackward ──────────────────────────────────

    public function testReadEventsBackward_success(): void
    {
        $body = json_encode([
            'entries' => [
                ['eventId' => 'z', 'eventType' => 'Latest', 'eventNumber' => 99, 'data' => '{}'],
            ],
        ]);
        $this->mockHandler->append(new Response(200, [], $body));

        $events = $this->eventStore->readEventsBackward('stream', 1);
        $this->assertCount(1, $events);
        $this->assertSame(99, $events[0]['eventNumber']);
    }

    // ── readLastEvent ───────────────────────────────────────

    public function testReadLastEvent_returnsLatest(): void
    {
        $body = json_encode([
            'entries' => [
                ['eventId' => 'last', 'eventType' => 'Final', 'eventNumber' => 42, 'data' => '{}'],
            ],
        ]);
        $this->mockHandler->append(new Response(200, [], $body));

        $event = $this->eventStore->readLastEvent('stream');
        $this->assertNotNull($event);
        $this->assertSame('Final', $event['eventType']);
    }

    public function testReadLastEvent_emptyStream_returnsNull(): void
    {
        $body = json_encode(['entries' => []]);
        $this->mockHandler->append(new Response(200, [], $body));

        $event = $this->eventStore->readLastEvent('empty-stream');
        $this->assertNull($event);
    }

    // ── readEvent ───────────────────────────────────────────

    public function testReadEvent_success(): void
    {
        $body = json_encode(['eventId' => 'x', 'eventType' => 'Test', 'data' => ['v' => 1]]);
        $this->mockHandler->append(new Response(200, [], $body));

        $event = $this->eventStore->readEvent('stream', 5);
        $this->assertNotNull($event);
        $this->assertSame('x', $event['eventId']);
    }

    public function testReadEvent_notFound_returnsNull(): void
    {
        $this->mockHandler->append(
            new RequestException('Not found', new Request('GET', '/streams/s/99'))
        );

        $event = $this->eventStore->readEvent('s', 99);
        $this->assertNull($event);
    }

    // ── streamExists ────────────────────────────────────────

    public function testStreamExists_true(): void
    {
        $this->mockHandler->append(new Response(200));

        $this->assertTrue($this->eventStore->streamExists('existing-stream'));
    }

    public function testStreamExists_false_on404(): void
    {
        $this->mockHandler->append(
            new RequestException(
                'Not found',
                new Request('HEAD', '/streams/x'),
                new Response(404),
            )
        );

        $this->assertFalse($this->eventStore->streamExists('non-existent'));
    }

    // ── deleteStream ────────────────────────────────────────

    public function testDeleteStream_success(): void
    {
        $this->mockHandler->append(new Response(204));

        $this->assertTrue($this->eventStore->deleteStream('test-stream'));
    }

    public function testDeleteStream_failure(): void
    {
        $this->mockHandler->append(
            new RequestException('Error', new Request('DELETE', '/streams/x'))
        );

        $this->assertFalse($this->eventStore->deleteStream('x'));
    }

    // ── hardDeleteStream ────────────────────────────────────

    public function testHardDeleteStream_success(): void
    {
        $this->mockHandler->append(new Response(204));

        $this->assertTrue($this->eventStore->hardDeleteStream('test-stream'));
    }

    // ── createProjection ────────────────────────────────────

    public function testCreateProjection_success(): void
    {
        $this->mockHandler->append(new Response(201));

        $this->assertTrue($this->eventStore->createProjection('TestProjection'));
    }

    public function testCreateProjection_alreadyExists_returnsTrue(): void
    {
        $this->mockHandler->append(
            new RequestException(
                'Conflict',
                new Request('POST', '/projections/continuous/Test'),
                new Response(409),
            )
        );

        $this->assertTrue($this->eventStore->createProjection('Test'));
    }

    // ── subscribe ───────────────────────────────────────────

    public function testSubscribe_returnsEventsAndUpdatesPosition(): void
    {
        $body = json_encode([
            'entries' => [
                ['eventId' => 'a', 'eventType' => 'E1', 'eventNumber' => 5, 'data' => '{}'],
                ['eventId' => 'b', 'eventType' => 'E2', 'eventNumber' => 6, 'data' => '{}'],
            ],
        ]);
        $this->mockHandler->append(new Response(200, [], $body));

        $result = $this->eventStore->subscribe('stream', 4, 10, 1);

        $this->assertCount(2, $result['events']);
        $this->assertSame(6, $result['lastEventNumber']);
    }

    public function testSubscribe_noNewEvents_returnsEmpty(): void
    {
        $body = json_encode(['entries' => []]);
        $this->mockHandler->append(new Response(200, [], $body));

        $result = $this->eventStore->subscribe('stream', 10, 10, 1);

        $this->assertEmpty($result['events']);
        $this->assertSame(10, $result['lastEventNumber']);
    }
}
