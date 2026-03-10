<?php

namespace SDPMlab\ZtEventGateway;

use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;

interface HandlerScannerInterface
{
    /**
     * 掃描並註冊 `Saga` 事件處理器
     *
     * @param string $namespace 目標掃描的命名空間 (通常是 `App\Sagas`)
     * @param EventBus $eventBus 事件匯流排，用於註冊 `EventHandler`
     * @return void
     */
    public function scanAndRegisterHandlers(string $namespace, EventBus $eventBus);
}
