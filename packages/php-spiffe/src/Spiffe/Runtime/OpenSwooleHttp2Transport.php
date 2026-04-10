<?php

declare(strict_types=1);

namespace Spiffe\Runtime;

use OpenSwoole\Coroutine\Socket;

/**
 * HTTP/2 transport for gRPC over UDS, built on OpenSwoole's Coroutine\Socket.
 *
 * Mirrors SwowHttp2Transport but uses OpenSwoole socket primitives:
 *   - Swow\Socket        → OpenSwoole\Coroutine\Socket
 *   - sendString()       → sendAll()
 *   - recvString($len)   → recvAll($len)
 *
 * HTTP/2 framing logic is shared via the Http2Frame helper.
 */
final class OpenSwooleHttp2Transport implements Http2TransportInterface
{
    private ?Socket $socket = null;
    private string $udsPath;
    private float $connectTimeout;
    private float $readTimeout;

    /** @var int Next stream ID (client streams are odd: 1, 3, 5, ...) */
    private int $nextStreamId = 1;

    private bool $connected = false;

    public function __construct(string $udsPath, float $connectTimeout = 5.0, float $readTimeout = 30.0)
    {
        $this->udsPath = $udsPath;
        $this->connectTimeout = $connectTimeout;
        $this->readTimeout = $readTimeout;
    }

    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        $this->socket = new Socket(AF_UNIX, SOCK_STREAM, 0);

        if (!$this->socket->connect($this->udsPath, 0, $this->connectTimeout)) {
            throw new \RuntimeException(sprintf(
                'Failed to connect to UDS %s: %s',
                $this->udsPath,
                $this->socket->errMsg,
            ));
        }

        // Send HTTP/2 connection preface
        $this->write(Http2Frame::CONNECTION_PREFACE);

        // Send empty SETTINGS frame
        $this->write(Http2Frame::settings());

        // Read server's SETTINGS frame
        $this->readFrame();

        // Send SETTINGS ACK
        $this->write(Http2Frame::settings(ack: true));

        // Optionally read SETTINGS ACK from server
        $this->readFrameNonBlocking();

        // Send large window update for connection-level flow control
        $this->write(Http2Frame::windowUpdate(0, 1048576));

        $this->connected = true;
    }

    public function unaryRequest(string $method, string $grpcPayload): array
    {
        $this->ensureConnected();
        $streamId = $this->allocateStream();

        $this->write(Http2Frame::grpcHeaders($method, $streamId));
        $this->write(Http2Frame::grpcData($grpcPayload, $streamId, endStream: true));

        return $this->readGrpcResponse($streamId);
    }

    public function serverStreamRequest(string $method, string $grpcPayload, callable $onFrame): void
    {
        $this->ensureConnected();
        $streamId = $this->allocateStream();

        $this->write(Http2Frame::grpcHeaders($method, $streamId));
        $this->write(Http2Frame::grpcData($grpcPayload, $streamId, endStream: true));

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
                    break;
                }
                continue;
            }

            // DATA frame → deliver to callback
            if ($frame['type'] === Http2Frame::DATA && $frame['stream_id'] === $streamId) {
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
        $this->nextStreamId += 2;
        return $id;
    }

    private function write(string $data): void
    {
        $sent = $this->socket->sendAll($data, $this->readTimeout);
        if ($sent === false) {
            $this->connected = false;
            throw new \RuntimeException('Socket write failed: ' . $this->socket->errMsg);
        }
    }

    /**
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

    private function readFrameNonBlocking(): void
    {
        try {
            $headerBytes = $this->socket->recvAll(Http2Frame::HEADER_SIZE, 0.1);
            if ($headerBytes !== false && strlen($headerBytes) === Http2Frame::HEADER_SIZE) {
                $header = Http2Frame::decodeHeader($headerBytes);
                if ($header['length'] > 0) {
                    $this->socket->recvAll($header['length'], 0.1);
                }
            }
        } catch (\Throwable) {
            // Timeout or no data — fine
        }
    }

    private function readExact(int $length): ?string
    {
        try {
            $data = $this->socket->recvAll($length, $this->readTimeout);
            if ($data === false || strlen($data) !== $length) {
                $this->connected = false;
                return null;
            }
            return $data;
        } catch (\Throwable) {
            $this->connected = false;
            return null;
        }
    }

    /**
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
                    break;
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
