<?php

declare(strict_types=1);

namespace ZtEventGateway\Worker;

use PhpAmqpLib\Message\AMQPMessage;
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

        // ── SPIFFE identity verification ─────────────────────
        $sourceSpiffeId = $payload['spiffe_id'] ?? '';
        $spiffePath = $payload['spiffe_path'] ?? [];

        if ($sourceSpiffeId !== '') {
            $this->verifySpiffeSource($sourceSpiffeId);
            fwrite(STDOUT, sprintf(
                "[request-consumer] verified source=%s path=[%s]\n",
                $sourceSpiffeId,
                implode(' → ', $spiffePath),
            ));
        } else {
            fwrite(STDOUT, "[request-consumer] WARNING: message has no SPIFFE identity\n");
        }

        // ── Route resolution ─────────────────────────────────
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

        // Forward the SPIFFE path to the next event
        $this->messageBus->publishEvent($eventClass, $eventData, null, is_array($spiffePath) ? $spiffePath : []);

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
