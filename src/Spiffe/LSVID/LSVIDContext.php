<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\Spiffe\LSVID;

/**
 * Per-process holder for the LSVID currently being handled.
 *
 * In the gateway/worker processes each message is processed synchronously by
 * one coroutine/worker, so a static "current" pointer is enough to thread the
 * inbound token through the Saga layer without threading a parameter through
 * every handler signature.
 *
 * Usage:
 *   LSVIDContext::set($rawInboundToken);
 *   try {
 *       $eventBus->dispatch($event);   // Saga::publish() will read current()
 *   } finally {
 *       LSVIDContext::clear();
 *   }
 */
final class LSVIDContext
{
    private static ?string $current = null;

    public static function set(?string $rawToken): void
    {
        self::$current = ($rawToken !== null && $rawToken !== '') ? $rawToken : null;
    }

    public static function current(): ?string
    {
        return self::$current;
    }

    public static function clear(): void
    {
        self::$current = null;
    }
}
