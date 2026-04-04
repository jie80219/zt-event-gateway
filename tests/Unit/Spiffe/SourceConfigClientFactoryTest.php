<?php

declare(strict_types=1);

namespace Tests\Unit\Spiffe;

use PHPUnit\Framework\TestCase;
use Spiffe\Source\SourceConfig;
use Spiffe\WorkloadAPIClientInterface;

final class SourceConfigClientFactoryTest extends TestCase
{
    public function testCreateClientUsesCustomFactory(): void
    {
        $mockClient = $this->createMock(WorkloadAPIClientInterface::class);

        $config = new SourceConfig(
            socketPath: 'unix:/tmp/test.sock',
            clientFactory: function (string $socket, float $connectTimeout, float $recvTimeout) use ($mockClient): WorkloadAPIClientInterface {
                $this->assertSame('unix:/tmp/test.sock', $socket);
                $this->assertSame(5.0, $connectTimeout);
                $this->assertSame(30.0, $recvTimeout);
                return $mockClient;
            },
        );

        $client = $config->createClient(30.0);
        $this->assertSame($mockClient, $client);
    }

    public function testWithClientFactoryReturnsNewConfig(): void
    {
        $original = new SourceConfig(socketPath: 'unix:/tmp/a.sock');

        $mockClient = $this->createMock(WorkloadAPIClientInterface::class);
        $modified = $original->withClientFactory(
            fn(string $s, float $c, float $r): WorkloadAPIClientInterface => $mockClient,
        );

        $this->assertNotSame($original, $modified);
        $this->assertSame($mockClient, $modified->createClient(30.0));
    }

    public function testCreateClientAutoDetectsRuntime(): void
    {
        // Without a custom factory, createClient should auto-detect runtime.
        // In test environment (Swow loaded), this should return a SpiffeWorkloadAPIClient.
        $config = new SourceConfig(socketPath: 'unix:/tmp/test.sock');
        $client = $config->createClient(10.0);
        $this->assertInstanceOf(WorkloadAPIClientInterface::class, $client);
    }

    public function testWithSocketPathPreservesClientFactory(): void
    {
        $mockClient = $this->createMock(WorkloadAPIClientInterface::class);
        $config = new SourceConfig(
            socketPath: 'unix:/tmp/a.sock',
            clientFactory: fn(string $s, float $c, float $r): WorkloadAPIClientInterface => $mockClient,
        );

        $modified = $config->withSocketPath('unix:/tmp/b.sock');
        $client = $modified->createClient(30.0);
        $this->assertSame($mockClient, $client);
    }
}
