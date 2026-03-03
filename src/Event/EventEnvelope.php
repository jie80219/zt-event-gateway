<?php

declare(strict_types=1);

namespace ZtEventGateway\Event;

use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;
use Ramsey\Uuid\Uuid;

final class EventEnvelope implements JsonSerializable
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private readonly string $eventId,
        private readonly string $eventType,
        private readonly DateTimeImmutable $occurredAt,
        private readonly string $source,
        private readonly array $payload,
        private readonly ?string $subject = null,
        private readonly array $attributes = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function orderCreated(array $payload, string $source): self
    {
        return new self(
            eventId: Uuid::uuid7()->toString(),
            eventType: 'orders.created.v1',
            occurredAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            source: $source,
            payload: $payload,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            eventId: (string) ($data['eventId'] ?? ''),
            eventType: (string) ($data['eventType'] ?? ''),
            occurredAt: new DateTimeImmutable((string) ($data['occurredAt'] ?? 'now')),
            source: (string) ($data['source'] ?? 'unknown'),
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
            subject: isset($data['subject']) ? (string) $data['subject'] : null,
            attributes: is_array($data['attributes'] ?? null) ? $data['attributes'] : [],
        );
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function routingKey(): string
    {
        return $this->eventType;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'eventId' => $this->eventId,
            'eventType' => $this->eventType,
            'occurredAt' => $this->occurredAt->format(DATE_ATOM),
            'source' => $this->source,
            'subject' => $this->subject,
            'attributes' => $this->attributes,
            'payload' => $this->payload,
        ];
    }
}
