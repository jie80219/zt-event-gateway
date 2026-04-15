<?php

declare(strict_types=1);

namespace Spiffe\Bundle;

use Spiffe\TrustDomain;

/**
 * X.509 trust bundle for a single trust domain.
 *
 * Holds the set of CA certificates (trust anchors) that can verify
 * X.509-SVIDs issued under this trust domain. Bundles are received
 * from the SPIRE Agent as concatenated ASN.1 DER blobs.
 */
final class X509Bundle
{
    private TrustDomain $trustDomain;

    /** @var list<\OpenSSLCertificate> Parsed CA certificates */
    private array $authorities;

    /** @var string PEM-encoded CA bundle (cached) */
    private string $pem;

    /**
     * @param list<\OpenSSLCertificate> $authorities
     */
    private function __construct(TrustDomain $trustDomain, array $authorities, string $pem)
    {
        $this->trustDomain = $trustDomain;
        $this->authorities = $authorities;
        $this->pem = $pem;
    }

    /**
     * Create a bundle from a concatenated ASN.1 DER blob (as returned by the Workload API).
     *
     * @throws \RuntimeException if no valid certificates can be parsed
     */
    public static function fromDer(TrustDomain $trustDomain, string $der): self
    {
        if ($der === '') {
            throw new \InvalidArgumentException("Empty DER data for trust domain {$trustDomain}");
        }

        $authorities = [];
        $pem = '';
        $offset = 0;
        $total = strlen($der);

        while ($offset < $total) {
            if (ord($der[$offset]) !== 0x30) {
                break;
            }

            $bytesConsumed = 0;
            $length = self::readDerLength($der, $offset + 1, $bytesConsumed);
            $certLen = 1 + $bytesConsumed + $length;
            $certDer = substr($der, $offset, $certLen);

            $certPem = "-----BEGIN CERTIFICATE-----\n"
                . chunk_split(base64_encode($certDer), 64, "\n")
                . "-----END CERTIFICATE-----\n";

            $parsed = openssl_x509_read($certPem);
            if ($parsed !== false) {
                $authorities[] = $parsed;
                $pem .= $certPem;
            }

            $offset += $certLen;
        }

        if ($authorities === []) {
            throw new \RuntimeException(
                "Failed to parse any CA certificates from DER bundle for trust domain {$trustDomain}"
            );
        }

        return new self($trustDomain, $authorities, $pem);
    }

    /**
     * Create a bundle from PEM-encoded CA certificates.
     */
    public static function fromPem(TrustDomain $trustDomain, string $pem): self
    {
        $authorities = [];
        $pattern = '/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s';

        if (!preg_match_all($pattern, $pem, $matches, PREG_SET_ORDER)) {
            throw new \RuntimeException(
                "No PEM certificates found for trust domain {$trustDomain}"
            );
        }

        foreach ($matches as $match) {
            $certPem = "-----BEGIN CERTIFICATE-----\n"
                . trim($match[1]) . "\n"
                . "-----END CERTIFICATE-----\n";

            $parsed = openssl_x509_read($certPem);
            if ($parsed !== false) {
                $authorities[] = $parsed;
            }
        }

        if ($authorities === []) {
            throw new \RuntimeException(
                "Failed to parse any CA certificates from PEM bundle for trust domain {$trustDomain}"
            );
        }

        return new self($trustDomain, $authorities, $pem);
    }

    public function trustDomain(): TrustDomain
    {
        return $this->trustDomain;
    }

    /**
     * @return list<\OpenSSLCertificate>
     */
    public function authorities(): array
    {
        return $this->authorities;
    }

    /**
     * PEM-encoded bundle containing all CA certificates.
     */
    public function pem(): string
    {
        return $this->pem;
    }

    /**
     * Write the CA bundle to a temporary file and return its path.
     * Useful for openssl_x509_verify() or stream context configuration.
     */
    public function writeTempCaFile(): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'x509_bundle_');
        file_put_contents($tmpFile, $this->pem);
        return $tmpFile;
    }

    public function hasAuthority(\OpenSSLCertificate $cert): bool
    {
        $certInfo = openssl_x509_parse($cert);
        if ($certInfo === false) {
            return false;
        }

        foreach ($this->authorities as $authority) {
            $authInfo = openssl_x509_parse($authority);
            if ($authInfo === false) {
                continue;
            }

            if ($certInfo['subject'] === $authInfo['subject']
                && $certInfo['issuer'] === $authInfo['issuer']
                && $certInfo['serialNumber'] === $authInfo['serialNumber']) {
                return true;
            }
        }

        return false;
    }

    private static function readDerLength(string $data, int $offset, int &$bytesConsumed): int
    {
        $byte = ord($data[$offset]);

        if ($byte < 0x80) {
            $bytesConsumed = 1;
            return $byte;
        }

        $numBytes = $byte & 0x7F;
        $bytesConsumed = 1 + $numBytes;

        $length = 0;
        for ($i = 0; $i < $numBytes; $i++) {
            $length = ($length << 8) | ord($data[$offset + 1 + $i]);
        }

        return $length;
    }
}
