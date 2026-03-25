<?php

declare(strict_types=1);

namespace Spiffe\Runtime;

/**
 * Minimal HTTP/2 frame constants and helpers for gRPC communication.
 *
 * This is NOT a full HTTP/2 implementation — it handles only the subset
 * needed for gRPC: SETTINGS, HEADERS, DATA, and WINDOW_UPDATE frames.
 */
final class Http2Frame
{
    // Frame types
    public const DATA          = 0x00;
    public const HEADERS       = 0x01;
    public const SETTINGS      = 0x04;
    public const PING          = 0x06;
    public const GOAWAY        = 0x07;
    public const WINDOW_UPDATE = 0x08;

    // Flags
    public const FLAG_END_STREAM  = 0x01;
    public const FLAG_END_HEADERS = 0x04;
    public const FLAG_PADDED      = 0x08;
    public const FLAG_ACK         = 0x01; // for SETTINGS and PING

    // HTTP/2 connection preface
    public const CONNECTION_PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";

    // Frame header size (9 bytes)
    public const HEADER_SIZE = 9;

    /**
     * Build a raw HTTP/2 frame.
     *
     * @param int    $type     Frame type constant
     * @param int    $flags    Frame flags
     * @param int    $streamId Stream identifier
     * @param string $payload  Frame payload
     */
    public static function encode(int $type, int $flags, int $streamId, string $payload = ''): string
    {
        $length = strlen($payload);
        // 3 bytes length + 1 byte type + 1 byte flags + 4 bytes stream ID (31 bits)
        return substr(pack('N', $length), 1)    // 3 bytes big-endian length
            . chr($type)
            . chr($flags)
            . pack('N', $streamId & 0x7FFFFFFF)
            . $payload;
    }

    /**
     * Decode a frame header from 9 raw bytes.
     *
     * @return array{length: int, type: int, flags: int, stream_id: int}
     */
    public static function decodeHeader(string $headerBytes): array
    {
        $length = (ord($headerBytes[0]) << 16)
                | (ord($headerBytes[1]) << 8)
                | ord($headerBytes[2]);

        return [
            'length'    => $length,
            'type'      => ord($headerBytes[3]),
            'flags'     => ord($headerBytes[4]),
            'stream_id' => unpack('N', substr($headerBytes, 5, 4))[1] & 0x7FFFFFFF,
        ];
    }

    /**
     * Build a SETTINGS frame (empty = use defaults).
     */
    public static function settings(bool $ack = false): string
    {
        return self::encode(
            self::SETTINGS,
            $ack ? self::FLAG_ACK : 0,
            0,
        );
    }

    /**
     * Build a WINDOW_UPDATE frame.
     */
    public static function windowUpdate(int $streamId, int $increment): string
    {
        return self::encode(
            self::WINDOW_UPDATE,
            0,
            $streamId,
            pack('N', $increment & 0x7FFFFFFF),
        );
    }

    /**
     * Encode gRPC request headers into a HEADERS frame.
     *
     * Uses literal-without-indexing HPACK encoding (simplest form).
     * Fine for gRPC where the header set is small and fixed.
     *
     * @param string $method  gRPC method path (e.g. /spiffe.workload.SpiffeWorkloadAPI/FetchX509SVID)
     * @param int    $streamId
     */
    public static function grpcHeaders(string $method, int $streamId): string
    {
        $encoded = self::hpackEncode(':method', 'POST')
            . self::hpackEncode(':scheme', 'http')
            . self::hpackEncode(':path', $method)
            . self::hpackEncode(':authority', 'localhost')
            . self::hpackEncode('content-type', 'application/grpc')
            . self::hpackEncode('te', 'trailers');

        return self::encode(
            self::HEADERS,
            self::FLAG_END_HEADERS,
            $streamId,
            $encoded,
        );
    }

    /**
     * Encode a DATA frame containing a gRPC length-prefixed message.
     */
    public static function grpcData(string $grpcPayload, int $streamId, bool $endStream = true): string
    {
        return self::encode(
            self::DATA,
            $endStream ? self::FLAG_END_STREAM : 0,
            $streamId,
            $grpcPayload,
        );
    }

    /**
     * Minimal HPACK literal-without-indexing encoding.
     * Format: 0x00 | name-length | name | value-length | value
     */
    public static function hpackEncode(string $name, string $value): string
    {
        return "\x00"
            . self::hpackInt(strlen($name), 7) . $name
            . self::hpackInt(strlen($value), 7) . $value;
    }

    /**
     * HPACK integer encoding (prefix bits).
     */
    public static function hpackInt(int $value, int $prefixBits): string
    {
        $maxPrefix = (1 << $prefixBits) - 1;
        if ($value < $maxPrefix) {
            return chr($value);
        }

        $result = chr($maxPrefix);
        $value -= $maxPrefix;
        while ($value >= 128) {
            $result .= chr(($value & 0x7F) | 0x80);
            $value >>= 7;
        }
        $result .= chr($value);
        return $result;
    }

    /**
     * Parse HPACK-encoded headers from a HEADERS frame payload.
     * Handles only literal-without-indexing and indexed header fields.
     *
     * @return array<string, string>
     */
    public static function hpackDecode(string $data): array
    {
        $headers = [];
        $offset = 0;
        $len = strlen($data);

        // Static table (partial — just what gRPC uses)
        $staticTable = [
            1 => [':authority', ''],
            2 => [':method', 'GET'],
            3 => [':method', 'POST'],
            4 => [':path', '/'],
            5 => [':path', '/index.html'],
            6 => [':scheme', 'http'],
            7 => [':scheme', 'https'],
            8 => [':status', '200'],
            13 => [':status', '500'],
        ];

        while ($offset < $len) {
            $byte = ord($data[$offset]);

            if ($byte & 0x80) {
                // Indexed header field
                $index = $byte & 0x7F;
                if (isset($staticTable[$index])) {
                    $headers[$staticTable[$index][0]] = $staticTable[$index][1];
                }
                $offset++;
            } elseif (($byte & 0xC0) === 0x40 || $byte === 0x00 || ($byte & 0xF0) === 0x10) {
                // Literal header field
                $offset++;

                $nameIndex = $byte & 0x3F;
                if ($byte === 0x00 || ($byte & 0xF0) === 0x10) {
                    $nameIndex = $byte & 0x0F;
                }

                if ($nameIndex > 0 && isset($staticTable[$nameIndex])) {
                    $name = $staticTable[$nameIndex][0];
                } else {
                    $nameLen = self::hpackReadInt($data, $offset, 7);
                    $name = substr($data, $offset, $nameLen);
                    $offset += $nameLen;
                }

                $valueLen = self::hpackReadInt($data, $offset, 7);
                $value = substr($data, $offset, $valueLen);
                $offset += $valueLen;

                $headers[$name] = $value;
            } else {
                // Dynamic table size update or unknown — skip
                $offset++;
            }
        }

        return $headers;
    }

    private static function hpackReadInt(string $data, int &$offset, int $prefixBits): int
    {
        $maxPrefix = (1 << $prefixBits) - 1;
        $value = ord($data[$offset]) & $maxPrefix;
        $offset++;

        if ($value < $maxPrefix) {
            return $value;
        }

        $shift = 0;
        do {
            $byte = ord($data[$offset]);
            $offset++;
            $value += ($byte & 0x7F) << $shift;
            $shift += 7;
        } while ($byte & 0x80);

        return $value;
    }
}
