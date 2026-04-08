<?php
namespace SDPMlab\ZtEventGateway;

use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\EventStore\EventStoreDB;
use SDPMlab\LSVID\LSVIDContext;

class EventBus
{
    /** @var array<string, list<callable>> */
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

    /**
     * Publish an event to the message bus with SPIFFE identity propagation.
     *
     * @param string $eventType  Fully-qualified event class name
     * @param array  $eventData  Event payload
     * @param string $streamName EventStore stream name
     * @param array  $spiffePath Previous identity chain to propagate
     */
    public function publish(string $eventType, array $eventData, string $streamName = 'Streams', array $spiffePath = []): void
    {
        $routingKey = substr(strrchr($eventType, '\\'), 1);

        // Pull the currently-handled inbound LSVID (if any). MessageBus will
        // wrap it as the `nested` claim of the new level it signs, producing
        // the L0 → L1 → L2 … nested chain.
        $priorLsvid = LSVIDContext::current();

        if ($this->eventStoreDB !== null) {
            $this->eventStoreDB->appendEvent($streamName, [
                'eventId' => uniqid('event_', true),
                'eventType' => $routingKey,
                'data' => $eventData,
                'metadata' => [
                    'spiffe_id' => $this->messageBus->getSpiffeId(),
                    'spiffe_path' => $spiffePath,
                    'lsvid_prior' => $priorLsvid,
                ],
            ]);
        }

        $this->messageBus->publishEvent(
            eventType: $eventType,
            eventData: $eventData,
            exchange: null,
            spiffePath: $spiffePath,
            priorLsvid: $priorLsvid,
        );
    }
}
