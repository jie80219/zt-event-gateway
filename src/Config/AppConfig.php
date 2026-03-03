<?php

declare(strict_types=1);

namespace ZtEventGateway\Config;

final class AppConfig
{
    public function __construct(
        private readonly string $serviceName,
        private readonly string $amqpHost,
        private readonly int $amqpPort,
        private readonly string $amqpUser,
        private readonly string $amqpPassword,
        private readonly string $amqpVhost,
        private readonly string $exchange,
        private readonly string $routingKey,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            serviceName: self::env('APP_SERVICE_NAME', 'php-gateway'),
            amqpHost: self::env('AMQP_HOST', '127.0.0.1'),
            amqpPort: (int) self::env('AMQP_PORT', '5672'),
            amqpUser: self::env('AMQP_USER', 'guest'),
            amqpPassword: self::env('AMQP_PASSWORD', 'guest'),
            amqpVhost: self::env('AMQP_VHOST', '/'),
            exchange: self::env('AMQP_EXCHANGE', 'domain.events'),
            routingKey: self::env('ORDER_CREATED_ROUTING_KEY', 'orders.created.v1'),
        );
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public function serviceName(): string
    {
        return $this->serviceName;
    }

    public function amqpHost(): string
    {
        return $this->amqpHost;
    }

    public function amqpPort(): int
    {
        return $this->amqpPort;
    }

    public function amqpUser(): string
    {
        return $this->amqpUser;
    }

    public function amqpPassword(): string
    {
        return $this->amqpPassword;
    }

    public function amqpVhost(): string
    {
        return $this->amqpVhost;
    }

    public function exchange(): string
    {
        return $this->exchange;
    }

    public function routingKey(): string
    {
        return $this->routingKey;
    }
}
