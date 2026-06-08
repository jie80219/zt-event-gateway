<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\Spiffe;

use Spiffe\Source\X509Source;
use Spiffe\TLS\SpiffeTlsContext;

/**
 * Static registry for the per-worker SpiffeTlsContext instance.
 *
 * Anser's ActionFilter instantiates filters via `new $className()` (zero
 * constructor arguments), so downstream filters cannot receive the TLS
 * context through dependency injection. This static holder bridges the gap
 * — set once during worker bootstrap, read by every filter invocation.
 *
 * Two registration modes:
 *   - setSource(X509Source): build a fresh SpiffeTlsContext from the source
 *     (preferred — the source already manages rotation in-process)
 *   - set(SpiffeTlsContext):  set a pre-built context directly
 */
final class SpiffeMtlsRegistry
{
    private static ?SpiffeTlsContext $context = null;
    private static ?X509Source $source = null;

    public static function set(SpiffeTlsContext $ctx): void
    {
        self::$context = $ctx;
    }

    public static function setSource(X509Source $source): void
    {
        self::$source = $source;
        self::$context = SpiffeTlsContext::fromSource($source);
    }

    public static function get(): ?SpiffeTlsContext
    {
        return self::$context;
    }

    public static function source(): ?X509Source
    {
        return self::$source;
    }
}
