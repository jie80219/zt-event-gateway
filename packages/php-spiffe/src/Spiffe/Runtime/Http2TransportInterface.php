<?php

declare(strict_types=1);

namespace Spiffe\Runtime;

/**
 * HTTP/2 transport abstraction for gRPC over UDS.
 *
 * Implementations provide the socket-level I/O using different coroutine
 * runtimes (Swow, OpenSwoole) while sharing the HTTP/2 framing logic
 * via the Http2Frame helper.
 */
interface Http2TransportInterface
{
    /**
     * Establish the HTTP/2 connection over UDS.
     */
    public function connect(): void;

    /**
     * Close the underlying socket connection.
     */
    public function close(): void;

    /**
     * Whether the transport has an active connection.
     */
    public function isConnected(): bool;

    /**
     * Send a gRPC unary request and read the response.
     *
     * @return array{status: int, headers: array<string, string>, data: string}
     */
    public function unaryRequest(string $method, string $grpcPayload): array;

    /**
     * Send a gRPC server-streaming request and yield responses.
     *
     * @param callable(array{headers: array<string, string>, data: string}): bool $onFrame
     *        Return false to stop reading.
     */
    public function serverStreamRequest(string $method, string $grpcPayload, callable $onFrame): void;
}
