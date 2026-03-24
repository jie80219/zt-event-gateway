<?php

declare(strict_types=1);

namespace Spiffe\TLS\Middleware;

use Spiffe\TLS\AuthorizationPolicy;
use Spiffe\TLS\AuthorizationResult;
use Spiffe\TLS\PeerIdentity;
use Spiffe\TLS\TlsPeerAuthorizer;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

/**
 * Workerman connection-level middleware for SPIFFE mTLS authorization.
 *
 * Hooks into the Workerman Worker lifecycle to verify peer SPIFFE IDs
 * at the earliest possible point after the TLS handshake:
 *
 *   1. On each new TLS connection (onConnect), extract the peer certificate
 *   2. Parse the SPIFFE ID from the URI SAN
 *   3. Evaluate the AuthorizationPolicy
 *   4. If denied → close connection immediately (before any HTTP traffic)
 *   5. If allowed → attach PeerIdentity to the connection for downstream use
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │                     Workerman Request Pipeline                        │
 * │                                                                      │
 * │  Client ───TLS──▶ Worker                                            │
 * │                     │                                                │
 * │              ┌──────▼───────────────────┐                            │
 * │              │   WorkermanSpiffeAuth     │                           │
 * │              │                           │                           │
 * │              │  onConnect($conn)         │                           │
 * │              │    ├── extractPeerCert()  │                           │
 * │              │    ├── parseSanUri()      │                           │
 * │              │    ├── policy.evaluate()  │                           │
 * │              │    │                      │                           │
 * │              │    ├─▶ DENIED → $conn->close()                       │
 * │              │    │                      │                           │
 * │              │    └─▶ ALLOWED           │                           │
 * │              │         └── $conn->peerIdentity = PeerIdentity       │
 * │              └──────────────────────────┘                            │
 * │                     │                                                │
 * │              ┌──────▼───────────────────┐                            │
 * │              │   onMessage($conn, $req) │                           │
 * │              │   // handler can access: │                           │
 * │              │   $conn->peerIdentity    │                           │
 * │              │     ->spiffeId()         │                           │
 * │              │     ->trustDomain()      │                           │
 * │              └──────────────────────────┘                            │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * Usage:
 *
 *   $policy = AuthorizationPolicy::create()
 *       ->allowTrustDomain('zt.local')
 *       ->denyId('spiffe://zt.local/compromised');
 *
 *   $auth = new WorkermanSpiffeAuth($policy);
 *
 *   $worker = new Worker('https://0.0.0.0:8443', $tlsCtx->forWorkerman(true));
 *   $worker->onConnect  = $auth->onConnect();
 *   $worker->onMessage  = function ($conn, $req) {
 *       $peerId = WorkermanSpiffeAuth::getPeerIdentity($conn);
 *       // peerId is guaranteed non-null — connection was already authorized
 *   };
 */
final class WorkermanSpiffeAuth
{
    /**
     * Property name used to attach PeerIdentity to the TcpConnection.
     * Public so handlers can reference it, but prefer getPeerIdentity().
     */
    public const IDENTITY_PROP = 'spiffePeerIdentity';

    /**
     * Property name for the raw AuthorizationResult.
     */
    public const AUTH_RESULT_PROP = 'spiffeAuthResult';

    private TlsPeerAuthorizer $authorizer;

    /** @var callable(AuthorizationResult, TcpConnection): void|null */
    private $onDenied;

    public function __construct(
        AuthorizationPolicy $policy,
        ?callable $auditLogger = null,
        ?callable $onDenied = null,
    ) {
        $this->authorizer = new TlsPeerAuthorizer($policy, $auditLogger);
        $this->onDenied = $onDenied;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Workerman callbacks
    // ══════════════════════════════════════════════════════════════════

    /**
     * Returns a closure suitable for Worker->onConnect.
     *
     * @return callable(TcpConnection): void
     */
    public function onConnect(): callable
    {
        return function (TcpConnection $connection): void {
            $this->handleConnect($connection);
        };
    }

    /**
     * Returns a wrapping closure for Worker->onMessage that verifies
     * the peer identity before delegating to the actual handler.
     *
     * Use this when `onConnect` timing doesn't work for your setup
     * (e.g., the peer cert isn't available until the first message).
     *
     * @param callable(TcpConnection, Request): void $handler
     * @return callable(TcpConnection, Request): void
     */
    public function wrapOnMessage(callable $handler): callable
    {
        return function (TcpConnection $connection, Request $request) use ($handler): void {
            // If already authorized via onConnect, skip
            if (isset($connection->{self::IDENTITY_PROP})) {
                $handler($connection, $request);
                return;
            }

            // Authorize on first message
            $result = $this->authorizer->authorizeWorkermanConnection($connection);
            $connection->{self::AUTH_RESULT_PROP} = $result;

            if (!$result->isAuthorized()) {
                $this->rejectConnection($connection, $result);
                return;
            }

            // Build and attach PeerIdentity
            $peerId = $result->peerId();
            if ($peerId !== null) {
                $connection->{self::IDENTITY_PROP} = new PeerIdentity(
                    $peerId,
                    '',
                    0,
                );
            }

            $handler($connection, $request);
        };
    }

    // ══════════════════════════════════════════════════════════════════
    //  Static helpers for request handlers
    // ══════════════════════════════════════════════════════════════════

    /**
     * Get the verified PeerIdentity from a connection.
     *
     * Returns null if the connection hasn't been authorized
     * (e.g., non-TLS connection, or authorization middleware not installed).
     */
    public static function getPeerIdentity(TcpConnection $connection): ?PeerIdentity
    {
        return $connection->{self::IDENTITY_PROP} ?? null;
    }

    /**
     * Get the raw AuthorizationResult from a connection.
     */
    public static function getAuthResult(TcpConnection $connection): ?AuthorizationResult
    {
        return $connection->{self::AUTH_RESULT_PROP} ?? null;
    }

    /**
     * Require an authorized peer identity, throwing if not present.
     *
     * @throws \RuntimeException if no identity is attached
     */
    public static function requirePeerIdentity(TcpConnection $connection): PeerIdentity
    {
        $identity = self::getPeerIdentity($connection);
        if ($identity === null) {
            throw new \RuntimeException(
                'No SPIFFE peer identity on connection — ensure WorkermanSpiffeAuth middleware is installed'
            );
        }
        return $identity;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Internal
    // ══════════════════════════════════════════════════════════════════

    private function handleConnect(TcpConnection $connection): void
    {
        $result = $this->authorizer->authorizeWorkermanConnection($connection);
        $connection->{self::AUTH_RESULT_PROP} = $result;

        if (!$result->isAuthorized()) {
            $this->rejectConnection($connection, $result);
            return;
        }

        // Build and attach PeerIdentity
        $peerId = $result->peerId();
        if ($peerId !== null) {
            // Re-extract cert for full PeerIdentity fields
            $socket = $connection->getSocket();
            $params = is_resource($socket) ? stream_context_get_params($socket) : [];
            $peerCert = $params['options']['ssl']['peer_certificate'] ?? null;

            if ($peerCert !== null) {
                $connection->{self::IDENTITY_PROP} = PeerIdentity::fromCert($peerCert, $peerId);
            } else {
                $connection->{self::IDENTITY_PROP} = new PeerIdentity(
                    $peerId,
                    '',
                    0,
                );
            }
        }
    }

    private function rejectConnection(TcpConnection $connection, AuthorizationResult $result): void
    {
        if ($this->onDenied !== null) {
            ($this->onDenied)($result, $connection);
        }

        // Send a 403 response before closing (best effort — may fail for non-HTTP)
        try {
            $connection->send(new Response(
                403,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'error'   => 'mTLS authorization failed',
                    'reason'  => $result->reason(),
                    'peer_id' => $result->peerIdString(),
                ], JSON_UNESCAPED_SLASHES),
            ));
        } catch (\Throwable) {
            // May fail if connection is raw TCP, not HTTP — that's OK
        }

        $connection->close();
    }
}
