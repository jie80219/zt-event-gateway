<?php
namespace SDPMlab\ZtEventGateway;

/**
 * 抽象 Saga 類別
 *
 * 所有 Saga 流程皆應繼承此基底類別，提供事件派發、補償、日誌、ID 產生等共用功能。
 */
abstract class Saga
{
    /**
     * @var EventBus eventbus，用於事件發布與補償事件觸發
     */
    protected EventBus $eventBus;

    /**
     * 建構式：注入eventbus
     *
     * @param EventBus $eventBus
     */
    public function __construct(EventBus $eventBus)
    {
        $this->eventBus = $eventBus;
    }

    /**
     * 發佈事件至 EventBus
     *
     * 用於流程中發送後續事件
     *
     * @param string $eventClass 事件類別（ex: OrderCreatedEvent::class）
     * @param array $payload 傳遞的資料內容
     */
    protected function publish(string $eventClass, array $payload): void
    {
        $this->eventBus->publish($eventClass, $payload);
    }

    /**
     * 發佈補償事件至 EventBus
     *
     * 用於錯誤或中斷時的回滾邏輯觸發
     *
     * @param string $rollbackEventClass 補償事件類別
     * @param array $payload 補償事件資料內容
     */
    protected function compensate(string $rollbackEventClass, array $payload): void
    {
        $this->eventBus->publish($rollbackEventClass, $payload);
    }

    /**
     * 紀錄日誌訊息（預設輸出至 console）
     *
     * 可被覆寫接入如 Monolog 或其他 log 工具
     *
     * @param string $message
     */
    protected function log(string $message): void
    {
        echo $message . PHP_EOL;
    }

    /**
     * 統一檢查服務層返回是否成功（如 API 回應）
     *
     * 預設成功格式為 ['code' => '200']
     * 當 code 為 500 時會等待 10 秒後重試，最多重試 3 次
     *
     * @param array $info
     * @param int $retryCount 當前重試次數
     * @return bool 是否成功
     */
    protected function isSuccess(array $info, int $retryCount = 0, int $maxRetry = 3): bool
    {
        if (isset($info['code']) && (string) $info['code'] === '500' && $retryCount < $maxRetry) {
            $this->log("收到 500 錯誤，第 " . ($retryCount + 1) . " 次重試，等待 10 秒...");
            sleep(10);
            return $this->isSuccess($info, $retryCount + 1, $maxRetry);
        }

        return isset($info['code']) && (string) $info['code'] === '200';
    }
}
