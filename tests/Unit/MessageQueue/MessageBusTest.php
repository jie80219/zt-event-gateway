<?php

declare(strict_types=1);

namespace Tests\Unit\MessageQueue;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;
use SDPMlab\LSVID\LSVID;
use SDPMlab\LSVID\LSVIDSigner;
use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;

// TestSvidReader lives in the LSVID package's test directory (not autoloaded by root).
require_once __DIR__ . '/../../../packages/php-lsvid/tests/LSVID/TestSvidReader.php';

use SDPMlab\LSVID\Tests\TestSvidReader;

class MessageBusTest extends TestCase
{
    private AMQPChannel $channel;
    private string $originalSpiffeId;

    protected function setUp(): void
    {
        $this->channel = $this->createMock(AMQPChannel::class);
        $this->originalSpiffeId = getenv('SPIFFE_ID') ?: '';
        putenv('SPIFFE_ID=spiffe://zt.local/test-worker');
    }

    protected function tearDown(): void
    {
        if ($this->originalSpiffeId !== '') {
            putenv('SPIFFE_ID=' . $this->originalSpiffeId);
        } else {
            putenv('SPIFFE_ID');
        }
    }

    /**
     * Build a minimal JWS token string that LSVID::parse() can accept.
     * Header must contain typ=LSVID; payload is arbitrary JSON.
     */
    private function buildMinimalLsvidToken(array $extraPayload = []): string
    {
        $header = $this->b64url(json_encode(['typ' => 'LSVID', 'alg' => 'ES256']));
        $payload = $this->b64url(json_encode(array_merge([
            'iss' => 'spiffe://zt.local/gateway',
            'aud' => 'spiffe://zt.local/test-worker',
            'iat' => time(),
            'exp' => time() + 3600,
            'jti' => bin2hex(random_bytes(8)),
        ], $extraPayload)));
        $sig = $this->b64url('fake-sig-for-test');

        return "{$header}.{$payload}.{$sig}";
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // ── Envelope Structure ──────────────────────────────────

    public function testPublishEventBuildsCorrectEnvelopeStructure(): void
    {
        $publishedBody = null;
        $this->channel->method('basic_publish')
            ->willReturnCallback(function (AMQPMessage $msg) use (&$publishedBody) {
                $publishedBody = $msg->getBody();
            });

        $bus = new MessageBus($this->channel, 'events');
        $bus->publishEvent(
            'App\\Events\\OrderCreatedEvent',
            ['orderId' => '123', 'userKey' => '1'],
        );

        $this->assertNotNull($publishedBody);
        $envelope = json_decode($publishedBody, true);

        $this->assertSame('App\\Events\\OrderCreatedEvent', $envelope['type']);
        $this->assertSame(['orderId' => '123', 'userKey' => '1'], $envelope['data']);
        $this->assertSame('spiffe://zt.local/test-worker', $envelope['spiffe_id']);
        $this->assertArrayHasKey('timestamp', $envelope);
        $this->assertArrayHasKey('spiffe_path', $envelope);
        $this->assertArrayNotHasKey('lsvid', $envelope);
    }

    public function testPublishEventRoutingKeyExtractsShortName(): void
    {
        $capturedRoutingKey = null;
        $this->channel->method('basic_publish')
            ->willReturnCallback(function (AMQPMessage $msg, string $exchange, string $routingKey) use (&$capturedRoutingKey) {
                $capturedRoutingKey = $routingKey;
            });

        $bus = new MessageBus($this->channel, 'events');
        $bus->publishEvent('App\\Events\\OrderCreatedEvent', []);

        $this->assertSame('OrderCreatedEvent', $capturedRoutingKey);
    }

    public function testPublishEventAppendsSpiffeIdToPath(): void
    {
        $publishedBody = null;
        $this->channel->method('basic_publish')
            ->willReturnCallback(function (AMQPMessage $msg) use (&$publishedBody) {
                $publishedBody = $msg->getBody();
            });

        $bus = new MessageBus($this->channel, 'events');
        $bus->publishEvent(
            'App\\Events\\OrderCreatedEvent',
            [],
            null,
            ['spiffe://zt.local/gateway'],
        );

        $envelope = json_decode($publishedBody, true);
        $this->assertSame(
            ['spiffe://zt.local/gateway', 'spiffe://zt.local/test-worker'],
            $envelope['spiffe_path'],
        );
    }

    public function testPublishEventOmitsSpiffeIdWhenEnvEmpty(): void
    {
        putenv('SPIFFE_ID');

        $publishedBody = null;
        $this->channel->method('basic_publish')
            ->willReturnCallback(function (AMQPMessage $msg) use (&$publishedBody) {
                $publishedBody = $msg->getBody();
            });

        // Must create MessageBus AFTER clearing env, since constructor reads it
        $bus = new MessageBus($this->channel, 'events');
        $bus->publishEvent('App\\Events\\TestEvent', [], null, ['existing']);

        $envelope = json_decode($publishedBody, true);
        $this->assertSame(['existing'], $envelope['spiffe_path']);
    }

    public function testPublishEventUsesDefaultExchange(): void
    {
        $capturedExchange = null;
        $this->channel->method('basic_publish')
            ->willReturnCallback(function (AMQPMessage $msg, string $exchange) use (&$capturedExchange) {
                $capturedExchange = $exchange;
            });

        $bus = new MessageBus($this->channel, 'my-exchange');
        $bus->publishEvent('App\\Events\\TestEvent', []);

        $this->assertSame('my-exchange', $capturedExchange);
    }

    public function testPublishEventUsesOverrideExchange(): void
    {
        $capturedExchange = null;
        $this->channel->method('basic_publish')
            ->willReturnCallback(function (AMQPMessage $msg, string $exchange) use (&$capturedExchange) {
                $capturedExchange = $exchange;
            });

        $bus = new MessageBus($this->channel, 'default');
        $bus->publishEvent('App\\Events\\TestEvent', [], 'override-exchange');

        $this->assertSame('override-exchange', $capturedExchange);
    }

    public function testPublishEventMessageIsPersistent(): void
    {
        $capturedMessage = null;
        $this->channel->method('basic_publish')
            ->willReturnCallback(function (AMQPMessage $msg) use (&$capturedMessage) {
                $capturedMessage = $msg;
            });

        $bus = new MessageBus($this->channel, 'events');
        $bus->publishEvent('App\\Events\\TestEvent', []);

        $this->assertNotNull($capturedMessage);
        $this->assertSame(
            AMQPMessage::DELIVERY_MODE_PERSISTENT,
            $capturedMessage->get('delivery_mode'),
        );
    }

    // ── LSVID Signing ───────────────────────────────────────
    //    LSVIDSigner is final — tests use real signers via TestSvidReader.

    private function createTestSigner(string $spiffeId = 'spiffe://zt.local/test-worker'): LSVIDSigner
    {
        $reader = TestSvidReader::create($spiffeId, 'zt.local');
        return new LSVIDSigner($reader);
    }

    public function testPublishEventExtendsLsvidWhenPriorProvided(): void
    {
        $gatewayReader = TestSvidReader::create('spiffe://zt.local/gateway', 'zt.local');
        $gatewaySigner = new LSVIDSigner($gatewayReader);
        $l0 = $gatewaySigner->createBase('spiffe://zt.local/test-worker');
        $priorToken = $l0->raw;

        $workerSigner = $this->createTestSigner();

        $publishedBody = null;
        $this->channel->method('basic_publish')
            ->willReturnCallback(function (AMQPMessage $msg) use (&$publishedBody) {
                $publishedBody = $msg->getBody();
            });

        $bus = new MessageBus($this->channel, 'events', $workerSigner, 'spiffe://zt.local/downstream');
        $bus->publishEvent('App\\Events\\TestEvent', ['traceId' => 'trace-1'], null, [], $priorToken);

        $envelope = json_decode($publishedBody, true);
        $this->assertArrayHasKey('lsvid', $envelope);

        // The extended token should be at level 1
        $parsed = LSVID::parse($envelope['lsvid']);
        $this->assertSame(1, $parsed->level());
    }

    public function testPublishEventCreatesFallbackL0WhenNoPriorAndNotRequired(): void
    {
        $signer = $this->createTestSigner();

        $publishedBody = null;
        $this->channel->method('basic_publish')
            ->willReturnCallback(function (AMQPMessage $msg) use (&$publishedBody) {
                $publishedBody = $msg->getBody();
            });

        $bus = new MessageBus($this->channel, 'events', $signer, 'spiffe://zt.local/downstream', false);
        $bus->publishEvent('App\\Events\\TestEvent', []);

        $envelope = json_decode($publishedBody, true);
        $this->assertArrayHasKey('lsvid', $envelope);

        $parsed = LSVID::parse($envelope['lsvid']);
        $this->assertSame(0, $parsed->level());
    }

    public function testPublishEventThrowsWhenNoPriorAndLsvidRequired(): void
    {
        $signer = $this->createTestSigner();

        $bus = new MessageBus($this->channel, 'events', $signer, 'spiffe://zt.local/downstream', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LSVID_REQUIRED');

        $bus->publishEvent('App\\Events\\TestEvent', []);
    }

    public function testPublishEventUsesOverrideAudience(): void
    {
        $signer = $this->createTestSigner();

        $publishedBody = null;
        $this->channel->method('basic_publish')
            ->willReturnCallback(function (AMQPMessage $msg) use (&$publishedBody) {
                $publishedBody = $msg->getBody();
            });

        $bus = new MessageBus($this->channel, 'events', $signer, 'default-aud', false);
        $bus->publishEvent('App\\Events\\TestEvent', [], null, [], null, 'override-aud');

        $envelope = json_decode($publishedBody, true);
        $this->assertArrayHasKey('lsvid', $envelope);

        // Verify the LSVID was created with the override audience
        $parsed = LSVID::parse($envelope['lsvid']);
        $this->assertSame('override-aud', $parsed->audience());
    }

    // ── Infrastructure Methods ──────────────────────────────

    public function testSetupExchangeCallsExchangeDeclare(): void
    {
        $this->channel->expects($this->once())
            ->method('exchange_declare')
            ->with('my-exchange', 'topic', false, true, false);

        $bus = new MessageBus($this->channel);
        $bus->setupExchange('my-exchange', 'topic');
    }

    public function testSetupQueueCallsQueueDeclareAndBind(): void
    {
        $this->channel->expects($this->once())
            ->method('queue_declare')
            ->with('my-queue', false, true, false, false);
        $this->channel->expects($this->once())
            ->method('queue_bind')
            ->with('my-queue', 'my-exchange', 'my-key');

        $bus = new MessageBus($this->channel);
        $bus->setupQueue('my-queue', 'my-exchange', 'my-key');
    }

    public function testPublishMessageCallsBasicPublish(): void
    {
        $capturedMessage = null;
        $capturedExchange = null;
        $capturedRoutingKey = null;

        $this->channel->expects($this->once())
            ->method('basic_publish')
            ->willReturnCallback(function (AMQPMessage $msg, string $exchange, string $routingKey) use (&$capturedMessage, &$capturedExchange, &$capturedRoutingKey) {
                $capturedMessage = $msg;
                $capturedExchange = $exchange;
                $capturedRoutingKey = $routingKey;
            });

        $bus = new MessageBus($this->channel);
        $bus->publishMessage('test-exchange', '{"test":true}', 'test-key');

        $this->assertSame('{"test":true}', $capturedMessage->getBody());
        $this->assertSame('test-exchange', $capturedExchange);
        $this->assertSame('test-key', $capturedRoutingKey);
    }
}
