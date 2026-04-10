<?php

namespace AnserGateway\Adapter;

use Workerman\Protocols\Http\Response;

/**
 * A value-object Response that is compatible with Workerman's Response API
 * but runs inside an OpenSwoole process.
 *
 * The Gateway Kernel (AnserGateway.handleRequest) builds this object via
 * Controller + Filter calls. After handleRequest() returns, bin/gateway.php
 * reads status/headers/body and writes them into the real Swoole\Http\Response.
 *
 * Only the methods actually called by the framework are overridden.
 */
class SwooleResponseAdapter extends Response
{
    protected int $statusCode = 200;
    protected array $headers = [];
    protected string $body = '';

    public function __construct(int $status = 200, array $headers = [], string $body = '')
    {
        $this->statusCode = $status;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function withStatus(int $code, ?string $reasonPhrase = null): static
    {
        $this->statusCode = $code;

        return $this;
    }

    public function withBody(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function withHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function rawBody(): string
    {
        return $this->body;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
