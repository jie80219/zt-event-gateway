<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\Spiffe\LSVID;

/**
 * Immutable Nested LSVID (Lightweight SVID).
 *
 * Wire format is JWS compact serialization:
 *   base64url(header) "." base64url(payload) "." base64url(signature)
 *
 * Nested levels are carried in the payload's `nested` claim as the *raw*
 * prior-level compact serialization. Parsing recurses through `nested`, so
 * any instance exposes its full ancestry via {@see chain()}.
 *
 *   L0                       L1                       L2
 * ┌────────────┐           ┌────────────┐           ┌────────────┐
 * │ Payload P0 │ ◄─nested──│ Payload P1 │ ◄─nested──│ Payload P2 │
 * │ Sig S0     │           │ Sig S1     │           │ Sig S2     │
 * └────────────┘           └────────────┘           └────────────┘
 */
final class LSVID
{
    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     */
    private function __construct(
        public readonly string $raw,
        public readonly string $signingInput,
        public readonly array $header,
        public readonly array $payload,
        public readonly string $signature,
        public readonly ?LSVID $nested,
    ) {
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     */
    public static function fromParts(
        string $raw,
        string $signingInput,
        array $header,
        array $payload,
        string $signature,
        ?LSVID $nested,
    ): self {
        return new self($raw, $signingInput, $header, $payload, $signature, $nested);
    }

    /**
     * Parse a raw compact LSVID, recursing into any nested prior level.
     */
    public static function parse(string $raw): self
    {
        $segments = explode('.', $raw);
        if (count($segments) !== 3) {
            throw new LSVIDException('LSVID must contain exactly three dot-separated segments.');
        }

        [$headerB64, $payloadB64, $sigB64] = $segments;

        $headerJson = self::b64UrlDecode($headerB64);
        $payloadJson = self::b64UrlDecode($payloadB64);
        $signature = self::b64UrlDecode($sigB64);

        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);
        if (!is_array($header) || !is_array($payload)) {
            throw new LSVIDException('LSVID header or payload is not valid JSON.');
        }

        if (($header['typ'] ?? null) !== 'LSVID') {
            throw new LSVIDException(sprintf('Unexpected LSVID typ: %s', (string) ($header['typ'] ?? 'null')));
        }

        $nested = null;
        if (isset($payload['nested']) && is_string($payload['nested']) && $payload['nested'] !== '') {
            $nested = self::parse($payload['nested']);
        }

        return new self(
            raw:          $raw,
            signingInput: $headerB64 . '.' . $payloadB64,
            header:       $header,
            payload:      $payload,
            signature:    $signature,
            nested:       $nested,
        );
    }

    /**
     * 0 for the base level (no nested), N for N extensions above a base.
     */
    public function level(): int
    {
        return $this->nested === null ? 0 : $this->nested->level() + 1;
    }

    /**
     * Full ancestry, root-first: [L0, L1, ..., $this].
     *
     * @return list<self>
     */
    public function chain(): array
    {
        $chain = $this->nested === null ? [] : $this->nested->chain();
        $chain[] = $this;
        return $chain;
    }

    public function issuer(): string
    {
        return (string) ($this->payload['iss'] ?? '');
    }

    public function audience(): ?string
    {
        $aud = $this->payload['aud'] ?? null;
        return is_string($aud) && $aud !== '' ? $aud : null;
    }

    public function expiresAt(): ?int
    {
        return isset($this->payload['exp']) ? (int) $this->payload['exp'] : null;
    }

    public function issuedAt(): ?int
    {
        return isset($this->payload['iat']) ? (int) $this->payload['iat'] : null;
    }

    /**
     * Leaf X.509 certificate (PEM) embedded in the header `x5c` claim.
     * Returns null when no cert chain is present.
     */
    public function leafCertificatePem(): ?string
    {
        $x5c = $this->header['x5c'] ?? null;
        if (!is_array($x5c) || $x5c === []) {
            return null;
        }

        $der = base64_decode((string) $x5c[0], true);
        if ($der === false) {
            throw new LSVIDException('Invalid x5c entry: not valid base64.');
        }

        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE-----\n";
    }

    public static function b64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function b64UrlDecode(string $text): string
    {
        $pad = strlen($text) % 4;
        if ($pad > 0) {
            $text .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);
        if ($decoded === false) {
            throw new LSVIDException('Invalid base64url encoding in LSVID.');
        }
        return $decoded;
    }
}
