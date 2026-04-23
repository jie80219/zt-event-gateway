<?php

declare(strict_types=1);

namespace ZtEventGateway\Worker;

use PhpAmqpLib\Message\AMQPMessage;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\MessageQueue\UnrecoverableMessageException;
use Keycloak\JwtValidator;
use Keycloak\KeycloakTokenContext;

/**
 * Event consumer — validates the Keycloak bearer token on the envelope,
 * rehydrates the event object, and dispatches to registered handlers.
 *
 * Unlike the previous SPIFFE/LSVID variant, there is no nested chain to
 * walk: every hop carries a flat Client Credentials token issued to
 * itself. The validator checks iss + aud + signature; the client_id path
 * is preserved only as an application-level trace in token_path.
 */
final class EventConsumer
{
    public function __construct(
        private readonly EventBus $eventBus,
        private readonly ?JwtValidator $jwtValidator,
        private readonly string $selfAudience,
        private readonly bool $requireAuthorization = true,
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

        $authorization = $payload['authorization'] ?? null;
        $tokenPath     = is_array($payload['token_path'] ?? null) ? $payload['token_path'] : [];
        $jwt           = is_array($authorization) && is_string($authorization['jwt'] ?? null)
            ? (string) $authorization['jwt'] : '';
        $clientId      = is_array($authorization) && is_string($authorization['client_id'] ?? null)
            ? (string) $authorization['client_id'] : '';
        $claims        = [];

        if ($this->requireAuthorization) {
            if ($jwt === '') {
                throw new UnrecoverableMessageException('Event envelope missing authorization.jwt');
            }
            if ($this->jwtValidator === null) {
                throw new UnrecoverableMessageException(
                    'Authorization required but no JwtValidator is configured (wiring error).',
                );
            }
            try {
                $claims = $this->jwtValidator->validate($jwt, $this->selfAudience);
            } catch (\Throwable $e) {
                throw new UnrecoverableMessageException('JWT validation failed: ' . $e->getMessage());
            }

            fwrite(STDOUT, sprintf(
                "[event-consumer] JWT verified iss=%s azp=%s aud=%s event=%s path=[%s]\n",
                (string) ($claims['iss'] ?? ''),
                (string) ($claims['azp'] ?? $claims['client_id'] ?? ''),
                $this->selfAudience,
                substr(strrchr($eventType, '\\') ?: $eventType, 1),
                implode(' -> ', $tokenPath),
            ));
        }

        $event = $this->buildEventInstance($eventType, $eventData);
        if ($event === null) {
            throw new UnrecoverableMessageException(sprintf('Unknown event class: %s', $eventType));
        }

        if ($jwt !== '') {
            KeycloakTokenContext::set($jwt, $clientId, $claims);
        }
        try {
            $this->eventBus->dispatch($event);
        } finally {
            KeycloakTokenContext::clear();
        }

        fwrite(STDOUT, sprintf("[event-consumer] handled event=%s\n", $eventType));
    }

    /**
     * Rehydrate an event object from its payload.
     *
     * OrderCreateRequestedEvent has a special constructor signature.
     * Other events fall back to reflection-based parameter mapping.
     */
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
