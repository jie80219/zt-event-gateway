<?php
namespace App\Sagas;
require_once __DIR__ . '/../init.php';
use SDPMlab\Anser\Service\ConcurrentAction;
use SDPMlab\AnserEDA\Attributes\EventHandler;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\Saga;

use App\Events\OrderCreateRequestedEvent;
use App\Events\OrderCreatedEvent;
use App\Events\InventoryDeductedEvent;
use App\Events\PaymentProcessedEvent;
use App\Events\OrderCompletedEvent;
use App\Events\OrderSagaCompletedEvent;
use App\Events\RollbackOrderEvent;
use App\Events\RollbackInventoryEvent;

use Services\UserService;
use Services\OrderService;
use Services\ProductionService;
use Services\Models\OrderProductDetail;

class OrderSaga extends Saga{
    private UserService $userService;
    private OrderService $orderService;
    private ProductionService $productionService;
    private string $userKey = '1';
    private string $orderId;
    private array $productList = [];
    public function __construct(EventBus $eventBus){
        parent::__construct($eventBus);
        $this->userService = new UserService();
        $this->orderService = new OrderService();
        $this->productionService = new ProductionService();
    }

    #[EventHandler]
    public function onOrderCreateRequested(OrderCreateRequestedEvent $event){
        $this->log("Saga Step 1: 收到訂單建立請求");
        $productList = $event->productList;
        // 取得最新價格
        foreach ($productList as &$product) {
            $info = $this->productionService
                ->productInfoAction((int)$product['p_key'])
                ->do()->getMeaningData();
            if (!is_array($info) || !$this->isSuccess($info)) {
                $this->log("[x] 商品資訊查詢失敗，中止 Step 1");
                return;
            }
            $price = $info['data']['price'] ?? null;
            if (is_numeric($price)) {
                $product['price'] = (int) $price;
            }
        }
        unset($product);
        $this->generateProductList($productList);
        // 產生 orderId
        $orderId = $this->generateOrderId();
        if (getenv('PERF_METRIC_ENABLED') === '1') {
            fwrite(STDOUT, sprintf(
                "[perf-saga-step1] ts=%.6f orderId=%s traceId=%s\n",
                microtime(true),
                $orderId,
                $event->getTraceId() ?? ''
            ));
        }
        // 新增訂單
        $info = $this->orderService
            ->createOrderAction((int) $this->userKey, $orderId, $this->productList)
            ->do()->getMeaningData();
        if (!is_array($info) || !$this->isSuccess($info)) {
            $this->log("[x] 訂單建立失敗，中止 Step 1");
            return;
        }
        $total = isset($info['total']) ? (int) $info['total'] : $this->calculateTotal($this->productList);
        $this->log("[x] 訂單建立成功");
          // 發送下一步消息
        $this->publish(OrderCreatedEvent::class, [
            'orderId' => $orderId,
            'userKey' => $this->userKey,
            'productList' => $this->productList,
            'total' => $total
        ]);
    }

    private function calculateTotal(array $productList): int
    {
        $total = 0;
        foreach ($productList as $p) {
            if ($p instanceof OrderProductDetail) {
                $total += $p->price * $p->amount;
            } elseif (is_array($p)) {
                $total += ((int) ($p['price'] ?? 0)) * ((int) ($p['amount'] ?? 0));
            }
        }
        return $total;
    }

    #[EventHandler]
    public function onOrderCreated(OrderCreatedEvent $event)
    {
        $this->log("Saga Step 2: 訂單建立，開始扣庫存");

        $concurrent = new ConcurrentAction();
        $actions = [];
        $keyToIndex = [];
        foreach ($event->productList as $index => $product) {
            $key = "product_{$index}";
            $actions[$key] = $this->productionService
                ->reduceInventory($product['p_key'], $event->orderId, $product['amount']);
            $keyToIndex[$key] = $index;
        }

        $concurrent->setActions($actions)->send();
        $results = $concurrent->getActionsMeaningData();

        $successfulDeductions = [];
        $inventoryFailed = false;
        foreach ($results as $key => $info) {
            if (is_array($info) && $this->isSuccess($info)) {
                $successfulDeductions[] = $event->productList[$keyToIndex[$key]];
            } else {
                $inventoryFailed = true;
            }
        }

        if ($inventoryFailed) {
            $this->log("[x] 扣減庫存失敗，觸發補償");
            $this->compensate(RollbackInventoryEvent::class, [
                'orderId'              => $event->orderId,
                'userKey'              => $event->userKey,
                'successfulDeductions' => $successfulDeductions,
                'paymentCompleted'     => false,
                'total'                => 0,
            ]);
            return;
        }

        $this->log("[x] 扣減庫存成功");
        $this->publish(InventoryDeductedEvent::class, [
            'orderId'     => $event->orderId,
            'userKey'     => $event->userKey,
            'productList' => $successfulDeductions,
            'total'       => $event->total,
        ]);
    }

    #[EventHandler]
    public function onInventoryDeducted(InventoryDeductedEvent $event)
    {
        $this->log("Saga Step 3: 開始支付");
        $info = $this->userService
		->walletChargeAction
		($event->userKey, $event->orderId, $event->total)
		->do()->getMeaningData();
        if (!$this->isSuccess($info)) {
            $this->log("[x] 支付失敗，開始回滾");
            $this->compensate(RollbackInventoryEvent::class, [
                'orderId' => $event->orderId,
                'userKey' => $event->userKey,
                'successfulDeductions' => $event->productList,
                'paymentCompleted' => false,
                'total' => 0,
            ]);
            return;
        }
        $this->log("[x] 支付成功");
        $this->publish(PaymentProcessedEvent::class, [
            'orderId'     => $event->orderId,
            'success'     => $this->isSuccess($info),
            'userKey'     => $event->userKey,
            'total'       => $event->total,
            'productList' => $event->productList,
        ]);
    }

    #[EventHandler]
    public function onPaymentProcessed(PaymentProcessedEvent $event)
    {
        if (!$event->success) {
            $this->compensate(RollbackInventoryEvent::class, [
                'orderId' => $event->orderId,
                'userKey' => $event->userKey,
                'successfulDeductions' => $event->productList,
                'paymentCompleted' => false,
                'total' => 0,
            ]);
            return;
        }

        $info = $this->orderService
            ->confirmOrderAction((int)$event->userKey, $event->orderId)
            ->do()->getMeaningData();

        if (!$this->isSuccess($info)) {
            $this->compensate(RollbackInventoryEvent::class, [
                'orderId' => $event->orderId,
                'userKey' => $event->userKey,
                'successfulDeductions' => $event->productList,
                'paymentCompleted' => true,
                'total' => $event->total,
            ]);
            return;
        }

        $this->log("✅ Saga Step 4: 訂單完成！");
        if (getenv('PERF_METRIC_ENABLED') === '1') {
            fwrite(STDOUT, sprintf(
                "[perf-saga-complete] ts=%.6f orderId=%s\n",
                microtime(true),
                $event->orderId
            ));
        }
        $this->publish(OrderSagaCompletedEvent::class, [
            'orderId' => $event->orderId,
            'userKey' => $event->userKey,
            'total' => $event->total,
            'status' => 'completed',
        ]);
    }

    #[EventHandler]
    public function onOrderSagaCompleted(OrderSagaCompletedEvent $event)
    {
        $this->log("✅ Saga 完成: orderId={$event->orderId}");
    }

    #[EventHandler]
    public function onRollbackInventory(RollbackInventoryEvent $event)
    {
        $this->log("RollbackSaga Step 2: 回滾已扣減庫存");

        if ($event->paymentCompleted) {
            $this->userService->walletCompensateAction(
                (int)$event->userKey, $event->orderId, $event->total
            )->do()->getMeaningData();
        }

        foreach ($event->successfulDeductions as $product) {
            $this->productionService
                ->addInventoryCompensateAction($product['p_key'], $event->orderId, $product['amount'])
                ->do()->getMeaningData();
        }

        $this->publish(RollbackOrderEvent::class, [
            'orderId' => $event->orderId,
            'userKey' => $event->userKey
        ]);
    }


    #[EventHandler]
    public function onRollbackOrder(RollbackOrderEvent $event)
    {
        $this->log("❌ RollbackSaga Step 1: 取消訂單");

        $info = $this->orderService
            ->compensateOrderAction($event->userKey, $event->orderId)->do()->getMeaningData();

        $outcome = $this->isSuccess($info) ? 'success' : 'fail';
        if ($outcome === 'success') {
            $this->log("✅ 訂單取消成功");
        } else {
            $this->log("❌ 訂單取消失敗");
        }

        if (getenv('PERF_METRIC_ENABLED') === '1') {
            fwrite(STDOUT, sprintf(
                "[perf-saga-rolled-back] ts=%.6f orderId=%s outcome=%s\n",
                microtime(true),
                $event->orderId,
                $outcome
            ));
        }
    }

    private function generateProductList(array $data): void
    {
        $this->productList = array_map(function ($product) {
            return new OrderProductDetail(
                p_key: $product['p_key'],
                price: (int)($product['price'] ?? 0),
                amount: $product['amount']
            );
        }, $data);
    }

    public function generateOrderId(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}
