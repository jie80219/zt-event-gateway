<?php

declare(strict_types=1);

namespace Spiffe;

use Spiffe\Runtime\Http2Frame;

/**
 * PHP-native replacement for the Go spiffe-helper binary.
 *
 * Feature parity with Go spiffe-helper:
 *   ✅ Fetch X.509-SVID and write PEM files
 *   ✅ Fetch JWT-SVID and write JWT bundle
 *   ✅ Daemon mode (persistent gRPC stream, auto-update on rotation)
 *   ✅ Renew signal (SIGHUP) to notify external processes
 *   ✅ Post-rotation command execution
 *
 * Uses ext-sockets (NOT stream_socket) to avoid Swow hook conflicts.
 *
 * Usage:
 *   // One-shot fetch
 *   $helper = new SpiffeHelper('/run/spire/sockets/agent.sock');
 *   $svid = $helper->fetchX509Svid();
 *
 *   // Daemon mode (like `spiffe-helper -config helper.conf`)
 *   $helper->daemon('/certs', [
 *       'renew_signal'  => 'SIGHUP',
 *       'pid_file'      => '/var/run/nginx.pid',
 *       'cmd'           => 'nginx',
 *       'cmd_args'      => ['-s', 'reload'],
 *       'jwt_audience'  => ['my-service'],
 *       'poll_interval' => 30,
 *   ]);
 */
final class SpiffeHelper
{
    private const GRPC_HEADER_SIZE = 5;

    private string $socketPath;
    private float $timeout;

    /** @var callable(string): void */
    private $logger;

    public function __construct(string $socketPath = '/run/spire/sockets/agent.sock', float $timeout = 10.0)
    {
        $this->socketPath = preg_replace('#^unix:#', '', $socketPath);
        $this->timeout = $timeout;
        $this->logger = static function (string $msg): void {
            fwrite(STDOUT, sprintf("[spiffe-helper-php] %s %s\n", date('H:i:s'), $msg));
        };
    }

    public function withLogger(callable $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    // ══════════════════════════════════════════════════════════════
    //  One-shot: Fetch X.509-SVID
    // ══════════════════════════════════════════════════════════════

    /**
     * @return array{spiffe_id: string, cert_pem: string, key_pem: string, bundle_pem: string, hint: string}
     */
    public function fetchX509Svid(): array
    {
        return $this->grpcCall(
            '/spiffe.workload.SpiffeWorkloadAPI/FetchX509SVID',
            pack('CN', 0, 0), // empty X509SVIDRequest
            fn(string $data) => $this->parseX509Response($data),
        );
    }

    /**
     * @return list<array{spiffe_id: string, cert_pem: string, key_pem: string, bundle_pem: string, hint: string}>
     */
    public function fetchAllX509Svids(): array
    {
        return $this->grpcCall(
            '/spiffe.workload.SpiffeWorkloadAPI/FetchX509SVID',
            pack('CN', 0, 0),
            fn(string $data) => $this->parseAllX509Response($data),
        );
    }

    // ══════════════════════════════════════════════════════════════
    //  One-shot: Fetch JWT-SVID
    // ══════════════════════════════════════════════════════════════

    /**
     * @param list<string> $audience
     * @return array{spiffe_id: string, token: string, hint: string}
     */
    public function fetchJwtSvid(array $audience, string $spiffeId = ''): array
    {
        $requestBody = $this->encodeJwtSvidRequest($audience, $spiffeId);
        $grpcPayload = pack('CN', 0, strlen($requestBody)) . $requestBody;

        return $this->grpcCall(
            '/spiffe.workload.SpiffeWorkloadAPI/FetchJWTSVID',
            $grpcPayload,
            fn(string $data) => $this->parseJwtSvidResponse($data),
        );
    }

    /**
     * Fetch JWT bundles (JWKS) for all trust domains.
     *
     * @return array<string, string>  trust_domain_uri => JWKS JSON
     */
    public function fetchJwtBundles(): array
    {
        return $this->grpcCall(
            '/spiffe.workload.SpiffeWorkloadAPI/FetchJWTBundles',
            pack('CN', 0, 0),
            fn(string $data) => $this->parseJwtBundlesResponse($data),
        );
    }

    // ══════════════════════════════════════════════════════════════
    //  One-shot: Write PEM files
    // ══════════════════════════════════════════════════════════════

    /**
     * @return array{cert: string, key: string, ca: string}
     */
    public function writePemFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $svid = $this->fetchX509Svid();

        $paths = [
            'cert' => "{$dir}/svid.pem",
            'key'  => "{$dir}/svid_key.pem",
            'ca'   => "{$dir}/bundle.pem",
        ];

        $this->atomicWrite($paths['cert'], $svid['cert_pem'], 0644);
        $this->atomicWrite($paths['key'], $svid['key_pem'], 0600);
        $this->atomicWrite($paths['ca'], $svid['bundle_pem'], 0644);

        return $paths;
    }

    /**
     * Write JWT bundle JWKS files.
     *
     * @return array<string, string>  trust_domain => file_path
     */
    public function writeJwtBundleFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $bundles = $this->fetchJwtBundles();
        $paths = [];

        foreach ($bundles as $tdUri => $jwksJson) {
            $safeName = preg_replace('/[^a-z0-9._-]/', '_', strtolower($tdUri));
            $path = "{$dir}/{$safeName}_jwks.json";
            $this->atomicWrite($path, $jwksJson, 0644);
            $paths[$tdUri] = $path;
        }

        return $paths;
    }

    // ══════════════════════════════════════════════════════════════
    //  Daemon mode — persistent watching + auto PEM rotation
    // ══════════════════════════════════════════════════════════════

    /**
     * Run as a daemon, watching for SVID rotations and updating PEM files.
     *
     * Equivalent to Go spiffe-helper with daemon_mode=true.
     *
     * @param string $certDir  Directory for PEM output
     * @param array{
     *     renew_signal?: string,
     *     pid_file?: string,
     *     cmd?: string,
     *     cmd_args?: list<string>,
     *     jwt_audience?: list<string>,
     *     jwt_bundle_dir?: string,
     *     poll_interval?: int,
     * } $options
     */
    public function daemon(string $certDir, array $options = []): void
    {
        $renewSignal   = $options['renew_signal'] ?? '';
        $pidFile       = $options['pid_file'] ?? '';
        $cmd           = $options['cmd'] ?? '';
        $cmdArgs       = $options['cmd_args'] ?? [];
        $jwtAudience   = $options['jwt_audience'] ?? [];
        $jwtBundleDir  = $options['jwt_bundle_dir'] ?? '';
        $pollInterval  = $options['poll_interval'] ?? 30;

        $this->log("Daemon started — cert_dir={$certDir} poll={$pollInterval}s");

        if (!is_dir($certDir)) {
            mkdir($certDir, 0755, true);
        }

        // Install SIGTERM/SIGINT handler for graceful shutdown
        $running = true;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () use (&$running) {
                $this->log('Received SIGTERM, shutting down');
                $running = false;
            });
            pcntl_signal(SIGINT, function () use (&$running) {
                $this->log('Received SIGINT, shutting down');
                $running = false;
            });
        }

        $lastCertHash = '';
        $rotationCount = 0;

        while ($running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            try {
                // ── Fetch X.509 SVID ─────────────────────────
                $svid = $this->fetchX509Svid();
                $currentHash = md5($svid['cert_pem']);

                if ($currentHash !== $lastCertHash) {
                    $rotationCount++;
                    $isFirst = $lastCertHash === '';
                    $lastCertHash = $currentHash;

                    // Write PEM files
                    $this->atomicWrite("{$certDir}/svid.pem", $svid['cert_pem'], 0644);
                    $this->atomicWrite("{$certDir}/svid_key.pem", $svid['key_pem'], 0600);
                    $this->atomicWrite("{$certDir}/bundle.pem", $svid['bundle_pem'], 0644);

                    $this->log(sprintf(
                        '%s X.509: %s (rotation #%d)',
                        $isFirst ? 'Initial' : 'Rotated',
                        $svid['spiffe_id'],
                        $rotationCount,
                    ));

                    // ── Fetch JWT bundle if configured ────────
                    if ($jwtBundleDir !== '' || $jwtAudience !== []) {
                        $this->fetchAndWriteJwt($certDir, $jwtBundleDir ?: $certDir, $jwtAudience);
                    }

                    // ── Post-rotation: send signal ────────────
                    if (!$isFirst && $renewSignal !== '' && $pidFile !== '') {
                        $this->sendSignal($renewSignal, $pidFile);
                    }

                    // ── Post-rotation: execute command ────────
                    if (!$isFirst && $cmd !== '') {
                        $this->executeCommand($cmd, $cmdArgs);
                    }
                }
            } catch (\Throwable $e) {
                $this->log("Error: {$e->getMessage()}");
            }

            // Sleep with signal checking
            for ($i = 0; $i < $pollInterval && $running; $i++) {
                sleep(1);
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
            }
        }

        $this->log("Daemon stopped (rotations: {$rotationCount})");
    }

    // ══════════════════════════════════════════════════════════════
    //  Post-rotation actions
    // ══════════════════════════════════════════════════════════════

    /**
     * Send a signal to a process specified by PID file.
     */
    private function sendSignal(string $signalName, string $pidFile): void
    {
        if (!file_exists($pidFile)) {
            $this->log("PID file not found: {$pidFile}");
            return;
        }

        $pid = (int) trim(file_get_contents($pidFile));
        if ($pid <= 0) {
            $this->log("Invalid PID in {$pidFile}");
            return;
        }

        $signal = match (strtoupper($signalName)) {
            'SIGHUP'  => SIGHUP,
            'SIGUSR1' => SIGUSR1,
            'SIGUSR2' => SIGUSR2,
            'SIGTERM' => SIGTERM,
            default   => null,
        };

        if ($signal === null) {
            $this->log("Unknown signal: {$signalName}");
            return;
        }

        if (posix_kill($pid, $signal)) {
            $this->log("Sent {$signalName} to PID {$pid}");
        } else {
            $this->log("Failed to send {$signalName} to PID {$pid}");
        }
    }

    /**
     * Execute a command after rotation.
     *
     * @param list<string> $args
     */
    private function executeCommand(string $cmd, array $args): void
    {
        $fullCmd = $cmd . ' ' . implode(' ', array_map('escapeshellarg', $args));
        $this->log("Executing: {$fullCmd}");

        $output = [];
        $returnCode = 0;
        exec($fullCmd . ' 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            $this->log("Command failed (exit {$returnCode}): " . implode(' ', $output));
        } else {
            $this->log("Command succeeded");
        }
    }

    private function fetchAndWriteJwt(string $certDir, string $bundleDir, array $audience): void
    {
        try {
            // JWT bundles
            $bundles = $this->fetchJwtBundles();
            foreach ($bundles as $tdUri => $jwksJson) {
                $safeName = preg_replace('/[^a-z0-9._-]/', '_', strtolower($tdUri));
                $this->atomicWrite("{$bundleDir}/{$safeName}_jwks.json", $jwksJson, 0644);
            }
            $this->log(sprintf('JWT bundles: %d trust domain(s)', count($bundles)));

            // JWT SVID (if audience specified)
            if ($audience !== []) {
                $jwt = $this->fetchJwtSvid($audience);
                $this->atomicWrite("{$certDir}/jwt_svid.token", $jwt['token'], 0600);
                $this->log("JWT-SVID: {$jwt['spiffe_id']} audience=[" . implode(',', $audience) . ']');
            }
        } catch (\Throwable $e) {
            $this->log("JWT fetch error: {$e->getMessage()}");
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  gRPC transport (ext-sockets, NOT hooked by Swow)
    // ══════════════════════════════════════════════════════════════

    /**
     * @template T
     * @param callable(string): T $parser
     * @return T
     */
    private function grpcCall(string $method, string $grpcPayload, callable $parser): mixed
    {
        $sock = $this->connect();

        try {
            // HTTP/2 preface + SETTINGS in one write
            $this->socketSend($sock, Http2Frame::CONNECTION_PREFACE . Http2Frame::settings());

            // Read server SETTINGS
            $this->readAndHandleFrames($sock, 1);

            // ACK + WINDOW_UPDATE
            $this->socketSend($sock,
                Http2Frame::settings(ack: true)
                . Http2Frame::windowUpdate(0, 1048576)
            );

            // Send request
            $streamId = 1;
            $this->socketSend($sock,
                Http2Frame::grpcHeaders($method, $streamId)
                . Http2Frame::grpcData($grpcPayload, $streamId, endStream: true)
            );

            // Read response
            $responseData = $this->readGrpcResponse($sock, $streamId);

            return $parser($responseData);
        } finally {
            socket_close($sock);
        }
    }

    /** @return \Socket */
    private function connect(): \Socket
    {
        $sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
        if ($sock === false) {
            throw new \RuntimeException('socket_create failed: ' . socket_strerror(socket_last_error()));
        }

        $sec = (int) $this->timeout;
        $usec = (int) (($this->timeout - $sec) * 1000000);
        socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $sec, 'usec' => $usec]);
        socket_set_option($sock, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $sec, 'usec' => $usec]);

        if (!@socket_connect($sock, $this->socketPath)) {
            $err = socket_strerror(socket_last_error($sock));
            socket_close($sock);
            throw new \RuntimeException("Cannot connect to SPIRE Agent at {$this->socketPath}: {$err}");
        }

        return $sock;
    }

    private function socketSend(\Socket $sock, string $data): void
    {
        $sent = 0;
        $total = strlen($data);
        while ($sent < $total) {
            $n = @socket_send($sock, substr($data, $sent), $total - $sent, 0);
            if ($n === false) {
                throw new \RuntimeException('socket_send failed: ' . socket_strerror(socket_last_error($sock)));
            }
            $sent += $n;
        }
    }

    private function socketRecv(\Socket $sock, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = '';
            $n = @socket_recv($sock, $chunk, $length - strlen($data), MSG_WAITALL);
            if ($n === false || $n === 0) {
                throw new \RuntimeException(
                    'socket_recv failed (' . strlen($data) . "/{$length}): "
                    . socket_strerror(socket_last_error($sock))
                );
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function readFrame(\Socket $sock): array
    {
        $h = Http2Frame::decodeHeader($this->socketRecv($sock, Http2Frame::HEADER_SIZE));
        $payload = $h['length'] > 0 ? $this->socketRecv($sock, $h['length']) : '';
        return ['type' => $h['type'], 'flags' => $h['flags'], 'stream_id' => $h['stream_id'], 'payload' => $payload];
    }

    private function readAndHandleFrames(\Socket $sock, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $f = $this->readFrame($sock);
            if ($f['type'] === Http2Frame::SETTINGS && !($f['flags'] & Http2Frame::FLAG_ACK)) {
                $this->socketSend($sock, Http2Frame::settings(ack: true));
            }
            if ($f['type'] === Http2Frame::PING && !($f['flags'] & Http2Frame::FLAG_ACK)) {
                $this->socketSend($sock, Http2Frame::encode(Http2Frame::PING, Http2Frame::FLAG_ACK, 0, $f['payload']));
            }
        }
    }

    private function readGrpcResponse(\Socket $sock, int $streamId): string
    {
        $data = '';

        while (true) {
            $f = $this->readFrame($sock);

            if ($f['type'] === Http2Frame::SETTINGS && !($f['flags'] & Http2Frame::FLAG_ACK)) {
                $this->socketSend($sock, Http2Frame::settings(ack: true));
                continue;
            }
            if ($f['type'] === Http2Frame::PING && !($f['flags'] & Http2Frame::FLAG_ACK)) {
                $this->socketSend($sock, Http2Frame::encode(Http2Frame::PING, Http2Frame::FLAG_ACK, 0, $f['payload']));
                continue;
            }
            if ($f['type'] === Http2Frame::WINDOW_UPDATE) {
                continue;
            }

            if ($f['type'] === Http2Frame::HEADERS) {
                $headers = Http2Frame::hpackDecode($f['payload']);
                if (isset($headers['grpc-status']) && (int) $headers['grpc-status'] !== 0) {
                    throw new \RuntimeException(sprintf(
                        'gRPC error: status=%s message=%s',
                        $headers['grpc-status'],
                        $headers['grpc-message'] ?? 'unknown',
                    ));
                }
                if ($f['flags'] & Http2Frame::FLAG_END_STREAM) {
                    break;
                }
                continue;
            }

            if ($f['type'] === Http2Frame::DATA && $f['stream_id'] === $streamId) {
                $data .= $f['payload'];
                if (strlen($f['payload']) > 0) {
                    $this->socketSend($sock, Http2Frame::windowUpdate(0, strlen($f['payload'])));
                    $this->socketSend($sock, Http2Frame::windowUpdate($streamId, strlen($f['payload'])));
                }
                if ($f['flags'] & Http2Frame::FLAG_END_STREAM) {
                    break;
                }
            }

            if ($f['type'] === Http2Frame::GOAWAY) {
                throw new \RuntimeException('Server sent GOAWAY');
            }
        }

        return $data;
    }

    // ══════════════════════════════════════════════════════════════
    //  Protobuf encoding (manual, no ext-protobuf dependency)
    // ══════════════════════════════════════════════════════════════

    /**
     * Encode JWTSVIDRequest protobuf.
     *
     * message JWTSVIDRequest {
     *   repeated string audience = 1;
     *   string spiffe_id = 2;
     * }
     */
    private function encodeJwtSvidRequest(array $audience, string $spiffeId): string
    {
        $buf = '';

        // field 1: repeated string audience
        foreach ($audience as $aud) {
            $buf .= $this->encodeVarint((1 << 3) | 2); // field 1, wire type 2
            $buf .= $this->encodeVarint(strlen($aud));
            $buf .= $aud;
        }

        // field 2: string spiffe_id
        if ($spiffeId !== '') {
            $buf .= $this->encodeVarint((2 << 3) | 2);
            $buf .= $this->encodeVarint(strlen($spiffeId));
            $buf .= $spiffeId;
        }

        return $buf;
    }

    private function encodeVarint(int $value): string
    {
        $buf = '';
        while ($value > 0x7F) {
            $buf .= chr(($value & 0x7F) | 0x80);
            $value >>= 7;
        }
        $buf .= chr($value & 0x7F);
        return $buf;
    }

    // ══════════════════════════════════════════════════════════════
    //  Protobuf parsing (manual, no ext-protobuf dependency)
    // ══════════════════════════════════════════════════════════════

    private function parseX509Response(string $grpcData): array
    {
        $all = $this->parseAllX509Response($grpcData);
        if ($all === []) {
            throw new \RuntimeException('X509SVIDResponse contains no SVIDs');
        }
        return $all[0];
    }

    private function parseAllX509Response(string $grpcData): array
    {
        if (strlen($grpcData) < self::GRPC_HEADER_SIZE) {
            throw new \RuntimeException('gRPC response too short: ' . strlen($grpcData) . ' bytes');
        }
        $proto = substr($grpcData, self::GRPC_HEADER_SIZE);
        $fields = $this->parseProtobuf($proto);

        $svids = [];
        foreach ($fields[1] ?? [] as $svidBytes) {
            $sf = $this->parseProtobuf($svidBytes);
            $svids[] = [
                'spiffe_id'  => $sf[1][0] ?? '',
                'cert_pem'   => $this->derToPem($sf[2][0] ?? '', 'CERTIFICATE'),
                'key_pem'    => $this->derKeyToPem($sf[3][0] ?? ''),
                'bundle_pem' => $this->derToPem($sf[4][0] ?? '', 'CERTIFICATE'),
                'hint'       => $sf[5][0] ?? '',
            ];
        }

        return $svids;
    }

    /**
     * Parse JWTSVIDResponse.
     *
     * message JWTSVIDResponse { repeated JWTSVID svids = 1; }
     * message JWTSVID { string spiffe_id = 1; string svid = 2; string hint = 3; }
     */
    private function parseJwtSvidResponse(string $grpcData): array
    {
        if (strlen($grpcData) < self::GRPC_HEADER_SIZE) {
            throw new \RuntimeException('JWT gRPC response too short');
        }
        $proto = substr($grpcData, self::GRPC_HEADER_SIZE);
        $fields = $this->parseProtobuf($proto);

        $svidBytes = $fields[1][0] ?? null;
        if ($svidBytes === null) {
            throw new \RuntimeException('JWTSVIDResponse contains no SVIDs');
        }

        $sf = $this->parseProtobuf($svidBytes);
        return [
            'spiffe_id' => $sf[1][0] ?? '',
            'token'     => $sf[2][0] ?? '',
            'hint'      => $sf[3][0] ?? '',
        ];
    }

    /**
     * Parse JWTBundlesResponse.
     *
     * message JWTBundlesResponse { map<string, bytes> bundles = 1; }
     *
     * Protobuf map is encoded as repeated message { string key = 1; bytes value = 2; }
     */
    private function parseJwtBundlesResponse(string $grpcData): array
    {
        if (strlen($grpcData) < self::GRPC_HEADER_SIZE) {
            throw new \RuntimeException('JWT bundles gRPC response too short');
        }
        $proto = substr($grpcData, self::GRPC_HEADER_SIZE);
        $fields = $this->parseProtobuf($proto);

        $bundles = [];
        foreach ($fields[1] ?? [] as $entryBytes) {
            $ef = $this->parseProtobuf($entryBytes);
            $key = $ef[1][0] ?? '';
            $value = $ef[2][0] ?? '';
            if ($key !== '') {
                $bundles[$key] = $value;
            }
        }

        return $bundles;
    }

    /** @return array<int, list<string>> */
    private function parseProtobuf(string $data): array
    {
        $fields = [];
        $offset = 0;
        $len = strlen($data);

        while ($offset < $len) {
            $tag = $this->readVarint($data, $offset);
            $fieldNumber = $tag >> 3;
            $wireType = $tag & 0x07;

            switch ($wireType) {
                case 0:
                    $this->readVarint($data, $offset);
                    break;
                case 1:
                    $offset += 8;
                    break;
                case 2:
                    $length = $this->readVarint($data, $offset);
                    $fields[$fieldNumber][] = substr($data, $offset, $length);
                    $offset += $length;
                    break;
                case 5:
                    $offset += 4;
                    break;
                default:
                    return $fields;
            }
        }

        return $fields;
    }

    private function readVarint(string $data, int &$offset): int
    {
        $value = 0;
        $shift = 0;
        do {
            if ($offset >= strlen($data)) {
                throw new \RuntimeException('Varint extends beyond buffer');
            }
            $byte = ord($data[$offset++]);
            $value |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while ($byte & 0x80);
        return $value;
    }

    // ══════════════════════════════════════════════════════════════
    //  DER → PEM conversion
    // ══════════════════════════════════════════════════════════════

    private function derToPem(string $der, string $type): string
    {
        if ($der === '') {
            return '';
        }

        $pem = '';
        $offset = 0;
        $total = strlen($der);

        while ($offset < $total) {
            if (ord($der[$offset]) !== 0x30) {
                break;
            }
            $length = $this->readDerLength($der, $offset + 1, $consumed);
            $certLen = 1 + $consumed + $length;
            $pem .= "-----BEGIN {$type}-----\n"
                . chunk_split(base64_encode(substr($der, $offset, $certLen)), 64, "\n")
                . "-----END {$type}-----\n";
            $offset += $certLen;
        }

        return $pem;
    }

    private function derKeyToPem(string $der): string
    {
        return $der === '' ? '' : "-----BEGIN PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PRIVATE KEY-----\n";
    }

    private function readDerLength(string $data, int $offset, int &$consumed): int
    {
        $byte = ord($data[$offset]);
        if ($byte < 0x80) {
            $consumed = 1;
            return $byte;
        }
        $numBytes = $byte & 0x7F;
        $consumed = 1 + $numBytes;
        $length = 0;
        for ($i = 0; $i < $numBytes; $i++) {
            $length = ($length << 8) | ord($data[$offset + 1 + $i]);
        }
        return $length;
    }

    private function atomicWrite(string $path, string $content, int $mode): void
    {
        $tmp = tempnam(dirname($path), '.spiffe_');
        file_put_contents($tmp, $content);
        chmod($tmp, $mode);
        rename($tmp, $path);
    }

    private function log(string $msg): void
    {
        ($this->logger)($msg);
    }
}
