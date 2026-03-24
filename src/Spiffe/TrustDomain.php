<?php

declare(strict_types=1);

namespace Spiffe;

/**
 * Immutable value object representing a SPIFFE Trust Domain.
 *
 * A trust domain is the root of a SPIFFE identity namespace. All SPIFFE IDs
 * within the same trust domain share a common root of trust.
 *
 * @see https://github.com/spiffe/spiffe/blob/main/standards/SPIFFE-ID.md
 */
final class TrustDomain
{
    private const SPIFFE_SCHEME = 'spiffe';

    private string $name;

    private function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Parse a trust domain from a string.
     *
     * Accepts:
     *  - Plain domain name: "zt.local"
     *  - Full SPIFFE ID URI: "spiffe://zt.local" or "spiffe://zt.local/workload"
     *
     * @throws \InvalidArgumentException if the input is not a valid trust domain
     */
    public static function parse(string $input): self
    {
        $input = trim($input);

        if ($input === '') {
            throw new \InvalidArgumentException('Trust domain cannot be empty');
        }

        // If it looks like a SPIFFE URI, extract the host component
        if (str_starts_with($input, self::SPIFFE_SCHEME . '://')) {
            $parsed = parse_url($input);
            if ($parsed === false || !isset($parsed['host']) || $parsed['host'] === '') {
                throw new \InvalidArgumentException(
                    "Invalid SPIFFE URI: {$input}"
                );
            }
            $input = $parsed['host'];
        }

        self::validate($input);

        return new self(strtolower($input));
    }

    /**
     * Validate that the trust domain name conforms to the SPIFFE specification.
     *
     * Trust domain names must:
     *  - Contain only lowercase letters, digits, hyphens, underscores, and dots
     *  - Not exceed 255 characters
     *
     * @throws \InvalidArgumentException
     */
    private static function validate(string $name): void
    {
        if (strlen($name) > 255) {
            throw new \InvalidArgumentException(
                "Trust domain exceeds maximum length of 255 characters: {$name}"
            );
        }

        if (!preg_match('/^[a-z0-9._-]+$/', strtolower($name))) {
            throw new \InvalidArgumentException(
                "Trust domain contains invalid characters: {$name}"
            );
        }
    }

    /**
     * The trust domain name (e.g. "zt.local").
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The trust domain as a SPIFFE ID URI string (e.g. "spiffe://zt.local").
     */
    public function idString(): string
    {
        return self::SPIFFE_SCHEME . '://' . $this->name;
    }

    /**
     * Create a SpiffeId under this trust domain with the given path segments.
     *
     * Example: TrustDomain::parse('zt.local')->newSpiffeId('/gateway')
     *          → spiffe://zt.local/gateway
     */
    public function newSpiffeId(string $path): SpiffeId
    {
        return SpiffeId::parse($this->idString() . $path);
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
