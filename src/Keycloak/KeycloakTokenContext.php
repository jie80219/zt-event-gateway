<?php

declare(strict_types=1);

namespace Keycloak;

/**
 * Coroutine-local context carrying the validated JWT + claims of the current
 * inbound request/event, so downstream publish calls can trace the caller
 * without re-parsing the envelope.
 *
 * Per-coroutine slots are keyed by the OpenSwoole coroutine id when available,
 * falling back to a single process-global slot for non-coroutine scripts
 * (Workerman, CLI).
 */
final class KeycloakTokenContext
{
    /** @var array<int, array{jwt:string, client_id:string, claims:array<string, mixed>}> */
    private static array $slots = [];

    private function __construct() {}

    /**
     * @param array<string, mixed> $claims
     */
    public static function set(string $jwt, string $clientId, array $claims): void
    {
        self::$slots[self::key()] = ['jwt' => $jwt, 'client_id' => $clientId, 'claims' => $claims];
    }

    /**
     * @return array{jwt:string, client_id:string, claims:array<string, mixed>}|null
     */
    public static function get(): ?array
    {
        return self::$slots[self::key()] ?? null;
    }

    public static function clear(): void
    {
        unset(self::$slots[self::key()]);
    }

    public static function reset(): void
    {
        self::$slots = [];
    }

    private static function key(): int
    {
        if (class_exists(\OpenSwoole\Coroutine::class, false)) {
            $cid = \OpenSwoole\Coroutine::getCid();
            if ($cid > 0) {
                return $cid;
            }
        }
        return -1;
    }
}
