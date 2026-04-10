<?php

namespace AnserGateway\Adapter;

use Workerman\Protocols\Http\Request;
use Swoole\Http\Request as SwooleRequest;

/**
 * Adapts a Swoole\Http\Request to the Workerman\Protocols\Http\Request
 * interface used by Anser-Gateway's Gateway Kernel (Router, Filter,
 * Controller pipeline).
 *
 * Only the methods actually called by the framework are overridden:
 *   - uri()     — AnserGateway.php
 *   - method()  — AnserGateway.php
 *   - rawBody() — Order Controller
 *   - header()  — Order Controller
 */
class SwooleRequestAdapter extends Request
{
    private SwooleRequest $swooleRequest;

    public function __construct(SwooleRequest $swooleRequest)
    {
        $this->swooleRequest = $swooleRequest;
    }

    public function uri(): string
    {
        $uri = $this->swooleRequest->server['request_uri'] ?? '/';
        $query = $this->swooleRequest->server['query_string'] ?? '';

        return $query !== '' ? "{$uri}?{$query}" : $uri;
    }

    public function method(): string
    {
        return $this->swooleRequest->server['request_method'] ?? 'GET';
    }

    public function rawBody(): string
    {
        return $this->swooleRequest->rawContent() ?: '';
    }

    public function header(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->swooleRequest->header ?? [];
        }

        $lower = strtolower($name);

        return $this->swooleRequest->header[$lower] ?? $default;
    }

    /**
     * Expose the underlying Swoole request for edge cases.
     */
    public function getSwooleRequest(): SwooleRequest
    {
        return $this->swooleRequest;
    }
}
