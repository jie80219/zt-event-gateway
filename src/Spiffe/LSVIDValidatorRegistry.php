<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\Spiffe;

use SDPMlab\LSVID\LSVIDValidator;

/**
 * Static registry for the per-worker LSVIDValidator instance.
 *
 * Mirrors {@see LSVIDSignerRegistry}: Anser's ActionFilter instantiates
 * filters via `new $className()` (zero constructor arguments), so the
 * SpiffeLsvidFilter cannot receive the validator through DI. This holder is
 * populated during worker bootstrap and read by every outbound HTTP call
 * to re-validate the prior LSVID token (defence in depth) before extending
 * it for the downstream service.
 */
final class LSVIDValidatorRegistry
{
    private static ?LSVIDValidator $validator = null;

    public static function set(LSVIDValidator $validator): void
    {
        self::$validator = $validator;
    }

    public static function get(): ?LSVIDValidator
    {
        return self::$validator;
    }
}
