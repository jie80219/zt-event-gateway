<?php

declare(strict_types=1);

namespace Tests\Unit\Spiffe\TLS;

use PHPUnit\Framework\TestCase;
use Spiffe\SpiffeId;
use Spiffe\TLS\AuthorizationPolicy;

final class AuthorizationPolicyTest extends TestCase
{
    public function testDefaultDeny(): void
    {
        $policy = AuthorizationPolicy::create()
            ->allowTrustDomain('zt.local');

        $foreign = SpiffeId::parse('spiffe://evil.domain/attacker');
        $this->assertFalse($policy->allows($foreign));
    }

    public function testAllowTrustDomain(): void
    {
        $policy = AuthorizationPolicy::create()
            ->allowTrustDomain('zt.local');

        $id = SpiffeId::parse('spiffe://zt.local/gateway');
        $this->assertTrue($policy->allows($id));
    }

    public function testAllowExactId(): void
    {
        $policy = AuthorizationPolicy::create()
            ->allowId('spiffe://zt.local/gateway');

        $gateway = SpiffeId::parse('spiffe://zt.local/gateway');
        $other = SpiffeId::parse('spiffe://zt.local/other');

        $this->assertTrue($policy->allows($gateway));
        $this->assertFalse($policy->allows($other));
    }

    public function testDenyOverridesAllow(): void
    {
        $policy = AuthorizationPolicy::create()
            ->allowTrustDomain('zt.local')
            ->denyId('spiffe://zt.local/blocked');

        $normal = SpiffeId::parse('spiffe://zt.local/gateway');
        $blocked = SpiffeId::parse('spiffe://zt.local/blocked');

        $this->assertTrue($policy->allows($normal));
        $this->assertFalse($policy->allows($blocked));
    }

    public function testDenyTrustDomain(): void
    {
        $policy = AuthorizationPolicy::create()
            ->allowTrustDomain('zt.local')
            ->denyTrustDomain('evil.domain');

        $evil = SpiffeId::parse('spiffe://evil.domain/sneaky');
        $this->assertFalse($policy->allows($evil));
    }

    public function testAllowPathPrefix(): void
    {
        $policy = AuthorizationPolicy::create()
            ->allowTrustDomain('zt.local')
            ->allowPathPrefix('/services/');

        $service = SpiffeId::parse('spiffe://zt.local/services/order');
        $admin = SpiffeId::parse('spiffe://zt.local/admin/panel');

        $this->assertTrue($policy->allows($service));
        $this->assertFalse($policy->allows($admin));
    }

    public function testAllowPathPattern(): void
    {
        $policy = AuthorizationPolicy::create()
            ->allowTrustDomain('zt.local')
            ->allowPathPattern('#^/workers/order-\d+$#');

        $match = SpiffeId::parse('spiffe://zt.local/workers/order-42');
        $noMatch = SpiffeId::parse('spiffe://zt.local/workers/admin');

        $this->assertTrue($policy->allows($match));
        $this->assertFalse($policy->allows($noMatch));
    }

    public function testCustomMatcher(): void
    {
        $policy = AuthorizationPolicy::create()
            ->allowWhere(fn(SpiffeId $id) => str_contains($id->path(), '/production/'));

        $prod = SpiffeId::parse('spiffe://any.domain/production/api');
        $dev = SpiffeId::parse('spiffe://any.domain/development/api');

        $this->assertTrue($policy->allows($prod));
        $this->assertFalse($policy->allows($dev));
    }

    public function testPermissive(): void
    {
        $policy = AuthorizationPolicy::permissive();

        $any = SpiffeId::parse('spiffe://anything.goes/here');
        $this->assertTrue($policy->allows($any));
    }

    public function testPermissiveStillDenies(): void
    {
        $policy = AuthorizationPolicy::permissive()
            ->denyId('spiffe://zt.local/blocked');

        $blocked = SpiffeId::parse('spiffe://zt.local/blocked');
        $other = SpiffeId::parse('spiffe://zt.local/ok');

        $this->assertFalse($policy->allows($blocked));
        $this->assertTrue($policy->allows($other));
    }

    public function testEvaluateReturnsReason(): void
    {
        $policy = AuthorizationPolicy::create()
            ->allowTrustDomain('zt.local');

        $result = $policy->evaluate(SpiffeId::parse('spiffe://zt.local/gw'));
        $this->assertTrue($result['allowed']);
        $this->assertStringContainsString('trust domain', $result['reason']);

        $denied = $policy->evaluate(SpiffeId::parse('spiffe://other/gw'));
        $this->assertFalse($denied['allowed']);
        $this->assertStringContainsString('no matching', $denied['reason']);
    }

    public function testAllowsString(): void
    {
        $policy = AuthorizationPolicy::create()
            ->allowTrustDomain('zt.local');

        $this->assertTrue($policy->allowsString('spiffe://zt.local/gw'));
        $this->assertFalse($policy->allowsString('not-a-valid-spiffe-id'));
    }
}
