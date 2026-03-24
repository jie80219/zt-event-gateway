<?php

declare(strict_types=1);

namespace Spiffe\TLS\Middleware;

use Spiffe\TLS\AuthorizationPolicy;
use Spiffe\TLS\AuthorizationResult;
use Spiffe\TLS\PeerIdentity;
use Spiffe\TLS\TlsPeerAuthorizer;

/**
 * Swoole Server connection-level middleware for SPIFFE mTLS authorization.
 *
 * Integrates with Swoole\Server's onConnect event to verify peer SPIFFE IDs
 * at the TLS handshake stage.
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │                     Swoole Server Pipeline                            │
 * │                                                                      │
 * │  Client ───TLS──▶ Swoole Server                                     │
 * │                     │                                                │
 * │              ┌──────▼───────────────────┐                            │
 * │              │   onConnect($server, $fd) │                           │
 * │              │   SwooleSpiffeAuth        │                           │
 * │              │     ├── getClientCert()   │                           │
 * │              │     ├── parseSanUri()     │                           │
 * │              │     ├── policy.evaluate() │                           │
 * │              │     ├── DENIED → close($fd)                          │
 * │              │     └── ALLOWED → store identity                     │
 * │              └──────────────────────────┘                            │
 * │                     │                                                │
 * │              ┌──────▼───────────────────┐                            │
 * │              │   onRequest($req, $resp)  │                           │
 * │              │   $identity = SwooleSpiffeAuth::getIdentity($fd)     │
 * │              └──────────────────────────┘                            │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * Usage:
 *
 *   $policy = AuthorizationPolicy::create()
 *       ->allowTrustDomain('zt.local');
 *
 *   $auth = new SwooleSpiffeAuth($policy);
 *
 *   $server = new Swoole\Http\Server('0.0.0.0', 8443, SWOOLE_PROCESS, SWOOLE_SOCK_TCP | SWOOLE_SSL);
 *   $server->set($tlsCtx->forSwooleServer(['spiffe://zt.local']));
 *
 *   $server->on('connect', $auth->onConnect());
 *   $server->on('close',   $auth->onClose());
 *   $server->on('request', function ($req, $resp) use ($auth) {
 *       $identity = $auth->getIdentity($req->fd);
 *       $resp->end("Hello, {$identity->spiffeId()}");
 *   });
 */
final class SwooleSpiffeAuth
{
    private TlsPeerAuthorizer $authorizer;

    /**
     * @var array<int, PeerIdentity> fd → PeerIdentity
     */
    private array $identities = [];

    /** @var callable(AuthorizationResult, int): void|null */
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
    //  Swoole Server callbacks
    // ══════════════════════════════════════════════════════════════════

    /**
     * Returns a closure for Swoole\Server->on('connect', ...).
     *
     * @return callable(\Swoole\Server, int): void
     */
    public function onConnect(): callable
    {
        return function (object $server, int $fd): void {
            $this->handleConnect($server, $fd);
        };
    }

    /**
     * Returns a closure for Swoole\Server->on('close', ...) to clean up identity state.
     *
     * @return callable(\Swoole\Server, int): void
     */
    public function onClose(): callable
    {
        return function (object $server, int $fd): void {
            unset($this->identities[$fd]);
        };
    }

    /**
     * Returns a wrapping closure for Swoole\Http\Server->on('request', ...)
     * that injects the peer identity check.
     *
     * @param callable(\Swoole\Http\Request, \Swoole\Http\Response): void $handler
     * @return callable(\Swoole\Http\Request, \Swoole\Http\Response): void
     */
    public function wrapOnRequest(callable $handler): callable
    {
        return function (object $request, object $response) use ($handler): void {
            $fd = $request->fd;

            if (!isset($this->identities[$fd])) {
                $response->status(403);
                $response->header('Content-Type', 'application/json');
                $response->end(json_encode([
                    'error'  => 'mTLS authorization failed',
                    'reason' => 'no verified SPIFFE identity for this connection',
                ], JSON_UNESCAPED_SLASHES));
                return;
            }

            $handler($request, $response);
        };
    }

    // ══════════════════════════════════════════════════════════════════
    //  Identity access
    // ══════════════════════════════════════════════════════════════════

    /**
     * Get the verified PeerIdentity for a given connection fd.
     */
    public function getIdentity(int $fd): ?PeerIdentity
    {
        return $this->identities[$fd] ?? null;
    }

    /**
     * Require a verified identity, throwing if not found.
     *
     * @throws \RuntimeException
     */
    public function requireIdentity(int $fd): PeerIdentity
    {
        return $this->identities[$fd]
            ?? throw new \RuntimeException("No SPIFFE peer identity for fd {$fd}");
    }

    /**
     * Get all currently tracked identities (for monitoring).
     *
     * @return array<int, PeerIdentity>
     */
    public function allIdentities(): array
    {
        return $this->identities;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Internal
    // ══════════════════════════════════════════════════════════════════

    private function handleConnect(object $server, int $fd): void
    {
        $result = $this->authorizer->authorizeSwooleConnection($server, $fd);

        if (!$result->isAuthorized()) {
            if ($this->onDenied !== null) {
                ($this->onDenied)($result, $fd);
            }
            $server->close($fd);
            return;
        }

        // Store the verified identity keyed by fd
        $peerId = $result->peerId();
        if ($peerId !== null) {
            // Build full PeerIdentity from the cert
            if (method_exists($server, 'getClientCert')) {
                $pem = $server->getClientCert($fd);
                if ($pem !== false && $pem !== '') {
                    $this->identities[$fd] = PeerIdentity::fromPem($pem, $peerId);
                    return;
                }
            }

            $this->identities[$fd] = new PeerIdentity($peerId, '', 0);
        }
    }
}
