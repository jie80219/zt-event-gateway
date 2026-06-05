<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\Vault\Tls;

/**
 * Static registry for the per-worker VaultTlsContext instance.
 *
 * Anser's ActionFilter instantiates filters via `new $className()` (zero
 * constructor arguments), so the VaultMtlsFilter cannot receive the TLS
 * context through dependency injection. This static holder bridges the gap
 * — set once during worker/gateway bootstrap, read by every filter
 * invocation.
 */
final class VaultMtlsRegistry
{
    private static ?VaultTlsContext $context = null;

    public static function set(VaultTlsContext $ctx): void
    {
        self::$context = $ctx;
    }

    public static function get(): ?VaultTlsContext
    {
        return self::$context;
    }
}
