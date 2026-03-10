<?php

declare(strict_types=1);

namespace ZtEventGateway\Worker;

use PhpAmqpLib\Message\AMQPMessage;
use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\MessageQueue\UnrecoverableMessageException;

final class RequestConsumer
{
    public function __construct(private readonly MessageBus $messageBus)
    {
    }

    public function process(AMQPMessage $message): void
    {
        $payload = json_decode($message->getBody(), true);
        if (!is_array($payload)) {
            throw new UnrecoverableMessageException('Invalid request payload.');
        }

        $route = $payload['route'] ?? $payload['eventType'] ?? $payload['type'] ?? null;
        if (!is_string($route) || $route === '') {
            throw new UnrecoverableMessageException('Missing request route.');
        }

        $eventClass = $this->resolveEventClass($route);
        if (!class_exists($eventClass)) {
            throw new UnrecoverableMessageException(sprintf('Unknown request route: %s', $route));
        }

        $eventData = $payload['data'] ?? [];
        if (!is_array($eventData)) {
            throw new UnrecoverableMessageException('Invalid request data.');
        }

        if (isset($payload['id']) && !isset($eventData['traceId'])) {
            $eventData['traceId'] = (string) $payload['id'];
        }

        $this->messageBus->publishEvent($eventClass, $eventData);

        fwrite(STDOUT, sprintf("[request-consumer] published event=%s\n", $eventClass));
    }

    private function resolveEventClass(string $route): string
    {
        if (str_contains($route, '\\')) {
            return $route;
        }

        return 'App\\Events\\' . $route;
    }
}
