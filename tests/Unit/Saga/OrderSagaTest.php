<?php

declare(strict_types=1);

namespace Tests\Unit\Saga;

use App\Events\OrderCreateRequestedEvent;
use App\Events\OrderCreatedEvent;
use App\Events\OrderSagaCompletedEvent;
use App\Events\RollbackInventoryEvent;
use App\Events\RollbackOrderEvent;
use App\Sagas\OrderSaga;
use PHPUnit\Framework\TestCase;
use SDPMlab\Anser\Service\ActionInterface;
use SDPMlab\ZtEventGateway\EventBus;
use Services\OrderService;
use Services\ProductionService;
use Services\UserService;

/**
 * OrderSaga unit tests — orchestrator mode.
 *
 * After the Step1→4 merge, `onOrderCreateRequested` runs the full happy
 * path inline; only compensation still rides on AMQP events. Tests focus on
 * the orchestrator's branching (which compensation event fires when) and
 * the rollback handlers.
 */
class OrderSagaTest extends TestCase
{
    private EventBus $eventBus;
    private ProductionService $prodSvc;
    private OrderService $orderSvc;
    private UserService $userSvc;
    private OrderSaga $saga;

    /** @var list<array{0: string, 1: array}> captured publish calls */
    private array $published = [];

    protected function setUp(): void
    {
        $this->eventBus = $this->createMock(EventBus::class);
        $this->prodSvc = $this->createMock(ProductionService::class);
        $this->orderSvc = $this->createMock(OrderService::class);
        $this->userSvc = $this->createMock(UserService::class);

        // Partial-mock OrderSaga so we can stub `runConcurrent` — the real
        // ConcurrentAction.send() hits Guzzle pool and can't run in unit tests.
        $this->saga = $this->getMockBuilder(OrderSaga::class)
            ->setConstructorArgs([$this->eventBus])
            ->onlyMethods(['runConcurrent'])
            ->getMock();

        foreach ([
            'productionService' => $this->prodSvc,
            'orderService' => $this->orderSvc,
            'userService' => $this->userSvc,
        ] as $prop => $mock) {
            $ref = new \ReflectionProperty(OrderSaga::class, $prop);
            $ref->setAccessible(true);
            $ref->setValue($this->saga, $mock);
        }

        $this->published = [];
        $this->eventBus->method('publish')
            ->willReturnCallback(function (string $eventClass, array $payload) {
                $this->published[] = [$eventClass, $payload];
            });
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function mockAction(array $meaningData): ActionInterface
    {
        $action = $this->createMock(ActionInterface::class);
        $action->method('do')->willReturn($action);
        $action->method('getMeaningData')->willReturn($meaningData);
        return $action;
    }

    private function makeOrderRequestedEvent(
        string $userKey = '1',
        array $productList = [['p_key' => 1, 'amount' => 2]],
    ): OrderCreateRequestedEvent {
        return new OrderCreateRequestedEvent(
            ['userKey' => $userKey, 'productList' => $productList],
            'trace-test-001',
        );
    }

    /**
     * Stub runConcurrent so every action returns the same meaningData.
     */
    private function stubConcurrentUniform(array $meaningData): void
    {
        $this->saga->method('runConcurrent')
            ->willReturnCallback(function (array $actions) use ($meaningData) {
                $out = [];
                foreach ($actions as $key => $_action) {
                    $out[$key] = $meaningData;
                }
                return $out;
            });
    }

    private function assertPublished(string $expectedEventClass, int $index = 0): array
    {
        $this->assertArrayHasKey($index, $this->published, "Expected publish #{$index} not found");
        $this->assertSame($expectedEventClass, $this->published[$index][0]);
        return $this->published[$index][1];
    }

    private function assertNothingPublished(): void
    {
        $this->assertCount(0, $this->published, 'Expected no events published');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Step 1: 查價 + 建單 失敗路徑
    // ═══════════════════════════════════════════════════════════════

    public function testStep1_productInfoFails_abortsWithoutPublish(): void
    {
        $this->prodSvc->method('productInfoAction')
            ->willReturn($this->mockAction(['code' => 500]));
        $this->stubConcurrentUniform(['code' => 500, 'msg' => 'down']);

        $this->orderSvc->expects($this->never())->method('createOrderAction');

        $this->saga->onOrderCreateRequested($this->makeOrderRequestedEvent());
        $this->assertNothingPublished();
    }

    public function testStep1_createOrderFails_abortsWithoutPublish(): void
    {
        $this->prodSvc->method('productInfoAction')
            ->willReturn($this->mockAction(['code' => 200, 'data' => ['price' => 100]]));
        $this->stubConcurrentUniform(['code' => 200, 'data' => ['price' => 100]]);
        $this->orderSvc->method('createOrderAction')
            ->willReturn($this->mockAction(['code' => 500, 'msg' => 'db error']));

        $this->saga->onOrderCreateRequested($this->makeOrderRequestedEvent());
        $this->assertNothingPublished();
    }

    public function testStep1_queriesAllProductIdsConcurrently(): void
    {
        $queriedIds = [];
        $this->prodSvc->method('productInfoAction')
            ->willReturnCallback(function (int $id) use (&$queriedIds) {
                $queriedIds[] = $id;
                return $this->mockAction(['code' => 200, 'data' => ['price' => 100]]);
            });
        $this->stubConcurrentUniform(['code' => 200, 'data' => ['price' => 100]]);
        $this->orderSvc->method('createOrderAction')
            ->willReturn($this->mockAction(['code' => 200, 'total' => 500]));
        $this->userSvc->method('walletChargeAction')
            ->willReturn($this->mockAction(['code' => 200]));
        $this->orderSvc->method('confirmOrderAction')
            ->willReturn($this->mockAction(['code' => 200]));

        $event = $this->makeOrderRequestedEvent('1', [
            ['p_key' => 7, 'amount' => 1],
            ['p_key' => 42, 'amount' => 3],
        ]);
        $this->saga->onOrderCreateRequested($event);

        $this->assertSame([7, 42], $queriedIds);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Happy path: Step 1 → 4 全跑完
    // ═══════════════════════════════════════════════════════════════

    public function testHappyPath_publishesOrderCreatedAndSagaCompleted(): void
    {
        $this->prodSvc->method('productInfoAction')
            ->willReturn($this->mockAction(['code' => 200, 'data' => ['price' => 100]]));
        $this->stubConcurrentUniform(['code' => 200, 'data' => ['price' => 100]]);
        $this->orderSvc->method('createOrderAction')
            ->willReturn($this->mockAction(['code' => 200, 'total' => 200]));
        $this->userSvc->method('walletChargeAction')
            ->willReturn($this->mockAction(['code' => 200]));
        $this->orderSvc->method('confirmOrderAction')
            ->willReturn($this->mockAction(['code' => 200]));

        $this->saga->onOrderCreateRequested(
            $this->makeOrderRequestedEvent('1', [['p_key' => 1, 'amount' => 2]]),
        );

        // 主路徑只 publish 2 個事件：OrderCreated (給 EventStore projection 起點)
        // 與 OrderSagaCompleted (終點)。中間 step 不再走 AMQP。
        $this->assertCount(2, $this->published);
        $created = $this->assertPublished(OrderCreatedEvent::class, 0);
        $this->assertSame(200, $created['total']);
        $orderId = $created['orderId'];

        $completed = $this->assertPublished(OrderSagaCompletedEvent::class, 1);
        $this->assertSame($orderId, $completed['orderId']);
        $this->assertSame('completed', $completed['status']);
        $this->assertSame(200, $completed['total']);
    }

    public function testHappyPath_calculatesTotalFromProductsWhenResponseOmitsTotal(): void
    {
        $this->prodSvc->method('productInfoAction')
            ->willReturn($this->mockAction(['code' => 200, 'data' => ['price' => 200]]));
        $this->stubConcurrentUniform(['code' => 200, 'data' => ['price' => 200]]);
        $this->orderSvc->method('createOrderAction')
            ->willReturn($this->mockAction(['code' => 200])); // no 'total'
        $this->userSvc->method('walletChargeAction')
            ->willReturn($this->mockAction(['code' => 200]));
        $this->orderSvc->method('confirmOrderAction')
            ->willReturn($this->mockAction(['code' => 200]));

        // price=200, amount=3 → total = 600
        $event = $this->makeOrderRequestedEvent('1', [['p_key' => 1, 'amount' => 3]]);
        $this->saga->onOrderCreateRequested($event);

        $created = $this->assertPublished(OrderCreatedEvent::class, 0);
        $this->assertSame(600, $created['total']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Step 2 失敗：扣庫存有任一筆失敗 → 觸發補償（paymentCompleted=false）
    // ═══════════════════════════════════════════════════════════════

    public function testStep2_inventoryFails_publishesOrderCreatedThenRollback(): void
    {
        $this->prodSvc->method('productInfoAction')
            ->willReturn($this->mockAction(['code' => 200, 'data' => ['price' => 100]]));
        $this->prodSvc->method('reduceInventory')
            ->willReturn($this->mockAction(['code' => 500]));
        $this->orderSvc->method('createOrderAction')
            ->willReturn($this->mockAction(['code' => 200, 'total' => 200]));

        // Step 1 並行查價成功，Step 2 並行扣庫存失敗
        $this->saga->method('runConcurrent')
            ->willReturnCallback(function (array $actions) {
                $out = [];
                foreach ($actions as $key => $_) {
                    // info_* 成功；ded_* 失敗
                    $out[$key] = str_starts_with($key, 'info_')
                        ? ['code' => 200, 'data' => ['price' => 100]]
                        : ['code' => 500];
                }
                return $out;
            });

        $this->saga->onOrderCreateRequested($this->makeOrderRequestedEvent());

        $this->assertPublished(OrderCreatedEvent::class, 0);
        $rollback = $this->assertPublished(RollbackInventoryEvent::class, 1);
        $this->assertFalse($rollback['paymentCompleted']);
        $this->assertSame(0, $rollback['total']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Step 3 失敗：付款失敗 → 觸發補償（paymentCompleted=false）
    // ═══════════════════════════════════════════════════════════════

    public function testStep3_paymentFails_publishesOrderCreatedThenRollback(): void
    {
        $this->prodSvc->method('productInfoAction')
            ->willReturn($this->mockAction(['code' => 200, 'data' => ['price' => 100]]));
        $this->stubConcurrentUniform(['code' => 200, 'data' => ['price' => 100]]);
        $this->orderSvc->method('createOrderAction')
            ->willReturn($this->mockAction(['code' => 200, 'total' => 200]));
        $this->userSvc->method('walletChargeAction')
            ->willReturn($this->mockAction(['code' => 500, 'msg' => 'insufficient']));

        $this->saga->onOrderCreateRequested(
            $this->makeOrderRequestedEvent('1', [['p_key' => 1, 'amount' => 2]]),
        );

        $this->assertPublished(OrderCreatedEvent::class, 0);
        $rollback = $this->assertPublished(RollbackInventoryEvent::class, 1);
        $this->assertFalse($rollback['paymentCompleted']);
        $this->assertSame(0, $rollback['total']);
        $this->assertCount(1, $rollback['successfulDeductions']);
    }

    public function testStep3_chargesUserWithCorrectTotal(): void
    {
        $chargedWith = [];
        $this->prodSvc->method('productInfoAction')
            ->willReturn($this->mockAction(['code' => 200, 'data' => ['price' => 100]]));
        $this->stubConcurrentUniform(['code' => 200, 'data' => ['price' => 100]]);
        $this->orderSvc->method('createOrderAction')
            ->willReturn($this->mockAction(['code' => 200, 'total' => 999]));
        $this->userSvc->method('walletChargeAction')
            ->willReturnCallback(function (int $userId, string $orderId, int $total) use (&$chargedWith) {
                $chargedWith = compact('userId', 'orderId', 'total');
                return $this->mockAction(['code' => 200]);
            });
        $this->orderSvc->method('confirmOrderAction')
            ->willReturn($this->mockAction(['code' => 200]));

        $this->saga->onOrderCreateRequested($this->makeOrderRequestedEvent('7'));

        $this->assertSame(7, $chargedWith['userId']);
        $this->assertSame(999, $chargedWith['total']);
        $this->assertNotEmpty($chargedWith['orderId']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Step 4 失敗：訂單確認失敗 → 觸發完整補償（paymentCompleted=true）
    // ═══════════════════════════════════════════════════════════════

    public function testStep4_confirmOrderFails_publishesOrderCreatedThenFullRollback(): void
    {
        $this->prodSvc->method('productInfoAction')
            ->willReturn($this->mockAction(['code' => 200, 'data' => ['price' => 100]]));
        $this->stubConcurrentUniform(['code' => 200, 'data' => ['price' => 100]]);
        $this->orderSvc->method('createOrderAction')
            ->willReturn($this->mockAction(['code' => 200, 'total' => 200]));
        $this->userSvc->method('walletChargeAction')
            ->willReturn($this->mockAction(['code' => 200]));
        $this->orderSvc->method('confirmOrderAction')
            ->willReturn($this->mockAction(['code' => 500, 'msg' => 'db error']));

        $this->saga->onOrderCreateRequested(
            $this->makeOrderRequestedEvent('1', [['p_key' => 1, 'amount' => 2]]),
        );

        $this->assertPublished(OrderCreatedEvent::class, 0);
        $rollback = $this->assertPublished(RollbackInventoryEvent::class, 1);
        $this->assertTrue($rollback['paymentCompleted']);
        $this->assertSame(200, $rollback['total']);
        $this->assertCount(1, $rollback['successfulDeductions']);
    }

    public function testStep4_confirmOrderCallsWithCorrectArgs(): void
    {
        $calledWith = [];
        $this->prodSvc->method('productInfoAction')
            ->willReturn($this->mockAction(['code' => 200, 'data' => ['price' => 100]]));
        $this->stubConcurrentUniform(['code' => 200, 'data' => ['price' => 100]]);
        $this->orderSvc->method('createOrderAction')
            ->willReturn($this->mockAction(['code' => 200, 'total' => 100]));
        $this->userSvc->method('walletChargeAction')
            ->willReturn($this->mockAction(['code' => 200]));
        $this->orderSvc->method('confirmOrderAction')
            ->willReturnCallback(function (int $userId, string $orderId) use (&$calledWith) {
                $calledWith = compact('userId', 'orderId');
                return $this->mockAction(['code' => 200]);
            });

        $this->saga->onOrderCreateRequested($this->makeOrderRequestedEvent('99'));

        $this->assertSame(99, $calledWith['userId']);
        $this->assertNotEmpty($calledWith['orderId']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Compensation: onRollbackInventory
    // ═══════════════════════════════════════════════════════════════

    public function testRollbackInventory_withPayment_refundsAndRestores(): void
    {
        $refundCalled = false;
        $this->userSvc->method('walletCompensateAction')
            ->willReturnCallback(function () use (&$refundCalled) {
                $refundCalled = true;
                return $this->mockAction(['code' => 200]);
            });

        $restoredProducts = [];
        $this->prodSvc->method('addInventoryCompensateAction')
            ->willReturnCallback(function ($pKey, $orderId, $amount) use (&$restoredProducts) {
                $restoredProducts[] = ['p_key' => $pKey, 'amount' => $amount];
                return $this->mockAction(['code' => 200]);
            });

        $event = new RollbackInventoryEvent(
            'order-001', '1',
            [['p_key' => 1, 'amount' => 2], ['p_key' => 3, 'amount' => 1]],
            paymentCompleted: true,
            total: 500,
        );

        $this->saga->onRollbackInventory($event);

        $this->assertTrue($refundCalled, 'Wallet refund should be called when paymentCompleted=true');
        $this->assertSame(
            [['p_key' => 1, 'amount' => 2], ['p_key' => 3, 'amount' => 1]],
            $restoredProducts,
        );
        $payload = $this->assertPublished(RollbackOrderEvent::class);
        $this->assertSame('order-001', $payload['orderId']);
    }

    public function testRollbackInventory_withoutPayment_skipsRefund(): void
    {
        $this->userSvc->expects($this->never())->method('walletCompensateAction');

        $this->prodSvc->method('addInventoryCompensateAction')
            ->willReturn($this->mockAction(['code' => 200]));

        $event = new RollbackInventoryEvent(
            'order-001', '1',
            [['p_key' => 1, 'amount' => 2]],
            paymentCompleted: false,
            total: 0,
        );

        $this->saga->onRollbackInventory($event);

        $this->assertPublished(RollbackOrderEvent::class);
    }

    public function testRollbackInventory_emptyDeductions_skipsRestore(): void
    {
        $this->prodSvc->expects($this->never())->method('addInventoryCompensateAction');

        $event = new RollbackInventoryEvent('order-001', '1', [], false, 0);

        $this->saga->onRollbackInventory($event);

        $this->assertPublished(RollbackOrderEvent::class);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Compensation: onRollbackOrder
    // ═══════════════════════════════════════════════════════════════

    public function testRollbackOrder_callsCompensateOrderAction(): void
    {
        $calledWith = [];
        $this->orderSvc->method('compensateOrderAction')
            ->willReturnCallback(function (int $userId, string $orderId) use (&$calledWith) {
                $calledWith = compact('userId', 'orderId');
                return $this->mockAction(['code' => 200]);
            });

        $event = new RollbackOrderEvent('order-001', '5');
        $this->saga->onRollbackOrder($event);

        $this->assertSame(['userId' => 5, 'orderId' => 'order-001'], $calledWith);
        $this->assertNothingPublished();
    }

    public function testRollbackOrder_failure_doesNotThrow(): void
    {
        $this->orderSvc->method('compensateOrderAction')
            ->willReturn($this->mockAction(['code' => 500, 'msg' => 'not found']));

        $event = new RollbackOrderEvent('order-001', '1');

        $this->saga->onRollbackOrder($event);
        $this->assertNothingPublished();
    }
}
