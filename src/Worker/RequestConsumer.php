<?php

declare(strict_types=1);

namespace ZtEventGateway\Worker;

use PhpAmqpLib\Message\AMQPMessage;
use SDPMlab\ZtEventGateway\Ingress\CanonicalOrderRequest;
use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\MessageQueue\UnrecoverableMessageException;

/**
 * Consumes inbound gateway request envelopes off the request queue, performs
 * canonical envelope structural validation, and republishes them as the
 * mapped domain event.
 *
 * Service identity is established at the transport layer via Vault PKI mTLS,
 * so there is no application-layer identity validation here — no SPIFFE
 * trust-domain check, no LSVID chain validation, no Keycloak JWT validation.
 */
final class RequestConsumer
{
    public function __construct(
        private readonly MessageBus $messageBus,
    ) {
    }

    public function process(AMQPMessage $message): void
    {
        $payload = json_decode($message->getBody(), true);
        if (!is_array($payload)) {
            throw new UnrecoverableMessageException('Invalid request payload.');
        }

        try {
            $envelope = CanonicalOrderRequest::validateEnvelope($payload);
        } catch (\InvalidArgumentException $exception) {
            throw new UnrecoverableMessageException($exception->getMessage());
        }

        $route     = $envelope['route'];
        $eventData = $envelope['eventData'];

        $eventClass = $this->resolveEventClass($route);
        if (!class_exists($eventClass)) {
            throw new UnrecoverableMessageException(sprintf('Unknown request route: %s', $route));
        }

        $this->messageBus->publishEvent(
            eventType: $eventClass,
            eventData: $eventData,
        );

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
