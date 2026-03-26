<?php

declare(strict_types=1);

namespace Tests\Unit\Spiffe;

use PHPUnit\Framework\TestCase;
use Spiffe\SpiffeId;
use Spiffe\TrustDomain;

final class SpiffeIdTest extends TestCase
{
    public function testParseValid(): void
    {
        $id = SpiffeId::parse('spiffe://zt.local/gateway');

        $this->assertSame('zt.local', $id->trustDomain()->name());
        $this->assertSame('/gateway', $id->path());
        $this->assertSame('spiffe://zt.local/gateway', (string) $id);
    }

    public function testParseTrustDomainOnly(): void
    {
        $id = SpiffeId::parse('spiffe://zt.local');

        $this->assertSame('zt.local', $id->trustDomain()->name());
        $this->assertSame('', $id->path());
    }

    public function testParseMultiSegmentPath(): void
    {
        $id = SpiffeId::parse('spiffe://zt.local/ns/production/workload');

        $this->assertSame('/ns/production/workload', $id->path());
    }

    public function testRejectsNonSpiffeScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SpiffeId::parse('https://zt.local/gateway');
    }

    public function testRejectsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SpiffeId::parse('');
    }

    public function testRejectsPort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SpiffeId::parse('spiffe://zt.local:8080/gateway');
    }

    public function testRejectsQuery(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SpiffeId::parse('spiffe://zt.local/gateway?foo=bar');
    }

    public function testRejectsFragment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SpiffeId::parse('spiffe://zt.local/gateway#section');
    }

    public function testRejectsTrailingSlash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SpiffeId::parse('spiffe://zt.local/gateway/');
    }

    public function testRejectsDoubleSlash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SpiffeId::parse('spiffe://zt.local//gateway');
    }

    public function testRejectsDotSegment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SpiffeId::parse('spiffe://zt.local/../etc/passwd');
    }

    public function testEquals(): void
    {
        $a = SpiffeId::parse('spiffe://zt.local/gateway');
        $b = SpiffeId::parse('spiffe://zt.local/gateway');
        $c = SpiffeId::parse('spiffe://zt.local/other');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testMemberOf(): void
    {
        $id = SpiffeId::parse('spiffe://zt.local/gateway');
        $td = TrustDomain::parse('zt.local');
        $other = TrustDomain::parse('other.domain');

        $this->assertTrue($id->memberOf($td));
        $this->assertFalse($id->memberOf($other));
    }

    public function testFromSegments(): void
    {
        $td = TrustDomain::parse('zt.local');
        $id = SpiffeId::fromSegments($td, '/gateway');

        $this->assertSame('spiffe://zt.local/gateway', (string) $id);
    }
}
