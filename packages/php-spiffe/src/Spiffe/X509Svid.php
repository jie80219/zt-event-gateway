<?php

declare(strict_types=1);

namespace Spiffe;

use Spiffe\Workload\X509SVID as X509SVIDProto;

/**
 * Domain entity representing an X.509-SVID.
 *
 * Wraps the raw protobuf DTO with:
 *  - Parsed SpiffeId value object
 *  - Certificate chain decoded from DER to OpenSSL resources/PEM
 *  - Private key decoded from PKCS#8 DER
 *  - Trust bundle (CA certificates)
 *
 * Instances are immutable once constructed.
 *
 * @see https://github.com/spiffe/spiffe/blob/main/standards/X509-SVID.md
 */
final class X509Svid
{
    private SpiffeId $spiffeId;

    /** @var string Raw DER-encoded certificate chain */
    private string $certChainDer;

    /** @var string Raw DER-encoded PKCS#8 private key */
    private string $privateKeyDer;

    /** @var string Raw DER-encoded CA bundle */
    private string $bundleDer;

    private string $hint;

    /** @var list<\OpenSSLCertificate>|null Lazy-parsed certificate objects */
    private ?array $certChainParsed = null;

    /** @var \OpenSSLAsymmetricKey|null Lazy-parsed private key */
    private ?\OpenSSLAsymmetricKey $privateKeyParsed = null;

    private function __construct(
        SpiffeId $spiffeId,
        string $certChainDer,
        string $privateKeyDer,
        string $bundleDer,
        string $hint,
    ) {
        $this->spiffeId = $spiffeId;
        $this->certChainDer = $certChainDer;
        $this->privateKeyDer = $privateKeyDer;
        $this->bundleDer = $bundleDer;
        $this->hint = $hint;
    }

    /**
     * Build an X509Svid entity from the protobuf DTO returned by the Workload API.
     *
     * @throws \InvalidArgumentException if the SPIFFE ID or certificate data is invalid
     */
    public static function fromProto(X509SVIDProto $proto): self
    {
        $rawId = $proto->getSpiffeId();
        if ($rawId === '') {
            throw new \InvalidArgumentException('X509SVID proto is missing spiffe_id');
        }

        $certChain = $proto->getX509Svid();
        if ($certChain === '') {
            throw new \InvalidArgumentException('X509SVID proto is missing x509_svid (certificate chain)');
        }

        $privateKey = $proto->getX509SvidKey();
        if ($privateKey === '') {
            throw new \InvalidArgumentException('X509SVID proto is missing x509_svid_key (private key)');
        }

        return new self(
            SpiffeId::parse($rawId),
            $certChain,
            $privateKey,
            $proto->getBundle(),
            $proto->getHint(),
        );
    }

    public function spiffeId(): SpiffeId
    {
        return $this->spiffeId;
    }

    public function trustDomain(): TrustDomain
    {
        return $this->spiffeId->trustDomain();
    }

    public function hint(): string
    {
        return $this->hint;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Certificate chain
    // ──────────────────────────────────────────────────────────────────

    /**
     * Raw DER-encoded certificate chain bytes (leaf first).
     */
    public function certChainDer(): string
    {
        return $this->certChainDer;
    }

    /**
     * Certificate chain as a PEM-encoded string.
     *
     * The SPIRE Agent returns a concatenated DER blob containing all
     * certificates in the chain. This method splits and converts them to PEM.
     */
    public function certChainPem(): string
    {
        $pem = '';
        foreach ($this->certChain() as $cert) {
            openssl_x509_export($cert, $out);
            $pem .= $out;
        }
        return $pem;
    }

    /**
     * Parsed OpenSSL certificate objects (leaf first).
     *
     * @return list<\OpenSSLCertificate>
     * @throws \RuntimeException if DER parsing fails
     */
    public function certChain(): array
    {
        if ($this->certChainParsed !== null) {
            return $this->certChainParsed;
        }

        $this->certChainParsed = self::parseDerCertificates($this->certChainDer);

        if ($this->certChainParsed === []) {
            throw new \RuntimeException('Failed to parse any certificates from the DER chain');
        }

        return $this->certChainParsed;
    }

    /**
     * The leaf (SVID) certificate — first in the chain.
     */
    public function leafCertificate(): \OpenSSLCertificate
    {
        return $this->certChain()[0];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Private key
    // ──────────────────────────────────────────────────────────────────

    /**
     * Raw DER-encoded PKCS#8 private key bytes.
     */
    public function privateKeyDer(): string
    {
        return $this->privateKeyDer;
    }

    /**
     * Private key as a PEM-encoded string.
     */
    public function privateKeyPem(): string
    {
        return "-----BEGIN PRIVATE KEY-----\n"
            . chunk_split(base64_encode($this->privateKeyDer), 64, "\n")
            . "-----END PRIVATE KEY-----\n";
    }

    /**
     * Parsed OpenSSL private key object.
     *
     * @throws \RuntimeException if the key cannot be parsed
     */
    public function privateKey(): \OpenSSLAsymmetricKey
    {
        if ($this->privateKeyParsed !== null) {
            return $this->privateKeyParsed;
        }

        $key = openssl_pkey_get_private($this->privateKeyPem());
        if ($key === false) {
            throw new \RuntimeException('Failed to parse PKCS#8 private key from DER');
        }

        $this->privateKeyParsed = $key;
        return $key;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Trust bundle (CA certificates)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Raw DER-encoded CA bundle bytes.
     */
    public function bundleDer(): string
    {
        return $this->bundleDer;
    }

    /**
     * CA bundle as a PEM-encoded string.
     */
    public function bundlePem(): string
    {
        if ($this->bundleDer === '') {
            return '';
        }

        $pem = '';
        foreach (self::parseDerCertificates($this->bundleDer) as $cert) {
            openssl_x509_export($cert, $out);
            $pem .= $out;
        }
        return $pem;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Convenience: write to temporary files (for curl/stream contexts)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Write the certificate chain + private key + bundle to temporary files
     * and return their paths. Useful for configuring TLS stream contexts.
     *
     * @return array{cert: string, key: string, ca: string} Temporary file paths
     */
    public function writeToTempFiles(): array
    {
        $certFile = tempnam(sys_get_temp_dir(), 'svid_cert_');
        $keyFile  = tempnam(sys_get_temp_dir(), 'svid_key_');
        $caFile   = tempnam(sys_get_temp_dir(), 'svid_ca_');

        file_put_contents($certFile, $this->certChainPem());
        file_put_contents($keyFile, $this->privateKeyPem());
        chmod($keyFile, 0600);
        file_put_contents($caFile, $this->bundlePem());

        return [
            'cert' => $certFile,
            'key'  => $keyFile,
            'ca'   => $caFile,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  DER parsing utility
    // ──────────────────────────────────────────────────────────────────

    /**
     * Split a concatenated DER blob into individual X.509 certificates.
     *
     * DER is a TLV (Tag-Length-Value) encoding. Each certificate starts with
     * a SEQUENCE tag (0x30). We read the length to find where each cert ends.
     *
     * @return list<\OpenSSLCertificate>
     */
    private static function parseDerCertificates(string $der): array
    {
        $certs = [];
        $offset = 0;
        $total = strlen($der);

        while ($offset < $total) {
            // Each DER certificate starts with SEQUENCE tag (0x30)
            if (ord($der[$offset]) !== 0x30) {
                break;
            }

            $bytesConsumed = 0;
            $length = self::readDerLength($der, $offset + 1, $bytesConsumed);
            $certLen = 1 + $bytesConsumed + $length; // tag + length-field + value

            $certDer = substr($der, $offset, $certLen);
            $pem = "-----BEGIN CERTIFICATE-----\n"
                . chunk_split(base64_encode($certDer), 64, "\n")
                . "-----END CERTIFICATE-----\n";

            $parsed = openssl_x509_read($pem);
            if ($parsed !== false) {
                $certs[] = $parsed;
            }

            $offset += $certLen;
        }

        return $certs;
    }

    /**
     * Read a DER length field (BER/DER definite-length encoding).
     *
     * @param int $offset  Position of the first length byte
     * @param int $bytesConsumed  Set to the number of bytes in the length field
     * @return int The decoded length value
     */
    private static function readDerLength(string $data, int $offset, int &$bytesConsumed): int
    {
        $byte = ord($data[$offset]);

        if ($byte < 0x80) {
            // Short form: length is this byte
            $bytesConsumed = 1;
            return $byte;
        }

        // Long form: lower 7 bits = number of subsequent length bytes
        $numBytes = $byte & 0x7F;
        $bytesConsumed = 1 + $numBytes;

        $length = 0;
        for ($i = 0; $i < $numBytes; $i++) {
            $length = ($length << 8) | ord($data[$offset + 1 + $i]);
        }

        return $length;
    }
}
