<?php

declare(strict_types=1);

namespace Spiffe;

use Google\Protobuf\Internal\Message;
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
 * Swow-native gRPC client for the SPIFFE Workload API.
 *
 * Uses SwowHttp2Transport (raw HTTP/2 over Swow\Socket) to communicate
 * with the SPIRE Agent via Unix Domain Socket.
 *
 *   ┌────────────────────────────────────────────────┐
 *   │         SpiffeWorkloadAPIClient                 │
 *   │                                                 │
 *   │  fetchX509Svid()  watchX509Svid()  ...          │
 *   │         │                │                      │
 *   │         ▼                ▼                      │
 *   │  ┌──────────────────────────────────┐           │
 *   │  │      SwowHttp2Transport          │           │
 *   │  │  (HTTP/2 framing over Swow\Socket)│          │
 *   │  └──────────────┬───────────────────┘           │
 *   │                 ▼                               │
 *   │     SPIRE Agent UDS socket                      │
 *   └────────────────────────────────────────────────┘
 */
class SpiffeWorkloadAPIClient implements WorkloadAPIClientInterface
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
    private ?SwowHttp2Transport $transport = null;

    public function __construct(
        string $socketPath = self::DEFAULT_SOCKET_PATH,
        float $connectTimeout = 5.0,
        float $recvTimeout = 30.0,
    ) {
        $this->udsPath = preg_replace('#^unix:#', '', $socketPath);
        $this->connectTimeout = $connectTimeout;
        $this->recvTimeout = $recvTimeout;
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
        $this->ensureTransport();
    }

    public function close(): void
    {
        $this->transport?->close();
        $this->transport = null;
    }

    public function isConnected(): bool
    {
        return $this->transport?->isConnected() ?? false;
    }

    // ── Internal ─────────────────────────────────────────────────

    private function ensureTransport(): void
    {
        if ($this->transport?->isConnected()) {
            return;
        }
        $this->transport = new SwowHttp2Transport($this->udsPath, $this->connectTimeout, $this->recvTimeout);
        $this->transport->connect();
    }

    /**
     * @template T of Message
     * @param class-string<T> $responseClass
     * @return T
     */
    private function unaryCall(string $method, Message $request, string $responseClass): Message
    {
        $this->ensureTransport();
        $payload = $this->grpcEncode($request->serializeToString());
        $response = $this->transport->unaryRequest($method, $payload);

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
        $this->ensureTransport();
        $payload = $this->grpcEncode($request->serializeToString());

        $this->transport->serverStreamRequest($method, $payload, function (array $frame) use ($responseClass, $onMessage): bool {
            if (empty($frame['data'])) {
                return true;
            }
            /** @var T $msg */
            $msg = new $responseClass();
            $msg->mergeFromString($this->grpcDecode($frame['data']));
            return $onMessage($msg);
        });
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
