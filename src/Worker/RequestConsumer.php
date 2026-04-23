<?php

declare(strict_types=1);

namespace ZtEventGateway\Worker;

use PhpAmqpLib\Message\AMQPMessage;
use SDPMlab\ZtEventGateway\Ingress\CanonicalOrderRequest;
use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\MessageQueue\UnrecoverableMessageException;
use Keycloak\JwtValidator;
use Keycloak\KeycloakTokenContext;

final class RequestConsumer
{
    public function __construct(
        private readonly MessageBus $messageBus,
        private readonly ?JwtValidator $jwtValidator,
        private readonly string $selfAudience,
        private readonly bool $requireAuthorization = true,
    ) {
    }

    public function process(AMQPMessage $message): void
    {
        $payload = json_decode($message->getBody(), true);
        if (!is_array($payload)) {
            throw new UnrecoverableMessageException('Invalid request payload.');
        }

        try {
            $envelope = CanonicalOrderRequest::validateEnvelope($payload, $this->requireAuthorization);
        } catch (\InvalidArgumentException $exception) {
            throw new UnrecoverableMessageException($exception->getMessage());
        }

        $jwt       = $envelope['jwt'];
        $clientId  = $envelope['clientId'];
        $tokenPath = $envelope['tokenPath'];
        $route     = $envelope['route'];
        $eventData = $envelope['eventData'];

        $claims = [];
        if ($this->requireAuthorization) {
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

            $azp = (string) ($claims['azp'] ?? $claims['client_id'] ?? '');
            if ($azp !== '' && $clientId !== '' && $azp !== $clientId) {
                throw new UnrecoverableMessageException(sprintf(
                    'JWT azp %s does not match envelope client_id %s',
                    $azp,
                    $clientId,
                ));
            }

            fwrite(STDOUT, sprintf(
                "[request-consumer] JWT verified iss=%s azp=%s aud=%s path=[%s]\n",
                (string) ($claims['iss'] ?? ''),
                $azp,
                $this->selfAudience,
                implode(' -> ', $tokenPath),
            ));
        }

        $eventClass = $this->resolveEventClass($route);
        if (!class_exists($eventClass)) {
            throw new UnrecoverableMessageException(sprintf('Unknown request route: %s', $route));
        }

        if ($this->requireAuthorization && $jwt !== '') {
            KeycloakTokenContext::set($jwt, $clientId, $claims);
        }
        try {
            $this->messageBus->publishEvent(
                eventType: $eventClass,
                eventData: $eventData,
                exchange: null,
                tokenPath: $tokenPath,
            );
        } finally {
            KeycloakTokenContext::clear();
        }

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
