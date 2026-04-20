<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Events\InventoryDeductedEvent;
use App\Events\OrderCreatedEvent;
use App\Events\OrderCreateRequestedEvent;
use App\Events\OrderSagaCompletedEvent;
use App\Events\PaymentProcessedEvent;
use App\Events\RollbackInventoryEvent;
use App\Events\RollbackOrderEvent;
use PHPUnit\Framework\TestCase;

class EventClassTest extends TestCase
{
    // ── OrderCreateRequestedEvent ───────────────────────────

    public function testOrderCreateRequestedEventExtractsProductList(): void
    {
        $event = new OrderCreateRequestedEvent([
            'userKey' => '1',
            'productList' => [['p_key' => 1, 'amount' => 2]],
        ]);

        $this->assertSame([['p_key' => 1, 'amount' => 2]], $event->productList);
    }

    public function testOrderCreateRequestedEventExtractsProductUnderscoreList(): void
    {
        $event = new OrderCreateRequestedEvent([
            'userKey' => '1',
            'product_list' => [['p_key' => 3, 'amount' => 1]],
        ]);

        $this->assertSame([['p_key' => 3, 'amount' => 1]], $event->productList);
    }

    public function testOrderCreateRequestedEventFallsBackToOrderData(): void
    {
        // When neither productList nor product_list exists, falls back to full orderData
        $data = [['p_key' => 5, 'amount' => 10]];
        $event = new OrderCreateRequestedEvent($data);

        $this->assertSame($data, $event->productList);
    }

    public function testOrderCreateRequestedEventGetTraceId(): void
    {
        $event = new OrderCreateRequestedEvent(['userKey' => '1'], 'trace-123');
        $this->assertSame('trace-123', $event->getTraceId());
    }

    public function testOrderCreateRequestedEventTraceIdDefaultsToNull(): void
    {
        $event = new OrderCreateRequestedEvent(['userKey' => '1']);
        $this->assertNull($event->getTraceId());
    }

    public function testOrderCreateRequestedEventGetDataReturnsOrderData(): void
    {
        $data = ['userKey' => '1', 'productList' => []];
        $event = new OrderCreateRequestedEvent($data);
        $this->assertSame($data, $event->getData());
    }

    // ── OrderCreatedEvent ───────────────────────────────────

    public function testOrderCreatedEventSetsProperties(): void
    {
        $products = [['p_key' => 1, 'amount' => 2, 'price' => 100]];
        $event = new OrderCreatedEvent('order-001', '5', $products, 200);

        $this->assertSame('order-001', $event->orderId);
        $this->assertSame('5', $event->userKey);
        $this->assertSame($products, $event->productList);
        $this->assertSame(200, $event->total);
    }

    // ── InventoryDeductedEvent ──────────────────────────────

    public function testInventoryDeductedEventSetsProperties(): void
    {
        $event = new InventoryDeductedEvent('order-002', '3', [], 500);

        $this->assertSame('order-002', $event->orderId);
        $this->assertSame('3', $event->userKey);
        $this->assertSame([], $event->productList);
        $this->assertSame(500, $event->total);
    }

    // ── PaymentProcessedEvent ───────────────────────────────

    public function testPaymentProcessedEventSetsAllProperties(): void
    {
        $products = [['p_key' => 1, 'amount' => 2]];
        $event = new PaymentProcessedEvent('order-003', true, '7', 999, $products);

        $this->assertSame('order-003', $event->orderId);
        $this->assertTrue($event->success);
        $this->assertSame('7', $event->userKey);
        $this->assertSame(999, $event->total);
        $this->assertSame($products, $event->productList);
    }

    public function testPaymentProcessedEventUsesDefaults(): void
    {
        $event = new PaymentProcessedEvent('order-004', false);

        $this->assertSame('order-004', $event->orderId);
        $this->assertFalse($event->success);
        $this->assertSame('', $event->userKey);
        $this->assertSame(0, $event->total);
        $this->assertSame([], $event->productList);
    }

    // ── OrderSagaCompletedEvent ─────────────────────────────

    public function testOrderSagaCompletedEventSetsProperties(): void
    {
        $event = new OrderSagaCompletedEvent('order-005', '1', 300, 'completed');

        $this->assertSame('order-005', $event->orderId);
        $this->assertSame('1', $event->userKey);
        $this->assertSame(300, $event->total);
        $this->assertSame('completed', $event->status);
    }

    public function testOrderSagaCompletedEventDefaultStatus(): void
    {
        $event = new OrderSagaCompletedEvent('order-006', '2', 100);
        $this->assertSame('completed', $event->status);
    }

    // ── RollbackInventoryEvent ──────────────────────────────

    public function testRollbackInventoryEventSetsAllProperties(): void
    {
        $deductions = [['p_key' => 1, 'amount' => 2]];
        $event = new RollbackInventoryEvent('order-007', '3', $deductions, true, 500);

        $this->assertSame('order-007', $event->orderId);
        $this->assertSame('3', $event->userKey);
        $this->assertSame($deductions, $event->successfulDeductions);
        $this->assertTrue($event->paymentCompleted);
        $this->assertSame(500, $event->total);
    }

    public function testRollbackInventoryEventUsesDefaults(): void
    {
        $event = new RollbackInventoryEvent('order-008', '1', []);

        $this->assertFalse($event->paymentCompleted);
        $this->assertSame(0, $event->total);
    }

    // ── RollbackOrderEvent ──────────────────────────────────

    public function testRollbackOrderEventSetsProperties(): void
    {
        $event = new RollbackOrderEvent('order-009', '5');

        $this->assertSame('order-009', $event->orderId);
        $this->assertSame('5', $event->userKey);
    }
}
