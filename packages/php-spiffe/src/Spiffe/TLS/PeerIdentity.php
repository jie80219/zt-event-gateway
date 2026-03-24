<?php

declare(strict_types=1);

namespace Spiffe\TLS;

use Spiffe\SpiffeId;
use Spiffe\TrustDomain;

/**
 * Verified SPIFFE identity of a TLS peer, attached to a connection after
 * successful mTLS handshake + authorization.
 *
 * This object is the downstream artifact that request handlers use to
 * answer "who is calling me?" — it carries the cryptographically-verified
 * SPIFFE ID extracted from the peer's X.509 certificate during the TLS
 * handshake, not from an HTTP header or any spoofable source.
 *
 * Lifecycle:
 *
 *   TLS handshake
 *   → peer cert extracted (OpenSSL)
 *   → SPIFFE ID parsed from URI SAN
 *   → AuthorizationPolicy evaluated
 *   → PeerIdentity created and attached to connection/request
 *   → Handler accesses $identity->spiffeId()
 */
final class PeerIdentity
{
    private SpiffeId $spiffeId;
    private string $certFingerprint;
    private int $certExpiresAt;
    private int $verifiedAt;

    public function __construct(
        SpiffeId $spiffeId,
        string $certFingerprint,
        int $certExpiresAt,
        ?int $verifiedAt = null,
    ) {
        $this->spiffeId = $spiffeId;
        $this->certFingerprint = $certFingerprint;
        $this->certExpiresAt = $certExpiresAt;
        $this->verifiedAt = $verifiedAt ?? time();
    }

    /**
     * Build from a parsed OpenSSL certificate that has already been chain-validated.
     */
    public static function fromCert(\OpenSSLCertificate $cert, SpiffeId $spiffeId): self
    {
        $info = openssl_x509_parse($cert);

        // SHA-256 fingerprint of the DER-encoded certificate
        openssl_x509_export($cert, $pem);
        $der = base64_decode(
            str_replace(["\n", "\r", '-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'], '', $pem)
        );
        $fingerprint = hash('sha256', $der);

        return new self(
            $spiffeId,
            $fingerprint,
            $info['validTo_time_t'] ?? 0,
        );
    }

    /**
     * Build from PEM string + already-extracted SPIFFE ID.
     */
    public static function fromPem(string $pem, SpiffeId $spiffeId): self
    {
        $cert = openssl_x509_read($pem);
        if ($cert === false) {
            throw new \RuntimeException('Failed to parse peer certificate PEM');
        }
        return self::fromCert($cert, $spiffeId);
    }

    public function spiffeId(): SpiffeId
    {
        return $this->spiffeId;
    }

    public function trustDomain(): TrustDomain
    {
        return $this->spiffeId->trustDomain();
    }

    /**
     * The workload path component (e.g. "/gateway").
     */
    public function workloadPath(): string
    {
        return $this->spiffeId->path();
    }

    /**
     * SHA-256 fingerprint of the peer's leaf certificate (hex-encoded).
     */
    public function certFingerprint(): string
    {
        return $this->certFingerprint;
    }

    /**
     * Unix timestamp when the peer's certificate expires.
     */
    public function certExpiresAt(): int
    {
        return $this->certExpiresAt;
    }

    /**
     * Unix timestamp when this identity was verified.
     */
    public function verifiedAt(): int
    {
        return $this->verifiedAt;
    }

    /**
     * Check if this peer belongs to the given trust domain.
     */
    public function isMemberOf(string $trustDomain): bool
    {
        return $this->spiffeId->memberOf(TrustDomain::parse($trustDomain));
    }

    /**
     * Check if this peer's path starts with the given prefix.
     */
    public function hasPathPrefix(string $prefix): bool
    {
        return str_starts_with($this->spiffeId->path(), $prefix);
    }

    public function __toString(): string
    {
        return (string) $this->spiffeId;
    }
}
