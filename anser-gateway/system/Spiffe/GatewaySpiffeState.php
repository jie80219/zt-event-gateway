<?php

namespace AnserGateway\Spiffe;

use Spiffe\Source\X509Source;

/**
 * Static registry holding per-worker SPIFFE state for the Gateway.
 *
 * Set once during OpenSwoole onWorkerStart (after X509Source is ready),
 * read by controllers + the AMQP publisher to populate event envelopes
 * with the gateway's SPIFFE identity.
 */
final class GatewaySpiffeState
{
    private static string $spiffeId = '';
    private static ?X509Source $x509Source = null;
    private static string $downstreamSpiffeId = '';

    public static function setSpiffeId(string $id): void
    {
        self::$spiffeId = $id;
    }

    public static function getSpiffeId(): string
    {
        return self::$spiffeId;
    }

    public static function setX509Source(?X509Source $source): void
    {
        self::$x509Source = $source;
    }

    public static function getX509Source(): ?X509Source
    {
        return self::$x509Source;
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
