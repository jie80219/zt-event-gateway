<?php

declare(strict_types=1);

namespace Tests\Unit\Spiffe\Runtime;

use PHPUnit\Framework\TestCase;
use Spiffe\Runtime\Http2Frame;

final class Http2FrameTest extends TestCase
{
    public function testEncodeDecodeFrameHeader(): void
    {
        $frame = Http2Frame::encode(Http2Frame::DATA, Http2Frame::FLAG_END_STREAM, 1, 'hello');
        $this->assertSame(Http2Frame::HEADER_SIZE + 5, strlen($frame));

        $header = Http2Frame::decodeHeader(substr($frame, 0, Http2Frame::HEADER_SIZE));
        $this->assertSame(5, $header['length']);
        $this->assertSame(Http2Frame::DATA, $header['type']);
        $this->assertSame(Http2Frame::FLAG_END_STREAM, $header['flags']);
        $this->assertSame(1, $header['stream_id']);

        $payload = substr($frame, Http2Frame::HEADER_SIZE);
        $this->assertSame('hello', $payload);
    }

    public function testSettingsFrame(): void
    {
        $settings = Http2Frame::settings();
        $header = Http2Frame::decodeHeader(substr($settings, 0, 9));

        $this->assertSame(Http2Frame::SETTINGS, $header['type']);
        $this->assertSame(0, $header['flags']);
        $this->assertSame(0, $header['stream_id']);
        $this->assertSame(0, $header['length']);
    }

    public function testSettingsAck(): void
    {
        $ack = Http2Frame::settings(ack: true);
        $header = Http2Frame::decodeHeader(substr($ack, 0, 9));

        $this->assertSame(Http2Frame::SETTINGS, $header['type']);
        $this->assertSame(Http2Frame::FLAG_ACK, $header['flags']);
    }

    public function testWindowUpdate(): void
    {
        $wu = Http2Frame::windowUpdate(0, 65535);
        $header = Http2Frame::decodeHeader(substr($wu, 0, 9));

        $this->assertSame(Http2Frame::WINDOW_UPDATE, $header['type']);
        $this->assertSame(0, $header['stream_id']);
        $this->assertSame(4, $header['length']);
    }

    public function testGrpcHeadersFrame(): void
    {
        $frame = Http2Frame::grpcHeaders('/spiffe.workload.SpiffeWorkloadAPI/FetchX509SVID', 1);
        $header = Http2Frame::decodeHeader(substr($frame, 0, 9));

        $this->assertSame(Http2Frame::HEADERS, $header['type']);
        $this->assertSame(Http2Frame::FLAG_END_HEADERS, $header['flags']);
        $this->assertSame(1, $header['stream_id']);
        $this->assertGreaterThan(0, $header['length']);
    }

    public function testGrpcDataFrame(): void
    {
        $payload = pack('CN', 0, 5) . 'hello'; // gRPC framed
        $frame = Http2Frame::grpcData($payload, 1, endStream: true);
        $header = Http2Frame::decodeHeader(substr($frame, 0, 9));

        $this->assertSame(Http2Frame::DATA, $header['type']);
        $this->assertSame(Http2Frame::FLAG_END_STREAM, $header['flags']);
        $this->assertSame(1, $header['stream_id']);
    }

    public function testHpackEncodeDecode(): void
    {
        $encoded = Http2Frame::hpackEncode('content-type', 'application/grpc')
            . Http2Frame::hpackEncode('te', 'trailers');

        $decoded = Http2Frame::hpackDecode($encoded);

        $this->assertSame('application/grpc', $decoded['content-type']);
        $this->assertSame('trailers', $decoded['te']);
    }

    public function testHpackIntSmall(): void
    {
        $encoded = Http2Frame::hpackInt(42, 7);
        $this->assertSame(chr(42), $encoded);
    }

    public function testHpackIntLarge(): void
    {
        $encoded = Http2Frame::hpackInt(300, 7);
        $this->assertGreaterThan(1, strlen($encoded));
    }

    public function testConnectionPreface(): void
    {
        $this->assertSame("PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n", Http2Frame::CONNECTION_PREFACE);
        $this->assertSame(24, strlen(Http2Frame::CONNECTION_PREFACE));
    }
}
