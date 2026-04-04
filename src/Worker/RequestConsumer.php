<?php

declare(strict_types=1);

namespace ZtEventGateway\Worker;

use PhpAmqpLib\Message\AMQPMessage;
use SDPMlab\ZtEventGateway\Ingress\CanonicalOrderRequest;
use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\MessageQueue\UnrecoverableMessageException;

final class RequestConsumer
{
    /** @var list<string> Allowed SPIFFE ID prefixes for message sources */
    private const ALLOWED_SOURCES = [
        'spiffe://zt.local/',
    ];

    public function __construct(private readonly MessageBus $messageBus)
    {
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

        $sourceSpiffeId = $envelope['spiffeId'];
        $spiffePath = $envelope['spiffePath'];
        $route = $envelope['route'];
        $eventData = $envelope['eventData'];

        $this->verifySpiffeSource($sourceSpiffeId);
        fwrite(STDOUT, sprintf(
            "[request-consumer] verified source=%s path=[%s]\n",
            $sourceSpiffeId,
            implode(' -> ', $spiffePath),
        ));

        $eventClass = $this->resolveEventClass($route);
        if (!class_exists($eventClass)) {
            throw new UnrecoverableMessageException(sprintf('Unknown request route: %s', $route));
        }

        // Forward the SPIFFE path to the next event
        $this->messageBus->publishEvent($eventClass, $eventData, null, $spiffePath);

        fwrite(STDOUT, sprintf("[request-consumer] published event=%s\n", $eventClass));
    }

    /**
     * Verify the message source belongs to our trust domain.
     */
    private function verifySpiffeSource(string $spiffeId): void
    {
        foreach (self::ALLOWED_SOURCES as $prefix) {
            if (str_starts_with($spiffeId, $prefix)) {
                return;
            }
        }

        throw new UnrecoverableMessageException(sprintf(
            'Untrusted SPIFFE source: %s (allowed: %s)',
            $spiffeId,
            implode(', ', self::ALLOWED_SOURCES),
        ));
    }

    private function resolveEventClass(string $route): string
    {
        if (str_contains($route, '\\')) {
            return $route;
        }

        return 'App\\Events\\' . $route;
    }
}
