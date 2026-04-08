<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\Spiffe;

use SDPMlab\LSVID\LSVIDSigner;

/**
 * Static registry for the per-worker LSVIDSigner instance.
 *
 * Anser's ActionFilter instantiates filters via `new $className()` (zero
 * constructor arguments), so the SpiffeLsvidFilter cannot receive the
 * signer through dependency injection. This static holder bridges the gap
 * — set once during worker bootstrap, read by every filter invocation.
 */
final class LSVIDSignerRegistry
{
    private static ?LSVIDSigner $signer = null;

    public static function set(LSVIDSigner $signer): void
    {
        self::$signer = $signer;
    }

    public static function get(): ?LSVIDSigner
    {
        return self::$signer;
    }
}
