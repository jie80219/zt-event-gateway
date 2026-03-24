<?php

declare(strict_types=1);

namespace Spiffe\TLS;

use Spiffe\SpiffeId;
use Spiffe\TrustDomain;

/**
 * Declarative authorization policy for SPIFFE mTLS peer verification.
 *
 * Evaluates whether a peer SPIFFE ID is permitted to access a resource,
 * combining multiple matching strategies:
 *
 *  - Exact SPIFFE ID match
 *  - Trust domain membership
 *  - Path prefix / regex patterns
 *  - Custom callable matchers
 *  - Deny-list (evaluated before allow-list)
 *
 * Evaluation order:
 *
 *   1. If peer matches any DENY rule  → denied
 *   2. If peer matches any ALLOW rule → allowed
 *   3. Otherwise                      → denied (deny by default)
 *
 * Usage:
 *
 *   $policy = AuthorizationPolicy::create()
 *       ->allowTrustDomain('zt.local')
 *       ->allowId('spiffe://partner.example/api')
 *       ->allowPathPrefix('/services/')
 *       ->denyId('spiffe://zt.local/compromised-workload');
 */
final class AuthorizationPolicy
{
    /** @var list<SpiffeId> */
    private array $allowedIds = [];

    /** @var list<TrustDomain> */
    private array $allowedDomains = [];

    /** @var list<string> Path prefixes (e.g. "/services/") */
    private array $allowedPathPrefixes = [];

    /** @var list<string> Regex patterns for path matching */
    private array $allowedPathPatterns = [];

    /** @var list<callable(SpiffeId): bool> Custom matchers */
    private array $customMatchers = [];

    /** @var list<SpiffeId> Explicit deny entries (evaluated first) */
    private array $deniedIds = [];

    /** @var list<TrustDomain> Explicit deny domains */
    private array $deniedDomains = [];

    private bool $allowAny = false;

    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    /**
     * Allow any valid SPIFFE ID. Use with extreme caution.
     */
    public static function permissive(): self
    {
        $p = new self();
        $p->allowAny = true;
        return $p;
    }

    // ── Allow rules ──────────────────────────────────────────────────

    public function allowId(string $spiffeId): self
    {
        $this->allowedIds[] = SpiffeId::parse($spiffeId);
        return $this;
    }

    /**
     * @param list<string> $ids
     */
    public function allowIds(array $ids): self
    {
        foreach ($ids as $id) {
            $this->allowedIds[] = SpiffeId::parse($id);
        }
        return $this;
    }

    public function allowTrustDomain(string $domain): self
    {
        $this->allowedDomains[] = TrustDomain::parse($domain);
        return $this;
    }

    /**
     * @param list<string> $domains
     */
    public function allowTrustDomains(array $domains): self
    {
        foreach ($domains as $d) {
            $this->allowedDomains[] = TrustDomain::parse($d);
        }
        return $this;
    }

    /**
     * Allow any SPIFFE ID whose path starts with $prefix.
     * Must be combined with a trust domain allow rule.
     */
    public function allowPathPrefix(string $prefix): self
    {
        $this->allowedPathPrefixes[] = $prefix;
        return $this;
    }

    /**
     * Allow any SPIFFE ID whose path matches the regex pattern.
     * Pattern is applied to the path component only (e.g. "/services/order-.*").
     */
    public function allowPathPattern(string $regex): self
    {
        $this->allowedPathPatterns[] = $regex;
        return $this;
    }

    /**
     * Add a custom matcher. Receives the parsed SpiffeId; return true to allow.
     *
     * @param callable(SpiffeId): bool $matcher
     */
    public function allowWhere(callable $matcher): self
    {
        $this->customMatchers[] = $matcher;
        return $this;
    }

    // ── Deny rules (evaluated before allow) ──────────────────────────

    public function denyId(string $spiffeId): self
    {
        $this->deniedIds[] = SpiffeId::parse($spiffeId);
        return $this;
    }

    /**
     * @param list<string> $ids
     */
    public function denyIds(array $ids): self
    {
        foreach ($ids as $id) {
            $this->deniedIds[] = SpiffeId::parse($id);
        }
        return $this;
    }

    public function denyTrustDomain(string $domain): self
    {
        $this->deniedDomains[] = TrustDomain::parse($domain);
        return $this;
    }

    // ── Evaluation ───────────────────────────────────────────────────

    /**
     * Evaluate the policy against a SPIFFE ID.
     *
     * @return array{allowed: bool, reason: string}
     */
    public function evaluate(SpiffeId $id): array
    {
        // Step 1: Deny rules (always checked first)
        foreach ($this->deniedIds as $denied) {
            if ($id->equals($denied)) {
                return ['allowed' => false, 'reason' => "explicitly denied: {$id}"];
            }
        }

        foreach ($this->deniedDomains as $denied) {
            if ($id->memberOf($denied)) {
                return ['allowed' => false, 'reason' => "trust domain denied: {$denied}"];
            }
        }

        // Step 2: Allow-any shortcut
        if ($this->allowAny) {
            return ['allowed' => true, 'reason' => 'permissive policy'];
        }

        // Step 3: Exact ID match
        foreach ($this->allowedIds as $allowed) {
            if ($id->equals($allowed)) {
                return ['allowed' => true, 'reason' => "exact match: {$id}"];
            }
        }

        // Step 4: Trust domain + optional path constraints
        foreach ($this->allowedDomains as $domain) {
            if (!$id->memberOf($domain)) {
                continue;
            }

            // If no path constraints, domain membership is sufficient
            if ($this->allowedPathPrefixes === [] && $this->allowedPathPatterns === []) {
                return ['allowed' => true, 'reason' => "trust domain match: {$domain}"];
            }

            // Check path prefixes
            foreach ($this->allowedPathPrefixes as $prefix) {
                if (str_starts_with($id->path(), $prefix)) {
                    return ['allowed' => true, 'reason' => "path prefix match: {$prefix}"];
                }
            }

            // Check path regex patterns
            foreach ($this->allowedPathPatterns as $pattern) {
                if (preg_match($pattern, $id->path()) === 1) {
                    return ['allowed' => true, 'reason' => "path pattern match: {$pattern}"];
                }
            }
        }

        // Step 5: Custom matchers
        foreach ($this->customMatchers as $i => $matcher) {
            if ($matcher($id)) {
                return ['allowed' => true, 'reason' => "custom matcher #{$i}"];
            }
        }

        // Default deny
        return ['allowed' => false, 'reason' => 'no matching allow rule'];
    }

    /**
     * Quick boolean check.
     */
    public function allows(SpiffeId $id): bool
    {
        return $this->evaluate($id)['allowed'];
    }

    /**
     * @param string $spiffeIdStr  Raw SPIFFE ID URI string
     */
    public function allowsString(string $spiffeIdStr): bool
    {
        try {
            return $this->allows(SpiffeId::parse($spiffeIdStr));
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
