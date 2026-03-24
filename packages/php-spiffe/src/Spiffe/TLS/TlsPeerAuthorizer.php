<?php

declare(strict_types=1);

namespace Spiffe\TLS;

use Spiffe\SpiffeId;

/**
 * Core engine for SPIFFE mTLS peer authorization at the TLS handshake stage.
 *
 * Combines X.509 certificate parsing with AuthorizationPolicy evaluation
 * to produce a verified PeerIdentity or a denial decision.
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │                   TLS Handshake Authorization Flow                    │
 * │                                                                      │
 * │  ┌─────────┐     TLS      ┌─────────┐                               │
 * │  │  Client  │─────────────▶│  Server  │                              │
 * │  └─────────┘   client cert └────┬─────┘                              │
 * │                                 │                                    │
 * │           ┌─────────────────────▼──────────────────────┐             │
 * │           │          TlsPeerAuthorizer                  │             │
 * │           │                                             │             │
 * │           │  ① Extract leaf cert from connection        │             │
 * │           │     ↓                                       │             │
 * │           │  ② Parse SAN URIs → find spiffe:// entry   │             │
 * │           │     ↓                                       │             │
 * │           │  ③ Validate: exactly 1 SPIFFE URI SAN      │             │
 * │           │     ↓                                       │             │
 * │           │  ④ Parse into SpiffeId value object         │             │
 * │           │     ↓                                       │             │
 * │           │  ⑤ AuthorizationPolicy.evaluate(id)        │             │
 * │           │     ├── deny-list → DENIED                  │             │
 * │           │     ├── allow-list → ALLOWED                │             │
 * │           │     └── no match → DENIED (default deny)    │             │
 * │           │     ↓                                       │             │
 * │           │  ⑥ Build PeerIdentity (fingerprint, expiry) │             │
 * │           │     ↓                                       │             │
 * │           │  ⑦ Emit AuthorizationResult + audit log     │             │
 * │           └─────────────────────────────────────────────┘             │
 * │                                 │                                    │
 * │                    ┌────────────┴──────────────┐                     │
 * │                    ▼                           ▼                     │
 * │             AuthorizationResult          AuthorizationResult         │
 * │             { allowed: true,             { allowed: false,           │
 * │               peerId: spiffe://…,          reason: "no match" }     │
 * │               peerIdentity: … }                                     │
 * └──────────────────────────────────────────────────────────────────────┘
 */
final class TlsPeerAuthorizer
{
    private AuthorizationPolicy $policy;

    /** @var callable(AuthorizationResult): void|null */
    private $auditLogger;

    public function __construct(AuthorizationPolicy $policy, ?callable $auditLogger = null)
    {
        $this->policy = $policy;
        $this->auditLogger = $auditLogger;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Primary API: authorize from various certificate sources
    // ══════════════════════════════════════════════════════════════════

    /**
     * Authorize a peer from its PEM-encoded leaf certificate.
     *
     * This is the most common entry point — called after the TLS handshake
     * when the peer's certificate is available.
     */
    public function authorizeFromPem(string $peerCertPem): AuthorizationResult
    {
        $cert = openssl_x509_read($peerCertPem);
        if ($cert === false) {
            return $this->denied('Failed to parse peer certificate PEM');
        }

        return $this->authorizeFromCert($cert);
    }

    /**
     * Authorize a peer from an OpenSSL certificate resource.
     */
    public function authorizeFromCert(\OpenSSLCertificate $cert): AuthorizationResult
    {
        $info = openssl_x509_parse($cert);
        if ($info === false) {
            return $this->denied('Failed to parse certificate info');
        }

        // Step 1: Extract SPIFFE ID from URI SAN
        $spiffeId = $this->extractSpiffeId($info);
        if ($spiffeId === null) {
            return $this->denied(
                'No valid spiffe:// URI found in certificate SAN',
                certInfo: $info,
            );
        }

        // Step 2: Validate exactly one SPIFFE URI SAN (per spec)
        $spiffeUriCount = $this->countSpiffeUris($info);
        if ($spiffeUriCount > 1) {
            return $this->denied(
                "Certificate has {$spiffeUriCount} spiffe:// URIs in SAN (expected exactly 1)",
                $spiffeId,
                $info,
            );
        }

        // Step 3: Evaluate authorization policy
        $evaluation = $this->policy->evaluate($spiffeId);
        if (!$evaluation['allowed']) {
            return $this->denied(
                $evaluation['reason'],
                $spiffeId,
                $info,
            );
        }

        // Step 4: Build verified PeerIdentity
        $identity = PeerIdentity::fromCert($cert, $spiffeId);

        return $this->allowed($spiffeId, $evaluation['reason'], $identity, $info);
    }

    /**
     * Authorize from a PHP stream context that has `capture_peer_cert` enabled.
     *
     * Usage:
     *   $sslCtx = stream_context_create([
     *       'ssl' => ['capture_peer_cert' => true, ...]
     *   ]);
     *   $fp = fopen('tls://peer:8443', 'r', false, $sslCtx);
     *   $result = $authorizer->authorizeFromStream($sslCtx);
     *
     * @param resource $streamOrContext
     */
    public function authorizeFromStream($streamOrContext): AuthorizationResult
    {
        $params = stream_context_get_params($streamOrContext);
        $peerCert = $params['options']['ssl']['peer_certificate'] ?? null;

        if ($peerCert === null) {
            return $this->denied(
                'No peer certificate captured — ensure capture_peer_cert is enabled in the SSL context'
            );
        }

        return $this->authorizeFromCert($peerCert);
    }

    /**
     * Authorize a raw SPIFFE ID string (for testing or non-TLS contexts).
     */
    public function authorizeId(string $spiffeIdStr): AuthorizationResult
    {
        try {
            $id = SpiffeId::parse($spiffeIdStr);
        } catch (\InvalidArgumentException $e) {
            return $this->denied("Invalid SPIFFE ID: {$e->getMessage()}");
        }

        $evaluation = $this->policy->evaluate($id);
        if (!$evaluation['allowed']) {
            return $this->denied($evaluation['reason'], $id);
        }

        return AuthorizationResult::allowed($id, $evaluation['reason']);
    }

    /**
     * Authorize from a Workerman TcpConnection.
     *
     * Workerman exposes the peer certificate via the connection's
     * transport-layer socket when SSL is enabled.
     *
     * @param \Workerman\Connection\TcpConnection $connection
     */
    public function authorizeWorkermanConnection(object $connection): AuthorizationResult
    {
        // Workerman stores the raw socket in $connection->getSocket()
        if (!method_exists($connection, 'getSocket')) {
            return $this->denied('Connection object does not expose getSocket()');
        }

        $socket = $connection->getSocket();
        if (!is_resource($socket) && !$socket instanceof \Socket) {
            return $this->denied('Connection socket is not available');
        }

        // Extract peer certificate from the SSL stream
        $peerCertPem = $this->extractPeerCertFromSocket($socket);
        if ($peerCertPem === null) {
            return $this->denied('Unable to extract peer certificate from connection socket');
        }

        return $this->authorizeFromPem($peerCertPem);
    }

    /**
     * Authorize from a Swoole connection's peer certificate.
     *
     * For Swoole Server with ssl_verify_peer enabled, the peer cert
     * is accessible via the connection info.
     *
     * @param \Swoole\Server $server
     * @param int $fd  Connection file descriptor
     */
    public function authorizeSwooleConnection(object $server, int $fd): AuthorizationResult
    {
        if (!method_exists($server, 'getClientCert')) {
            return $this->denied('Swoole server does not support getClientCert()');
        }

        $peerCertPem = $server->getClientCert($fd);
        if ($peerCertPem === false || $peerCertPem === '') {
            return $this->denied('No client certificate available for this Swoole connection');
        }

        return $this->authorizeFromPem($peerCertPem);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Policy access
    // ══════════════════════════════════════════════════════════════════

    public function policy(): AuthorizationPolicy
    {
        return $this->policy;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Internal: SPIFFE ID extraction from certificate
    // ══════════════════════════════════════════════════════════════════

    /**
     * Extract the SPIFFE ID from the certificate's Subject Alternative Name.
     */
    private function extractSpiffeId(array $certInfo): ?SpiffeId
    {
        foreach ($this->extractSanUris($certInfo) as $uri) {
            if (str_starts_with($uri, 'spiffe://')) {
                try {
                    return SpiffeId::parse($uri);
                } catch (\InvalidArgumentException) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Count the number of spiffe:// URIs in the SAN extension.
     */
    private function countSpiffeUris(array $certInfo): int
    {
        $count = 0;
        foreach ($this->extractSanUris($certInfo) as $uri) {
            if (str_starts_with($uri, 'spiffe://')) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @return list<string>
     */
    private function extractSanUris(array $certInfo): array
    {
        $san = $certInfo['extensions']['subjectAltName'] ?? '';
        if ($san === '') {
            return [];
        }

        $uris = [];
        foreach (explode(',', $san) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'URI:')) {
                $uris[] = substr($entry, 4);
            }
        }

        return $uris;
    }

    /**
     * Extract peer certificate PEM from a raw PHP socket resource.
     */
    private function extractPeerCertFromSocket($socket): ?string
    {
        // For stream-based sockets (Workerman uses stream_socket_*)
        if (is_resource($socket)) {
            $meta = stream_get_meta_data($socket);
            $params = stream_context_get_params($socket);
            $cert = $params['options']['ssl']['peer_certificate'] ?? null;

            if ($cert !== null) {
                openssl_x509_export($cert, $pem);
                return $pem;
            }
        }

        return null;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Internal: result builders with audit logging
    // ══════════════════════════════════════════════════════════════════

    private function allowed(
        SpiffeId $peerId,
        string $reason,
        ?PeerIdentity $identity = null,
        ?array $certInfo = null,
    ): AuthorizationResult {
        $result = AuthorizationResult::allowed($peerId, $reason, $certInfo);
        $this->audit($result);
        return $result;
    }

    private function denied(
        string $reason,
        ?SpiffeId $peerId = null,
        ?array $certInfo = null,
    ): AuthorizationResult {
        $result = AuthorizationResult::denied($reason, $peerId, $certInfo);
        $this->audit($result);
        return $result;
    }

    private function audit(AuthorizationResult $result): void
    {
        if ($this->auditLogger !== null) {
            try {
                ($this->auditLogger)($result);
            } catch (\Throwable) {
                // Never let audit logging break the auth flow
            }
        }
    }
}
