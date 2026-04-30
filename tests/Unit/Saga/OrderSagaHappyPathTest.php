<?php

declare(strict_types=1);

namespace Tests\Unit\Saga;

use App\Events\InventoryDeductedEvent;
use App\Events\OrderCreatedEvent;
use App\Events\OrderCreateRequestedEvent;
use App\Events\PaymentProcessedEvent;
use App\Events\RollbackInventoryEvent;
use App\Sagas\OrderSaga;
use GuzzleHttp\Promise\FulfilledPromise;
use PHPUnit\Framework\TestCase;
use SDPMlab\Anser\Service\ActionInterface;
use SDPMlab\ZtEventGateway\EventBus;
use Services\Models\OrderProductDetail;
use Services\OrderService;
use Services\ProductionService;
use Services\UserService;

/**
 * Happy-path tests for OrderSaga.
 *
 * Covers the successful order completion chain:
 *   OrderCreateRequested → OrderCreated → InventoryDeducted → PaymentProcessed
 *
 * Each step is exercised in isolation; the final test composes them end-to-end
 * by re-feeding the captured publish payloads into the next handler. This is
 * the test that proves "訂單能完成" — if the chain breaks at any step, this
 * file is what should fail.
 */
final class OrderSagaHappyPathTest extends TestCase
{
    private EventBus $eventBus;
    private ProductionService $prodSvc;
    private OrderService $orderSvc;
    private UserService $userSvc;
    private OrderSaga $saga;

    /** @var list<array{0: string, 1: array}> */
    private array $published = [];

    protected function setUp(): void
    {
        $this->eventBus = $this->createMock(EventBus::class);
        $this->prodSvc = $this->createMock(ProductionService::class);
        $this->orderSvc = $this->createMock(OrderService::class);
        $this->userSvc = $this->createMock(UserService::class);

        $this->saga = new OrderSaga($this->eventBus);

        foreach ([
            'productionService' => $this->prodSvc,
            'orderService' => $this->orderSvc,
            'userService' => $this->userSvc,
        ] as $prop => $mock) {
            $ref = new \ReflectionProperty($this->saga, $prop);
            $ref->setAccessible(true);
            $ref->setValue($this->saga, $mock);
        }

        $this->published = [];
        $this->eventBus->method('publish')
            ->willReturnCallback(function (string $eventClass, array $payload) {
                $this->published[] = [$eventClass, $payload];
            });
    }

    /**
     * Builds an ActionInterface mock that mimics enough of the SDPMlab\Anser
     * surface to satisfy both synchronous (`do()->getMeaningData()`) and
     * concurrent (`ConcurrentAction::send()` calling `doAsync()`) usages.
     */
    private function mockAction(array $meaningData): ActionInterface
    {
        $action = $this->createMock(ActionInterface::class);
        $action->method('do')->willReturn($action);
        $action->method('getMeaningData')->willReturn($meaningData);
        // ConcurrentAction::send() iterates doAsync() and unwraps Guzzle promises.
        $action->method('doAsync')->willReturn(new FulfilledPromise(null));
        return $action;
    }

    private function assertPublished(string $expectedEventClass, int $index = 0): array
    {
        $this->assertArrayHasKey($index, $this->published, "Expected publish call #{$index} not found");
        $this->assertSame($expectedEventClass, $this->published[$index][0]);
        return $this->published[$index][1];
    }

    private function assertNothingPublished(): void
    {
        $this->assertCount(0, $this->published, 'Expected no events published');
    }

    // ═══════════════════════════════════════════════════════════
    //  Step 1 — onOrderCreateRequested
    // ═══════════════════════════════════════════════════════════

    public function testOnOrderCreateRequested_publishesOrderCreatedWithEventUserKey(): void
    {
        $this->prodSvc->method('productInfoAction')
            ->willReturn($this->mockAction(['code' => '200', 'data' => ['price' => 250]]));
        $this->orderSvc->method('createOrderAction')
            ->willReturn($this->mockAction(['code' => '200', 'total' => 500]));

        $event = new OrderCreateRequestedEvent([
            'userKey' => '42',
            'product_list' => [['p_key' => 1, 'amount' => 2]],
        ]);

        $this->saga->onOrderCreateRequested($event);

        $payload = $this->assertPublished(OrderCreatedEvent::class);
        $this->assertSame('42', $payload['userKey'], 'userKey must come from event, not hardcoded "1"');
        $this->assertSame(500, $payload['total'], 'total must come from API response, not magic 1000 fallback');
        $this->assertCount(1, $payload['productList']);
        $product = $payload['productList'][0];
        $this->assertInstanceOf(OrderProductDetail::class, $product);
        $this->assertSame(250, $product->price, 'price must be applied from productInfoAction result');
        $this->assertSame(1, $product->p_key);
        $this->assertSame(2, $product->amount);
    }

    public function testOnOrderCreateRequested_priceFromApiAsString_isCoercedToInt(): void
    {
        $this->prodSvc->method('productInfoAction')
            ->willReturn($this->mockAction(['code' => '200', 'data' => ['price' => '199']]));
        $this->orderSvc->method('createOrderAction')
            ->willReturn($this->mockAction(['code' => '200', 'total' => 199]));

        $event = new OrderCreateRequestedEvent([
            'userKey' => '7',
            'product_list' => [['p_key' => 5, 'amount' => 1]],
        ]);

        $this->saga->onOrderCreateRequested($event);

        $payload = $this->assertPublished(OrderCreatedEvent::class);
        $this->assertSame(199, $payload['productList'][0]->price);
    }

    public function testOnOrderCreateRequested_totalMissingFromApi_fallsBackToComputed(): void
    {
        $this->prodSvc->method('productInfoAction')
            ->willReturn($this->mockAction(['code' => '200', 'data' => ['price' => 100]]));
        // API doesn't return 'total' — saga must compute price * amount sum.
        $this->orderSvc->method('createOrderAction')
            ->willReturn($this->mockAction(['code' => '200']));

        $event = new OrderCreateRequestedEvent([
            'userKey' => '9',
            'product_list' => [
                ['p_key' => 1, 'amount' => 2],   // 100 * 2 = 200
                ['p_key' => 2, 'amount' => 3],   // 100 * 3 = 300
            ],
        ]);

        $this->saga->onOrderCreateRequested($event);

        $payload = $this->assertPublished(OrderCreatedEvent::class);
        $this->assertSame(500, $payload['total'], 'total must be computed from productList when API omits it');
    }

    // ═══════════════════════════════════════════════════════════
    //  Step 2 — onOrderCreated
    // ═══════════════════════════════════════════════════════════

    public function testOnOrderCreated_allInventorySucceeds_publishesInventoryDeducted(): void
    {
        $this->prodSvc->method('reduceInventory')
            ->willReturnCallback(fn() => $this->mockAction(['code' => '200']));

        // userKey must be a numeric string — downstream services (walletCharge /
        // createOrder / compensateOrder) declare `int $userId` and rely on PHP
        // coercive type juggling. Non-numeric strings raise TypeError.
        $event = new OrderCreatedEvent('o-1', '1', [
            ['p_key' => 1, 'amount' => 2],
            ['p_key' => 2, 'amount' => 1],
        ], 500);

        $this->saga->onOrderCreated($event);

        $payload = $this->assertPublished(InventoryDeductedEvent::class);
        $this->assertSame('o-1', $payload['orderId']);
        $this->assertSame('1', $payload['userKey']);
        $this->assertCount(2, $payload['productList'], 'all 2 products must be in successfulDeductions');
        $this->assertSame(500, $payload['total']);
        $this->assertCount(1, $this->published, 'no rollback event should be published on full success');
    }

    public function testOnOrderCreated_partialInventoryFails_compensatesWithSuccessfulOnly(): void
    {
        $callCount = 0;
        $this->prodSvc->method('reduceInventory')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return $callCount === 1
                    ? $this->mockAction(['code' => '200'])
                    : $this->mockAction(['code' => 500, 'msg' => 'oversold']);
            });

        $event = new OrderCreatedEvent('o-2', '2', [
            ['p_key' => 1, 'amount' => 2],
            ['p_key' => 2, 'amount' => 1],
        ], 500);

        $this->saga->onOrderCreated($event);

        $payload = $this->assertPublished(RollbackInventoryEvent::class);
        $this->assertSame('o-2', $payload['orderId']);
        $this->assertSame('2', $payload['userKey']);
        $this->assertCount(1, $payload['successfulDeductions'], 'only the succeeded product belongs in the rollback list');
        $this->assertSame(1, $payload['successfulDeductions'][0]['p_key']);
        $this->assertCount(1, $this->published, 'must publish only RollbackInventoryEvent, not InventoryDeducted');
    }

    // ═══════════════════════════════════════════════════════════
    //  Step 3 — onInventoryDeducted
    // ═══════════════════════════════════════════════════════════

    public function testOnInventoryDeducted_walletChargeSucceeds_publishesPaymentProcessed(): void
    {
        $this->userSvc->method('walletChargeAction')
            ->willReturn($this->mockAction(['code' => '200']));

        $event = new InventoryDeductedEvent('o-3', '3', [
            ['p_key' => 1, 'amount' => 1],
        ], 100);

        $this->saga->onInventoryDeducted($event);

        $payload = $this->assertPublished(PaymentProcessedEvent::class);
        $this->assertSame('o-3', $payload['orderId']);
        $this->assertTrue($payload['success']);
    }

    // ═══════════════════════════════════════════════════════════
    //  Step 4 — onPaymentProcessed (terminal)
    // ═══════════════════════════════════════════════════════════

    public function testOnPaymentProcessed_success_completesWithoutPublishing(): void
    {
        $this->saga->onPaymentProcessed(new PaymentProcessedEvent('o-4', true));
        $this->assertNothingPublished();
    }

    // ═══════════════════════════════════════════════════════════
    //  End-to-end happy path — replays captured payloads
    // ═══════════════════════════════════════════════════════════

    public function testFullHappyPathChain_publishesExpectedSequence(): void
    {
        $this->prodSvc->method('productInfoAction')
            ->willReturn($this->mockAction(['code' => '200', 'data' => ['price' => 50]]));
        $this->orderSvc->method('createOrderAction')
            ->willReturn($this->mockAction(['code' => '200', 'total' => 100]));
        $this->prodSvc->method('reduceInventory')
            ->willReturnCallback(fn() => $this->mockAction(['code' => '200']));
        $this->userSvc->method('walletChargeAction')
            ->willReturn($this->mockAction(['code' => '200']));

        $this->saga->onOrderCreateRequested(new OrderCreateRequestedEvent([
            'userKey' => '999',
            'product_list' => [['p_key' => 1, 'amount' => 2]],
        ]));

        // Replay Step 1 → Step 2: convert OrderProductDetail back to arrays for the event ctor.
        [, $orderCreated] = $this->published[0];
        $this->saga->onOrderCreated(new OrderCreatedEvent(
            $orderCreated['orderId'],
            $orderCreated['userKey'],
            array_map(static fn(OrderProductDetail $p) => $p->toArray(), $orderCreated['productList']),
            $orderCreated['total']
        ));

        [, $inventoryDeducted] = $this->published[1];
        $this->saga->onInventoryDeducted(new InventoryDeductedEvent(
            $inventoryDeducted['orderId'],
            $inventoryDeducted['userKey'],
            $inventoryDeducted['productList'],
            $inventoryDeducted['total']
        ));

        [, $paymentProcessed] = $this->published[2];
        $this->saga->onPaymentProcessed(new PaymentProcessedEvent(
            $paymentProcessed['orderId'],
            (bool) $paymentProcessed['success']
        ));

        $this->assertCount(3, $this->published, 'exactly 3 publishes for the happy path (no rollback)');
        $this->assertSame(OrderCreatedEvent::class, $this->published[0][0]);
        $this->assertSame(InventoryDeductedEvent::class, $this->published[1][0]);
        $this->assertSame(PaymentProcessedEvent::class, $this->published[2][0]);
        $this->assertSame('999', $this->published[0][1]['userKey']);
        $this->assertSame(100, $this->published[1][1]['total']);
        $this->assertTrue($this->published[2][1]['success']);
    }
}
