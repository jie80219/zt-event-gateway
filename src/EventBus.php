<?php
namespace SDPMlab\ZtEventGateway;

use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\EventStore\EventStoreDB;

class EventBus
{
    /** @var array<string, list<callable>> */
    private array $handlers = [];
    private MessageBus $messageBus;
    private ?EventStoreDB $eventStoreDB;

    /** @var list<array{eventType: string, handler: string, exception: \Throwable}> */
    private array $lastDispatchErrors = [];

    public function __construct(MessageBus $messageBus, ?EventStoreDB $eventStoreDB = null)
    {
        $this->messageBus = $messageBus;
        $this->eventStoreDB = $eventStoreDB;
    }

    public function registerHandler(string $eventType, callable $handler): void
    {
        if (!isset($this->handlers[$eventType])) {
            $this->handlers[$eventType] = [];
        }

        foreach ($this->handlers[$eventType] as $existingHandler) {
            if ($existingHandler === $handler) {
                return;
            }
        }

        $this->handlers[$eventType][] = $handler;
    }

    /**
     * 移除指定事件類型的某個 handler
     */
    public function removeHandler(string $eventType, callable $handler): void
    {
        if (!isset($this->handlers[$eventType])) {
            return;
        }

        $this->handlers[$eventType] = array_values(array_filter(
            $this->handlers[$eventType],
            fn(callable $h) => $h !== $handler,
        ));

        if (empty($this->handlers[$eventType])) {
            unset($this->handlers[$eventType]);
        }
    }

    /**
     * 檢查是否有註冊指定事件類型的 handler
     */
    public function hasHandlers(string $eventType): bool
    {
        return !empty($this->handlers[$eventType]);
    }

    /**
     * 取得指定事件類型的 handler 數量
     */
    public function getHandlerCount(string $eventType): int
    {
        return count($this->handlers[$eventType] ?? []);
    }

    /**
     * 取得所有已註冊的事件類型
     *
     * @return list<string>
     */
    public function getRegisteredEventTypes(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * 分派事件至所有已註冊的 handler。
     *
     * 單一 handler 拋出例外時記錄錯誤並繼續執行其餘 handler，
     * 確保一個 handler 的失敗不會中斷整個事件處理鏈。
     * 若所有 handler 都失敗，則拋出最後一個例外。
     */
    public function dispatch(object $event): void
    {
        $eventType = get_class($event);
        $this->lastDispatchErrors = [];

        if (!isset($this->handlers[$eventType])) {
            return;
        }

        $handlers = $this->handlers[$eventType];
        $successCount = 0;

        foreach ($handlers as $handler) {
            try {
                call_user_func($handler, $event);
                $successCount++;
            } catch (\Throwable $e) {
                $handlerName = $this->describeHandler($handler);
                fwrite(STDERR, sprintf(
                    "[event-bus] handler %s failed for %s: %s\n",
                    $handlerName,
                    $eventType,
                    $e->getMessage(),
                ));
                $this->lastDispatchErrors[] = [
                    'eventType' => $eventType,
                    'handler' => $handlerName,
                    'exception' => $e,
                ];
            }
        }

        // 若全部 handler 都失敗，拋出最後一個例外
        if ($successCount === 0 && !empty($this->lastDispatchErrors)) {
            throw end($this->lastDispatchErrors)['exception'];
        }
    }

    /**
     * 取得最近一次 dispatch 中的錯誤
     *
     * @return list<array{eventType: string, handler: string, exception: \Throwable}>
     */
    public function getLastDispatchErrors(): array
    {
        return $this->lastDispatchErrors;
    }

    /**
     * Publish an event to the message bus.
     *
     * Service identity is transport-layer Vault PKI mTLS only — the envelope
     * carries no application-layer identity token, so there is nothing to
     * propagate beyond the event payload itself.
     *
     * @param string $eventType  Fully-qualified event class name
     * @param array  $eventData  Event payload
     * @param string $streamName EventStore stream name
     */
    public function publish(
        string $eventType,
        array $eventData,
        string $streamName = 'Streams',
    ): void {
        $routingKey = substr(strrchr($eventType, '\\'), 1);

        if ($this->eventStoreDB !== null) {
            $this->eventStoreDB->appendEvent($streamName, [
                'eventId' => uniqid('event_', true),
                'eventType' => $routingKey,
                'data' => $eventData,
                'metadata' => [],
            ]);
        }

        $this->messageBus->publishEvent(
            eventType: $eventType,
            eventData: $eventData,
            exchange: null,
        );
    }

    /**
     * 描述 handler 的名稱，用於日誌
     */
    private function describeHandler(callable $handler): string
    {
        if (is_array($handler)) {
            return get_class($handler[0]) . '::' . $handler[1];
        }
        if (is_string($handler)) {
            return $handler;
        }
        return '{closure}';
    }
}
