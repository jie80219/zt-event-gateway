<?php

declare(strict_types=1);

namespace Spiffe;

use Google\Protobuf\Internal\Message;
use Spiffe\Workload\JWTBundlesRequest;
use Spiffe\Workload\JWTBundlesResponse;
use Spiffe\Workload\JWTSVIDRequest;
use Spiffe\Workload\JWTSVIDResponse;
use Spiffe\Workload\ValidateJWTSVIDRequest;
use Spiffe\Workload\ValidateJWTSVIDResponse;
use Spiffe\Workload\X509BundlesRequest;
use Spiffe\Workload\X509BundlesResponse;
use Spiffe\Workload\X509SVIDRequest;
use Spiffe\Workload\X509SVIDResponse;
use Swoole\Coroutine\Http2\Client as Http2Client;
use Swoole\Http2\Request as Http2Request;
use Swoole\Http2\Response as Http2Response;

/**
 * Swoole-based gRPC client for the SPIFFE Workload API.
 *
 * Solves the fundamental mismatch between PHP's request-destroy lifecycle
 * (FPM/Apache) and gRPC's requirement for persistent HTTP/2 connections:
 *
 * - Uses Swoole's coroutine HTTP/2 client for non-blocking, long-lived connections
 * - Connects via Unix Domain Socket (UDS) to the local SPIRE Agent
 * - Maintains a persistent connection that survives across multiple calls
 * - Supports server-streaming RPCs with callback-based consumption
 * - Auto-reconnects on connection failure
 */
class SwooleSpiffeWorkloadAPIClient
{
    private const DEFAULT_SOCKET_PATH = 'unix:/tmp/spire-agent/public/api.sock';

    private const RPC_FETCH_X509_SVID    = '/spiffe.workload.SpiffeWorkloadAPI/FetchX509SVID';
    private const RPC_FETCH_X509_BUNDLES = '/spiffe.workload.SpiffeWorkloadAPI/FetchX509Bundles';
    private const RPC_FETCH_JWT_SVID     = '/spiffe.workload.SpiffeWorkloadAPI/FetchJWTSVID';
    private const RPC_FETCH_JWT_BUNDLES  = '/spiffe.workload.SpiffeWorkloadAPI/FetchJWTBundles';
    private const RPC_VALIDATE_JWT_SVID  = '/spiffe.workload.SpiffeWorkloadAPI/ValidateJWTSVID';

    /** gRPC frame header: 1 byte compression flag + 4 bytes message length */
    private const GRPC_HEADER_SIZE = 5;

    private Http2Client $client;
    private string $udsPath;
    private bool $connected = false;

    /** @var float Connection timeout in seconds */
    private float $connectTimeout;

    /** @var float Read/write timeout in seconds */
    private float $recvTimeout;

    public function __construct(
        string $socketPath = self::DEFAULT_SOCKET_PATH,
        float $connectTimeout = 5.0,
        float $recvTimeout = 30.0,
    ) {
        // Strip 'unix:' prefix — Swoole Http2\Client expects a raw filesystem path
        $this->udsPath = preg_replace('#^unix:#', '', $socketPath);
        $this->connectTimeout = $connectTimeout;
        $this->recvTimeout = $recvTimeout;

        $this->initClient();
    }

    // ──────────────────────────────────────────────────────────────────
    //  X.509-SVID Profile
    // ──────────────────────────────────────────────────────────────────

    /**
     * Fetch a single X.509-SVID response (first frame from the server stream).
     *
     * Use this when you only need the current SVID snapshot.
     */
    public function fetchX509Svid(?X509SVIDRequest $request = null): X509SVIDResponse
    {
        $request ??= new X509SVIDRequest();

        return $this->unaryCall(
            self::RPC_FETCH_X509_SVID,
            $request,
            X509SVIDResponse::class,
        );
    }

    /**
     * Subscribe to the X.509-SVID stream.
     *
     * The SPIRE Agent pushes a new X509SVIDResponse whenever certificates
     * rotate. The callback is invoked for each update; return false from
     * the callback to stop watching.
     *
     * @param callable(X509SVIDResponse): bool $onUpdate
     */
    public function watchX509Svid(callable $onUpdate, ?X509SVIDRequest $request = null): void
    {
        $request ??= new X509SVIDRequest();

        $this->serverStreamCall(
            self::RPC_FETCH_X509_SVID,
            $request,
            X509SVIDResponse::class,
            $onUpdate,
        );
    }

    /**
     * Fetch current X.509 bundles (first frame from the server stream).
     */
    public function fetchX509Bundles(?X509BundlesRequest $request = null): X509BundlesResponse
    {
        $request ??= new X509BundlesRequest();

        return $this->unaryCall(
            self::RPC_FETCH_X509_BUNDLES,
            $request,
            X509BundlesResponse::class,
        );
    }

    /**
     * Subscribe to X.509 bundle updates.
     *
     * @param callable(X509BundlesResponse): bool $onUpdate
     */
    public function watchX509Bundles(callable $onUpdate, ?X509BundlesRequest $request = null): void
    {
        $request ??= new X509BundlesRequest();

        $this->serverStreamCall(
            self::RPC_FETCH_X509_BUNDLES,
            $request,
            X509BundlesResponse::class,
            $onUpdate,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    //  JWT-SVID Profile
    // ──────────────────────────────────────────────────────────────────

    /**
     * Fetch JWT-SVIDs for the requested audience (unary RPC).
     */
    public function fetchJwtSvid(JWTSVIDRequest $request): JWTSVIDResponse
    {
        return $this->unaryCall(
            self::RPC_FETCH_JWT_SVID,
            $request,
            JWTSVIDResponse::class,
        );
    }

    /**
     * Fetch current JWT bundles (first frame from the server stream).
     */
    public function fetchJwtBundles(?JWTBundlesRequest $request = null): JWTBundlesResponse
    {
        $request ??= new JWTBundlesRequest();

        return $this->unaryCall(
            self::RPC_FETCH_JWT_BUNDLES,
            $request,
            JWTBundlesResponse::class,
        );
    }

    /**
     * Subscribe to JWT bundle updates.
     *
     * @param callable(JWTBundlesResponse): bool $onUpdate
     */
    public function watchJwtBundles(callable $onUpdate, ?JWTBundlesRequest $request = null): void
    {
        $request ??= new JWTBundlesRequest();

        $this->serverStreamCall(
            self::RPC_FETCH_JWT_BUNDLES,
            $request,
            JWTBundlesResponse::class,
            $onUpdate,
        );
    }

    /**
     * Validate a JWT-SVID against the expected audience (unary RPC).
     */
    public function validateJwtSvid(ValidateJWTSVIDRequest $request): ValidateJWTSVIDResponse
    {
        return $this->unaryCall(
            self::RPC_VALIDATE_JWT_SVID,
            $request,
            ValidateJWTSVIDResponse::class,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    //  Connection lifecycle
    // ──────────────────────────────────────────────────────────────────

    /**
     * Establish or re-establish the HTTP/2 connection to the SPIRE Agent.
     *
     * @throws \RuntimeException if the connection cannot be established
     */
    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        $this->initClient();

        $ok = $this->client->connect();
        if (!$ok) {
            throw new \RuntimeException(sprintf(
                'Failed to connect to SPIRE Agent at %s — errno: %d, error: %s',
                $this->udsPath,
                $this->client->errCode,
                socket_strerror($this->client->errCode),
            ));
        }

        $this->connected = true;
    }

    /**
     * Gracefully close the connection.
     */
    public function close(): void
    {
        if ($this->connected) {
            $this->client->close();
            $this->connected = false;
        }
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->client->connected;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Internal: transport layer
    // ──────────────────────────────────────────────────────────────────

    private function initClient(): void
    {
        // Swoole Http2\Client accepts (host, port, ssl).
        // For UDS: host = socket path, port = 0, ssl = false.
        $this->client = new Http2Client($this->udsPath, 0, false);

        $this->client->set([
            'timeout'         => $this->connectTimeout,
            'write_timeout'   => $this->recvTimeout,
            'read_timeout'    => $this->recvTimeout,
        ]);

        $this->connected = false;
    }

    /**
     * Ensure we have a live connection, reconnecting if necessary.
     */
    private function ensureConnected(): void
    {
        if (!$this->isConnected()) {
            $this->connected = false;
            $this->connect();
        }
    }

    /**
     * Build a gRPC HTTP/2 request.
     */
    private function buildRequest(string $method, Message $message): Http2Request
    {
        $req = new Http2Request();
        $req->method = 'POST';
        $req->path   = $method;
        $req->headers = [
            'content-type' => 'application/grpc',
            'te'           => 'trailers',
        ];
        $req->data = $this->grpcEncode($message->serializeToString());

        return $req;
    }

    /**
     * Perform a unary-style call. For server-streaming RPCs this reads only
     * the first response frame (useful for one-shot fetches).
     *
     * @template T of Message
     * @param class-string<T> $responseClass
     * @return T
     *
     * @throws \RuntimeException on transport or gRPC-level errors
     */
    private function unaryCall(string $method, Message $request, string $responseClass): Message
    {
        $this->ensureConnected();

        $req = $this->buildRequest($method, $request);
        $streamId = $this->client->send($req);

        if ($streamId === false) {
            $this->connected = false;
            throw new \RuntimeException("Failed to send gRPC request to {$method}");
        }

        $response = $this->client->recv($this->recvTimeout);
        $this->assertValidResponse($response, $method);

        /** @var T $msg */
        $msg = new $responseClass();
        $msg->mergeFromString($this->grpcDecode($response->data));

        return $msg;
    }

    /**
     * Consume a server-streaming RPC. Each frame from the server is decoded
     * and passed to $onMessage. Return false from the callback to cancel.
     *
     * @template T of Message
     * @param class-string<T> $responseClass
     * @param callable(T): bool $onMessage
     */
    private function serverStreamCall(
        string $method,
        Message $request,
        string $responseClass,
        callable $onMessage,
    ): void {
        $this->ensureConnected();

        // Use pipeline mode so we can keep reading frames on the same stream
        $req = $this->buildRequest($method, $request);
        $req->pipeline = true;

        $streamId = $this->client->send($req);
        if ($streamId === false) {
            $this->connected = false;
            throw new \RuntimeException("Failed to send streaming gRPC request to {$method}");
        }

        while (true) {
            $response = $this->client->recv($this->recvTimeout);

            if ($response === false) {
                // Timeout or connection lost
                $this->connected = false;
                throw new \RuntimeException(sprintf(
                    'Stream interrupted on %s — errno: %d',
                    $method,
                    $this->client->errCode,
                ));
            }

            // End-of-stream: HEADERS-only frame with grpc-status trailer
            if (isset($response->headers['grpc-status'])) {
                $grpcStatus = (int) $response->headers['grpc-status'];
                if ($grpcStatus !== 0) {
                    throw new \RuntimeException(sprintf(
                        'gRPC error on %s — status: %d, message: %s',
                        $method,
                        $grpcStatus,
                        $response->headers['grpc-message'] ?? 'unknown',
                    ));
                }
                break; // clean end of stream
            }

            // Decode the DATA frame
            if (!empty($response->data)) {
                /** @var T $msg */
                $msg = new $responseClass();
                $msg->mergeFromString($this->grpcDecode($response->data));

                $continue = $onMessage($msg);
                if ($continue === false) {
                    break; // caller requested cancellation
                }
            }
        }
    }

    /**
     * Validate a gRPC response, throwing on transport or application errors.
     */
    private function assertValidResponse(?Http2Response $response, string $method): void
    {
        if ($response === false || $response === null) {
            $this->connected = false;
            throw new \RuntimeException(sprintf(
                'No response from %s — errno: %d',
                $method,
                $this->client->errCode,
            ));
        }

        if ($response->statusCode !== 200) {
            throw new \RuntimeException(sprintf(
                'HTTP error on %s — status: %d',
                $method,
                $response->statusCode,
            ));
        }

        // Check gRPC-level status in trailers (may appear on the first frame for unary)
        if (isset($response->headers['grpc-status']) && (int) $response->headers['grpc-status'] !== 0) {
            throw new \RuntimeException(sprintf(
                'gRPC error on %s — status: %s, message: %s',
                $method,
                $response->headers['grpc-status'],
                $response->headers['grpc-message'] ?? 'unknown',
            ));
        }
    }

    // ──────────────────────────────────────────────────────────────────
    //  gRPC frame encoding
    // ──────────────────────────────────────────────────────────────────

    /**
     * Encode a serialized protobuf payload into a gRPC Length-Prefixed Message.
     *
     * Wire format: [1 byte compressed flag][4 bytes big-endian length][payload]
     */
    private function grpcEncode(string $data): string
    {
        return pack('CN', 0, strlen($data)) . $data;
    }

    /**
     * Decode a gRPC Length-Prefixed Message, stripping the 5-byte header.
     */
    private function grpcDecode(string $data): string
    {
        if (strlen($data) < self::GRPC_HEADER_SIZE) {
            throw new \RuntimeException('Invalid gRPC frame: data too short');
        }

        return substr($data, self::GRPC_HEADER_SIZE);
    }
}
