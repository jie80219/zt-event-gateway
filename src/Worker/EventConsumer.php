<?php

declare(strict_types=1);

namespace ZtEventGateway\Worker;

use PhpAmqpLib\Message\AMQPMessage;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\MessageQueue\UnrecoverableMessageException;

/**
 * 事件消費者（Event Consumer）
 *
 * 從 RabbitMQ 事件佇列接收已由 Saga 發出的事件，負責：
 *   1. 反序列化 envelope 並檢查基本欄位（type / data）。
 *   2. 把 envelope 還原為事件物件並交由 EventBus 分派至對應的 handler。
 *
 * 服務身份由傳輸層的 Vault PKI mTLS 建立，因此此處不做任何應用層的身份
 * 驗證 — 沒有 SPIFFE trust-domain 檢查、沒有 LSVID 鏈驗證、也沒有
 * Keycloak JWT 驗證；只保留 envelope 的結構驗證。
 */
final class EventConsumer
{
    /**
     * 建構子。
     *
     * @param EventBus $eventBus 事件總線，負責把事件分派給已註冊的 handler。
     */
    public function __construct(
        private readonly EventBus $eventBus,
    ) {
    }

    /**
     * 處理單一 AMQP 事件訊息。
     *
     * 此方法是事件流程的結構防線：任何不符合 schema 的訊息都會以
     * {@see UnrecoverableMessageException} 中斷處理，讓上層 Consumer 直接
     * ack / dead-letter 而不是 requeue，避免壞訊息造成 requeue storm。
     */
    public function process(AMQPMessage $message): void
    {
        // 1. 解析 envelope 的 JSON 主體。
        //    非法 JSON 一律視為結構錯誤，直接丟出 unrecoverable 例外。
        $payload = json_decode($message->getBody(), true);
        if (!is_array($payload)) {
            throw new UnrecoverableMessageException('Invalid event payload.');
        }

        // 2. 檢查最基本的必填欄位：事件類別與事件資料。
        $eventType = $payload['type'] ?? null;
        $eventData = $payload['data'] ?? null;

        if (!is_string($eventType) || !is_array($eventData)) {
            throw new UnrecoverableMessageException('Missing event type or data.');
        }

        // 3. 把 envelope 還原為對應的事件物件。
        //    若類別不存在（例如路由到未知的事件類別），直接當成結構錯誤丟棄。
        $event = $this->buildEventInstance($eventType, $eventData);
        if ($event === null) {
            throw new UnrecoverableMessageException(sprintf('Unknown event class: %s', $eventType));
        }

        // 4. 分派事件。
        $this->eventBus->dispatch($event);

        fwrite(STDOUT, sprintf("[event-consumer] handled event=%s\n", $eventType));
    }

    /**
     * 把事件資料反序列化為具體的事件物件。
     *
     * 支援兩種路徑：
     *   1. 對於已知的特殊事件（目前為 OrderCreateRequestedEvent），直接
     *      依其建構子簽名組裝。
     *   2. 其他事件類別則用反射：把 payload 依參數名稱對應，找不到就
     *      回退到預設值，再找不到則填 null。
     *
     * 回傳 null 表示 $eventClass 不存在（路由到未知類別），呼叫端
     * 會把它轉成 UnrecoverableMessageException。
     */
    private function buildEventInstance(string $eventClass, array $payload): ?object
    {
        // 類別不存在 → 由呼叫端決定如何處理（目前會轉為不可恢復例外）。
        if (!class_exists($eventClass)) {
            return null;
        }

        // 特殊處理：OrderCreateRequestedEvent 的建構子簽名比較特別，
        // 需要把整包 payload 以及可選的 traceId 分開傳入。
        if ($eventClass === \App\Events\OrderCreateRequestedEvent::class) {
            return new $eventClass(
                $payload,
                isset($payload['traceId']) ? (string) $payload['traceId'] : null,
            );
        }

        // 一般事件走反射路徑：逐一對應 constructor 參數。
        //
        // Run 7: per-event-type reflection cache. ReflectionClass +
        // constructor parameter metadata (name / default availability /
        // default value) are immutable for a given class, so we resolve
        // them once and reuse across messages.
        static $reflCache = [];

        if (!isset($reflCache[$eventClass])) {
            $reflection = new \ReflectionClass($eventClass);
            $constructor = $reflection->getConstructor();
            $params = [];
            if ($constructor !== null) {
                foreach ($constructor->getParameters() as $parameter) {
                    $hasDefault = $parameter->isDefaultValueAvailable();
                    $params[] = [
                        'name' => $parameter->getName(),
                        'hasDefault' => $hasDefault,
                        'default' => $hasDefault ? $parameter->getDefaultValue() : null,
                    ];
                }
            }
            $reflCache[$eventClass] = [
                'reflection' => $reflection,
                'hasConstructor' => $constructor !== null,
                'params' => $params,
            ];
        }

        $cached = $reflCache[$eventClass];
        $reflection = $cached['reflection'];

        if (!$cached['hasConstructor']) {
            // 無建構子：直接 new。
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($cached['params'] as $parameter) {
            $name = $parameter['name'];
            // 依參數名字從 payload 抓值。
            if (array_key_exists($name, $payload)) {
                $args[] = $payload[$name];
                continue;
            }

            // payload 沒有這個鍵 → 嘗試使用參數的預設值。
            if ($parameter['hasDefault']) {
                $args[] = $parameter['default'];
                continue;
            }

            // 既無 payload 值也無預設值 → 填 null，交由事件類別自行處理。
            $args[] = null;
        }

        return $reflection->newInstanceArgs($args);
    }
}
