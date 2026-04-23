<?php
namespace SDPMlab\ZtEventGateway;

use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\EventStore\EventStoreDB;
use Keycloak\KeycloakTokenContext;

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

    public function hasHandlers(string $eventType): bool
    {
        return !empty($this->handlers[$eventType]);
    }

    public function getHandlerCount(string $eventType): int
    {
        return count($this->handlers[$eventType] ?? []);
    }

    /**
     * @return list<string>
     */
    public function getRegisteredEventTypes(): array
    {
        return array_keys($this->handlers);
    }

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

        if ($successCount === 0 && !empty($this->lastDispatchErrors)) {
            throw end($this->lastDispatchErrors)['exception'];
        }
    }

    /**
     * @return list<array{eventType: string, handler: string, exception: \Throwable}>
     */
    public function getLastDispatchErrors(): array
    {
        return $this->lastDispatchErrors;
    }

    /**
     * Publish an event to the message bus with Keycloak client_id trace.
     *
     * @param string $eventType  Fully-qualified event class name
     * @param array  $eventData  Event payload
     * @param string $streamName EventStore stream name
     * @param array  $tokenPath  Previous client_id trace to propagate
     */
    public function publish(string $eventType, array $eventData, string $streamName = 'Streams', array $tokenPath = []): void
    {
        $routingKey = substr(strrchr($eventType, '\\'), 1);

        if ($this->eventStoreDB !== null) {
            $ctx = KeycloakTokenContext::get();
            $this->eventStoreDB->appendEvent($streamName, [
                'eventId' => uniqid('event_', true),
                'eventType' => $routingKey,
                'data' => $eventData,
                'metadata' => [
                    'client_id'  => $this->messageBus->getClientId(),
                    'token_path' => $tokenPath,
                    'caller'     => $ctx['client_id'] ?? null,
                ],
            ]);
        }

        $this->messageBus->publishEvent(
            eventType: $eventType,
            eventData: $eventData,
            exchange: null,
            tokenPath: $tokenPath,
        );
    }

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
