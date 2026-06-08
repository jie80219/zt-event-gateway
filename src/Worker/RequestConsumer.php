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
    /** @var list<string> Allowed SPIFFE ID prefixes for message sources */
    private const ALLOWED_SOURCES = [
        'spiffe://zt.local/',
    ];

    public function __construct(
        private readonly MessageBus $messageBus,
        private readonly bool $requireSpiffeIdentity = true,
        private readonly ?JwtValidator $jwtValidator = null,
        private readonly string $selfAudience = '',
        private readonly bool $requireAuthorization = false,
    ) {
    }

    public function process(AMQPMessage $message): void
    {
        $payload = json_decode($message->getBody(), true);
        if (!is_array($payload)) {
            throw new UnrecoverableMessageException('Invalid request payload.');
        }

        try {
            $envelope = CanonicalOrderRequest::validateEnvelope(
                $payload,
                $this->requireSpiffeIdentity,
                $this->requireAuthorization,
            );
        } catch (\InvalidArgumentException $exception) {
            throw new UnrecoverableMessageException($exception->getMessage());
        }

        $sourceSpiffeId = $envelope['spiffeId'] ?? '';
        $spiffePath     = $envelope['spiffePath'] ?? [];
        $jwt            = $envelope['jwt'] ?? '';
        $clientId       = $envelope['clientId'] ?? '';
        $tokenPath      = $envelope['tokenPath'] ?? [];
        $route          = $envelope['route'];
        $eventData      = $envelope['eventData'];

        // ── SPIFFE source verification (prefix only — no LSVID chain) ──
        if ($this->requireSpiffeIdentity) {
            $this->verifySpiffeSource($sourceSpiffeId);
            fwrite(STDOUT, sprintf(
                "[request-consumer] verified source=%s path=[%s]\n",
                $sourceSpiffeId,
                implode(' -> ', $spiffePath),
            ));
        }

        // ── Keycloak JWT validation ───────────────────────────────
        $kcClaims = [];
        if ($this->requireAuthorization) {
            if ($this->jwtValidator === null) {
                throw new UnrecoverableMessageException(
                    'Authorization required but no JwtValidator is configured (wiring error).',
                );
            }
            try {
                $kcClaims = $this->jwtValidator->validate($jwt, $this->selfAudience);
            } catch (\Throwable $e) {
                throw new UnrecoverableMessageException('JWT validation failed: ' . $e->getMessage());
            }

            $azp = (string) ($kcClaims['azp'] ?? $kcClaims['client_id'] ?? '');
            if ($azp !== '' && $clientId !== '' && $azp !== $clientId) {
                throw new UnrecoverableMessageException(sprintf(
                    'JWT azp %s does not match envelope client_id %s',
                    $azp,
                    $clientId,
                ));
            }

            fwrite(STDOUT, sprintf(
                "[request-consumer] JWT verified iss=%s azp=%s aud=%s path=[%s]\n",
                (string) ($kcClaims['iss'] ?? ''),
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
            KeycloakTokenContext::set($jwt, $clientId, $kcClaims);
        }
        try {
            $this->messageBus->publishEvent(
                eventType: $eventClass,
                eventData: $eventData,
                exchange: null,
                spiffePath: $spiffePath,
                tokenPath: $tokenPath,
            );
        } finally {
            KeycloakTokenContext::clear();
        }

        fwrite(STDOUT, sprintf("[request-consumer] published event=%s\n", $eventClass));
    }

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
