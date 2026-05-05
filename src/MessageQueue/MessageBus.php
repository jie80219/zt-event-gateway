<?php

namespace SDPMlab\ZtEventGateway\MessageQueue;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;
use SDPMlab\LSVID\LSVID;
use SDPMlab\LSVID\LSVIDSigner;

class MessageBus
{
    private AMQPChannel $channel;
    private string $defaultExchange;
    private string $spiffeId;
    private ?LSVIDSigner $lsvidSigner;
    private string $downstreamAudience;
    private bool $lsvidRequired;

    public function __construct(
        AMQPChannel $channel,
        string $defaultExchange = 'events',
        ?LSVIDSigner $lsvidSigner = null,
        string $downstreamAudience = '',
        bool $lsvidRequired = false,
    ) {
        $this->channel = $channel;
        $this->defaultExchange = $defaultExchange;
        $this->spiffeId = getenv('SPIFFE_ID') ?: '';
        $this->lsvidSigner = $lsvidSigner;
        $this->downstreamAudience = $downstreamAudience;
        $this->lsvidRequired = $lsvidRequired;
    }

    public function setLsvidSigner(?LSVIDSigner $signer): void
    {
        $this->lsvidSigner = $signer;
    }

    public function setupExchange(string $exchange, string $exchangeType = 'topic'): void
    {
        $this->channel->exchange_declare($exchange, $exchangeType, false, true, false);
    }

    public function setupQueue(string $queue, string $exchange, string $routingKey = '#'): void
    {
        $this->channel->queue_declare($queue, false, true, false, false);
        $this->channel->queue_bind($queue, $exchange, $routingKey);
    }

    public function publishMessage(string $exchange, string $message, string $routingKey = 'OrderCreateRequestedEvent'): void
    {
        $msg = new AMQPMessage($message, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
        $this->channel->basic_publish($msg, $exchange, $routingKey);
    }

    /**
     * Publish an event with SPIFFE identity chain.
     *
     * When an {@see LSVIDSigner} is configured:
     *   - $priorLsvid !== null → calls extend() to wrap the prior token as
     *     the `nested` claim of a new level (L1, L2, …).
     *   - $priorLsvid === null → calls createBase() to mint a fresh L0.
     *     This is a migration-period fallback for when the gateway hasn't
     *     minted an L0 yet. When $lsvidRequired is true, this path throws.
     *
     * @param string      $eventType   Fully-qualified event class name
     * @param array       $eventData   Event payload
     * @param string|null $exchange    Override exchange (default: $defaultExchange)
     * @param array       $spiffePath  Previous SPIFFE identity chain from upstream
     * @param string|null $priorLsvid  Inbound LSVID raw token to nest (null = base level L0)
     * @param string|null $audience    Override next-hop SPIFFE ID (default: $downstreamAudience)
     */
    public function publishEvent(
        string $eventType,
        array $eventData,
        ?string $exchange = null,
        array $spiffePath = [],
        ?string $priorLsvid = null,
        ?string $audience = null,
    ): void {
        $routingKey = substr(strrchr($eventType, '\\'), 1);

        // Append current service's SPIFFE ID to the identity path
        if ($this->spiffeId !== '') {
            $spiffePath[] = $this->spiffeId;
        }

        $effectiveAudience = $audience ?? $this->downstreamAudience;

        $lsvidRaw = null;
        if ($this->lsvidSigner !== null) {
            if ($priorLsvid !== null && $priorLsvid !== '') {
                // Step 2 — Extension: wrap prior token as nested claim.
                $currentLevel = LSVID::parse($priorLsvid)->level() + 1;
                $lsvid = $this->lsvidSigner->extend(
                    priorRawToken: $priorLsvid,
                    audience: $effectiveAudience,
                    extraClaims: [
                        'eventType' => $eventType,
                        'traceId'   => $eventData['traceId'] ?? null,
                        'level'     => 'L' . $currentLevel,
                    ],
                );
                $lsvidRaw = $lsvid->raw;
            } else {
                // No prior token — migration-period fallback.
                if ($this->lsvidRequired) {
                    throw new \RuntimeException(
                        'LSVID_REQUIRED is set but no prior LSVID was provided. '
                        . 'The gateway must mint L0 at ingress; check gateway LSVID configuration.',
                    );
                }

                fwrite(STDERR, sprintf(
                    "[message-bus] WARNING: creating fallback L0 (no prior LSVID from gateway). "
                    . "Set LSVID_REQUIRED=1 to enforce gateway-minted L0.\n",
                ));

                $lsvid = $this->lsvidSigner->createBase(
                    audience: $effectiveAudience !== '' ? $effectiveAudience : $this->spiffeId,
                    extraClaims: [
                        'eventType' => $eventType,
                        'traceId'   => $eventData['traceId'] ?? null,
                        'level'     => 'L0',
                    ],
                );
                $lsvidRaw = $lsvid->raw;
            }
        }

        $envelope = [
            'type'        => $eventType,
            'data'        => $eventData,
            'spiffe_id'   => $this->spiffeId,
            'spiffe_path' => $spiffePath,
            'timestamp'   => date(DATE_RFC3339),
        ];
        if ($lsvidRaw !== null) {
            $envelope['lsvid'] = $lsvidRaw;
        }

        $message = new AMQPMessage(
            json_encode($envelope),
            ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT],
        );

        $this->channel->basic_publish($message, $exchange ?? $this->defaultExchange, $routingKey);
    }

    public function getSpiffeId(): string
    {
        return $this->spiffeId;
    }
}
