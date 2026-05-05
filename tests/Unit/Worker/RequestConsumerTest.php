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
            );

        $consumer = new RequestConsumer($messageBus);
        $payload = json_encode([
            'schema_version' => 1,
            'type' => 'gateway.request',
            'route' => 'OrderCreateRequestedEvent',
            'id' => 'trace-1',
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
            'data' => [
                'userKey' => '1',
                'productList' => [['p_key' => 1, 'amount' => 1]],
            ],
        ], JSON_THROW_ON_ERROR)));
    }

    public function testProcessRejectsInvalidPayload(): void
    {
        $messageBus = $this->createMock(MessageBus::class);
        $messageBus->expects($this->never())->method('publishEvent');

        $consumer = new RequestConsumer($messageBus);

        $this->expectException(UnrecoverableMessageException::class);
        $consumer->process(new AMQPMessage('not-json'));
    }
}
