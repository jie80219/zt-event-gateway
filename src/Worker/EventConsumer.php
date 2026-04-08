<?php

declare(strict_types=1);

namespace ZtEventGateway\Worker;

use PhpAmqpLib\Message\AMQPMessage;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\MessageQueue\UnrecoverableMessageException;
use SDPMlab\LSVID\LSVIDContext;
use SDPMlab\LSVID\LSVIDException;
use SDPMlab\LSVID\LSVIDValidator;

final class EventConsumer
{
    /** @var list<string> Allowed SPIFFE ID prefixes */
    private const ALLOWED_SOURCES = [
        'spiffe://zt.local/',
    ];

    public function __construct(
        private readonly EventBus $eventBus,
        private readonly ?LSVIDValidator $lsvidValidator = null,
        private readonly bool $lsvidRequired = false,
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

        // ── SPIFFE identity verification ─────────────────────
        $sourceSpiffeId = $payload['spiffe_id'] ?? '';
        $spiffePath = $payload['spiffe_path'] ?? [];

        if ($sourceSpiffeId !== '') {
            $this->verifySpiffeSource($sourceSpiffeId);
            fwrite(STDOUT, sprintf(
                "[event-consumer] source=%s path=[%s] event=%s\n",
                $sourceSpiffeId,
                implode(' → ', $spiffePath),
                substr(strrchr($eventType, '\\') ?: $eventType, 1),
            ));
        } else {
            fwrite(STDOUT, sprintf(
                "[event-consumer] WARNING: no SPIFFE identity on event=%s\n",
                $eventType,
            ));
        }

        // ── Nested LSVID: verify full chain, then expose the raw token
        //    to the Saga layer via LSVIDContext. EventBus::publish() reads
        //    it and asks MessageBus to wrap it as the `nested` claim of
        //    the next level signed downstream.
        $rawLsvid = is_string($payload['lsvid'] ?? null) ? (string) $payload['lsvid'] : null;

        if ($rawLsvid !== null) {
            // LSVID present — validator MUST be configured.
            if ($this->lsvidValidator === null) {
                throw new UnrecoverableMessageException(
                    'LSVID present on event envelope but no LSVIDValidator is configured.',
                );
            }

            try {
                $workerSpiffeId = getenv('SPIFFE_ID') ?: null;

                $parsed = $this->lsvidValidator->validate(
                    $rawLsvid,
                    expectedAudience: $workerSpiffeId,
                );

                fwrite(STDOUT, sprintf(
                    "[event-consumer] LSVID chain L0..L%d verified: %s\n",
                    $parsed->level(),
                    implode(
                        ' -> ',
                        array_map(static fn($l) => $l->issuer(), $parsed->chain()),
                    ),
                ));
            } catch (LSVIDException $e) {
                throw new UnrecoverableMessageException('LSVID validation failed: ' . $e->getMessage());
            }
        } elseif ($this->lsvidRequired) {
            throw new UnrecoverableMessageException(
                'LSVID required but event envelope carries none.',
            );
        }

        $event = $this->buildEventInstance($eventType, $eventData);
        if ($event === null) {
            throw new UnrecoverableMessageException(sprintf('Unknown event class: %s', $eventType));
        }

        LSVIDContext::set($rawLsvid);
        try {
            $this->eventBus->dispatch($event);
        } finally {
            LSVIDContext::clear();
        }

        fwrite(STDOUT, sprintf("[event-consumer] handled event=%s\n", $eventType));
    }

    private function verifySpiffeSource(string $spiffeId): void
    {
        foreach (self::ALLOWED_SOURCES as $prefix) {
            if (str_starts_with($spiffeId, $prefix)) {
                return;
            }
        }

        throw new UnrecoverableMessageException(sprintf(
            'Untrusted SPIFFE source: %s',
            $spiffeId,
        ));
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

        $reflection = new \ReflectionClass($eventClass);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $payload)) {
                $args[] = $payload[$name];
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }

            $args[] = null;
        }

        return $reflection->newInstanceArgs($args);
    }
}
