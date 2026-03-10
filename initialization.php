<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Wire\AMQPTable;

class InitRabbitMQ extends BaseCommand
{
    protected $group       = 'RabbitMQ';
    protected $name        = 'rabbitmq:init';
    protected $description = 'Initialize Event-Gateway Topology (Entry Queue + Anser-EDA Events).';
    protected $usage       = 'rabbitmq:init [-f]';
    protected $options     = [
        '-f' => 'Force reset (Delete existing queues/exchanges before declaring)',
    ];

    // =========================================================================
    // 1. 架構常數定義
    // =========================================================================
    const MAIN_EXCHANGE = 'events';     // Anser-EDA 標準 Exchange
    const EXCHANGE_TYPE = 'direct';     // Direct 模式

    // Gateway 的入口佇列 (這是你遺失的部分)
    const ENTRY_QUEUE_NAME = 'request_queue';
    const ENTRY_ROUTING_KEY = 'request.new';

    // =========================================================================
    // 2. 定義 Anser-EDA 的六個核心事件
    // =========================================================================
    // 注意：這裡建議使用完整的 Namespace 類別名稱，以符合 Anser-EDA HandlerScanner 的習慣
    private $eventQueues = [
        'OrderCreateRequestedEvent', // 事件 1: 訂單建立請求
        'InventoryDeductedEvent',    // 事件 2: 庫存已扣除
        'PaymentProcessedEvent',     // 事件 3: 付款已處理
        'OrderCreatedEvent',         // 事件 4: 訂單建立成功
        'RollbackInventoryEvent',    // 事件 5: 補償-庫存回滾
        'RollbackOrderEvent',        // 事件 6: 補償-訂單取消
    ];

    public function run(array $params)
    {
        $fresh = isset($params['f']) || CLI::getOption('f');

        CLI::write("🚀 [Anser-Gateway] Initializing Hybrid Topology...", 'yellow');

        $host = getenv('RABBITMQ_HOST') ?: 'anser_rabbitmq';
        $port = getenv('RABBITMQ_PORT') ?: 5672;
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $pass = getenv('RABBITMQ_PASS') ?: 'guest';

        try {
            $connection = new AMQPStreamConnection($host, $port, $user, $pass);
            $channel = $connection->channel();

            // 如果有 -f 參數，先執行清除
            if ($fresh) {
                $this->teardown($channel);
            }

            // 執行初始化
            $this->setup($channel);

            $channel->close();
            $connection->close();

            CLI::write("✅ Initialization Completed Successfully!", 'green');

        } catch (\Throwable $e) {
            CLI::error("❌ Initialization Failed: " . $e->getMessage());
        }
    }

    private function setup($channel)
    {
        CLI::write("🛠️  Setting up Exchange...", 'cyan');

        // 1. 宣告主要 Exchange
        $channel->exchange_declare(
            self::MAIN_EXCHANGE,
            self::EXCHANGE_TYPE,
            false,
            true, // durable
            false
        );
        CLI::write("   ├── [Exchange] " . self::MAIN_EXCHANGE . " (" . self::EXCHANGE_TYPE . ") created.", 'light_gray');

        // 2. 建立並綁定 Gateway Entry Queue (入口佇列)
        CLI::write("🛠️  Setting up Gateway Entry Queue...", 'cyan');
        
        $channel->queue_declare(
            self::ENTRY_QUEUE_NAME, 
            false, 
            true, // durable
            false, 
            false
        );
        
        $channel->queue_bind(self::ENTRY_QUEUE_NAME, self::MAIN_EXCHANGE, self::ENTRY_ROUTING_KEY);
        
        CLI::write("   ├── [Queue] " . self::ENTRY_QUEUE_NAME, 'green');
        CLI::write("   │    └── Bound Key: " . self::ENTRY_ROUTING_KEY, 'dark_gray');

        // 3. 建立並綁定 Anser-EDA 事件佇列
        CLI::write("🛠️  Setting up Saga Event Queues...", 'cyan');
        
        foreach ($this->eventQueues as $eventName) {
            // Queue Name 與 Routing Key 通常都設為事件類別名稱
            $queueName = $eventName;
            $routingKey = $eventName;

            $channel->queue_declare($queueName, false, true, false, false);
            $channel->queue_bind($queueName, self::MAIN_EXCHANGE, $routingKey);

            CLI::write("   ├── [Queue] {$queueName}", 'green');
            CLI::write("   │    └── Bound Key: {$routingKey}", 'dark_gray');
        }
    }

    private function teardown($channel)
    {
        CLI::write("⚠️  [Fresh Mode] Cleaning up old topology...", 'red');

        // 1. 刪除 Entry Queue
        try {
            $channel->queue_delete(self::ENTRY_QUEUE_NAME);
            CLI::write("   🗑️  Deleted Queue: " . self::ENTRY_QUEUE_NAME, 'light_red');
        } catch (\Exception $e) {}

        // 2. 刪除 Event Queues
        foreach ($this->eventQueues as $qName) {
            try {
                $channel->queue_delete($qName);
                CLI::write("   🗑️  Deleted Queue: {$qName}", 'light_red');
            } catch (\Exception $e) {}
        }

        // 3. 刪除 Exchange
        try {
            $channel->exchange_delete(self::MAIN_EXCHANGE);
            CLI::write("   🗑️  Deleted Exchange: " . self::MAIN_EXCHANGE, 'light_red');
        } catch (\Exception $e) {}

        CLI::newLine();
    }
}