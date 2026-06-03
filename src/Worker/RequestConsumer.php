<?php

declare(strict_types=1);

namespace ZtEventGateway\Worker;

use PhpAmqpLib\Message\AMQPMessage;
use SDPMlab\ZtEventGateway\Ingress\CanonicalOrderRequest;
use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\MessageQueue\UnrecoverableMessageException;
use SDPMlab\LSVID\LSVIDException;
use SDPMlab\LSVID\LSVIDValidator;
use Keycloak\JwtValidator;
use Keycloak\KeycloakTokenContext;

final class RequestConsumer
{
    /** @var list<string> Allowed SPIFFE ID prefixes for message sources */
    private const ALLOWED_SOURCES = [
        'spiffe://zt.local/',
    ];

    /**
     * Defensive memoize for L0 validation. Cache hit rate is expected to be
     * low here (gateway mints a fresh jti per HTTP request via
     * LSVIDSigner::createBase()), so this is primarily a guard against AMQP
     * redelivery on consumer crash: a redelivered envelope carries the same
     * raw L0 and would otherwise pay full ECDSA chain verification again.
     * Same shape/TTL as EventConsumer so both sides behave consistently.
     */
    private const VERIFIED_TTL = 60.0;

    /** Hard cap to bound memory; cheap drain to half when exceeded. */
    private const VERIFIED_CAP = 1024;

    /**
     * Cache key bakes in (rawLsvid, expectedAudience, expectedSubject)
     * so a token-for-A cannot satisfy a check that demands subject=B.
     *
     * @var array<string, array{at: float, parsed: object}>
     */
    private array $verifiedTokens = [];

    public function __construct(
        private readonly MessageBus $messageBus,
        // SPIFFE / LSVID side
        private readonly ?LSVIDValidator $lsvidValidator = null,
        private readonly bool $lsvidRequired = false,
        private readonly bool $requireSpiffeIdentity = true,
        // Keycloak side
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

        // ── SPIFFE source verification ────────────────────────────
        if ($this->requireSpiffeIdentity) {
            $this->verifySpiffeSource($sourceSpiffeId);
            fwrite(STDOUT, sprintf(
                "[request-consumer] verified source=%s path=[%s]\n",
                $sourceSpiffeId,
                implode(' -> ', $spiffePath),
            ));
        }

        // ── Nested LSVID validation ───────────────────────────────
        $priorLsvid = null;
        $inboundLsvid = is_string($payload['lsvid'] ?? null) ? (string) $payload['lsvid'] : null;

        if (!$this->requireSpiffeIdentity) {
            // Master SPIFFE toggle off — bypass prefix + LSVID validation entirely.
        } elseif ($inboundLsvid !== null) {
            if ($this->lsvidValidator === null) {
                throw new UnrecoverableMessageException(
                    'LSVID present on envelope but no LSVIDValidator is configured. '
                    . 'This is a wiring error — check worker LSVID configuration.',
                );
            }

            try {
                $workerSpiffeId = getenv('SPIFFE_ID') ?: null;

                $cacheKey = hash(
                    'sha256',
                    $inboundLsvid . '|' . ($workerSpiffeId ?? '') . '|' . $sourceSpiffeId,
                );
                $cached = $this->verifiedTokens[$cacheKey] ?? null;
                if ($cached !== null && (microtime(true) - $cached['at']) <= self::VERIFIED_TTL) {
                    $parsed = $cached['parsed'];
                } else {
                    $parsed = $this->lsvidValidator->validate(
                        $inboundLsvid,
                        expectedAudience: $workerSpiffeId,
                        expectedSubject: $sourceSpiffeId,
                    );
                    if (count($this->verifiedTokens) >= self::VERIFIED_CAP) {
                        $this->verifiedTokens = array_slice(
                            $this->verifiedTokens,
                            (int) (self::VERIFIED_CAP / 2),
                            null,
                            true,
                        );
                    }
                    $this->verifiedTokens[$cacheKey] = [
                        'at'     => microtime(true),
                        'parsed' => $parsed,
                    ];
                }

                $chain = $parsed->chain();
                if ($chain !== []) {
                    $l0Subject = $chain[0]->subject();
                    if ($l0Subject !== null && $l0Subject !== $sourceSpiffeId) {
                        throw new LSVIDException(sprintf(
                            'L0 subject %s does not match envelope source %s.',
                            $l0Subject,
                            $sourceSpiffeId,
                        ));
                    }
                }

                $priorLsvid = $inboundLsvid;
                fwrite(STDOUT, sprintf(
                    "[request-consumer] LSVID L%d OK iss=%s\n",
                    $parsed->level(),
                    $parsed->issuer(),
                ));
                if (getenv('LSVID_LOG_PAYLOAD') === '1') {
                    foreach ($parsed->chain() as $i => $lvl) {
                        fwrite(STDOUT, sprintf(
                            "[request-consumer] LSVID L%d payload=%s\n",
                            $i,
                            json_encode($lvl->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ));
                    }
                }
            } catch (LSVIDException $e) {
                throw new UnrecoverableMessageException('Invalid inbound LSVID: ' . $e->getMessage());
            }
        } elseif ($this->lsvidRequired) {
            throw new UnrecoverableMessageException(
                'LSVID required but envelope carries none. '
                . 'Ensure the gateway mints L0 at ingress (LSVID_ENABLED=1).',
            );
        } else {
            fwrite(STDOUT, "[request-consumer] no LSVID on envelope (migration-period fallback).\n");
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

        // Set Keycloak context so downstream EventBus.publish() metadata
        // can record the upstream caller. Always cleared in finally.
        if ($this->requireAuthorization && $jwt !== '') {
            KeycloakTokenContext::set($jwt, $clientId, $kcClaims);
        }
        try {
            $this->messageBus->publishEvent(
                eventType: $eventClass,
                eventData: $eventData,
                exchange: null,
                spiffePath: $spiffePath,
                priorLsvid: $priorLsvid,
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
