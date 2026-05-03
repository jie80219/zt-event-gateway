<?php
namespace App\Sagas;
require_once __DIR__ . '/../init.php';
use SDPMlab\Anser\Service\ConcurrentAction;
use SDPMlab\ZtEventGateway\Attributes\EventHandler;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\Saga;

use App\Events\OrderCreateRequestedEvent;
use App\Events\OrderCreatedEvent;
use App\Events\InventoryDeductedEvent;
use App\Events\PaymentProcessedEvent;
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

    public function handle(string $eventType, array $payload): void
    {
        $name = $this->eventName($eventType);

        switch ($name) {
            case 'OrderCreateRequestedEvent':
                $this->onOrderCreateRequested(new OrderCreateRequestedEvent(
                    $payload,
                    isset($payload['traceId']) ? (string) $payload['traceId'] : null
                ));
                return;

            case 'OrderCreatedEvent':
                $this->onOrderCreated(new OrderCreatedEvent(
                    (string) ($payload['orderId'] ?? $payload['o_key'] ?? ''),
                    (string) ($payload['userKey'] ?? $payload['customerId'] ?? $this->userKey),
                    $this->extractProductList($payload),
                    (int) ($payload['total'] ?? $payload['amount'] ?? 0)
                ));
                return;

            case 'InventoryDeductedEvent':
                $this->onInventoryDeducted(new InventoryDeductedEvent(
                    (string) ($payload['orderId'] ?? $payload['o_key'] ?? ''),
                    (string) ($payload['userKey'] ?? $payload['customerId'] ?? $this->userKey),
                    $this->extractProductList($payload),
                    (int) ($payload['total'] ?? $payload['amount'] ?? 0)
                ));
                return;

            case 'PaymentProcessedEvent':
                $this->onPaymentProcessed(new PaymentProcessedEvent(
                    (string) ($payload['orderId'] ?? $payload['o_key'] ?? ''),
                    filter_var($payload['success'] ?? false, FILTER_VALIDATE_BOOL)
                ));
                return;

            case 'RollbackInventoryEvent':
                $this->onRollbackInventory(new RollbackInventoryEvent(
                    (string) ($payload['orderId'] ?? $payload['o_key'] ?? ''),
                    (string) ($payload['userKey'] ?? $payload['customerId'] ?? $this->userKey),
                    is_array($payload['successfulDeductions'] ?? null) ? $payload['successfulDeductions'] : [],
                    filter_var($payload['paymentCompleted'] ?? false, FILTER_VALIDATE_BOOL),
                    (int) ($payload['total'] ?? $payload['amount'] ?? 0)
                ));
                return;

            case 'RollbackOrderEvent':
                $this->onRollbackOrder(new RollbackOrderEvent(
                    (string) ($payload['orderId'] ?? $payload['o_key'] ?? ''),
                    (string) ($payload['userKey'] ?? $payload['customerId'] ?? $this->userKey)
                ));
                return;

            default:
                $this->log("[OrderSaga] skip unsupported event={$name}");
        }
    }

    #[EventHandler]
    public function onOrderCreateRequested(OrderCreateRequestedEvent $event){
        $this->log("Saga Step 1: 收到訂單建立請求");
        $userKey = (string) ($event->orderData['userKey'] ?? $this->userKey);
        $productList = $event->productList;
        // 取得最新價格
        foreach ($productList as &$product) {
            $price = $this->productionService
                ->productInfoAction((int)$product['p_key'])
                ->do()->getMeaningData()['data']['price'] ?? null;
            if (is_int($price) || (is_numeric($price) && $price >= 0)) {
                $product['price'] = (int) $price;
            }
        }
        unset($product);
        $this->generateProductList($productList);
        // 產生 orderId
        $orderId = $this->generateOrderId();
        // 新增訂單
        $info = $this->orderService
            ->createOrderAction($userKey, $orderId, $this->productList)
            ->do()->getMeaningData();
        $total = $info['total'] ?? array_sum(array_map(
            static fn(OrderProductDetail $p) => $p->price * $p->amount,
            $this->productList
        ));
        if ($total <= 0) {
            $this->log("[!] 警告：total 計算為 0，請檢查商品價格與數量");
        }
        $this->log("[x] 訂單建立成功");
        $this->publish(OrderCreatedEvent::class, [
            'orderId' => $orderId,
            'userKey' => $userKey,
            'productList' => $this->productList,
            'total' => $total
        ]);
    }

    #[EventHandler]
    public function onOrderCreated(OrderCreatedEvent $event)
    {
        $this->log("Saga Step 2: 訂單建立，開始扣庫存");

        $successfulDeductions = [];
        $inventoryFailed = false;
       
        $concurrent = new ConcurrentAction();
        $actions = [];

        foreach ($event->productList as $index => $product) {
            $actions["product_{$index}"] = $this->productionService->reduceInventory($product['p_key'], $event->orderId, $product['amount']);
        }

        $concurrent->setActions($actions)->send();

        // getActionsMeaningData() returns alias => meaningData (already-resolved arrays)
        foreach ($concurrent->getActionsMeaningData() as $alias => $info) {
            $index = (int) substr((string) $alias, strlen('product_'));
            if ($this->isSuccess(is_array($info) ? $info : [])) {
                $successfulDeductions[] = $event->productList[$index];
            } else {
                $inventoryFailed = true;
            }
        }

        if ($inventoryFailed) {
            $this->log("[x] 扣減庫存失敗，發起補償");
            $this->compensate(RollbackInventoryEvent::class, [
                'orderId' => $event->orderId,
                'userKey' => $event->userKey,
                'successfulDeductions' => $successfulDeductions,
                'paymentCompleted' => false,
                'total' => $event->total,
            ]);
            return;
        }

        $this->log("[x] 扣減庫存成功");
        $this->publish(InventoryDeductedEvent::class, [
            'orderId' => $event->orderId,
            'userKey' => $event->userKey,
            'productList' => $successfulDeductions,
            'total' => $event->total
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
			#發送回滾訊息
            $this->compensate(RollbackInventoryEvent::class, [
                'orderId' => $event->orderId,
                'userKey' => $event->userKey,
                'successfulDeductions' => $event->productList,
                'paymentCompleted' => false,
                'total' => $event->total
            ]);
            return;
        }
        $this->log("[x] 支付成功");
		#下一步訊息
        $this->publish(PaymentProcessedEvent::class, [
            'orderId' => $event->orderId,
            'success' => true
        ]);
    }

    #[EventHandler]
    public function onPaymentProcessed(PaymentProcessedEvent $event)
    {
        if ($event->success) {
            $this->log("✅ Saga Step 4: 訂單完成！");
        }
    }

    #[EventHandler]
    public function onRollbackInventory(RollbackInventoryEvent $event)
    {
        $this->log("RollbackSaga Step 2: 回滾已扣減庫存");
        if ($event->paymentCompleted && $event->total > 0) {
            $this->userService
                ->walletCompensateAction($event->userKey, $event->orderId, $event->total)
                ->do()->getMeaningData();
        }
		#進行回滾
        foreach ($event->successfulDeductions as $product) {
            $info = $this->productionService
			->addInventoryCompensateAction
			($product['p_key'], $event->orderId, $product['amount']
			)->do()->getMeaningData(); 
        }
		#發送下一個訊息
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

        if ($this->isSuccess($info)) {
            $this->log("✅ 訂單取消成功");
        } else {
            $this->log("❌ 訂單取消失敗");
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

    private function eventName(string $eventType): string
    {
        $pos = strrpos($eventType, '\\');

        return $pos === false ? $eventType : substr($eventType, $pos + 1);
    }

    private function extractProductList(array $payload): array
    {
        $productList = $payload['productList'] ?? $payload['product_detail'] ?? $payload['product_list'] ?? [];

        return is_array($productList) ? array_values($productList) : [];
    }
}
