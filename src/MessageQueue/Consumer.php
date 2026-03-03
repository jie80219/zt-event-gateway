<?php
namespace SDPMlab\AnserEDA\MessageQueue;

use Hyperf\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;

class Consumer
{
    private $channel;
    private $eventBus;
    private $startTime;
    private $firstMessageProcessed = false;
    private $logFile;
    private $queueName;

    public function __construct($channel, $eventBus)
    {
        $this->channel = $channel;
        $this->eventBus = $eventBus;
    }

    public function consume(string $queue)
    {
        $this->queueName = $queue;
        
        $callback = function ($msg) {

            $eventData = json_decode($msg->body, true);
            if (!$eventData || !isset($eventData['type'])) {
                return;
            }

            $eventType = $eventData['type'];

            if (class_exists($eventType)) {
                $eventObject = new $eventType(...array_values($eventData['data'])); 
                $this->eventBus->dispatch($eventObject);
                
            }

            if ($msg->has('delivery_tag')) {
                $this->channel->basic_ack($msg->get('delivery_tag'));
            }
        };

        $this->channel->basic_consume($queue, '', false, false, false, false, $callback);

        while (true) {
            try {
                $this->channel->wait();
            } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                // 忽略超時異常
            }
        }
    }
}
