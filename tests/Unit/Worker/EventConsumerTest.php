<?php

declare(strict_types=1);

namespace Tests\Unit\Worker;

use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;
use SDPMlab\LSVID\JtiReplayCache;
use SDPMlab\LSVID\LSVIDContext;
use SDPMlab\LSVID\LSVIDSigner;
use SDPMlab\LSVID\LSVIDValidator;
use SDPMlab\LSVID\Tests\TestSvidReader;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\MessageQueue\UnrecoverableMessageException;
use ZtEventGateway\Worker\EventConsumer;

require_once __DIR__ . '/../../../packages/php-lsvid/tests/LSVID/TestSvidReader.php';

/**
 * Unit boundary tests for {@see EventConsumer}.
 *
 * The EventConsumer sits on the second AMQP hop: it dequeues an event
 * envelope previously published by MessageBus and decides whether to:
 *   - drop it (untrusted SPIFFE source, missing schema, broken LSVID)
 *   - dispatch it through EventBus to the registered Saga handlers
 *
 * Today the only EventConsumer tests live indirectly inside the E2E
 * scripts. These cases pin the unit-level rejection contract so future
 * refactors don't quietly weaken any of the gates.
 */
final class EventConsumerTest extends TestCase
{
    private const GW_SPIFFE = 'spiffe://zt.local/php-gateway';
    private const WK_SPIFFE = 'spiffe://zt.local/php-worker';
    private const DOWNSTREAM_SPIFFE = 'spiffe://zt.local/order-service';

    protected function tearDown(): void
    {
        // EventConsumer mutates LSVIDContext (coroutine-local). Even though
        // we dispatch synchronously, leaks across tests would surface as
        // weird "extend with stale prior token" failures elsewhere.
        LSVIDContext::clear();
    }

    /**
     * @return array{0: LSVIDSigner, 1: LSVIDSigner, 2: LSVIDValidator}
     */
    private function makeLsvidStack(): array
    {
        $gwReader = TestSvidReader::create(self::GW_SPIFFE, 'zt.local');
        $wkReader = $gwReader->deriveWorkload(self::WK_SPIFFE);
        $gwSigner = new LSVIDSigner($gwReader, defaultTtlSeconds: 300);
        $wkSigner = new LSVIDSigner($wkReader, defaultTtlSeconds: 300);
        $validator = new LSVIDValidator(
            $wkReader,
            clockSkewSeconds: 30,
            jtiCache: new JtiReplayCache(),
        );
        return [$gwSigner, $wkSigner, $validator];
    }

    /**
     * The Saga handler dispatched by EventConsumer expects a specific
     * event class — for boundary tests we use OrderCreateRequestedEvent
     * since EventConsumer has a code path that constructs it explicitly.
     */
    private function envelope(
        string $traceId,
        string $sourceSpiffeId,
        ?string $lsvid = null,
        string $eventType = 'App\\Events\\OrderCreateRequestedEvent',
    ): string {
        $body = [
            'type' => $eventType,
            'spiffe_id' => $sourceSpiffeId,
            'spiffe_path' => [$sourceSpiffeId],
            'data' => [
                'userKey' => '1',
                'productList' => [['p_key' => 1, 'amount' => 1]],
                'traceId' => $traceId,
            ],
        ];
        if ($lsvid !== null) {
            $body['lsvid'] = $lsvid;
        }
        return json_encode($body, JSON_THROW_ON_ERROR);
    }

    // ── Structural rejections ─────────────────────────────────

    public function testRejectsNonJsonPayload(): void
    {
        $eventBus = $this->createMock(EventBus::class);
        $eventBus->expects($this->never())->method('dispatch');
        $consumer = new EventConsumer($eventBus);

        $this->expectException(UnrecoverableMessageException::class);
        $this->expectExceptionMessage('Invalid event payload');
        $consumer->process(new AMQPMessage('not json at all'));
    }

    public function testRejectsMissingTypeOrData(): void
    {
        $eventBus = $this->createMock(EventBus::class);
        $eventBus->expects($this->never())->method('dispatch');
        $consumer = new EventConsumer($eventBus);

        $this->expectException(UnrecoverableMessageException::class);
        $this->expectExceptionMessage('Missing event type or data');
        $consumer->process(new AMQPMessage(json_encode([
            'spiffe_id' => self::WK_SPIFFE,
            'spiffe_path' => [self::WK_SPIFFE],
            // type and data both missing
        ], JSON_THROW_ON_ERROR)));
    }

    public function testRejectsUnknownEventClass(): void
    {
        $eventBus = $this->createMock(EventBus::class);
        $eventBus->expects($this->never())->method('dispatch');
        $consumer = new EventConsumer($eventBus);

        $this->expectException(UnrecoverableMessageException::class);
        $this->expectExceptionMessage('Unknown event class');
        $consumer->process(new AMQPMessage($this->envelope(
            traceId: 'trace-bogus',
            sourceSpiffeId: self::WK_SPIFFE,
            eventType: 'App\\Events\\NonExistentEvent',
        )));
    }

    // ── SPIFFE trust-domain gate ──────────────────────────────

    public function testRejectsUntrustedSpiffeSource(): void
    {
        $eventBus = $this->createMock(EventBus::class);
        $eventBus->expects($this->never())->method('dispatch');
        $consumer = new EventConsumer($eventBus);

        $this->expectException(UnrecoverableMessageException::class);
        $this->expectExceptionMessage('Untrusted SPIFFE source');
        $consumer->process(new AMQPMessage($this->envelope(
            traceId: 'trace-attacker',
            sourceSpiffeId: 'spiffe://evil.domain/attacker',
        )));
    }

    public function testAcceptsMissingSpiffeIdWithWarning(): void
    {
        // Per the production code: empty spiffe_id is allowed (logged as
        // a warning), so legacy traffic does not deadlock during migration.
        // This is intentional — pin it so a future hardening that flips
        // it to fail-closed is reviewed.
        $eventBus = $this->createMock(EventBus::class);
        $eventBus->expects($this->once())->method('dispatch');
        $consumer = new EventConsumer($eventBus);

        $consumer->process(new AMQPMessage(json_encode([
            'type' => 'App\\Events\\OrderCreateRequestedEvent',
            'spiffe_id' => '',
            'spiffe_path' => [],
            'data' => [
                'userKey' => '1',
                'productList' => [['p_key' => 1, 'amount' => 1]],
                'traceId' => 'trace-legacy',
            ],
        ], JSON_THROW_ON_ERROR)));
    }

    // ── LSVID integration boundary ────────────────────────────

    public function testValidLsvidChainReachesEventBus(): void
    {
        $previousEnv = getenv('SPIFFE_ID');
        putenv('SPIFFE_ID=' . self::WK_SPIFFE);
        try {
            [$gwSigner, $wkSigner, $validator] = $this->makeLsvidStack();
            // Realistic flow: gateway L0 → worker extends to L1 audience=worker.
            $l0 = $gwSigner->createBase(audience: self::WK_SPIFFE);
            $l1 = $wkSigner->extend(priorRawToken: $l0->raw, audience: self::WK_SPIFFE);

            $eventBus = $this->createMock(EventBus::class);
            $eventBus->expects($this->once())->method('dispatch');
            $consumer = new EventConsumer($eventBus, $validator, lsvidRequired: true);

            $consumer->process(new AMQPMessage($this->envelope(
                traceId: 'trace-l1-ok',
                sourceSpiffeId: self::WK_SPIFFE,
                lsvid: $l1->raw,
            )));
        } finally {
            putenv('SPIFFE_ID' . ($previousEnv === false ? '' : '=' . $previousEnv));
        }
    }

    public function testRejectsExpiredLsvid(): void
    {
        $previousEnv = getenv('SPIFFE_ID');
        putenv('SPIFFE_ID=' . self::WK_SPIFFE);
        try {
            $gwReader = TestSvidReader::create(self::GW_SPIFFE, 'zt.local');
            $wkReader = $gwReader->deriveWorkload(self::WK_SPIFFE);
            $staleSigner = new LSVIDSigner($gwReader, defaultTtlSeconds: -500);
            $validator = new LSVIDValidator(
                $wkReader,
                clockSkewSeconds: 30,
                jtiCache: new JtiReplayCache(),
            );
            $stale = $staleSigner->createBase(audience: self::WK_SPIFFE);

            $eventBus = $this->createMock(EventBus::class);
            $eventBus->expects($this->never())->method('dispatch');
            $consumer = new EventConsumer($eventBus, $validator, lsvidRequired: true);

            $this->expectException(UnrecoverableMessageException::class);
            $this->expectExceptionMessage('LSVID validation failed');
            $consumer->process(new AMQPMessage($this->envelope(
                traceId: 'trace-expired',
                sourceSpiffeId: self::GW_SPIFFE,
                lsvid: $stale->raw,
            )));
        } finally {
            putenv('SPIFFE_ID' . ($previousEnv === false ? '' : '=' . $previousEnv));
        }
    }

    public function testRejectsTamperedSignature(): void
    {
        $previousEnv = getenv('SPIFFE_ID');
        putenv('SPIFFE_ID=' . self::WK_SPIFFE);
        try {
            [$gwSigner, , $validator] = $this->makeLsvidStack();
            $l0 = $gwSigner->createBase(audience: self::WK_SPIFFE);
            $parts = explode('.', $l0->raw);
            $parts[2] = str_repeat('A', strlen($parts[2]));
            $tampered = implode('.', $parts);

            $eventBus = $this->createMock(EventBus::class);
            $eventBus->expects($this->never())->method('dispatch');
            $consumer = new EventConsumer($eventBus, $validator, lsvidRequired: true);

            $this->expectException(UnrecoverableMessageException::class);
            $this->expectExceptionMessage('LSVID validation failed');
            $consumer->process(new AMQPMessage($this->envelope(
                traceId: 'trace-tampered',
                sourceSpiffeId: self::GW_SPIFFE,
                lsvid: $tampered,
            )));
        } finally {
            putenv('SPIFFE_ID' . ($previousEnv === false ? '' : '=' . $previousEnv));
        }
    }

    public function testRejectsLsvidWithWrongAudience(): void
    {
        // Worker SPIFFE_ID = php-worker, but the prior hop minted L0 for
        // a different downstream. EventConsumer must refuse — otherwise
        // tokens "in flight" for one service could be replayed on another.
        $previousEnv = getenv('SPIFFE_ID');
        putenv('SPIFFE_ID=' . self::WK_SPIFFE);
        try {
            [$gwSigner, , $validator] = $this->makeLsvidStack();
            $l0 = $gwSigner->createBase(audience: self::DOWNSTREAM_SPIFFE);

            $eventBus = $this->createMock(EventBus::class);
            $eventBus->expects($this->never())->method('dispatch');
            $consumer = new EventConsumer($eventBus, $validator, lsvidRequired: true);

            $this->expectException(UnrecoverableMessageException::class);
            $this->expectExceptionMessage('LSVID validation failed');
            $consumer->process(new AMQPMessage($this->envelope(
                traceId: 'trace-wrong-aud',
                sourceSpiffeId: self::GW_SPIFFE,
                lsvid: $l0->raw,
            )));
        } finally {
            putenv('SPIFFE_ID' . ($previousEnv === false ? '' : '=' . $previousEnv));
        }
    }

    public function testRejectsBrokenChainOnL0ToL1(): void
    {
        // Gateway points L0 at downstream; worker still extends to its own
        // L1. The validator's chain-continuity check should reject because
        // L0.aud (downstream) does not match L1.iss (worker).
        $previousEnv = getenv('SPIFFE_ID');
        putenv('SPIFFE_ID=' . self::WK_SPIFFE);
        try {
            [$gwSigner, $wkSigner, $validator] = $this->makeLsvidStack();
            $l0 = $gwSigner->createBase(audience: self::DOWNSTREAM_SPIFFE);
            $l1 = $wkSigner->extend(priorRawToken: $l0->raw, audience: self::WK_SPIFFE);

            $eventBus = $this->createMock(EventBus::class);
            $eventBus->expects($this->never())->method('dispatch');
            $consumer = new EventConsumer($eventBus, $validator, lsvidRequired: true);

            $this->expectException(UnrecoverableMessageException::class);
            $this->expectExceptionMessage('LSVID validation failed');
            $consumer->process(new AMQPMessage($this->envelope(
                traceId: 'trace-broken-chain',
                sourceSpiffeId: self::WK_SPIFFE,
                lsvid: $l1->raw,
            )));
        } finally {
            putenv('SPIFFE_ID' . ($previousEnv === false ? '' : '=' . $previousEnv));
        }
    }

    public function testLsvidPresentButValidatorMissingIsWiringError(): void
    {
        [$gwSigner, , ] = $this->makeLsvidStack();
        $l0 = $gwSigner->createBase(audience: self::WK_SPIFFE);

        $eventBus = $this->createMock(EventBus::class);
        $eventBus->expects($this->never())->method('dispatch');
        $consumer = new EventConsumer($eventBus, lsvidValidator: null);

        $this->expectException(UnrecoverableMessageException::class);
        $this->expectExceptionMessage('no LSVIDValidator is configured');
        $consumer->process(new AMQPMessage($this->envelope(
            traceId: 'trace-no-validator',
            sourceSpiffeId: self::GW_SPIFFE,
            lsvid: $l0->raw,
        )));
    }

    public function testLsvidRequiredButAbsentIsRejected(): void
    {
        $eventBus = $this->createMock(EventBus::class);
        $eventBus->expects($this->never())->method('dispatch');
        $consumer = new EventConsumer($eventBus, lsvidValidator: null, lsvidRequired: true);

        $this->expectException(UnrecoverableMessageException::class);
        $this->expectExceptionMessage('LSVID required but event envelope carries none');
        $consumer->process(new AMQPMessage($this->envelope(
            traceId: 'trace-no-lsvid',
            sourceSpiffeId: self::WK_SPIFFE,
        )));
    }

    // ── LSVIDContext lifecycle ────────────────────────────────

    public function testLsvidContextIsClearedAfterDispatch(): void
    {
        $previousEnv = getenv('SPIFFE_ID');
        putenv('SPIFFE_ID=' . self::WK_SPIFFE);
        try {
            [$gwSigner, , $validator] = $this->makeLsvidStack();
            $l0 = $gwSigner->createBase(audience: self::WK_SPIFFE);

            // Capture the raw token visible to handlers via LSVIDContext.
            $eventBus = $this->createMock(EventBus::class);
            $seenInsideDispatch = null;
            $eventBus->method('dispatch')
                ->willReturnCallback(function () use (&$seenInsideDispatch) {
                    $seenInsideDispatch = LSVIDContext::current();
                });

            $consumer = new EventConsumer($eventBus, $validator, lsvidRequired: true);
            $consumer->process(new AMQPMessage($this->envelope(
                traceId: 'trace-ctx',
                sourceSpiffeId: self::GW_SPIFFE,
                lsvid: $l0->raw,
            )));

            $this->assertSame($l0->raw, $seenInsideDispatch, 'Handler must see the raw LSVID via LSVIDContext.');
            $this->assertNull(
                LSVIDContext::current(),
                'LSVIDContext must be cleared after dispatch returns to avoid coroutine leaks.',
            );
        } finally {
            putenv('SPIFFE_ID' . ($previousEnv === false ? '' : '=' . $previousEnv));
        }
    }

    public function testLsvidContextIsClearedEvenWhenDispatchThrows(): void
    {
        $previousEnv = getenv('SPIFFE_ID');
        putenv('SPIFFE_ID=' . self::WK_SPIFFE);
        try {
            [$gwSigner, , $validator] = $this->makeLsvidStack();
            $l0 = $gwSigner->createBase(audience: self::WK_SPIFFE);

            $eventBus = $this->createMock(EventBus::class);
            $eventBus->method('dispatch')
                ->willThrowException(new \RuntimeException('handler blew up'));

            $consumer = new EventConsumer($eventBus, $validator, lsvidRequired: true);

            try {
                $consumer->process(new AMQPMessage($this->envelope(
                    traceId: 'trace-ctx-throw',
                    sourceSpiffeId: self::GW_SPIFFE,
                    lsvid: $l0->raw,
                )));
                $this->fail('expected dispatch to bubble');
            } catch (\RuntimeException $e) {
                $this->assertSame('handler blew up', $e->getMessage());
            }

            $this->assertNull(
                LSVIDContext::current(),
                'finally{} must clear LSVIDContext even when the handler throws.',
            );
        } finally {
            putenv('SPIFFE_ID' . ($previousEnv === false ? '' : '=' . $previousEnv));
        }
    }
}
