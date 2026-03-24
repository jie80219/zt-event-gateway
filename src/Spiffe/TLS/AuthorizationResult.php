<?php

declare(strict_types=1);

namespace Spiffe\TLS;

use Spiffe\SpiffeId;

/**
 * Structured result of a SPIFFE peer authorization decision.
 *
 * Captures the full context of the authorization check:
 *  - The peer's SPIFFE ID (if successfully extracted)
 *  - Whether authorization was granted
 *  - The reason for the decision (for audit logging)
 *  - The raw peer certificate info (for debugging)
 */
final class AuthorizationResult
{
    private function __construct(
        private readonly bool $authorized,
        private readonly ?SpiffeId $peerId,
        private readonly string $reason,
        private readonly ?array $certInfo,
    ) {}

    public static function allowed(SpiffeId $peerId, string $reason, ?array $certInfo = null): self
    {
        return new self(true, $peerId, $reason, $certInfo);
    }

    public static function denied(string $reason, ?SpiffeId $peerId = null, ?array $certInfo = null): self
    {
        return new self(false, $peerId, $reason, $certInfo);
    }

    public function isAuthorized(): bool
    {
        return $this->authorized;
    }

    public function peerId(): ?SpiffeId
    {
        return $this->peerId;
    }

    /**
     * The SPIFFE ID as a string, or null if extraction failed.
     */
    public function peerIdString(): ?string
    {
        return $this->peerId !== null ? (string) $this->peerId : null;
    }

    /**
     * The peer's trust domain name, or null.
     */
    public function peerTrustDomain(): ?string
    {
        return $this->peerId?->trustDomain()->name();
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * Raw certificate info from openssl_x509_parse(), if available.
     */
    public function certInfo(): ?array
    {
        return $this->certInfo;
    }

    /**
     * Throw if authorization was denied.
     *
     * @throws \RuntimeException
     */
    public function throwOnDenied(): void
    {
        if (!$this->authorized) {
            throw new \RuntimeException(
                'SPIFFE peer authorization denied: ' . $this->reason
            );
        }
    }

    /**
     * Structured array for audit logging / JSON serialization.
     */
    public function toAuditLog(): array
    {
        return [
            'authorized'   => $this->authorized,
            'peer_id'      => $this->peerIdString(),
            'trust_domain' => $this->peerTrustDomain(),
            'reason'       => $this->reason,
            'timestamp'    => time(),
        ];
    }

    public function __toString(): string
    {
        $status = $this->authorized ? 'ALLOWED' : 'DENIED';
        $id = $this->peerIdString() ?? 'unknown';
        return "[{$status}] peer={$id} reason={$this->reason}";
    }
}
