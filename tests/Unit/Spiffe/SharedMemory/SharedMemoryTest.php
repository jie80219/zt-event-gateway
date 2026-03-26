<?php

declare(strict_types=1);

namespace Tests\Unit\Spiffe\SharedMemory;

use PHPUnit\Framework\TestCase;
use Spiffe\SharedMemory\SpiffeTableReader;
use Spiffe\SharedMemory\SpiffeTableSchema;
use Spiffe\SharedMemory\SpiffeTableStore;

final class SharedMemoryTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/spiffe-test-' . getmypid();
        SpiffeTableSchema::createAll($this->baseDir);
    }

    protected function tearDown(): void
    {
        SpiffeTableSchema::cleanup($this->baseDir);
    }

    public function testSchemaCreatesDirectories(): void
    {
        $this->assertDirectoryExists("{$this->baseDir}/x509");
        $this->assertDirectoryExists("{$this->baseDir}/jwt");
        $this->assertFileExists("{$this->baseDir}/meta.json");
    }

    public function testInitialMetadata(): void
    {
        $reader = new SpiffeTableReader($this->baseDir);
        $meta = $reader->readMeta();

        $this->assertSame(0, $meta['version']);
        $this->assertSame('idle', $meta['x509_state']);
        $this->assertSame('idle', $meta['jwt_state']);
        $this->assertSame(0, $meta['x509_count']);
        $this->assertFalse($reader->hasCredentials());
        $this->assertFalse($reader->isReady());
    }

    public function testPublishAndReadX509(): void
    {
        $store = new SpiffeTableStore($this->baseDir);
        $reader = new SpiffeTableReader($this->baseDir);

        // Create a mock X509Svid — we need the interface.
        // Since X509Svid requires proto, test via direct file writes.
        $mockData = [
            'spiffe_id'    => 'spiffe://zt.local/gateway',
            'trust_domain' => 'zt.local',
            'cert_pem'     => "-----BEGIN CERTIFICATE-----\nMOCK\n-----END CERTIFICATE-----\n",
            'key_pem'      => "-----BEGIN PRIVATE KEY-----\nMOCK\n-----END PRIVATE KEY-----\n",
            'bundle_pem'   => "-----BEGIN CERTIFICATE-----\nCA\n-----END CERTIFICATE-----\n",
            'hint'         => 'internal',
            'updated_at'   => time(),
        ];

        // Simulate what SpiffeTableStore::publishX509 does internally
        SpiffeTableSchema::atomicWrite(
            "{$this->baseDir}/x509/0.json",
            json_encode($mockData),
        );
        SpiffeTableSchema::atomicWrite(
            "{$this->baseDir}/meta.json",
            json_encode([
                'version' => 2, // even = complete
                'x509_state' => 'ready',
                'jwt_state' => 'idle',
                'x509_count' => 1,
                'jwt_count' => 0,
                'updated_at' => time(),
                'error' => '',
            ]),
        );

        // Read back
        $primary = $reader->readX509Primary();
        $this->assertNotNull($primary);
        $this->assertSame('spiffe://zt.local/gateway', $primary['spiffe_id']);
        $this->assertSame('zt.local', $primary['trust_domain']);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $primary['cert_pem']);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $primary['key_pem']);
        $this->assertSame('internal', $primary['hint']);

        // All X.509
        $all = $reader->readAllX509();
        $this->assertCount(1, $all);

        // By hint
        $byHint = $reader->readX509ByHint('internal');
        $this->assertNotNull($byHint);
        $this->assertNull($reader->readX509ByHint('nonexistent'));

        // Metadata
        $this->assertTrue($reader->hasCredentials());
        $this->assertSame(2, $reader->version());
    }

    public function testPublishAndReadJwtBundles(): void
    {
        $store = new SpiffeTableStore($this->baseDir);
        $reader = new SpiffeTableReader($this->baseDir);

        $store->publishJwtBundles([
            'zt.local' => '{"keys":[{"kid":"k1","kty":"EC"}]}',
        ]);

        $bundle = $reader->readJwtBundle('zt.local');
        $this->assertNotNull($bundle);
        $this->assertSame('zt.local', $bundle['trust_domain']);
        $this->assertStringContainsString('"keys"', $bundle['jwks_json']);

        $all = $reader->readAllJwtBundles();
        $this->assertArrayHasKey('zt.local', $all);
    }

    public function testStateUpdates(): void
    {
        $store = new SpiffeTableStore($this->baseDir);
        $reader = new SpiffeTableReader($this->baseDir);

        $store->updateX509State(\Spiffe\Source\SourceState::Ready);
        $store->updateJwtState(\Spiffe\Source\SourceState::Ready);

        $meta = $reader->readMeta();
        $this->assertSame('ready', $meta['x509_state']);
        $this->assertSame('ready', $meta['jwt_state']);
        $this->assertTrue($reader->isReady());
    }

    public function testErrorTracking(): void
    {
        $store = new SpiffeTableStore($this->baseDir);
        $reader = new SpiffeTableReader($this->baseDir);

        $store->updateError('connection lost');
        $meta = $reader->readMeta();
        $this->assertSame('connection lost', $meta['error']);

        $store->clearError();
        $meta = $reader->readMeta();
        $this->assertSame('', $meta['error']);
    }

    public function testOddVersionCausesRetry(): void
    {
        $reader = new SpiffeTableReader($this->baseDir);

        // Write meta with odd version (simulating mid-write)
        SpiffeTableSchema::atomicWrite(
            "{$this->baseDir}/meta.json",
            json_encode(['version' => 3, 'x509_count' => 1, 'x509_state' => 'ready', 'jwt_state' => 'idle', 'jwt_count' => 0, 'updated_at' => time(), 'error' => '']),
        );
        SpiffeTableSchema::atomicWrite(
            "{$this->baseDir}/x509/0.json",
            json_encode(['spiffe_id' => 'spiffe://zt.local/test', 'trust_domain' => 'zt.local', 'cert_pem' => 'c', 'key_pem' => 'k', 'bundle_pem' => 'b', 'hint' => '', 'updated_at' => time()]),
        );

        // Reader should spin-wait and eventually return null (version stays odd)
        $result = $reader->readX509Primary();
        $this->assertNull($result, 'Should return null when version is permanently odd');
    }

    public function testCleanup(): void
    {
        SpiffeTableSchema::cleanup($this->baseDir);
        $this->assertDirectoryDoesNotExist($this->baseDir);
    }
}
