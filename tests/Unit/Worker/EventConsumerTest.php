<?php

declare(strict_types=1);

namespace Tests\Unit\Worker;

use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;
use SDPMlab\LSVID\LSVIDContext;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\MessageQueue\UnrecoverableMessageException;
use ZtEventGateway\Worker\EventConsumer;

class EventConsumerTest extends TestCase
{
    private EventBus $eventBus;

    protected function setUp(): void
    {
        $this->eventBus = $this->createMock(EventBus::class);
    }

    private function makeMessage(array $payload): AMQPMessage
    {
        return new AMQPMessage(json_encode($payload));
    }

    // ── Invalid Envelope ─────────────────────────────────────

    public function testRejectsInvalidJson(): void
    {
        $consumer = new EventConsumer($this->eventBus);
        $message = new AMQPMessage('not-json');

        $this->expectException(UnrecoverableMessageException::class);
        $this->expectExceptionMessage('Invalid event payload');

        $consumer->process($message);
    }

    public function testRejectsMissingType(): void
    {
        $consumer = new EventConsumer($this->eventBus);

        $this->expectException(UnrecoverableMessageException::class);
        $this->expectExceptionMessage('Missing event type or data');

        $consumer->process($this->makeMessage([
            'data' => ['foo' => 'bar'],
        ]));
    }

    public function testRejectsMissingData(): void
    {
        $consumer = new EventConsumer($this->eventBus);

        $this->expectException(UnrecoverableMessageException::class);
        $this->expectExceptionMessage('Missing event type or data');

        $consumer->process($this->makeMessage([
            'type' => 'App\\Events\\OrderCreatedEvent',
        ]));
    }

    // ── SPIFFE Source Verification ───────────────────────────

    public function testRejectsUntrustedSpiffeSource(): void
    {
        $consumer = new EventConsumer(
            $this->eventBus,
            requireSpiffeIdentity: true,
        );

        $this->expectException(UnrecoverableMessageException::class);
        $this->expectExceptionMessage('Untrusted SPIFFE source');

        $consumer->process($this->makeMessage([
            'type' => 'App\\Events\\OrderCreatedEvent',
            'data' => ['orderId' => '1', 'userKey' => '1', 'productList' => [], 'total' => 100],
            'spiffe_id' => 'spiffe://evil.domain/attacker',
            'spiffe_path' => ['spiffe://evil.domain/attacker'],
        ]));
    }

    public function testAllowsTrustedSpiffeSource(): void
    {
        $this->eventBus->expects($this->once())->method('dispatch');

        $consumer = new EventConsumer(
            $this->eventBus,
            requireSpiffeIdentity: true,
        );

        $consumer->process($this->makeMessage([
            'type' => 'App\\Events\\OrderCreatedEvent',
            'data' => ['orderId' => '1', 'userKey' => '1', 'productList' => [], 'total' => 100],
            'spiffe_id' => 'spiffe://zt.local/php-worker',
            'spiffe_path' => ['spiffe://zt.local/php-worker'],
        ]));
    }

    // ── SPIFFE_ENABLED=0 (requireSpiffeIdentity=false) ──────

    public function testSkipsSpiffeValidationWhenDisabled(): void
    {
        $this->eventBus->expects($this->once())->method('dispatch');

        $consumer = new EventConsumer(
            $this->eventBus,
            requireSpiffeIdentity: false,
        );

        $consumer->process($this->makeMessage([
            'type' => 'App\\Events\\OrderCreatedEvent',
            'data' => ['orderId' => '1', 'userKey' => '1', 'productList' => [], 'total' => 100],
            'spiffe_id' => 'spiffe://evil.domain/attacker',
        ]));
    }

    // ── LSVID Validation ────────────────────────────────────

    public function testRejectsLsvidWithoutValidator(): void
    {
        $consumer = new EventConsumer(
            $this->eventBus,
            lsvidValidator: null,
            requireSpiffeIdentity: true,
        );

        $this->expectException(UnrecoverableMessageException::class);
        $this->expectExceptionMessage('no LSVIDValidator is configured');

        $consumer->process($this->makeMessage([
            'type' => 'App\\Events\\OrderCreatedEvent',
            'data' => ['orderId' => '1', 'userKey' => '1', 'productList' => [], 'total' => 100],
            'spiffe_id' => 'spiffe://zt.local/php-worker',
            'spiffe_path' => [],
            'lsvid' => 'some.jwt.token',
        ]));
    }

    public function testRejectsWhenLsvidRequiredButMissing(): void
    {
        $consumer = new EventConsumer(
            $this->eventBus,
            lsvidRequired: true,
            requireSpiffeIdentity: true,
        );

        $this->expectException(UnrecoverableMessageException::class);
        $this->expectExceptionMessage('LSVID required but event envelope carries none');

        $consumer->process($this->makeMessage([
            'type' => 'App\\Events\\OrderCreatedEvent',
            'data' => ['orderId' => '1', 'userKey' => '1', 'productList' => [], 'total' => 100],
            'spiffe_id' => 'spiffe://zt.local/php-worker',
            'spiffe_path' => [],
        ]));
    }

    public function testAllowsMissingLsvidWhenNotRequired(): void
    {
        $this->eventBus->expects($this->once())->method('dispatch');

        $consumer = new EventConsumer(
            $this->eventBus,
            lsvidRequired: false,
            requireSpiffeIdentity: true,
        );

        $consumer->process($this->makeMessage([
            'type' => 'App\\Events\\OrderCreatedEvent',
            'data' => ['orderId' => '1', 'userKey' => '1', 'productList' => [], 'total' => 100],
            'spiffe_id' => 'spiffe://zt.local/php-worker',
            'spiffe_path' => [],
        ]));
    }

    // ── Unknown Event Class ─────────────────────────────────

    public function testRejectsUnknownEventClass(): void
    {
        $consumer = new EventConsumer(
            $this->eventBus,
            requireSpiffeIdentity: false,
        );

        $this->expectException(UnrecoverableMessageException::class);
        $this->expectExceptionMessage('Unknown event class');

        $consumer->process($this->makeMessage([
            'type' => 'App\\Events\\NonExistentEvent',
            'data' => [],
        ]));
    }

    // ── LSVIDContext Lifecycle ───────────────────────────────

    public function testLsvidContextClearedAfterDispatch(): void
    {
        $this->eventBus->method('dispatch')
            ->willReturnCallback(function () {
                // During dispatch, context should be null (no rawLsvid set
                // because no lsvid on envelope and requireSpiffeIdentity=false)
                $this->assertNull(LSVIDContext::current());
            });

        $consumer = new EventConsumer(
            $this->eventBus,
            requireSpiffeIdentity: false,
        );

        $consumer->process($this->makeMessage([
            'type' => 'App\\Events\\OrderCreatedEvent',
            'data' => ['orderId' => '1', 'userKey' => '1', 'productList' => [], 'total' => 100],
        ]));

        // After process, context should be cleared
        $this->assertNull(LSVIDContext::current());
    }

    public function testLsvidContextClearedEvenOnDispatchFailure(): void
    {
        $this->eventBus->method('dispatch')
            ->willThrowException(new \RuntimeException('handler failed'));

        $consumer = new EventConsumer(
            $this->eventBus,
            requireSpiffeIdentity: false,
        );

        try {
            $consumer->process($this->makeMessage([
                'type' => 'App\\Events\\OrderCreatedEvent',
                'data' => ['orderId' => '1', 'userKey' => '1', 'productList' => [], 'total' => 100],
            ]));
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull(LSVIDContext::current());
    }

    // ── Reflection-based Event Construction ─────────────────

    public function testDispatchesReflectionBuiltEvent(): void
    {
        $dispatchedEvent = null;
        $this->eventBus->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$dispatchedEvent) {
                $dispatchedEvent = $event;
            });

        $consumer = new EventConsumer(
            $this->eventBus,
            requireSpiffeIdentity: false,
        );

        $consumer->process($this->makeMessage([
            'type' => 'App\\Events\\OrderCreatedEvent',
            'data' => [
                'orderId' => 'order-reflect',
                'userKey' => '7',
                'productList' => [['p_key' => 1, 'amount' => 2]],
                'total' => 300,
            ],
        ]));

        $this->assertInstanceOf(\App\Events\OrderCreatedEvent::class, $dispatchedEvent);
        $this->assertSame('order-reflect', $dispatchedEvent->orderId);
        $this->assertSame('7', $dispatchedEvent->userKey);
        $this->assertSame(300, $dispatchedEvent->total);
    }

    public function testSpiffeDisabledIgnoresLsvidOnEnvelope(): void
    {
        // When requireSpiffeIdentity=false, an lsvid on the envelope should
        // be ignored (not validated, not set into LSVIDContext).
        $this->eventBus->method('dispatch')
            ->willReturnCallback(function () {
                $this->assertNull(LSVIDContext::current());
            });

        $consumer = new EventConsumer(
            $this->eventBus,
            requireSpiffeIdentity: false,
        );

        $consumer->process($this->makeMessage([
            'type' => 'App\\Events\\OrderCreatedEvent',
            'data' => ['orderId' => '1', 'userKey' => '1', 'productList' => [], 'total' => 0],
            'lsvid' => 'some.token.here',
        ]));

        $this->assertNull(LSVIDContext::current());
    }
}
