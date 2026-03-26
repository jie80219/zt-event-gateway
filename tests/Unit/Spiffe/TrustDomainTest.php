<?php

declare(strict_types=1);

namespace Tests\Unit\Spiffe;

use PHPUnit\Framework\TestCase;
use Spiffe\TrustDomain;

final class TrustDomainTest extends TestCase
{
    public function testParsePlain(): void
    {
        $td = TrustDomain::parse('zt.local');

        $this->assertSame('zt.local', $td->name());
        $this->assertSame('spiffe://zt.local', $td->idString());
        $this->assertSame('zt.local', (string) $td);
    }

    public function testParseFromUri(): void
    {
        $td = TrustDomain::parse('spiffe://zt.local/some-path');
        $this->assertSame('zt.local', $td->name());
    }

    public function testNormalizesToLowercase(): void
    {
        $td = TrustDomain::parse('ZT.LOCAL');
        $this->assertSame('zt.local', $td->name());
    }

    public function testRejectsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TrustDomain::parse('');
    }

    public function testRejectsInvalidChars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TrustDomain::parse('zt local');
    }

    public function testRejectsTooLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TrustDomain::parse(str_repeat('a', 256));
    }

    public function testEquals(): void
    {
        $a = TrustDomain::parse('zt.local');
        $b = TrustDomain::parse('ZT.LOCAL');
        $c = TrustDomain::parse('other.domain');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testNewSpiffeId(): void
    {
        $td = TrustDomain::parse('zt.local');
        $id = $td->newSpiffeId('/gateway');

        $this->assertSame('spiffe://zt.local/gateway', (string) $id);
    }
}
