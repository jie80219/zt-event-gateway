<?php

declare(strict_types=1);

namespace Keycloak;

use Keycloak\SharedMemory\KeycloakTableReader;
use Keycloak\SharedMemory\KeycloakTableStore;

/**
 * Cross-process JWKS cache — readers decode public keys for JWT signature verification.
 */
final class JwksCache
{
    public function __construct(
        private readonly string $realm,
        private readonly KeycloakTableReader $reader,
        private readonly ?KeycloakTableStore $store = null,
    ) {}

    /**
     * Returns the parsed JWKS `keys` array, or null if the cache is empty.
     *
     * @return list<array<string, mixed>>|null
     */
    public function readKeys(): ?array
    {
        $row = $this->reader->readJwks($this->realm);
        if ($row === null) {
            return null;
        }
        $decoded = json_decode($row['jwks_json'], true, 16);
        if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
            return null;
        }
        return $decoded['keys'];
    }

    public function readIssuer(): ?string
    {
        return $this->reader->readJwks($this->realm)['issuer'] ?? null;
    }

    public function write(string $issuer, string $jwksJson): void
    {
        if ($this->store === null) {
            throw new \RuntimeException('JwksCache is read-only (no store configured)');
        }
        $this->store->publishJwks([
            $this->realm => [
                'issuer'    => $issuer,
                'jwks_json' => $jwksJson,
            ],
        ]);
        $this->store->updateJwksState('ready');
    }
}
