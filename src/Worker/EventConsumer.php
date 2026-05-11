<?php

declare(strict_types=1);

namespace ZtEventGateway\Worker;

use PhpAmqpLib\Message\AMQPMessage;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\MessageQueue\UnrecoverableMessageException;

/**
 * 事件消費者：從 RabbitMQ 事件佇列接收事件，反序列化後交由 EventBus 分派。
 */
final class EventConsumer
{
    public function __construct(
        private readonly EventBus $eventBus,
    ) {
    }

    public function process(AMQPMessage $message): void
    {
        $payload = json_decode($message->getBody(), true);
        if (!is_array($payload)) {
            throw new UnrecoverableMessageException('Invalid event payload.');
        }

        $eventType = $payload['type'] ?? null;
        $eventData = $payload['data'] ?? null;

        if (!is_string($eventType) || !is_array($eventData)) {
            throw new UnrecoverableMessageException('Missing event type or data.');
        }

        $event = $this->buildEventInstance($eventType, $eventData);
        if ($event === null) {
            throw new UnrecoverableMessageException(sprintf('Unknown event class: %s', $eventType));
        }

        $this->eventBus->dispatch($event);

        fwrite(STDOUT, sprintf("[event-consumer] handled event=%s\n", $eventType));
    }

    private function buildEventInstance(string $eventClass, array $payload): ?object
    {
        if (!class_exists($eventClass)) {
            return null;
        }

        if ($eventClass === \App\Events\OrderCreateRequestedEvent::class) {
            return new $eventClass(
                $payload,
                isset($payload['traceId']) ? (string) $payload['traceId'] : null,
            );
        }

        $signature = self::constructorSignature($eventClass);

        if ($signature === null) {
            return new $eventClass();
        }

        $args = [];
        foreach ($signature as $param) {
            if (array_key_exists($param['name'], $payload)) {
                $args[] = $payload[$param['name']];
            } elseif ($param['hasDefault']) {
                $args[] = $param['default'];
            } else {
                $args[] = null;
            }
        }

        return new $eventClass(...$args);
    }

    /**
     * Cache constructor parameter metadata per event class. Saves a
     * ReflectionClass + parameters walk per inbound event.
     *
     * @return list<array{name:string,hasDefault:bool,default:mixed}>|null
     *         null when the class has no constructor.
     */
    private static function constructorSignature(string $eventClass): ?array
    {
        /** @var array<string, list<array{name:string,hasDefault:bool,default:mixed}>|null> $cache */
        static $cache = [];
        if (array_key_exists($eventClass, $cache)) {
            return $cache[$eventClass];
        }

        $reflection = new \ReflectionClass($eventClass);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $cache[$eventClass] = null;
        }

        $signature = [];
        foreach ($constructor->getParameters() as $parameter) {
            $signature[] = [
                'name'       => $parameter->getName(),
                'hasDefault' => $parameter->isDefaultValueAvailable(),
                'default'    => $parameter->isDefaultValueAvailable()
                    ? $parameter->getDefaultValue()
                    : null,
            ];
        }

        return $cache[$eventClass] = $signature;
    }
}
