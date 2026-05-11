<?php
namespace App\Sagas;
require_once __DIR__ . '/../init.php';
use SDPMlab\Anser\Service\ConcurrentAction;
use SDPMlab\AnserEDA\Attributes\EventHandler;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\Saga;

use App\Events\OrderCreateRequestedEvent;
use App\Events\OrderCreatedEvent;
use App\Events\OrderSagaCompletedEvent;
use App\Events\RollbackOrderEvent;
use App\Events\RollbackInventoryEvent;

use Services\UserService;
use Services\OrderService;
use Services\ProductionService;
use Services\Models\OrderProductDetail;

/**
 * OrderSaga — orchestrator mode.
 *
 * Step 1→4 跑在同一個 handler 內，避免每步走一次 AMQP 跳轉。
 * 仍 publish `OrderCreatedEvent` 與 `OrderSagaCompletedEvent` 供 EventStore
 * projection 計算訂單處理時間；中間態（庫存扣減、付款完成）只在 saga 內部
 * 流轉，不再經過 broker。補償路徑保留事件鏈以便持久化。
 */
class OrderSaga extends Saga{
    private UserService $userService;
    private OrderService $orderService;
    private ProductionService $productionService;
    private string $userKey = '1';
    private array $productList = [];
    public function __construct(EventBus $eventBus){
        parent::__construct($eventBus);
        $this->userService = new UserService();
        $this->orderService = new OrderService();
        $this->productionService = new ProductionService();
    }

    #[EventHandler]
    public function onOrderCreateRequested(OrderCreateRequestedEvent $event): void
    {
        $this->log("Saga: 收到訂單請求，進入 orchestrator");

        // 從 envelope 讀 userKey；step 3/4 內用 $this->userKey 呼叫下游。
        $orderData = $event->orderData ?? [];
        $incomingUserKey = $orderData['userKey'] ?? null;
        if (is_string($incomingUserKey) && $incomingUserKey !== '') {
            $this->userKey = $incomingUserKey;
        } elseif (is_int($incomingUserKey)) {
            $this->userKey = (string) $incomingUserKey;
        }

        // ── Step 1: 並行查價 + 建單 ──
        $step1 = $this->doStep1($event->productList, $event->getTraceId() ?? '');
        if ($step1 === null) {
            return;
        }
        [$orderId, $total] = $step1;

        // EventStore projection 依賴 OrderCreatedEvent 的 timestamp 作為
        // 訂單處理時間的起點，所以這一筆仍走 publish (AMQP + EventStore)。
        $this->publish(OrderCreatedEvent::class, [
            'orderId'     => $orderId,
            'userKey'     => $this->userKey,
            'productList' => $this->productList,
            'total'       => $total,
        ]);

        // ── Step 2: 並行扣庫存 ──
        $step2 = $this->doStep2($orderId);
        if (!$step2['ok']) {
            $this->compensate(RollbackInventoryEvent::class, [
                'orderId'              => $orderId,
                'userKey'              => $this->userKey,
                'successfulDeductions' => $step2['successful'],
                'paymentCompleted'     => false,
                'total'                => 0,
            ]);
            return;
        }
        $successfulDeductions = $step2['successful'];

        // ── Step 3: 扣款 ──
        if (!$this->doStep3($orderId, $total)) {
            $this->compensate(RollbackInventoryEvent::class, [
                'orderId'              => $orderId,
                'userKey'              => $this->userKey,
                'successfulDeductions' => $successfulDeductions,
                'paymentCompleted'     => false,
                'total'                => 0,
            ]);
            return;
        }

        // ── Step 4: 確認訂單 ──
        if (!$this->doStep4($orderId)) {
            $this->compensate(RollbackInventoryEvent::class, [
                'orderId'              => $orderId,
                'userKey'              => $this->userKey,
                'successfulDeductions' => $successfulDeductions,
                'paymentCompleted'     => true,
                'total'                => $total,
            ]);
            return;
        }

        $this->log("✅ Saga Step 4: 訂單完成！");
        if (getenv('PERF_METRIC_ENABLED') === '1') {
            fwrite(STDOUT, sprintf(
                "[perf-saga-complete] ts=%.6f orderId=%s\n",
                microtime(true),
                $orderId
            ));
        }

        $this->publish(OrderSagaCompletedEvent::class, [
            'orderId' => $orderId,
            'userKey' => $this->userKey,
            'total'   => $total,
            'status'  => 'completed',
        ]);
    }

    /**
     * Step 1: 並行查商品價格 + 建單。
     *
     * @return array{0:string,1:int}|null  [orderId, total]，失敗則 null
     */
    protected function doStep1(array $productList, string $traceId): ?array
    {
        $this->log("Saga Step 1: 查商品價格 + 建單");

        // 並行查價（取代原本的 foreach 同步序列呼叫）
        $actions = [];
        $keyToIndex = [];
        foreach ($productList as $index => $product) {
            $key = "info_{$index}";
            $actions[$key] = $this->productionService->productInfoAction((int) $product['p_key']);
            $keyToIndex[$key] = $index;
        }
        $results = $this->runConcurrent($actions);

        foreach ($results as $key => $info) {
            if (!is_array($info) || !$this->isSuccess($info)) {
                $this->log("[x] 商品資訊查詢失敗，中止 Step 1");
                return null;
            }
            $price = $info['data']['price'] ?? null;
            if (is_numeric($price)) {
                $productList[$keyToIndex[$key]]['price'] = (int) $price;
            }
        }

        $this->generateProductList($productList);
        $orderId = $this->generateOrderId();

        if (getenv('PERF_METRIC_ENABLED') === '1') {
            fwrite(STDOUT, sprintf(
                "[perf-saga-step1] ts=%.6f orderId=%s traceId=%s\n",
                microtime(true),
                $orderId,
                $traceId
            ));
        }

        $info = $this->orderService
            ->createOrderAction((int) $this->userKey, $orderId, $this->productList)
            ->do()->getMeaningData();
        if (!is_array($info) || !$this->isSuccess($info)) {
            $this->log("[x] 訂單建立失敗，中止 Step 1");
            return null;
        }
        $total = isset($info['total']) ? (int) $info['total'] : $this->calculateTotal($this->productList);
        $this->log("[x] 訂單建立成功");

        return [$orderId, $total];
    }

    /**
     * Step 2: 並行扣庫存。
     *
     * @return array{ok:bool,successful:list<array>}
     */
    protected function doStep2(string $orderId): array
    {
        $this->log("Saga Step 2: 扣庫存");

        $actions = [];
        $keyToIndex = [];
        foreach ($this->productList as $index => $product) {
            $key = "ded_{$index}";
            $pKey = $product instanceof OrderProductDetail ? $product->p_key : $product['p_key'];
            $amount = $product instanceof OrderProductDetail ? $product->amount : $product['amount'];
            $actions[$key] = $this->productionService->reduceInventory($pKey, $orderId, $amount);
            $keyToIndex[$key] = $index;
        }
        $results = $this->runConcurrent($actions);

        $successful = [];
        $failed = false;
        foreach ($results as $key => $info) {
            $idx = $keyToIndex[$key];
            $product = $this->productList[$idx];
            $row = $product instanceof OrderProductDetail
                ? ['p_key' => $product->p_key, 'amount' => $product->amount, 'price' => $product->price]
                : $product;
            if (is_array($info) && $this->isSuccess($info)) {
                $successful[] = $row;
            } else {
                $failed = true;
            }
        }

        if ($failed) {
            $this->log("[x] 扣減庫存失敗，觸發補償");
            return ['ok' => false, 'successful' => $successful];
        }
        $this->log("[x] 扣減庫存成功");
        return ['ok' => true, 'successful' => $successful];
    }

    protected function doStep3(string $orderId, int $total): bool
    {
        $this->log("Saga Step 3: 開始支付");
        $info = $this->userService
            ->walletChargeAction((int) $this->userKey, $orderId, $total)
            ->do()->getMeaningData();
        if (!is_array($info) || !$this->isSuccess($info)) {
            $this->log("[x] 支付失敗，開始回滾");
            return false;
        }
        $this->log("[x] 支付成功");
        return true;
    }

    protected function doStep4(string $orderId): bool
    {
        $info = $this->orderService
            ->confirmOrderAction((int) $this->userKey, $orderId)
            ->do()->getMeaningData();
        return is_array($info) && $this->isSuccess($info);
    }

    /**
     * 抽出 ConcurrentAction 執行步驟，方便測試覆寫。
     *
     * @param array<string, \SDPMlab\Anser\Service\ActionInterface> $actions
     * @return array<string, mixed>
     */
    protected function runConcurrent(array $actions): array
    {
        $concurrent = new ConcurrentAction();
        $concurrent->setActions($actions)->send();
        return $concurrent->getActionsMeaningData();
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
    public function onRollbackInventory(RollbackInventoryEvent $event)
    {
        $this->log("RollbackSaga Step 2: 回滾已扣減庫存");

        // 退款（單筆，本身已最低延遲）
        if ($event->paymentCompleted) {
            $this->userService->walletCompensateAction(
                (int)$event->userKey, $event->orderId, $event->total
            )->do()->getMeaningData();
        }

        // 並行補償庫存（與 Step 2 對稱：扣庫存並行，補償也並行）
        if (!empty($event->successfulDeductions)) {
            $actions = [];
            foreach ($event->successfulDeductions as $index => $product) {
                $key = "comp_{$index}";
                $actions[$key] = $this->productionService->addInventoryCompensateAction(
                    $product['p_key'], $event->orderId, $product['amount']
                );
            }
            $this->runConcurrent($actions);
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
