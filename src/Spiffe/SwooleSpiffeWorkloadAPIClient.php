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

/**
 * OpenSwoole-native gRPC client for the SPIFFE Workload API.
 *
 * Uses Swoole\Coroutine\Http2\Client for native HTTP/2 over UDS.
 * No hand-rolled HTTP/2 framing needed — Swoole handles it all.
 */
class SwooleSpiffeWorkloadAPIClient
{
    private const DEFAULT_SOCKET_PATH = 'unix:/tmp/spire-agent/public/api.sock';

    private const RPC_FETCH_X509_SVID    = '/spiffe.workload.SpiffeWorkloadAPI/FetchX509SVID';
    private const RPC_FETCH_X509_BUNDLES = '/spiffe.workload.SpiffeWorkloadAPI/FetchX509Bundles';
    private const RPC_FETCH_JWT_SVID     = '/spiffe.workload.SpiffeWorkloadAPI/FetchJWTSVID';
    private const RPC_FETCH_JWT_BUNDLES  = '/spiffe.workload.SpiffeWorkloadAPI/FetchJWTBundles';
    private const RPC_VALIDATE_JWT_SVID  = '/spiffe.workload.SpiffeWorkloadAPI/ValidateJWTSVID';

    private const GRPC_HEADER_SIZE = 5;

    private Http2Client $client;
    private string $udsPath;
    private bool $connected = false;
    private float $connectTimeout;
    private float $recvTimeout;

    public function __construct(
        string $socketPath = self::DEFAULT_SOCKET_PATH,
        float $connectTimeout = 5.0,
        float $recvTimeout = 30.0,
    ) {
        $this->udsPath = preg_replace('#^unix:#', '', $socketPath);
        $this->connectTimeout = $connectTimeout;
        $this->recvTimeout = $recvTimeout;
        $this->initClient();
    }

    // ── X.509-SVID Profile ───────────────────────────────────────

    public function fetchX509Svid(?X509SVIDRequest $request = null): X509SVIDResponse
    {
        $request ??= new X509SVIDRequest();
        return $this->unaryCall(self::RPC_FETCH_X509_SVID, $request, X509SVIDResponse::class);
    }

    /** @param callable(X509SVIDResponse): bool $onUpdate */
    public function watchX509Svid(callable $onUpdate, ?X509SVIDRequest $request = null): void
    {
        $request ??= new X509SVIDRequest();
        $this->serverStreamCall(self::RPC_FETCH_X509_SVID, $request, X509SVIDResponse::class, $onUpdate);
    }

    public function fetchX509Bundles(?X509BundlesRequest $request = null): X509BundlesResponse
    {
        $request ??= new X509BundlesRequest();
        return $this->unaryCall(self::RPC_FETCH_X509_BUNDLES, $request, X509BundlesResponse::class);
    }

    /** @param callable(X509BundlesResponse): bool $onUpdate */
    public function watchX509Bundles(callable $onUpdate, ?X509BundlesRequest $request = null): void
    {
        $request ??= new X509BundlesRequest();
        $this->serverStreamCall(self::RPC_FETCH_X509_BUNDLES, $request, X509BundlesResponse::class, $onUpdate);
    }

    // ── JWT-SVID Profile ─────────────────────────────────────────

    public function fetchJwtSvid(JWTSVIDRequest $request): JWTSVIDResponse
    {
        return $this->unaryCall(self::RPC_FETCH_JWT_SVID, $request, JWTSVIDResponse::class);
    }

    public function fetchJwtBundles(?JWTBundlesRequest $request = null): JWTBundlesResponse
    {
        $request ??= new JWTBundlesRequest();
        return $this->unaryCall(self::RPC_FETCH_JWT_BUNDLES, $request, JWTBundlesResponse::class);
    }

    /** @param callable(JWTBundlesResponse): bool $onUpdate */
    public function watchJwtBundles(callable $onUpdate, ?JWTBundlesRequest $request = null): void
    {
        $request ??= new JWTBundlesRequest();
        $this->serverStreamCall(self::RPC_FETCH_JWT_BUNDLES, $request, JWTBundlesResponse::class, $onUpdate);
    }

    public function validateJwtSvid(ValidateJWTSVIDRequest $request): ValidateJWTSVIDResponse
    {
        return $this->unaryCall(self::RPC_VALIDATE_JWT_SVID, $request, ValidateJWTSVIDResponse::class);
    }

    // ── Connection lifecycle ─────────────────────────────────────

    public function connect(): void
    {
        if ($this->connected) {
            return;
        }
        $this->initClient();
        if (!$this->client->connect()) {
            throw new \RuntimeException(sprintf(
                'Failed to connect to SPIRE Agent at %s — errno: %d, error: %s',
                $this->udsPath,
                $this->client->errCode,
                socket_strerror($this->client->errCode),
            ));
        }
        $this->connected = true;
    }

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

    // ── Internal transport ───────────────────────────────────────

    private function initClient(): void
    {
        $this->client = new Http2Client($this->udsPath, 0, false);
        $this->client->set([
            'timeout'       => $this->connectTimeout,
            'write_timeout' => $this->recvTimeout,
            'read_timeout'  => $this->recvTimeout,
        ]);
        $this->connected = false;
    }

    private function ensureConnected(): void
    {
        if (!$this->isConnected()) {
            $this->connected = false;
            $this->connect();
        }
    }

    private function buildRequest(string $method, Message $message): Http2Request
    {
        $req = new Http2Request();
        $req->method = 'POST';
        $req->path = $method;
        $req->headers = [
            'content-type' => 'application/grpc',
            'te'           => 'trailers',
        ];
        $req->data = $this->grpcEncode($message->serializeToString());
        return $req;
    }

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

        $msg = new $responseClass();
        $msg->mergeFromString($this->grpcDecode($response->data));
        return $msg;
    }

    private function serverStreamCall(string $method, Message $request, string $responseClass, callable $onMessage): void
    {
        $this->ensureConnected();

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
                $this->connected = false;
                throw new \RuntimeException(sprintf('Stream interrupted on %s — errno: %d', $method, $this->client->errCode));
            }

            if (isset($response->headers['grpc-status'])) {
                $grpcStatus = (int) $response->headers['grpc-status'];
                if ($grpcStatus !== 0) {
                    throw new \RuntimeException(sprintf(
                        'gRPC error on %s — status: %d, message: %s',
                        $method, $grpcStatus, $response->headers['grpc-message'] ?? 'unknown',
                    ));
                }
                break;
            }

            if (!empty($response->data)) {
                $msg = new $responseClass();
                $msg->mergeFromString($this->grpcDecode($response->data));
                if ($onMessage($msg) === false) {
                    break;
                }
            }
        }
    }

    private function assertValidResponse($response, string $method): void
    {
        if ($response === false || $response === null) {
            $this->connected = false;
            throw new \RuntimeException(sprintf('No response from %s — errno: %d', $method, $this->client->errCode));
        }
        if ($response->statusCode !== 200) {
            throw new \RuntimeException(sprintf('HTTP error on %s — status: %d', $method, $response->statusCode));
        }
        if (isset($response->headers['grpc-status']) && (int) $response->headers['grpc-status'] !== 0) {
            throw new \RuntimeException(sprintf(
                'gRPC error on %s — status: %s, message: %s',
                $method, $response->headers['grpc-status'], $response->headers['grpc-message'] ?? 'unknown',
            ));
        }
    }

    private function grpcEncode(string $data): string
    {
        return pack('CN', 0, strlen($data)) . $data;
    }

    private function grpcDecode(string $data): string
    {
        if (strlen($data) < self::GRPC_HEADER_SIZE) {
            throw new \RuntimeException('Invalid gRPC frame: data too short');
        }
        return substr($data, self::GRPC_HEADER_SIZE);
    }
}
