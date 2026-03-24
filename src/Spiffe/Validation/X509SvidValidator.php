<?php

declare(strict_types=1);

namespace Spiffe\Validation;

use Spiffe\Bundle\X509Bundle;
use Spiffe\SpiffeId;
use Spiffe\TrustDomain;
use Spiffe\X509Svid;

/**
 * Validates X.509-SVIDs according to the SPIFFE X.509-SVID specification.
 *
 * Performs the following local checks using PHP's OpenSSL extension:
 *
 *  1. Certificate chain integrity — verifies the leaf through intermediates
 *     up to the trust bundle's CA roots
 *  2. SPIFFE ID SAN — the leaf certificate MUST contain a URI SAN matching
 *     the declared SPIFFE ID (exactly one spiffe:// URI)
 *  3. Expiration — the leaf certificate MUST be within its validity period
 *  4. Key pair matching — the private key MUST correspond to the leaf cert
 *  5. Key usage — digitalSignature and keyEncipherment per X.509-SVID spec
 *  6. Basic constraints — leaf MUST NOT be a CA certificate
 *
 * @see https://github.com/spiffe/spiffe/blob/main/standards/X509-SVID.md
 */
final class X509SvidValidator
{
    /** @var int Allowed clock skew in seconds when checking expiration */
    private int $allowedClockSkew;

    public function __construct(int $allowedClockSkew = 60)
    {
        $this->allowedClockSkew = $allowedClockSkew;
    }

    /**
     * Perform full validation of an X.509-SVID against a trust bundle.
     *
     * @param X509Svid    $svid   The SVID to validate
     * @param X509Bundle  $bundle The trust bundle for the SVID's trust domain
     *
     * @return ValidationResult
     */
    public function validate(X509Svid $svid, X509Bundle $bundle): ValidationResult
    {
        $errors = [];

        // 1. Trust domain match
        if (!$svid->trustDomain()->equals($bundle->trustDomain())) {
            $errors[] = sprintf(
                'Trust domain mismatch: SVID belongs to "%s" but bundle is for "%s"',
                $svid->trustDomain(),
                $bundle->trustDomain(),
            );
        }

        $leaf = $svid->leafCertificate();
        $leafInfo = openssl_x509_parse($leaf);
        if ($leafInfo === false) {
            return ValidationResult::failure(['Failed to parse leaf certificate']);
        }

        // 2. SPIFFE ID in SAN URI
        $sanError = $this->validateSpiffeIdSan($leafInfo, $svid->spiffeId());
        if ($sanError !== null) {
            $errors[] = $sanError;
        }

        // 3. Certificate expiration
        $expirationError = $this->validateExpiration($leafInfo);
        if ($expirationError !== null) {
            $errors[] = $expirationError;
        }

        // 4. Key pair matching
        $keyMatchError = $this->validateKeyPair($leaf, $svid);
        if ($keyMatchError !== null) {
            $errors[] = $keyMatchError;
        }

        // 5. Key usage
        $keyUsageErrors = $this->validateKeyUsage($leafInfo);
        $errors = array_merge($errors, $keyUsageErrors);

        // 6. Basic constraints — leaf must NOT be a CA
        $basicConstraintError = $this->validateBasicConstraints($leafInfo);
        if ($basicConstraintError !== null) {
            $errors[] = $basicConstraintError;
        }

        // 7. Certificate chain verification against the trust bundle
        $chainError = $this->validateChain($svid, $bundle);
        if ($chainError !== null) {
            $errors[] = $chainError;
        }

        if ($errors !== []) {
            return ValidationResult::failure($errors);
        }

        return ValidationResult::success();
    }

    /**
     * Quick check: is the SVID currently within its validity period?
     */
    public function isExpired(X509Svid $svid): bool
    {
        $info = openssl_x509_parse($svid->leafCertificate());
        if ($info === false) {
            return true;
        }

        $now = time();
        $notBefore = $info['validFrom_time_t'] ?? 0;
        $notAfter = $info['validTo_time_t'] ?? 0;

        return $now < ($notBefore - $this->allowedClockSkew)
            || $now > ($notAfter + $this->allowedClockSkew);
    }

    /**
     * Extract the SPIFFE ID from a leaf certificate's SAN URI extension.
     * Returns null if no valid SPIFFE ID is found.
     */
    public function extractSpiffeId(\OpenSSLCertificate $cert): ?SpiffeId
    {
        $info = openssl_x509_parse($cert);
        if ($info === false) {
            return null;
        }

        $uris = $this->extractSanUris($info);
        foreach ($uris as $uri) {
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

    // ──────────────────────────────────────────────────────────────────
    //  Individual validation steps
    // ──────────────────────────────────────────────────────────────────

    /**
     * Validate that the leaf cert's SAN URI matches the declared SPIFFE ID.
     */
    private function validateSpiffeIdSan(array $certInfo, SpiffeId $expectedId): ?string
    {
        $uris = $this->extractSanUris($certInfo);

        // Per spec: exactly one spiffe:// URI SAN
        $spiffeUris = array_filter($uris, fn(string $u) => str_starts_with($u, 'spiffe://'));
        $spiffeUris = array_values($spiffeUris);

        if (count($spiffeUris) === 0) {
            return 'Leaf certificate has no spiffe:// URI in Subject Alternative Name';
        }

        if (count($spiffeUris) > 1) {
            return sprintf(
                'Leaf certificate has %d spiffe:// URIs in SAN (expected exactly 1)',
                count($spiffeUris),
            );
        }

        try {
            $certSpiffeId = SpiffeId::parse($spiffeUris[0]);
        } catch (\InvalidArgumentException $e) {
            return "Invalid SPIFFE ID in certificate SAN: {$e->getMessage()}";
        }

        if (!$certSpiffeId->equals($expectedId)) {
            return sprintf(
                'SPIFFE ID mismatch: certificate SAN has "%s" but SVID declares "%s"',
                $certSpiffeId,
                $expectedId,
            );
        }

        return null;
    }

    /**
     * Validate that the certificate is within its validity period.
     */
    private function validateExpiration(array $certInfo): ?string
    {
        $now = time();
        $notBefore = $certInfo['validFrom_time_t'] ?? 0;
        $notAfter = $certInfo['validTo_time_t'] ?? 0;

        if ($now < ($notBefore - $this->allowedClockSkew)) {
            return sprintf(
                'Certificate is not yet valid (notBefore: %s)',
                date('Y-m-d\TH:i:sP', $notBefore),
            );
        }

        if ($now > ($notAfter + $this->allowedClockSkew)) {
            return sprintf(
                'Certificate has expired (notAfter: %s)',
                date('Y-m-d\TH:i:sP', $notAfter),
            );
        }

        return null;
    }

    /**
     * Validate that the private key corresponds to the leaf certificate.
     */
    private function validateKeyPair(\OpenSSLCertificate $leaf, X509Svid $svid): ?string
    {
        $matches = openssl_x509_check_private_key($leaf, $svid->privateKey());
        if (!$matches) {
            return 'Private key does not match the leaf certificate public key';
        }

        return null;
    }

    /**
     * Validate key usage extensions per the X.509-SVID specification.
     *
     * Per spec:
     *  - digitalSignature MUST be set
     *  - keyEncipherment SHOULD be set (for RSA key exchange)
     *  - keyAgreement SHOULD be set (for ECDH)
     *  - keyCertSign and cRLSign MUST NOT be set on leaf SVIDs
     *
     * @return list<string> Errors (empty if valid)
     */
    private function validateKeyUsage(array $certInfo): array
    {
        $errors = [];

        // Key Usage from extensions
        $keyUsage = $certInfo['extensions']['keyUsage'] ?? null;
        if ($keyUsage !== null) {
            $usages = array_map('trim', explode(',', $keyUsage));

            if (!in_array('Digital Signature', $usages, true)) {
                $errors[] = 'Leaf certificate missing required keyUsage: Digital Signature';
            }

            // Leaf SVID must not have CA-related usages
            if (in_array('Certificate Sign', $usages, true)) {
                $errors[] = 'Leaf certificate must not have keyUsage: Certificate Sign';
            }
            if (in_array('CRL Sign', $usages, true)) {
                $errors[] = 'Leaf certificate must not have keyUsage: CRL Sign';
            }
        }

        return $errors;
    }

    /**
     * Validate basic constraints — leaf SVID must not be a CA.
     */
    private function validateBasicConstraints(array $certInfo): ?string
    {
        $basicConstraints = $certInfo['extensions']['basicConstraints'] ?? '';

        if (str_contains($basicConstraints, 'CA:TRUE')) {
            return 'Leaf certificate has CA:TRUE in basicConstraints (must be CA:FALSE for SVIDs)';
        }

        return null;
    }

    /**
     * Verify the certificate chain from the leaf through intermediates
     * up to the trust bundle's CA roots using OpenSSL.
     */
    private function validateChain(X509Svid $svid, X509Bundle $bundle): ?string
    {
        // Write the CA bundle to a temp file for openssl_x509_verify
        $caFile = $bundle->writeTempCaFile();

        try {
            // Build the untrusted intermediate chain (everything except the leaf)
            $chain = $svid->certChain();
            $leafPem = '';
            openssl_x509_export($chain[0], $leafPem);

            $intermediatePems = [];
            for ($i = 1, $count = count($chain); $i < $count; $i++) {
                $pem = '';
                openssl_x509_export($chain[$i], $pem);
                $intermediatePems[] = $pem;
            }

            // Write intermediate certs to temp file if any
            $untrustedFile = null;
            if ($intermediatePems !== []) {
                $untrustedFile = tempnam(sys_get_temp_dir(), 'x509_untrusted_');
                file_put_contents($untrustedFile, implode('', $intermediatePems));
            }

            // Use openssl CLI for full chain verification (PHP's openssl extension
            // does not expose X509_verify_cert directly with untrusted intermediates)
            $cmd = sprintf(
                'openssl verify -CAfile %s%s',
                escapeshellarg($caFile),
                $untrustedFile !== null ? ' -untrusted ' . escapeshellarg($untrustedFile) : '',
            );

            // Write leaf to a temp file for verification
            $leafFile = tempnam(sys_get_temp_dir(), 'x509_leaf_');
            file_put_contents($leafFile, $leafPem);
            $cmd .= ' ' . escapeshellarg($leafFile);

            $output = [];
            $returnCode = 0;
            exec($cmd . ' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                return 'Certificate chain verification failed: ' . implode(' ', $output);
            }

            return null;
        } finally {
            // Clean up temporary files
            @unlink($caFile);
            if (isset($untrustedFile)) {
                @unlink($untrustedFile);
            }
            if (isset($leafFile)) {
                @unlink($leafFile);
            }
        }
    }

    /**
     * Extract URI entries from the Subject Alternative Name extension.
     *
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
            // SAN URI entries appear as "URI:spiffe://..."
            if (str_starts_with($entry, 'URI:')) {
                $uris[] = substr($entry, 4);
            }
        }

        return $uris;
    }
}
