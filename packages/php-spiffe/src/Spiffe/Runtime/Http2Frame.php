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
    public const RST_STREAM    = 0x03;
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
            . self::hpackEncode('te', 'trailers')
            . self::hpackEncode('workload.spiffe.io', 'true');

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

        // RFC 7541 Appendix A — complete HPACK static table (61 entries).
        // grpc-go uses indexed references to entries like content-type (31),
        // so the table MUST be complete; a partial table causes the decoder
        // to misinterpret indexed names as wire-encoded names, corrupting
        // all subsequent offsets.
        $staticTable = [
            1  => [':authority', ''],
            2  => [':method', 'GET'],
            3  => [':method', 'POST'],
            4  => [':path', '/'],
            5  => [':path', '/index.html'],
            6  => [':scheme', 'http'],
            7  => [':scheme', 'https'],
            8  => [':status', '200'],
            9  => [':status', '204'],
            10 => [':status', '206'],
            11 => [':status', '304'],
            12 => [':status', '400'],
            13 => [':status', '404'],
            14 => [':status', '500'],
            15 => ['accept-charset', ''],
            16 => ['accept-encoding', 'gzip, deflate'],
            17 => ['accept-language', ''],
            18 => ['accept-ranges', ''],
            19 => ['accept', ''],
            20 => ['access-control-allow-origin', ''],
            21 => ['age', ''],
            22 => ['allow', ''],
            23 => ['authorization', ''],
            24 => ['cache-control', ''],
            25 => ['content-disposition', ''],
            26 => ['content-encoding', ''],
            27 => ['content-language', ''],
            28 => ['content-length', ''],
            29 => ['content-location', ''],
            30 => ['content-range', ''],
            31 => ['content-type', ''],
            32 => ['date', ''],
            33 => ['etag', ''],
            34 => ['expect', ''],
            35 => ['expires', ''],
            36 => ['from', ''],
            37 => ['host', ''],
            38 => ['if-match', ''],
            39 => ['if-modified-since', ''],
            40 => ['if-none-match', ''],
            41 => ['if-range', ''],
            42 => ['if-unmodified-since', ''],
            43 => ['last-modified', ''],
            44 => ['link', ''],
            45 => ['location', ''],
            46 => ['max-forwards', ''],
            47 => ['proxy-authenticate', ''],
            48 => ['proxy-authorization', ''],
            49 => ['range', ''],
            50 => ['referer', ''],
            51 => ['refresh', ''],
            52 => ['retry-after', ''],
            53 => ['server', ''],
            54 => ['set-cookie', ''],
            55 => ['strict-transport-security', ''],
            56 => ['transfer-encoding', ''],
            57 => ['user-agent', ''],
            58 => ['vary', ''],
            59 => ['via', ''],
            60 => ['www-authenticate', ''],
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
                    [$name, ] = self::hpackReadString($data, $offset);
                }

                [$value, ] = self::hpackReadString($data, $offset);

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

    /**
     * Read an HPACK string (handles Huffman bit).
     *
     * @return array{0: string, 1: bool} [decoded_string, was_huffman]
     */
    private static function hpackReadString(string $data, int &$offset): array
    {
        $firstByte = ord($data[$offset]);
        $huffman = (bool) ($firstByte & 0x80);
        $strLen = self::hpackReadInt($data, $offset, 7);
        $raw = substr($data, $offset, $strLen);
        $offset += $strLen;

        if ($huffman) {
            return [self::huffmanDecode($raw), true];
        }
        return [$raw, false];
    }

    /**
     * Decode HPACK Huffman-encoded bytes (RFC 7541 Appendix B).
     *
     * Uses a flat decode table: for each symbol (0–256), the table stores
     * [code, bitLength]. Decoding walks the input bits and matches against
     * the table entries. EOS (symbol 256) terminates.
     */
    private static function huffmanDecode(string $data): string
    {
        // Build decode tree on first call (cached in static var).
        static $tree = null;
        if ($tree === null) {
            $tree = self::buildHuffmanTree();
        }

        $result = '';
        $node = $tree;
        $bits = '';

        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $byte = ord($data[$i]);
            for ($bit = 7; $bit >= 0; $bit--) {
                $b = ($byte >> $bit) & 1;
                $node = $b ? ($node['r'] ?? null) : ($node['l'] ?? null);
                if ($node === null) {
                    // Invalid Huffman code — return what we have
                    return $result;
                }
                if (isset($node['sym'])) {
                    if ($node['sym'] === 256) {
                        return $result; // EOS
                    }
                    $result .= chr($node['sym']);
                    $node = $tree;
                }
            }
        }

        return $result;
    }

    /**
     * Build the HPACK Huffman binary tree from the RFC 7541 table.
     *
     * @return array Trie node: ['l' => left, 'r' => right, 'sym' => int]
     */
    private static function buildHuffmanTree(): array
    {
        // RFC 7541 Appendix B: symbol → [hex_code, bit_length]
        $table = [
            [0x1ff8, 13], [0x7fffd8, 23], [0xfffffe2, 28], [0xfffffe3, 28],
            [0xfffffe4, 28], [0xfffffe5, 28], [0xfffffe6, 28], [0xfffffe7, 28],
            [0xfffffe8, 28], [0xffffea, 24], [0x3ffffffc, 30], [0xfffffe9, 28],
            [0xfffffea, 28], [0x3ffffffd, 30], [0xfffffeb, 28], [0xfffffec, 28],
            [0xfffffed, 28], [0xfffffee, 28], [0xfffffef, 28], [0xffffff0, 28],
            [0xffffff1, 28], [0xffffff2, 28], [0x3ffffffe, 30], [0xffffff3, 28],
            [0xffffff4, 28], [0xffffff5, 28], [0xffffff6, 28], [0xffffff7, 28],
            [0xffffff8, 28], [0xffffff9, 28], [0xffffffa, 28], [0xffffffb, 28],
            [0x14, 6],     // 32 ' '
            [0x3f8, 10], [0x3f9, 10], [0xffa, 12], [0x1ff9, 13],
            [0x15, 6], [0xf8, 8], [0x7fa, 11], [0x3fa, 10], [0x3fb, 10],
            [0xf9, 8], [0x7fb, 11], [0xfa, 8], [0x16, 6], [0x17, 6], [0x18, 6],
            [0x0, 5], [0x1, 5], [0x2, 5], [0x19, 6], [0x1a, 6], [0x1b, 6],
            [0x1c, 6], [0x1d, 6], [0x1e, 6], [0x1f, 6], [0x5c, 7], [0xfb, 8],
            [0x7ffc, 15],  // 58 ':'
            [0x20, 6], [0xffb, 12], [0x3fc, 10], [0x1ffa, 13],
            [0x21, 6],     // 64 '@' → but 65 is 'A'
            [0x5d, 7], [0x5e, 7], [0x5f, 7], [0x60, 7], [0x61, 7],
            [0x62, 7], [0x63, 7], [0x64, 7], [0x65, 7], [0x66, 7],
            [0x67, 7], [0x68, 7], [0x69, 7], [0x6a, 7], [0x6b, 7],
            [0x6c, 7], [0x6d, 7], [0x6e, 7], [0x6f, 7], [0x70, 7],
            [0x71, 7], [0x72, 7], [0xfc, 8], [0x73, 7], [0xfd, 8],
            [0x1ffb, 13], [0x7fff0, 19], [0x1ffc, 13], [0x3ffc, 14],
            [0x22, 6],     // 95 '_'
            [0x7ffd, 15],
            [0x3, 5], [0x23, 6], [0x4, 5], [0x24, 6], [0x5, 5],     // a-e
            [0x25, 6], [0x26, 6], [0x27, 6], [0x6, 5], [0x74, 7],   // f-j
            [0x75, 7], [0x28, 6], [0x29, 6], [0x2a, 6], [0x7, 5],   // k-o
            [0x2b, 6], [0x76, 7], [0x2c, 6], [0x8, 5], [0x9, 5],    // p-t
            [0x2d, 6], [0x77, 7], [0x78, 7], [0x79, 7], [0x7a, 7],  // u-y
            [0x7b, 7],                                                 // z
            [0x7ffe, 15], [0x7fc, 11], [0x3ffd, 14], [0x1ffd, 13],
            [0xffffffc, 28], [0xfffe6, 20], [0x3fffd2, 22], [0xfffe7, 20],
            [0xfffe8, 20], [0x3fffd3, 22], [0x3fffd4, 22], [0x3fffd5, 22],
            [0x7fffd9, 23], [0x3fffd6, 22], [0x7fffda, 23], [0x7fffdb, 23],
            [0x7fffdc, 23], [0x7fffdd, 23], [0x7fffde, 23], [0xffffeb, 24],
            [0x7fffdf, 23], [0xffffec, 24], [0xffffed, 24], [0x3fffd7, 22],
            [0x7fffe0, 23], [0xffffee, 24], [0x7fffe1, 23], [0x7fffe2, 23],
            [0x7fffe3, 23], [0x7fffe4, 23], [0x1fffdc, 21], [0x3fffd8, 22],
            [0x7fffe5, 23], [0x3fffd9, 22], [0x7fffe6, 23], [0x7fffe7, 23],
            [0xffffef, 24], [0x3fffda, 22], [0x1fffdd, 21], [0xfffe9, 20],
            [0x3fffdb, 22], [0x3fffdc, 22], [0x7fffe8, 23], [0x7fffe9, 23],
            [0x1fffde, 21], [0x7fffea, 23], [0x3fffdd, 22], [0x3fffde, 22],
            [0xfffff0, 24], [0x1fffdf, 21], [0x3fffdf, 22], [0x7fffeb, 23],
            [0x7fffec, 23], [0x1fffe0, 21], [0x1fffe1, 21], [0x3fffe0, 22],
            [0x1fffe2, 21], [0x7fffed, 23], [0x3fffe1, 22], [0x7fffee, 23],
            [0x7fffef, 23], [0xfffea, 20], [0x3fffe2, 22], [0x3fffe3, 22],
            [0x3fffe4, 22], [0x7ffff0, 23], [0x3fffe5, 22], [0x3fffe6, 22],
            [0x7ffff1, 23], [0x3ffffe0, 26], [0x3ffffe1, 26], [0xfffeb, 20],
            [0x7fff1, 19], [0x3fffe7, 22], [0x7ffff2, 23], [0x3fffe8, 22],
            [0x1ffffec, 25], [0x3ffffe2, 26], [0x3ffffe3, 26], [0x3ffffe4, 26],
            [0x7ffffde, 27], [0x7ffffdf, 27], [0x3ffffe5, 26], [0xfffff1, 24],
            [0x1ffffed, 25], [0x7fff2, 19], [0x1fffe3, 21], [0x3ffffe6, 26],
            [0x7ffffe0, 27], [0x7ffffe1, 27], [0x3ffffe7, 26], [0x7ffffe2, 27],
            [0xfffff2, 24], [0x1fffe4, 21], [0x1fffe5, 21], [0x3ffffe8, 26],
            [0x3ffffe9, 26], [0xffffffd, 28], [0x7ffffe3, 27], [0x7ffffe4, 27],
            [0x7ffffe5, 27], [0xfffec, 20], [0xfffff3, 24], [0xfffed, 20],
            [0x1fffe6, 21], [0x3fffe9, 22], [0x1fffe7, 21], [0x1fffe8, 21],
            [0x7ffff3, 23], [0x3fffea, 22], [0x3fffeb, 22], [0x1ffffee, 25],
            [0x1ffffef, 25], [0xfffff4, 24], [0xfffff5, 24], [0x3ffffea, 26],
            [0x7ffff4, 23], [0x3ffffeb, 26], [0x7ffffe6, 27], [0x3ffffec, 26],
            [0x3ffffed, 26], [0x7ffffe7, 27], [0x7ffffe8, 27], [0x7ffffe9, 27],
            [0x7ffffea, 27], [0x7ffffeb, 27], [0xffffffe, 28], [0x7ffffec, 27],
            [0x7ffffed, 27], [0x7ffffee, 27], [0x7ffffef, 27], [0x7fffff0, 27],
            [0x3ffffee, 26],
            [0x3fffffff, 30], // 256 = EOS
        ];

        $root = [];
        foreach ($table as $sym => [$code, $bits]) {
            $node = &$root;
            for ($i = $bits - 1; $i >= 0; $i--) {
                $dir = ($code >> $i) & 1 ? 'r' : 'l';
                if (!isset($node[$dir])) {
                    $node[$dir] = [];
                }
                $node = &$node[$dir];
            }
            $node['sym'] = $sym;
            unset($node);
        }

        return $root;
    }
}
