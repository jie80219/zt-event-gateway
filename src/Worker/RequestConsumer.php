<?php

declare(strict_types=1);

namespace ZtEventGateway\Worker;

use PhpAmqpLib\Message\AMQPMessage;
use SDPMlab\ZtEventGateway\Ingress\CanonicalOrderRequest;
use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\MessageQueue\UnrecoverableMessageException;
use SDPMlab\LSVID\LSVIDException;
use SDPMlab\LSVID\LSVIDValidator;

final class RequestConsumer
{
    /** @var list<string> Allowed SPIFFE ID prefixes for message sources */
    private const ALLOWED_SOURCES = [
        'spiffe://zt.local/',
    ];

    public function __construct(
        private readonly MessageBus $messageBus,
        private readonly ?LSVIDValidator $lsvidValidator = null,
        private readonly bool $lsvidRequired = false,
        private readonly bool $requireSpiffeIdentity = true,
    ) {
    }

    public function process(AMQPMessage $message): void
    {
        $payload = json_decode($message->getBody(), true);
        if (!is_array($payload)) {
            throw new UnrecoverableMessageException('Invalid request payload.');
        }

        try {
            $envelope = CanonicalOrderRequest::validateEnvelope($payload, $this->requireSpiffeIdentity);
        } catch (\InvalidArgumentException $exception) {
            throw new UnrecoverableMessageException($exception->getMessage());
        }

        $sourceSpiffeId = $envelope['spiffeId'];
        $spiffePath = $envelope['spiffePath'];
        $route = $envelope['route'];
        $eventData = $envelope['eventData'];

        if ($this->requireSpiffeIdentity) {
            $this->verifySpiffeSource($sourceSpiffeId);
            fwrite(STDOUT, sprintf(
                "[request-consumer] verified source=%s path=[%s]\n",
                $sourceSpiffeId,
                implode(' -> ', $spiffePath),
            ));
        }

        // ── Nested LSVID: validate any prior-level token on the inbound
        //    envelope, then forward it as the `nested` claim of the next
        //    level signed by MessageBus. When no prior token exists, the
        //    next hop becomes the base (L0) — but only when LSVID is not
        //    required. With LSVID_REQUIRED=1, absence is an error.
        $priorLsvid = null;
        $inboundLsvid = is_string($payload['lsvid'] ?? null) ? (string) $payload['lsvid'] : null;

        if (!$this->requireSpiffeIdentity) {
            // Master SPIFFE toggle off — bypass prefix + LSVID validation entirely.
            // Envelope structure was still checked above.
        } elseif ($inboundLsvid !== null) {
            // LSVID present — validator MUST be configured. A wiring error
            // where the validator is null but the envelope carries an lsvid
            // is treated as unrecoverable (fail-closed).
            if ($this->lsvidValidator === null) {
                throw new UnrecoverableMessageException(
                    'LSVID present on envelope but no LSVIDValidator is configured. '
                    . 'This is a wiring error — check worker LSVID configuration.',
                );
            }

            try {
                $workerSpiffeId = getenv('SPIFFE_ID') ?: null;

                $parsed = $this->lsvidValidator->validate(
                    $inboundLsvid,
                    expectedAudience: $workerSpiffeId,
                    expectedSubject: $sourceSpiffeId,
                );

                // Additional reconciliation: L0.sub must match envelope source.
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
            // Migration period: no lsvid, not required — proceed without.
            fwrite(STDOUT, "[request-consumer] no LSVID on envelope (migration-period fallback).\n");
        }

        $eventClass = $this->resolveEventClass($route);
        if (!class_exists($eventClass)) {
            throw new UnrecoverableMessageException(sprintf('Unknown request route: %s', $route));
        }

        $this->messageBus->publishEvent(
            eventType: $eventClass,
            eventData: $eventData,
            exchange: null,
            spiffePath: $spiffePath,
            priorLsvid: $priorLsvid,
        );

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
