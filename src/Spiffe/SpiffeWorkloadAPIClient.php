<?php

declare(strict_types=1);

namespace Spiffe;

use Google\Protobuf\Internal\Message;
use Spiffe\Runtime\RuntimeDetector;
use Spiffe\Runtime\SwowHttp2Transport;
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

/**
 * Runtime-agnostic gRPC client for the SPIFFE Workload API.
 *
 * Auto-detects Swoole or Swow and uses the appropriate HTTP/2 transport:
 *
 *   ┌──────────────────────────────────────────────────────────────┐
 *   │              SpiffeWorkloadAPIClient                         │
 *   │                                                              │
 *   │  fetchX509Svid()    watchX509Svid()    fetchJwtSvid()  ...  │
 *   │         │                  │                  │              │
 *   │         └──────────────────┴──────────────────┘              │
 *   │                            │                                 │
 *   │              ┌─────────────┴────────────┐                   │
 *   │              │    RuntimeDetector        │                   │
 *   │              └──────┬──────────┬─────────┘                   │
 *   │                     │          │                             │
 *   │          ┌──────────▼──┐  ┌────▼─────────────┐              │
 *   │          │   Swoole    │  │   Swow            │             │
 *   │          │ Http2\Client│  │ SwowHttp2Transport│             │
 *   │          └─────────────┘  └──────────────────┘              │
 *   │                     │          │                             │
 *   │                     └────┬─────┘                             │
 *   │                          ▼                                   │
 *   │              SPIRE Agent UDS socket                          │
 *   └──────────────────────────────────────────────────────────────┘
 *
 * Usage:
 *   $client = new SpiffeWorkloadAPIClient();  // auto-detect runtime
 *   $resp = $client->fetchX509Svid();
 */
class SpiffeWorkloadAPIClient
{
    private const DEFAULT_SOCKET_PATH = 'unix:/tmp/spire-agent/public/api.sock';

    private const RPC_FETCH_X509_SVID    = '/spiffe.workload.SpiffeWorkloadAPI/FetchX509SVID';
    private const RPC_FETCH_X509_BUNDLES = '/spiffe.workload.SpiffeWorkloadAPI/FetchX509Bundles';
    private const RPC_FETCH_JWT_SVID     = '/spiffe.workload.SpiffeWorkloadAPI/FetchJWTSVID';
    private const RPC_FETCH_JWT_BUNDLES  = '/spiffe.workload.SpiffeWorkloadAPI/FetchJWTBundles';
    private const RPC_VALIDATE_JWT_SVID  = '/spiffe.workload.SpiffeWorkloadAPI/ValidateJWTSVID';

    private const GRPC_HEADER_SIZE = 5;

    private string $udsPath;
    private float $connectTimeout;
    private float $recvTimeout;
    private string $runtime;

    // Swoole transport
    private $swooleClient = null;
    private bool $swooleConnected = false;

    // Swow transport
    private ?SwowHttp2Transport $swowTransport = null;

    public function __construct(
        string $socketPath = self::DEFAULT_SOCKET_PATH,
        float $connectTimeout = 5.0,
        float $recvTimeout = 30.0,
    ) {
        $this->udsPath = preg_replace('#^unix:#', '', $socketPath);
        $this->connectTimeout = $connectTimeout;
        $this->recvTimeout = $recvTimeout;
        $this->runtime = RuntimeDetector::available();
    }

    // ── Public API (same as SwooleSpiffeWorkloadAPIClient) ───────

    public function fetchX509Svid(?X509SVIDRequest $request = null): X509SVIDResponse
    {
        $request ??= new X509SVIDRequest();
        return $this->unaryCall(self::RPC_FETCH_X509_SVID, $request, X509SVIDResponse::class);
    }

    /**
     * @param callable(X509SVIDResponse): bool $onUpdate
     */
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

    /**
     * @param callable(X509BundlesResponse): bool $onUpdate
     */
    public function watchX509Bundles(callable $onUpdate, ?X509BundlesRequest $request = null): void
    {
        $request ??= new X509BundlesRequest();
        $this->serverStreamCall(self::RPC_FETCH_X509_BUNDLES, $request, X509BundlesResponse::class, $onUpdate);
    }

    public function fetchJwtSvid(JWTSVIDRequest $request): JWTSVIDResponse
    {
        return $this->unaryCall(self::RPC_FETCH_JWT_SVID, $request, JWTSVIDResponse::class);
    }

    public function fetchJwtBundles(?JWTBundlesRequest $request = null): JWTBundlesResponse
    {
        $request ??= new JWTBundlesRequest();
        return $this->unaryCall(self::RPC_FETCH_JWT_BUNDLES, $request, JWTBundlesResponse::class);
    }

    /**
     * @param callable(JWTBundlesResponse): bool $onUpdate
     */
    public function watchJwtBundles(callable $onUpdate, ?JWTBundlesRequest $request = null): void
    {
        $request ??= new JWTBundlesRequest();
        $this->serverStreamCall(self::RPC_FETCH_JWT_BUNDLES, $request, JWTBundlesResponse::class, $onUpdate);
    }

    public function validateJwtSvid(ValidateJWTSVIDRequest $request): ValidateJWTSVIDResponse
    {
        return $this->unaryCall(self::RPC_VALIDATE_JWT_SVID, $request, ValidateJWTSVIDResponse::class);
    }

    public function connect(): void
    {
        match ($this->runtime) {
            'swoole' => $this->swooleConnect(),
            'swow'   => $this->swowConnect(),
            default  => throw new \RuntimeException('No coroutine runtime available'),
        };
    }

    public function close(): void
    {
        match ($this->runtime) {
            'swoole' => $this->swooleClose(),
            'swow'   => $this->swowClose(),
            default  => null,
        };
    }

    public function isConnected(): bool
    {
        return match ($this->runtime) {
            'swoole' => $this->swooleConnected && $this->swooleClient?->connected ?? false,
            'swow'   => $this->swowTransport?->isConnected() ?? false,
            default  => false,
        };
    }

    // ── Swoole transport ─────────────────────────────────────────

    private function swooleConnect(): void
    {
        if ($this->swooleConnected) {
            return;
        }
        $this->swooleClient = new \Swoole\Coroutine\Http2\Client($this->udsPath, 0, false);
        $this->swooleClient->set([
            'timeout' => $this->connectTimeout,
            'write_timeout' => $this->recvTimeout,
            'read_timeout' => $this->recvTimeout,
        ]);
        if (!$this->swooleClient->connect()) {
            throw new \RuntimeException("Swoole: Failed to connect to {$this->udsPath}");
        }
        $this->swooleConnected = true;
    }

    private function swooleClose(): void
    {
        if ($this->swooleConnected && $this->swooleClient) {
            $this->swooleClient->close();
            $this->swooleConnected = false;
        }
    }

    private function swooleEnsureConnected(): void
    {
        if (!$this->swooleConnected || !($this->swooleClient?->connected ?? false)) {
            $this->swooleConnected = false;
            $this->swooleConnect();
        }
    }

    // ── Swow transport ───────────────────────────────────────────

    private function swowConnect(): void
    {
        if ($this->swowTransport?->isConnected()) {
            return;
        }
        $this->swowTransport = new SwowHttp2Transport($this->udsPath, $this->connectTimeout, $this->recvTimeout);
        $this->swowTransport->connect();
    }

    private function swowClose(): void
    {
        $this->swowTransport?->close();
        $this->swowTransport = null;
    }

    // ── Unified call dispatch ────────────────────────────────────

    /**
     * @template T of Message
     * @param class-string<T> $responseClass
     * @return T
     */
    private function unaryCall(string $method, Message $request, string $responseClass): Message
    {
        $payload = $this->grpcEncode($request->serializeToString());

        if ($this->runtime === 'swoole') {
            return $this->swooleUnary($method, $request, $responseClass);
        }

        // Swow path
        $this->swowConnect();
        $response = $this->swowTransport->unaryRequest($method, $payload);

        /** @var T $msg */
        $msg = new $responseClass();
        $msg->mergeFromString($this->grpcDecode($response['data']));
        return $msg;
    }

    /**
     * @template T of Message
     * @param class-string<T> $responseClass
     * @param callable(T): bool $onMessage
     */
    private function serverStreamCall(string $method, Message $request, string $responseClass, callable $onMessage): void
    {
        $payload = $this->grpcEncode($request->serializeToString());

        if ($this->runtime === 'swoole') {
            $this->swooleServerStream($method, $request, $responseClass, $onMessage);
            return;
        }

        // Swow path
        $this->swowConnect();
        $this->swowTransport->serverStreamRequest($method, $payload, function (array $frame) use ($responseClass, $onMessage): bool {
            if (empty($frame['data'])) {
                return true;
            }
            $msg = new $responseClass();
            $msg->mergeFromString($this->grpcDecode($frame['data']));
            return $onMessage($msg);
        });
    }

    // ── Swoole-specific call implementations ─────────────────────

    private function swooleUnary(string $method, Message $request, string $responseClass): Message
    {
        $this->swooleEnsureConnected();
        $req = new \Swoole\Http2\Request();
        $req->method = 'POST';
        $req->path = $method;
        $req->headers = ['content-type' => 'application/grpc', 'te' => 'trailers'];
        $req->data = $this->grpcEncode($request->serializeToString());

        $streamId = $this->swooleClient->send($req);
        if ($streamId === false) {
            $this->swooleConnected = false;
            throw new \RuntimeException("Swoole: Failed to send request to {$method}");
        }

        $response = $this->swooleClient->recv($this->recvTimeout);
        if ($response === false || $response === null) {
            $this->swooleConnected = false;
            throw new \RuntimeException("Swoole: No response from {$method}");
        }

        if (isset($response->headers['grpc-status']) && (int) $response->headers['grpc-status'] !== 0) {
            throw new \RuntimeException(sprintf(
                'gRPC error on %s — status: %s, message: %s',
                $method,
                $response->headers['grpc-status'],
                $response->headers['grpc-message'] ?? 'unknown',
            ));
        }

        $msg = new $responseClass();
        $msg->mergeFromString($this->grpcDecode($response->data));
        return $msg;
    }

    private function swooleServerStream(string $method, Message $request, string $responseClass, callable $onMessage): void
    {
        $this->swooleEnsureConnected();
        $req = new \Swoole\Http2\Request();
        $req->method = 'POST';
        $req->path = $method;
        $req->headers = ['content-type' => 'application/grpc', 'te' => 'trailers'];
        $req->data = $this->grpcEncode($request->serializeToString());
        $req->pipeline = true;

        $streamId = $this->swooleClient->send($req);
        if ($streamId === false) {
            $this->swooleConnected = false;
            throw new \RuntimeException("Swoole: Failed to send streaming request to {$method}");
        }

        while (true) {
            $response = $this->swooleClient->recv($this->recvTimeout);
            if ($response === false) {
                $this->swooleConnected = false;
                throw new \RuntimeException("Swoole: Stream interrupted on {$method}");
            }
            if (isset($response->headers['grpc-status'])) {
                $status = (int) $response->headers['grpc-status'];
                if ($status !== 0) {
                    throw new \RuntimeException(sprintf('gRPC error on %s — status: %d', $method, $status));
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

    // ── gRPC framing ─────────────────────────────────────────────

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
