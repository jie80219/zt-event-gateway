<?php

declare(strict_types=1);

namespace Tests\Unit\Worker;

use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;
use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\MessageQueue\UnrecoverableMessageException;
use ZtEventGateway\Worker\RequestConsumer;

final class RequestConsumerTest extends TestCase
{
    public function testProcessPublishesCanonicalEvent(): void
    {
        $messageBus = $this->createMock(MessageBus::class);
        $messageBus->expects($this->once())
            ->method('publishEvent')
            ->with(
                'App\\Events\\OrderCreateRequestedEvent',
                $this->callback(function (array $data): bool {
                    return $data['userKey'] === '1'
                        && $data['traceId'] === 'trace-1'
                        && $data['productList'][0]['p_key'] === 1
                        && $data['productList'][0]['amount'] === 2
                        && $data['total'] === 100;
                }),
                null,
                ['spiffe://zt.local/php-gateway']
            );

        $consumer = new RequestConsumer($messageBus);
        $payload = json_encode([
            'schema_version' => 1,
            'type' => 'gateway.request',
            'route' => 'OrderCreateRequestedEvent',
            'id' => 'trace-1',
            'spiffe_id' => 'spiffe://zt.local/php-gateway',
            'spiffe_path' => ['spiffe://zt.local/php-gateway'],
            'data' => [
                'user_id' => 1,
                'product_list' => [
                    ['p_key' => 1, 'amount' => 2],
                ],
                'total' => 100,
            ],
        ], JSON_THROW_ON_ERROR);

        $consumer->process(new AMQPMessage($payload));
    }

    public function testProcessRejectsMissingSchemaVersion(): void
    {
        $messageBus = $this->createMock(MessageBus::class);
        $messageBus->expects($this->never())->method('publishEvent');

        $consumer = new RequestConsumer($messageBus);

        $this->expectException(UnrecoverableMessageException::class);
        $consumer->process(new AMQPMessage(json_encode([
            'type' => 'gateway.request',
            'route' => 'OrderCreateRequestedEvent',
            'id' => 'trace-2',
            'spiffe_id' => 'spiffe://zt.local/php-gateway',
            'spiffe_path' => ['spiffe://zt.local/php-gateway'],
            'data' => [
                'userKey' => '1',
                'productList' => [['p_key' => 1, 'amount' => 1]],
            ],
        ], JSON_THROW_ON_ERROR)));
    }

    public function testProcessRejectsUntrustedSpiffeId(): void
    {
        $messageBus = $this->createMock(MessageBus::class);
        $messageBus->expects($this->never())->method('publishEvent');

        $consumer = new RequestConsumer($messageBus);

        $this->expectException(UnrecoverableMessageException::class);
        $consumer->process(new AMQPMessage(json_encode([
            'schema_version' => 1,
            'type' => 'gateway.request',
            'route' => 'OrderCreateRequestedEvent',
            'id' => 'trace-3',
            'spiffe_id' => 'spiffe://evil.domain/attacker',
            'spiffe_path' => ['spiffe://evil.domain/attacker'],
            'data' => [
                'userKey' => '1',
                'productList' => [['p_key' => 1, 'amount' => 1]],
                'total' => 0,
            ],
        ], JSON_THROW_ON_ERROR)));
    }

    public function testProcessAcceptsEnvelopeWithoutSpiffeIdentityWhenMasterToggleOff(): void
    {
        // When SPIFFE_ENABLED=0 the worker constructs RequestConsumer with
        // requireSpiffeIdentity=false. Envelopes lacking spiffe_id/path and
        // carrying no LSVID should still publish successfully; the trust
        // domain prefix check is intentionally bypassed.
        $messageBus = $this->createMock(MessageBus::class);
        $messageBus->expects($this->once())
            ->method('publishEvent')
            ->with(
                'App\\Events\\OrderCreateRequestedEvent',
                $this->callback(static fn (array $data): bool => $data['traceId'] === 'trace-baseline'),
                null,
                [],
            );

        $consumer = new RequestConsumer(
            $messageBus,
            null,
            false,
            false,
        );

        $consumer->process(new AMQPMessage(json_encode([
            'schema_version' => 1,
            'type' => 'gateway.request',
            'route' => 'OrderCreateRequestedEvent',
            'id' => 'trace-baseline',
            'spiffe_id' => '',
            'spiffe_path' => [],
            'data' => [
                'userKey' => '1',
                'productList' => [['p_key' => 1, 'amount' => 1]],
                'total' => 100,
            ],
        ], JSON_THROW_ON_ERROR)));
    }

    // ── Additional Edge Cases ───────────────────────────────

    public function testProcessRejectsInvalidJson(): void
    {
        $messageBus = $this->createMock(MessageBus::class);
        $messageBus->expects($this->never())->method('publishEvent');

        $consumer = new RequestConsumer($messageBus);

        $this->expectException(UnrecoverableMessageException::class);
        $this->expectExceptionMessage('Invalid request payload');

        $consumer->process(new AMQPMessage('not-json'));
    }

    public function testProcessRejectsUnknownRoute(): void
    {
        $messageBus = $this->createMock(MessageBus::class);
        $messageBus->expects($this->never())->method('publishEvent');

        $consumer = new RequestConsumer($messageBus, null, false, false);

        $this->expectException(UnrecoverableMessageException::class);
        $this->expectExceptionMessage('Unknown request route');

        $consumer->process(new AMQPMessage(json_encode([
            'schema_version' => 1,
            'type' => 'gateway.request',
            'route' => 'NonExistentEvent',
            'id' => 'trace-unknown',
            'spiffe_id' => '',
            'spiffe_path' => [],
            'data' => [
                'userKey' => '1',
                'productList' => [['p_key' => 1, 'amount' => 1]],
            ],
        ], JSON_THROW_ON_ERROR)));
    }

    public function testProcessRejectsLsvidPresentButNoValidator(): void
    {
        $messageBus = $this->createMock(MessageBus::class);
        $messageBus->expects($this->never())->method('publishEvent');

        $consumer = new RequestConsumer(
            $messageBus,
            null,           // no validator
            false,
            true,           // requireSpiffeIdentity
        );

        $this->expectException(UnrecoverableMessageException::class);
        $this->expectExceptionMessage('no LSVIDValidator is configured');

        $consumer->process(new AMQPMessage(json_encode([
            'schema_version' => 1,
            'type' => 'gateway.request',
            'route' => 'OrderCreateRequestedEvent',
            'id' => 'trace-lsvid-no-validator',
            'spiffe_id' => 'spiffe://zt.local/gateway',
            'spiffe_path' => ['spiffe://zt.local/gateway'],
            'lsvid' => 'some.jwt.token',
            'data' => [
                'userKey' => '1',
                'productList' => [['p_key' => 1, 'amount' => 1]],
            ],
        ], JSON_THROW_ON_ERROR)));
    }

    public function testProcessRejectsLsvidRequiredButMissing(): void
    {
        $messageBus = $this->createMock(MessageBus::class);
        $messageBus->expects($this->never())->method('publishEvent');

        $consumer = new RequestConsumer(
            $messageBus,
            null,
            true,           // lsvidRequired
            true,           // requireSpiffeIdentity
        );

        $this->expectException(UnrecoverableMessageException::class);
        $this->expectExceptionMessage('LSVID required');

        $consumer->process(new AMQPMessage(json_encode([
            'schema_version' => 1,
            'type' => 'gateway.request',
            'route' => 'OrderCreateRequestedEvent',
            'id' => 'trace-lsvid-required',
            'spiffe_id' => 'spiffe://zt.local/gateway',
            'spiffe_path' => ['spiffe://zt.local/gateway'],
            'data' => [
                'userKey' => '1',
                'productList' => [['p_key' => 1, 'amount' => 1]],
            ],
        ], JSON_THROW_ON_ERROR)));
    }

    public function testProcessResolvesFullyQualifiedRoute(): void
    {
        $messageBus = $this->createMock(MessageBus::class);
        $messageBus->expects($this->once())
            ->method('publishEvent')
            ->with(
                'App\\Events\\OrderCreateRequestedEvent',
                $this->anything(),
                null,
                $this->anything(),
            );

        $consumer = new RequestConsumer($messageBus, null, false, false);

        $consumer->process(new AMQPMessage(json_encode([
            'schema_version' => 1,
            'type' => 'gateway.request',
            'route' => 'App\\Events\\OrderCreateRequestedEvent',
            'id' => 'trace-fq',
            'spiffe_id' => '',
            'spiffe_path' => [],
            'data' => [
                'userKey' => '1',
                'productList' => [['p_key' => 1, 'amount' => 1]],
            ],
        ], JSON_THROW_ON_ERROR)));
    }
}
