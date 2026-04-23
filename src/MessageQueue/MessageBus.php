<?php

namespace SDPMlab\ZtEventGateway\MessageQueue;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;
use Keycloak\TokenProvider;

class MessageBus
{
    private AMQPChannel $channel;
    private string $defaultExchange;
    private ?TokenProvider $tokenProvider;
    private string $clientId;

    public function __construct(
        AMQPChannel $channel,
        string $defaultExchange = 'events',
        ?TokenProvider $tokenProvider = null,
    ) {
        $this->channel = $channel;
        $this->defaultExchange = $defaultExchange;
        $this->tokenProvider = $tokenProvider;
        $this->clientId = $tokenProvider?->getClientId() ?? (getenv('KEYCLOAK_CLIENT_ID') ?: '');
    }

    public function setTokenProvider(?TokenProvider $tokenProvider): void
    {
        $this->tokenProvider = $tokenProvider;
        if ($tokenProvider !== null) {
            $this->clientId = $tokenProvider->getClientId();
        }
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
     * Publish an event with the current service-account Keycloak bearer token.
     *
     * Every hop carries a flat Client Credentials token issued to the *current*
     * service (no token chaining/nesting). The `token_path` array accumulates
     * client_ids as the envelope traverses services — pure trace, not
     * cryptographically bound.
     *
     * @param string      $eventType   Fully-qualified event class name
     * @param array       $eventData   Event payload
     * @param string|null $exchange    Override exchange (default: $defaultExchange)
     * @param array       $tokenPath   Previous client_id trace from upstream
     */
    public function publishEvent(
        string $eventType,
        array $eventData,
        ?string $exchange = null,
        array $tokenPath = [],
    ): void {
        $routingKey = substr(strrchr($eventType, '\\'), 1);

        if ($this->clientId !== '') {
            $tokenPath[] = $this->clientId;
        }

        $envelope = [
            'type'       => $eventType,
            'data'       => $eventData,
            'token_path' => $tokenPath,
            'timestamp'  => date(DATE_RFC3339),
        ];

        if ($this->tokenProvider !== null) {
            $envelope['authorization'] = [
                'jwt'       => $this->tokenProvider->getAccessToken(),
                'client_id' => $this->clientId,
            ];
        }

        $message = new AMQPMessage(
            json_encode($envelope),
            ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT],
        );

        $this->channel->basic_publish($message, $exchange ?? $this->defaultExchange, $routingKey);
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }
}
