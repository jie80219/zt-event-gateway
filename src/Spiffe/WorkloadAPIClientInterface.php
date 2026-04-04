<?php

declare(strict_types=1);

namespace Spiffe;

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
 * Contract for SPIFFE Workload API gRPC clients.
 *
 * Both the Swow-native (SpiffeWorkloadAPIClient) and the OpenSwoole-native
 * (SwooleSpiffeWorkloadAPIClient) implementations share this interface,
 * allowing X509Source and JwtSource to work with either coroutine runtime.
 */
interface WorkloadAPIClientInterface
{
    // ── X.509-SVID Profile ───────────────────────────────────────

    public function fetchX509Svid(?X509SVIDRequest $request = null): X509SVIDResponse;

    /** @param callable(X509SVIDResponse): bool $onUpdate */
    public function watchX509Svid(callable $onUpdate, ?X509SVIDRequest $request = null): void;

    public function fetchX509Bundles(?X509BundlesRequest $request = null): X509BundlesResponse;

    /** @param callable(X509BundlesResponse): bool $onUpdate */
    public function watchX509Bundles(callable $onUpdate, ?X509BundlesRequest $request = null): void;

    // ── JWT-SVID Profile ─────────────────────────────────────────

    public function fetchJwtSvid(JWTSVIDRequest $request): JWTSVIDResponse;

    public function fetchJwtBundles(?JWTBundlesRequest $request = null): JWTBundlesResponse;

    /** @param callable(JWTBundlesResponse): bool $onUpdate */
    public function watchJwtBundles(callable $onUpdate, ?JWTBundlesRequest $request = null): void;

    public function validateJwtSvid(ValidateJWTSVIDRequest $request): ValidateJWTSVIDResponse;

    // ── Connection lifecycle ─────────────────────────────────────

    public function connect(): void;

    public function close(): void;

    public function isConnected(): bool;
}
