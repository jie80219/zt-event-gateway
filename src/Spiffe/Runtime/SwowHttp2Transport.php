<?php

declare(strict_types=1);

namespace Spiffe\Runtime;

use Swow\Socket;

/**
 * Minimal HTTP/2 transport for gRPC over UDS, built on Swow's Socket.
 *
 * This replaces Swoole\Coroutine\Http2\Client for Swow deployments.
 * Implements only the gRPC-required subset of HTTP/2:
 *
 *   - Connection preface + SETTINGS exchange
 *   - HEADERS frame (with minimal HPACK literal encoding)
 *   - DATA frame (gRPC length-prefixed messages)
 *   - Response frame reading (HEADERS + DATA + trailers)
 *
 * Non-goals: flow control tuning, server push, HPACK dynamic table,
 * priority, stream multiplexing (gRPC uses one stream per call).
 */
final class SwowHttp2Transport
{
    private ?Socket $socket = null;
    private string $udsPath;
    private int $connectTimeout;
    private int $readTimeout;

    /** @var int Next stream ID (client streams are odd: 1, 3, 5, ...) */
    private int $nextStreamId = 1;

    private bool $connected = false;

    public function __construct(string $udsPath, float $connectTimeout = 5.0, float $readTimeout = 30.0)
    {
        $this->udsPath = $udsPath;
        $this->connectTimeout = (int) ($connectTimeout * 1000);
        $this->readTimeout = (int) ($readTimeout * 1000);
    }

    /**
     * Establish the HTTP/2 connection over UDS.
     */
    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        $this->socket = new Socket(Socket::TYPE_UNIX_STREAM);
        $this->socket->connect($this->udsPath, 0, $this->connectTimeout);

        // Send HTTP/2 connection preface
        $this->write(Http2Frame::CONNECTION_PREFACE);

        // Send empty SETTINGS frame
        $this->write(Http2Frame::settings());

        // Read server's SETTINGS frame
        $this->readFrame(); // server SETTINGS

        // Send SETTINGS ACK
        $this->write(Http2Frame::settings(ack: true));

        // Optionally read SETTINGS ACK from server
        // (some servers send it, some don't — just drain if available)
        $this->readFrameNonBlocking();

        // Send large window update for connection-level flow control
        $this->write(Http2Frame::windowUpdate(0, 1048576));

        $this->connected = true;
    }

    /**
     * Send a gRPC unary request and read the response.
     *
     * @return array{status: int, headers: array<string, string>, data: string}
     */
    public function unaryRequest(string $method, string $grpcPayload): array
    {
        $this->ensureConnected();
        $streamId = $this->allocateStream();

        // Send HEADERS + DATA
        $this->write(Http2Frame::grpcHeaders($method, $streamId));
        $this->write(Http2Frame::grpcData($grpcPayload, $streamId, endStream: true));

        // Read response frames
        return $this->readGrpcResponse($streamId);
    }

    /**
     * Send a gRPC server-streaming request and yield responses.
     *
     * @param callable(array{headers: array<string, string>, data: string}): bool $onFrame
     *        Return false to stop reading.
     */
    public function serverStreamRequest(string $method, string $grpcPayload, callable $onFrame): void
    {
        $this->ensureConnected();
        $streamId = $this->allocateStream();

        // Send HEADERS (no END_STREAM) + DATA with END_STREAM
        $this->write(Http2Frame::grpcHeaders($method, $streamId));
        $this->write(Http2Frame::grpcData($grpcPayload, $streamId, endStream: true));

        // Read frames until end-of-stream
        while (true) {
            $frame = $this->readFrame();
            if ($frame === null) {
                throw new \RuntimeException("Stream interrupted on {$method}");
            }

            // WINDOW_UPDATE / SETTINGS / PING — handle silently
            if (in_array($frame['type'], [Http2Frame::WINDOW_UPDATE, Http2Frame::SETTINGS, Http2Frame::PING], true)) {
                if ($frame['type'] === Http2Frame::PING && !($frame['flags'] & Http2Frame::FLAG_ACK)) {
                    $this->write(Http2Frame::encode(Http2Frame::PING, Http2Frame::FLAG_ACK, 0, $frame['payload']));
                }
                if ($frame['type'] === Http2Frame::SETTINGS && !($frame['flags'] & Http2Frame::FLAG_ACK)) {
                    $this->write(Http2Frame::settings(ack: true));
                }
                continue;
            }

            // HEADERS frame (response headers or trailers)
            if ($frame['type'] === Http2Frame::HEADERS) {
                $headers = Http2Frame::hpackDecode($frame['payload']);

                // Trailers with grpc-status → end of stream
                if (isset($headers['grpc-status'])) {
                    $status = (int) $headers['grpc-status'];
                    if ($status !== 0) {
                        throw new \RuntimeException(sprintf(
                            'gRPC error on %s — status: %d, message: %s',
                            $method,
                            $status,
                            $headers['grpc-message'] ?? 'unknown',
                        ));
                    }
                    break; // clean end of stream
                }
                continue;
            }

            // DATA frame → deliver to callback
            if ($frame['type'] === Http2Frame::DATA && $frame['stream_id'] === $streamId) {
                // Send WINDOW_UPDATE to prevent flow control stall
                if (strlen($frame['payload']) > 0) {
                    $this->write(Http2Frame::windowUpdate(0, strlen($frame['payload'])));
                    $this->write(Http2Frame::windowUpdate($streamId, strlen($frame['payload'])));
                }

                $continue = $onFrame([
                    'headers' => [],
                    'data'    => $frame['payload'],
                ]);
                if ($continue === false) {
                    break;
                }
            }

            // End of stream flag on DATA
            if ($frame['flags'] & Http2Frame::FLAG_END_STREAM) {
                break;
            }
        }
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            try {
                $this->socket->close();
            } catch (\Throwable) {
            }
            $this->socket = null;
        }
        $this->connected = false;
        $this->nextStreamId = 1;
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->socket !== null;
    }

    // ── Internal ─────────────────────────────────────────────────────

    private function ensureConnected(): void
    {
        if (!$this->isConnected()) {
            $this->connected = false;
            $this->connect();
        }
    }

    private function allocateStream(): int
    {
        $id = $this->nextStreamId;
        $this->nextStreamId += 2; // client streams are odd
        return $id;
    }

    private function write(string $data): void
    {
        $this->socket->sendString($data, $this->readTimeout);
    }

    /**
     * Read a single HTTP/2 frame.
     *
     * @return array{type: int, flags: int, stream_id: int, payload: string}|null
     */
    private function readFrame(): ?array
    {
        $headerBytes = $this->readExact(Http2Frame::HEADER_SIZE);
        if ($headerBytes === null) {
            return null;
        }

        $header = Http2Frame::decodeHeader($headerBytes);
        $payload = '';
        if ($header['length'] > 0) {
            $payload = $this->readExact($header['length']);
            if ($payload === null) {
                return null;
            }
        }

        return [
            'type'      => $header['type'],
            'flags'     => $header['flags'],
            'stream_id' => $header['stream_id'],
            'payload'   => $payload,
        ];
    }

    /**
     * Try to read a frame without blocking (best-effort drain).
     */
    private function readFrameNonBlocking(): void
    {
        try {
            $headerBytes = $this->socket->recvString(Http2Frame::HEADER_SIZE, 100);
            if (strlen($headerBytes) === Http2Frame::HEADER_SIZE) {
                $header = Http2Frame::decodeHeader($headerBytes);
                if ($header['length'] > 0) {
                    $this->socket->recvString($header['length'], 100);
                }
            }
        } catch (\Throwable) {
            // Timeout or no data — fine
        }
    }

    private function readExact(int $length): ?string
    {
        try {
            $data = $this->socket->recvString($length, $this->readTimeout);
            return strlen($data) === $length ? $data : null;
        } catch (\Throwable) {
            $this->connected = false;
            return null;
        }
    }

    /**
     * Read a full gRPC response (HEADERS + DATA + optional trailers).
     *
     * @return array{status: int, headers: array<string, string>, data: string}
     */
    private function readGrpcResponse(int $streamId): array
    {
        $responseHeaders = [];
        $responseData = '';

        while (true) {
            $frame = $this->readFrame();
            if ($frame === null) {
                throw new \RuntimeException('Connection closed while reading gRPC response');
            }

            // Handle control frames
            if (in_array($frame['type'], [Http2Frame::WINDOW_UPDATE, Http2Frame::SETTINGS, Http2Frame::PING], true)) {
                if ($frame['type'] === Http2Frame::PING && !($frame['flags'] & Http2Frame::FLAG_ACK)) {
                    $this->write(Http2Frame::encode(Http2Frame::PING, Http2Frame::FLAG_ACK, 0, $frame['payload']));
                }
                if ($frame['type'] === Http2Frame::SETTINGS && !($frame['flags'] & Http2Frame::FLAG_ACK)) {
                    $this->write(Http2Frame::settings(ack: true));
                }
                continue;
            }

            if ($frame['type'] === Http2Frame::HEADERS) {
                $headers = Http2Frame::hpackDecode($frame['payload']);
                $responseHeaders = array_merge($responseHeaders, $headers);

                if ($frame['flags'] & Http2Frame::FLAG_END_STREAM) {
                    break; // trailers-only response
                }
            }

            if ($frame['type'] === Http2Frame::DATA && $frame['stream_id'] === $streamId) {
                $responseData .= $frame['payload'];

                if (strlen($frame['payload']) > 0) {
                    $this->write(Http2Frame::windowUpdate(0, strlen($frame['payload'])));
                    $this->write(Http2Frame::windowUpdate($streamId, strlen($frame['payload'])));
                }

                if ($frame['flags'] & Http2Frame::FLAG_END_STREAM) {
                    break;
                }
            }

            if ($frame['type'] === Http2Frame::GOAWAY) {
                $this->connected = false;
                throw new \RuntimeException('Server sent GOAWAY');
            }
        }

        $httpStatus = (int) ($responseHeaders[':status'] ?? 200);
        $grpcStatus = (int) ($responseHeaders['grpc-status'] ?? 0);

        if ($grpcStatus !== 0) {
            throw new \RuntimeException(sprintf(
                'gRPC error — status: %d, message: %s',
                $grpcStatus,
                $responseHeaders['grpc-message'] ?? 'unknown',
            ));
        }

        return [
            'status'  => $httpStatus,
            'headers' => $responseHeaders,
            'data'    => $responseData,
        ];
    }
}
