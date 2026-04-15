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

    public function testIsStaleReportsFreshOnRecentWrite(): void
    {
        $reader = new SpiffeTableReader($this->baseDir);

        SpiffeTableSchema::atomicWrite(
            "{$this->baseDir}/meta.json",
            json_encode([
                'version' => 2, 'x509_state' => 'ready', 'jwt_state' => 'ready',
                'x509_count' => 1, 'jwt_count' => 0,
                'updated_at' => time(), 'error' => '',
            ]),
        );

        $this->assertFalse($reader->isStale(60), 'just-written meta should not be stale');
    }

    public function testIsStaleReportsStaleOnOldUpdate(): void
    {
        $reader = new SpiffeTableReader($this->baseDir);

        SpiffeTableSchema::atomicWrite(
            "{$this->baseDir}/meta.json",
            json_encode([
                'version' => 2, 'x509_state' => 'ready', 'jwt_state' => 'ready',
                'x509_count' => 1, 'jwt_count' => 0,
                'updated_at' => time() - 3600, 'error' => '',
            ]),
        );

        $this->assertTrue($reader->isStale(60), '1h-old meta must be stale at 60s threshold');
        $this->assertFalse($reader->isStale(7200), '1h-old meta is fresh at 2h threshold');
    }

    public function testIsStaleReportsStaleWhenNeverUpdated(): void
    {
        // Fresh createAll() leaves updated_at=0 → secondsSinceLastUpdate() returns -1
        $reader = new SpiffeTableReader($this->baseDir);
        $this->assertTrue($reader->isStale(60), 'never-updated meta must report stale');
    }

    public function testWatchVersionFiresOnChange(): void
    {
        $reader = new SpiffeTableReader($this->baseDir);

        $writeMeta = function (int $version): void {
            SpiffeTableSchema::atomicWrite(
                "{$this->baseDir}/meta.json",
                json_encode([
                    'version' => $version, 'x509_state' => 'ready', 'jwt_state' => 'ready',
                    'x509_count' => 0, 'jwt_count' => 0,
                    'updated_at' => time(), 'error' => '',
                ]),
            );
        };

        $writeMeta(2);

        $fires = [];
        $iterations = 0;

        $reader->watchVersion(
            onChange: static function (int $new, int $old) use (&$fires): void {
                $fires[] = [$old, $new];
            },
            pollInterval: 0.0,
            running: function () use (&$iterations, $writeMeta): bool {
                $iterations++;
                if ($iterations === 2) {
                    $writeMeta(4);
                }
                if ($iterations === 4) {
                    $writeMeta(6);
                }
                return $iterations < 6;
            },
            sleeper: static fn(float $s) => null,
        );

        $this->assertCount(2, $fires, 'should fire on each version bump');
        $this->assertSame([2, 4], $fires[0]);
        $this->assertSame([4, 6], $fires[1]);
    }

    public function testWatchVersionDoesNotFireWhenStable(): void
    {
        $reader = new SpiffeTableReader($this->baseDir);
        SpiffeTableSchema::atomicWrite(
            "{$this->baseDir}/meta.json",
            json_encode([
                'version' => 4, 'x509_state' => 'ready', 'jwt_state' => 'ready',
                'x509_count' => 0, 'jwt_count' => 0,
                'updated_at' => time(), 'error' => '',
            ]),
        );

        $fires = 0;
        $iterations = 0;
        $reader->watchVersion(
            onChange: static function () use (&$fires): void { $fires++; },
            pollInterval: 0.0,
            running: static function () use (&$iterations): bool {
                $iterations++;
                return $iterations < 5;
            },
            sleeper: static fn(float $s) => null,
        );

        $this->assertSame(0, $fires, 'no rotation should not fire callback');
    }

    public function testAtomicWriteIsDurableAcrossConcurrentReaders(): void
    {
        // Sanity: repeated write/read cycles never produce partial JSON.
        // Combined with LOCK_SH on the reader side, this guards against
        // readers catching an in-flight rename.
        $path = "{$this->baseDir}/meta.json";

        for ($i = 0; $i < 100; $i++) {
            SpiffeTableSchema::atomicWrite(
                $path,
                json_encode(['version' => $i * 2, 'payload' => str_repeat('x', 512)]),
            );
            $data = file_get_contents($path);
            $decoded = json_decode($data, true);
            $this->assertIsArray($decoded, "iter {$i}: expected valid JSON, got: " . substr($data, 0, 80));
            $this->assertSame($i * 2, $decoded['version']);
        }
    }
}
