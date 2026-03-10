<?php
namespace SDPMlab\ZtEventGateway;

use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\EventStore\EventStoreDB;

class EventBus
{
    /**
     * @var array<string, list<callable>>
     */
    private array $handlers = [];
    private MessageBus $messageBus;
    private ?EventStoreDB $eventStoreDB;
    
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

        // ✅ 確保不會重複註冊相同的 handler
        foreach ($this->handlers[$eventType] as $existingHandler) {
            if ($existingHandler === $handler) {
                return;
            }
        }

        $this->handlers[$eventType][] = $handler;
    }

    public function dispatch(object $event): void
    {
        $eventType = get_class($event);

        if (!isset($this->handlers[$eventType])) {
            return;
        }

        foreach ($this->handlers[$eventType] as $handler) {
            call_user_func($handler, $event);
        }
    }

    public function publish(string $eventType, array $eventData, string $streamName = 'Streams'): void
    {
        $routingKey = substr(strrchr($eventType, '\\'), 1);

        if ($this->eventStoreDB !== null) {
            $this->eventStoreDB->appendEvent($streamName, [
                'eventId' => uniqid('event_', true),
                'eventType' => $routingKey,
                'data' => $eventData,
                'metadata' => []
            ]);
        }

        $this->messageBus->publishEvent($eventType, $eventData);
    }

}
