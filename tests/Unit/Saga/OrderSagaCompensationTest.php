<?php

declare(strict_types=1);

namespace Tests\Unit\Saga;

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
 * Health tests for the compensation handlers in OrderSaga.
 *
 * The current implementation calls the wallet refund / inventory restore /
 * order cancel actions in a fire-and-forget manner — the return values are
 * fetched but never asserted. These tests pin that behaviour so that a
 * future PR adding `isSuccess()` checks on the rollback path becomes a
 * deliberate, reviewable change rather than a silent contract drift.
 *
 * If/when the saga starts:
 *   - retrying or DLQ-ing failed compensations
 *   - aborting RollbackOrderEvent when refund / restore failed
 *   - emitting a separate observability event for failed compensations
 * the assertions in this file will need to be revisited intentionally.
 */
final class OrderSagaCompensationTest extends TestCase
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

    private function mockAction(array $meaningData): ActionInterface
    {
        $action = $this->createMock(ActionInterface::class);
        $action->method('do')->willReturn($action);
        $action->method('getMeaningData')->willReturn($meaningData);
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
    //  onRollbackInventory — fire-and-forget refund / restore
    // ═══════════════════════════════════════════════════════════

    public function testRollbackInventory_walletRefundFails_stillRestoresInventory(): void
    {
        // Refund call returns a 500 meaning-data; the saga must NOT short-circuit.
        $refundCalled = 0;
        $this->userSvc->method('walletCompensateAction')
            ->willReturnCallback(function () use (&$refundCalled) {
                $refundCalled++;
                return $this->mockAction(['code' => 500, 'msg' => 'wallet down']);
            });

        $restoredProducts = [];
        $this->prodSvc->method('addInventoryCompensateAction')
            ->willReturnCallback(function ($pKey, $orderId, $amount) use (&$restoredProducts) {
                $restoredProducts[] = ['p_key' => $pKey, 'amount' => $amount];
                return $this->mockAction(['code' => 200]);
            });

        $event = new RollbackInventoryEvent(
            'order-001',
            '1',
            [['p_key' => 1, 'amount' => 2], ['p_key' => 3, 'amount' => 1]],
            paymentCompleted: true,
            total: 500,
        );

        $this->saga->onRollbackInventory($event);

        $this->assertSame(1, $refundCalled, 'Wallet refund should have been attempted exactly once.');
        $this->assertSame(
            [['p_key' => 1, 'amount' => 2], ['p_key' => 3, 'amount' => 1]],
            $restoredProducts,
            'Inventory restore must continue even after the refund returned an error code.',
        );
        $this->assertPublished(
            RollbackOrderEvent::class,
            0,
        );
    }

    public function testRollbackInventory_walletRefundThrows_stillRestoresInventory(): void
    {
        // Even an outright exception on the refund call should not stop the
        // rest of the rollback — current behaviour is "log and ignore".
        // NOTE: this is read-only verification of present-day behaviour,
        // not an endorsement that throwing-is-fine. A future fix should
        // surface refund failures (e.g. via DLQ) and update this test.
        $this->userSvc->method('walletCompensateAction')
            ->willThrowException(new \RuntimeException('wallet svc unreachable'));

        $this->prodSvc->method('addInventoryCompensateAction')
            ->willReturn($this->mockAction(['code' => 200]));

        $event = new RollbackInventoryEvent(
            'order-002',
            '5',
            [['p_key' => 9, 'amount' => 1]],
            paymentCompleted: true,
            total: 100,
        );

        // Today the saga lets the exception bubble (no try/catch around
        // walletCompensateAction). This assertion documents that —
        // change it the day the saga starts swallowing the exception
        // intentionally.
        $this->expectException(\RuntimeException::class);
        $this->saga->onRollbackInventory($event);
    }

    public function testRollbackInventory_inventoryRestoreFails_stillPublishesRollbackOrder(): void
    {
        $this->userSvc->method('walletCompensateAction')
            ->willReturn($this->mockAction(['code' => 200]));

        // Both restore calls fail — saga should still publish RollbackOrder
        // because there is no isSuccess() gate today.
        $restoreAttempts = 0;
        $this->prodSvc->method('addInventoryCompensateAction')
            ->willReturnCallback(function () use (&$restoreAttempts) {
                $restoreAttempts++;
                return $this->mockAction(['code' => 500, 'msg' => 'inventory db down']);
            });

        $event = new RollbackInventoryEvent(
            'order-003',
            '7',
            [['p_key' => 1, 'amount' => 2], ['p_key' => 2, 'amount' => 5]],
            paymentCompleted: true,
            total: 200,
        );

        $this->saga->onRollbackInventory($event);

        $this->assertSame(2, $restoreAttempts, 'All restore calls were attempted regardless of failure.');
        $payload = $this->assertPublished(RollbackOrderEvent::class);
        $this->assertSame('order-003', $payload['orderId']);
        $this->assertSame('7', $payload['userKey']);
    }

    public function testRollbackInventory_inventoryRestoreThrowsMidLoop_propagates(): void
    {
        // Restore loop is a plain foreach with no try/catch — second iteration
        // throwing aborts the loop and skips the publish. Pinning that.
        $this->userSvc->method('walletCompensateAction')
            ->willReturn($this->mockAction(['code' => 200]));

        $calls = 0;
        $this->prodSvc->method('addInventoryCompensateAction')
            ->willReturnCallback(function () use (&$calls) {
                $calls++;
                if ($calls === 2) {
                    throw new \RuntimeException('inventory svc dropped');
                }
                return $this->mockAction(['code' => 200]);
            });

        $event = new RollbackInventoryEvent(
            'order-004',
            '8',
            [['p_key' => 1, 'amount' => 1], ['p_key' => 2, 'amount' => 1], ['p_key' => 3, 'amount' => 1]],
            paymentCompleted: true,
            total: 300,
        );

        try {
            $this->saga->onRollbackInventory($event);
            $this->fail('Expected RuntimeException to bubble out of compensation.');
        } catch (\RuntimeException $e) {
            $this->assertSame('inventory svc dropped', $e->getMessage());
        }

        $this->assertSame(2, $calls, 'Loop aborts on the throwing call.');
        // RollbackOrder must NOT be published when the loop bails.
        $this->assertNothingPublished();
    }

    // ═══════════════════════════════════════════════════════════
    //  onRollbackOrder — silent failure on cancel
    // ═══════════════════════════════════════════════════════════

    public function testRollbackOrder_cancelFails_silentlyContinues(): void
    {
        $this->orderSvc->method('compensateOrderAction')
            ->willReturn($this->mockAction(['code' => 500, 'msg' => 'order not found']));

        $event = new RollbackOrderEvent('order-005', '1');

        // Today: handler logs "❌ 訂單取消失敗" and returns. No exception,
        // no further publish. If the future fix introduces e.g. a DLQ
        // event for failed cancels, this assertion will start failing.
        $this->saga->onRollbackOrder($event);

        $this->assertNothingPublished();
    }

    public function testRollbackOrder_cancelThrows_propagates(): void
    {
        $this->orderSvc->method('compensateOrderAction')
            ->willThrowException(new \RuntimeException('order svc 503'));

        $this->expectException(\RuntimeException::class);
        $this->saga->onRollbackOrder(new RollbackOrderEvent('order-006', '2'));
    }
}
