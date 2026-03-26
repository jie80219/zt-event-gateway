<?php

declare(strict_types=1);

namespace Tests\Unit\Spiffe\TLS;

use PHPUnit\Framework\TestCase;
use Spiffe\TLS\TlsCredential;

final class TlsCredentialTest extends TestCase
{
    public function testBasicAccessors(): void
    {
        $cred = new TlsCredential(
            spiffeId: 'spiffe://zt.local/gw',
            trustDomain: 'zt.local',
            certPem: "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----\n",
            keyPem: "-----BEGIN PRIVATE KEY-----\nTEST\n-----END PRIVATE KEY-----\n",
            bundlePem: "-----BEGIN CERTIFICATE-----\nCA\n-----END CERTIFICATE-----\n",
            version: 42,
        );

        $this->assertSame('spiffe://zt.local/gw', $cred->spiffeId());
        $this->assertSame('zt.local', $cred->trustDomain());
        $this->assertSame(42, $cred->version());
        $this->assertTrue($cred->isValid());
    }

    public function testIsValidRequiresBothCertAndKey(): void
    {
        $empty = new TlsCredential('id', 'td', '', '', '', 0);
        $this->assertFalse($empty->isValid());

        $certOnly = new TlsCredential('id', 'td', 'cert', '', '', 0);
        $this->assertFalse($certOnly->isValid());

        $keyOnly = new TlsCredential('id', 'td', '', 'key', '', 0);
        $this->assertFalse($keyOnly->isValid());
    }

    public function testTempFilesCreatedLazily(): void
    {
        $cred = new TlsCredential(
            'id', 'td',
            "-----BEGIN CERTIFICATE-----\nCERT\n-----END CERTIFICATE-----\n",
            "-----BEGIN PRIVATE KEY-----\nKEY\n-----END PRIVATE KEY-----\n",
            "-----BEGIN CERTIFICATE-----\nCA\n-----END CERTIFICATE-----\n",
        );

        // Files should not exist yet (lazy)
        $certFile = $cred->certFile();
        $keyFile = $cred->keyFile();
        $caFile = $cred->caFile();

        $this->assertFileExists($certFile);
        $this->assertFileExists($keyFile);
        $this->assertFileExists($caFile);

        // Key file should be 0600
        $this->assertSame(0600, fileperms($keyFile) & 0777);

        // Content matches
        $this->assertStringContainsString('CERT', file_get_contents($certFile));
        $this->assertStringContainsString('KEY', file_get_contents($keyFile));
        $this->assertStringContainsString('CA', file_get_contents($caFile));

        // Cleanup removes files
        $cred->cleanup();
        $this->assertFileDoesNotExist($certFile);
        $this->assertFileDoesNotExist($keyFile);
        $this->assertFileDoesNotExist($caFile);
    }

    public function testMaterializeFiles(): void
    {
        $cred = new TlsCredential('id', 'td', 'cert', 'key', 'ca');
        $files = $cred->materializeFiles();

        $this->assertArrayHasKey('cert', $files);
        $this->assertArrayHasKey('key', $files);
        $this->assertArrayHasKey('ca', $files);
        $this->assertFileExists($files['cert']);

        $cred->cleanup();
    }
}
