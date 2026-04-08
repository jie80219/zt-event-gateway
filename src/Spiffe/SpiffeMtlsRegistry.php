<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\Spiffe;

use Spiffe\TLS\SpiffeTlsContext;

/**
 * Static registry for the per-worker SpiffeTlsContext instance.
 *
 * Anser's ActionFilter instantiates filters via `new $className()` (zero
 * constructor arguments), so the SpiffeLsvidFilter cannot receive the TLS
 * context through dependency injection. This static holder bridges the gap
 * — set once during worker bootstrap, read by every filter invocation.
 */
final class SpiffeMtlsRegistry
{
    private static ?SpiffeTlsContext $context = null;

    public static function set(SpiffeTlsContext $ctx): void
    {
        self::$context = $ctx;
    }

    public static function get(): ?SpiffeTlsContext
    {
        return self::$context;
    }
}
