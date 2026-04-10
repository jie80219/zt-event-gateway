<?php

namespace AnserGateway\Spiffe;

use SDPMlab\LSVID\LSVIDSigner;

/**
 * Static registry holding per-worker SPIFFE state for the Gateway.
 *
 * Set once during OpenSwoole onWorkerStart (after SVID is fetched from
 * SHM / X509Source), read by Order Controller to mint LSVID L0 tokens
 * and populate the event envelope.
 */
final class GatewaySpiffeState
{
    private static string $spiffeId = '';
    private static ?LSVIDSigner $lsvidSigner = null;
    private static string $downstreamSpiffeId = '';

    public static function setSpiffeId(string $id): void
    {
        self::$spiffeId = $id;
    }

    public static function getSpiffeId(): string
    {
        return self::$spiffeId;
    }

    public static function setLsvidSigner(?LSVIDSigner $signer): void
    {
        self::$lsvidSigner = $signer;
    }

    public static function getLsvidSigner(): ?LSVIDSigner
    {
        return self::$lsvidSigner;
    }

    public static function setDownstreamSpiffeId(string $id): void
    {
        self::$downstreamSpiffeId = $id;
    }

    public static function getDownstreamSpiffeId(): string
    {
        return self::$downstreamSpiffeId;
    }
}
