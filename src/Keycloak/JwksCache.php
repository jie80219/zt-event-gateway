<?php

declare(strict_types=1);

namespace Keycloak;

/**
 * In-process JWKS cache — fetches the JWKS document over HTTPS on first use
 * and refreshes it on `kid` lookup misses. No cross-process sharing.
 */
final class JwksCache
{
    /** Soft TTL before a forced refresh, in seconds. */
    private const SOFT_TTL = 300;

    private ?string $issuer = null;
    /** @var list<array<string, mixed>>|null */
    private ?array $keys = null;
    private int $fetchedAt = 0;

    public function __construct(
        private readonly string $realm,
        private readonly string $jwksUri,
    ) {}

    /**
     * @return list<array<string, mixed>>|null
     */
    public function readKeys(bool $force = false): ?array
    {
        if ($force || $this->keys === null || (time() - $this->fetchedAt) > self::SOFT_TTL) {
            $this->fetch();
        }
        return $this->keys;
    }

    public function readIssuer(): ?string
    {
        if ($this->issuer === null) {
            $this->fetch();
        }
        return $this->issuer;
    }

    public function realm(): string
    {
        return $this->realm;
    }

    private function fetch(): void
    {
        $ch = curl_init($this->jwksUri);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || $code !== 200) {
            throw new \RuntimeException(sprintf(
                'JWKS fetch failed (uri=%s, http=%d, err=%s)',
                $this->jwksUri,
                (int) $code,
                $err,
            ));
        }
        $decoded = json_decode($body, true, 16);
        if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
            throw new \RuntimeException('JWKS document is missing the "keys" array');
        }
        $this->keys      = $decoded['keys'];
        $this->fetchedAt = time();
    }
}
