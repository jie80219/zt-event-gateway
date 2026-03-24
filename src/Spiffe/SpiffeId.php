<?php

declare(strict_types=1);

namespace Spiffe;

/**
 * Immutable value object representing a SPIFFE ID.
 *
 * A SPIFFE ID is a URI of the form: spiffe://<trust-domain>/<workload-path>
 *
 * Examples:
 *  - spiffe://zt.local/gateway
 *  - spiffe://zt.local/php-gateway
 *
 * @see https://github.com/spiffe/spiffe/blob/main/standards/SPIFFE-ID.md
 */
final class SpiffeId
{
    private const SPIFFE_SCHEME = 'spiffe';

    private TrustDomain $trustDomain;
    private string $path;

    private function __construct(TrustDomain $trustDomain, string $path)
    {
        $this->trustDomain = $trustDomain;
        $this->path = $path;
    }

    /**
     * Parse a SPIFFE ID from its URI string representation.
     *
     * @param string $uri e.g. "spiffe://zt.local/gateway"
     *
     * @throws \InvalidArgumentException if the URI is not a valid SPIFFE ID
     */
    public static function parse(string $uri): self
    {
        $uri = trim($uri);

        if ($uri === '') {
            throw new \InvalidArgumentException('SPIFFE ID cannot be empty');
        }

        $parsed = parse_url($uri);
        if ($parsed === false) {
            throw new \InvalidArgumentException("Malformed SPIFFE ID URI: {$uri}");
        }

        // Scheme validation
        $scheme = $parsed['scheme'] ?? '';
        if (strtolower($scheme) !== self::SPIFFE_SCHEME) {
            throw new \InvalidArgumentException(
                "SPIFFE ID must use the 'spiffe' scheme, got: {$scheme}"
            );
        }

        // Trust domain (host) validation
        $host = $parsed['host'] ?? '';
        if ($host === '') {
            throw new \InvalidArgumentException(
                "SPIFFE ID is missing a trust domain: {$uri}"
            );
        }

        // Forbidden URI components
        if (isset($parsed['port'])) {
            throw new \InvalidArgumentException(
                "SPIFFE ID must not contain a port: {$uri}"
            );
        }
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            throw new \InvalidArgumentException(
                "SPIFFE ID must not contain user info: {$uri}"
            );
        }
        if (isset($parsed['query'])) {
            throw new \InvalidArgumentException(
                "SPIFFE ID must not contain a query string: {$uri}"
            );
        }
        if (isset($parsed['fragment'])) {
            throw new \InvalidArgumentException(
                "SPIFFE ID must not contain a fragment: {$uri}"
            );
        }

        $path = $parsed['path'] ?? '';
        self::validatePath($path, $uri);

        $trustDomain = TrustDomain::parse($host);

        return new self($trustDomain, $path);
    }

    /**
     * Construct a SpiffeId from an existing TrustDomain and a path.
     *
     * @param string $path Must start with '/' or be empty
     */
    public static function fromSegments(TrustDomain $trustDomain, string $path): self
    {
        self::validatePath($path, $trustDomain->idString() . $path);

        return new self($trustDomain, $path);
    }

    private static function validatePath(string $path, string $uri): void
    {
        if ($path === '' || $path === '/') {
            return; // trust-domain-only SPIFFE ID is valid
        }

        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException(
                "SPIFFE ID path must start with '/': {$uri}"
            );
        }

        // Path must not end with '/'
        if (str_ends_with($path, '/')) {
            throw new \InvalidArgumentException(
                "SPIFFE ID path must not have a trailing slash: {$uri}"
            );
        }

        // No empty segments (double slashes)
        if (str_contains($path, '//')) {
            throw new \InvalidArgumentException(
                "SPIFFE ID path must not contain empty segments: {$uri}"
            );
        }

        // No relative path traversal
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException(
                    "SPIFFE ID path must not contain dot segments: {$uri}"
                );
            }
        }
    }

    public function trustDomain(): TrustDomain
    {
        return $this->trustDomain;
    }

    /**
     * The path component (e.g. "/gateway"). Empty string if none.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Check if this SPIFFE ID belongs to the given trust domain.
     */
    public function memberOf(TrustDomain $td): bool
    {
        return $this->trustDomain->equals($td);
    }

    public function equals(self $other): bool
    {
        return $this->trustDomain->equals($other->trustDomain)
            && $this->path === $other->path;
    }

    /**
     * Full URI representation: spiffe://<trust-domain><path>
     */
    public function __toString(): string
    {
        return self::SPIFFE_SCHEME . '://' . $this->trustDomain->name() . $this->path;
    }
}
