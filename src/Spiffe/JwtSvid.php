<?php

declare(strict_types=1);

namespace Spiffe;

use Spiffe\Workload\JWTSVID as JWTSVIDProto;

/**
 * Domain entity representing a JWT-SVID.
 *
 * Wraps the raw protobuf DTO with:
 *  - Parsed SpiffeId value object
 *  - Decoded JWT header and claims (without cryptographic verification —
 *    the SPIRE Agent has already validated the token)
 *  - Expiration checking
 *
 * Instances are immutable once constructed.
 *
 * @see https://github.com/spiffe/spiffe/blob/main/standards/JWT-SVID.md
 */
final class JwtSvid
{
    private SpiffeId $spiffeId;

    /** @var string Raw JWT token (JWS Compact Serialization: header.payload.signature) */
    private string $token;

    /** @var array<string, mixed> Decoded JWT claims payload */
    private array $claims;

    /** @var array<string, mixed> Decoded JWT header (JOSE header) */
    private array $header;

    private string $hint;

    private function __construct(
        SpiffeId $spiffeId,
        string $token,
        array $header,
        array $claims,
        string $hint,
    ) {
        $this->spiffeId = $spiffeId;
        $this->token = $token;
        $this->header = $header;
        $this->claims = $claims;
        $this->hint = $hint;
    }

    /**
     * Build a JwtSvid entity from the protobuf DTO returned by the Workload API.
     *
     * @throws \InvalidArgumentException if the SPIFFE ID or JWT is invalid
     */
    public static function fromProto(JWTSVIDProto $proto): self
    {
        $rawId = $proto->getSpiffeId();
        if ($rawId === '') {
            throw new \InvalidArgumentException('JWTSVID proto is missing spiffe_id');
        }

        $token = $proto->getSvid();
        if ($token === '') {
            throw new \InvalidArgumentException('JWTSVID proto is missing svid (JWT token)');
        }

        [$header, $claims] = self::decodeJwt($token);

        return new self(
            SpiffeId::parse($rawId),
            $token,
            $header,
            $claims,
            $proto->getHint(),
        );
    }

    public function spiffeId(): SpiffeId
    {
        return $this->spiffeId;
    }

    public function trustDomain(): TrustDomain
    {
        return $this->spiffeId->trustDomain();
    }

    /**
     * The raw JWT token string (JWS Compact Serialization).
     */
    public function token(): string
    {
        return $this->token;
    }

    public function hint(): string
    {
        return $this->hint;
    }

    // ──────────────────────────────────────────────────────────────────
    //  JWT claims access
    // ──────────────────────────────────────────────────────────────────

    /**
     * All decoded claims from the JWT payload.
     *
     * @return array<string, mixed>
     */
    public function claims(): array
    {
        return $this->claims;
    }

    /**
     * The JOSE header (alg, kid, typ, etc.).
     *
     * @return array<string, mixed>
     */
    public function header(): array
    {
        return $this->header;
    }

    /**
     * The "sub" (subject) claim — should match the SPIFFE ID.
     */
    public function subject(): ?string
    {
        return $this->claims['sub'] ?? null;
    }

    /**
     * The "aud" (audience) claim.
     *
     * @return list<string>
     */
    public function audience(): array
    {
        $aud = $this->claims['aud'] ?? [];
        return is_array($aud) ? $aud : [$aud];
    }

    /**
     * The "exp" (expiration time) claim as a DateTimeImmutable.
     */
    public function expiry(): ?\DateTimeImmutable
    {
        if (!isset($this->claims['exp'])) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp((int) $this->claims['exp']);
    }

    /**
     * The "iat" (issued at) claim as a DateTimeImmutable.
     */
    public function issuedAt(): ?\DateTimeImmutable
    {
        if (!isset($this->claims['iat'])) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp((int) $this->claims['iat']);
    }

    /**
     * Check whether the token has expired.
     */
    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        $expiry = $this->expiry();
        if ($expiry === null) {
            return false; // no expiry → never expires
        }

        $now ??= new \DateTimeImmutable();
        return $now >= $expiry;
    }

    /**
     * Check if the token was issued for the given audience.
     */
    public function hasAudience(string $audience): bool
    {
        return in_array($audience, $this->audience(), true);
    }

    // ──────────────────────────────────────────────────────────────────
    //  JWT decoding (no signature verification — SPIRE already did that)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Decode a JWS Compact Serialization token into [header, claims].
     *
     * This performs structural parsing only. Cryptographic verification
     * is the responsibility of the SPIRE Agent (via ValidateJWTSVID RPC)
     * or the trust bundle's public keys.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     * @throws \InvalidArgumentException if the JWT structure is invalid
     */
    private static function decodeJwt(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException(
                'Invalid JWT: expected 3 dot-separated parts (header.payload.signature)'
            );
        }

        $header = self::base64UrlDecode($parts[0]);
        $payload = self::base64UrlDecode($parts[1]);

        $headerData = json_decode($header, true, 16, JSON_THROW_ON_ERROR);
        $claimsData = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);

        if (!is_array($headerData) || !is_array($claimsData)) {
            throw new \InvalidArgumentException('Invalid JWT: header or payload is not a JSON object');
        }

        return [$headerData, $claimsData];
    }

    private static function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid base64url encoding in JWT');
        }

        return $decoded;
    }
}
