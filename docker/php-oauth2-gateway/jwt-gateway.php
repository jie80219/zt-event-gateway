<?php

declare(strict_types=1);

/**
 * Profile-E gateway — OAuth 2.0 Bearer + Static JWT (HS256).
 *
 * Non-workload-identity zero-trust baseline for the security-suite cross-
 * architecture comparison (see docs/experiment-comparison-targets.md §2.6).
 *
 * Scope (intentional):
 *   - Validates Authorization: Bearer <JWT>, HS256 with PROFILE_E_JWT_HS256_KEY.
 *   - Enforces iss / aud / exp / nbf / alg=HS256. Refuses alg=none.
 *   - Builds a canonical envelope matching CanonicalOrderRequest::SCHEMA_VERSION
 *     and publishes to the same AMQP exchange/queue as the main gateway.
 *
 * Structural gaps (by design, not bugs — measured by security-suite):
 *   - No mTLS to downstream services → M01-M03 cannot be blocked here.
 *   - No nested signing chain → C01-C03, D01-D05 cannot be blocked here.
 *   - No SVID rotation path → SHM-related cases don't apply.
 *   - No defence after the gateway boundary → Q01/Q02 (direct AMQP inject)
 *     succeed because downstream workers run with SPIFFE_ENABLED=0 in this
 *     profile.
 *
 * Not meant for production. Dev key is hardcoded below in the env var;
 * docker-compose.profile-e.yml supplies it.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$env = static function (string $key, string $default = ''): string {
    $v = getenv($key);
    return is_string($v) && $v !== '' ? $v : $default;
};

$host = $env('GATEWAY_HOST', '0.0.0.0');
$port = (int) $env('GATEWAY_PORT', '8080');
$jwtKey = $env('PROFILE_E_JWT_HS256_KEY');
$expIss = $env('PROFILE_E_JWT_ISSUER');
$expAud = $env('PROFILE_E_JWT_AUDIENCE');

if ($jwtKey === '') {
    fwrite(STDERR, "[profile-e] PROFILE_E_JWT_HS256_KEY is required\n");
    exit(1);
}

$rbHost = $env('RABBITMQ_HOST', 'rabbitmq');
$rbPort = (int) $env('RABBITMQ_PORT', '5672');
$rbUser = $env('RABBITMQ_USER', 'zt');
$rbPass = $env('RABBITMQ_PASS', 'ztpass');
$exchange = $env('REQUEST_EXCHANGE', 'events');
$routingKey = $env('REQUEST_ROUTING_KEY', 'request.new');

function b64url_decode(string $s): string
{
    $pad = 4 - (strlen($s) % 4);
    if ($pad < 4) {
        $s .= str_repeat('=', $pad);
    }
    return base64_decode(strtr($s, '-_', '+/'));
}

/**
 * @return array<string,mixed>|null  claims on success, null on rejection
 */
function validate_jwt(string $token, string $key, string $iss, string $aud, ?string &$err): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        $err = 'malformed';
        return null;
    }
    [$h64, $p64, $s64] = $parts;
    $header = json_decode(b64url_decode($h64), true);
    if (!is_array($header)) {
        $err = 'bad_header';
        return null;
    }
    // Block alg=none (F01-equivalent) and any non-HS256 downgrade attempt.
    if (($header['alg'] ?? '') !== 'HS256') {
        $err = 'alg_not_allowed';
        return null;
    }

    $expected = hash_hmac('sha256', "$h64.$p64", $key, true);
    if (!hash_equals(b64url_decode($s64), $expected)) {
        $err = 'bad_signature';
        return null;
    }

    $payload = json_decode(b64url_decode($p64), true);
    if (!is_array($payload)) {
        $err = 'bad_payload';
        return null;
    }
    $now = time();
    // 10s clock skew, same as the main LSVIDValidator.
    if (isset($payload['exp']) && $payload['exp'] < $now - 10) {
        $err = 'expired';
        return null;
    }
    if (isset($payload['nbf']) && $payload['nbf'] > $now + 10) {
        $err = 'not_yet_valid';
        return null;
    }
    if ($iss !== '' && ($payload['iss'] ?? '') !== $iss) {
        $err = 'issuer_mismatch';
        return null;
    }
    if ($aud !== '') {
        $a = $payload['aud'] ?? null;
        $matched = is_array($a) ? in_array($aud, $a, true) : ($a === $aud);
        if (!$matched) {
            $err = 'audience_mismatch';
            return null;
        }
    }
    return $payload;
}

$server = new Server($host, $port);
$server->set([
    'worker_num' => (int) $env('GATEWAY_WORKERS', '2'),
    'enable_coroutine' => true,
    'log_level' => SWOOLE_LOG_INFO,
]);

$server->on('request', function (Request $req, Response $res) use (
    $jwtKey, $expIss, $expAud, $rbHost, $rbPort, $rbUser, $rbPass, $exchange, $routingKey
) {
    $path = $req->server['request_uri'] ?? '/';
    $method = $req->server['request_method'] ?? 'GET';

    if ($path === '/api/health') {
        $res->status(200);
        $res->header('Content-Type', 'application/json');
        $res->end(json_encode(['status' => 'ok', 'mode' => 'profile-e-oauth2-bearer']));
        return;
    }

    if ($path !== '/api/orders' || $method !== 'POST') {
        $res->status(404);
        $res->end('not found');
        return;
    }

    $auth = $req->header['authorization'] ?? '';
    if (stripos($auth, 'Bearer ') !== 0) {
        $res->status(401);
        $res->header('Content-Type', 'application/json');
        $res->end(json_encode(['error' => 'missing_bearer', 'hint' => 'Authorization: Bearer <JWT>']));
        return;
    }
    $token = substr($auth, 7);

    $err = null;
    $claims = validate_jwt($token, $jwtKey, $expIss, $expAud, $err);
    if ($claims === null) {
        fwrite(STDOUT, "[profile-e] reject jwt: {$err}\n");
        $res->status(401);
        $res->header('Content-Type', 'application/json');
        $res->end(json_encode(['error' => 'invalid_jwt', 'reason' => $err]));
        return;
    }

    $raw = $req->rawContent() ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $res->status(400);
        $res->header('Content-Type', 'application/json');
        $res->end(json_encode(['error' => 'bad_body']));
        return;
    }

    $subject = $claims['sub'] ?? 'unknown';
    $envelope = [
        'schema_version' => 1,
        'type'           => 'gateway.request',
        'route'          => 'order.create',
        'id'             => 'prof-e-' . bin2hex(random_bytes(6)),
        // No SPIFFE identity in Profile E — we synthesize a pseudo-spiffe URL from
        // the JWT subject so the envelope schema stays structurally compatible,
        // but ALLOWED_SOURCES prefix checks will not recognize it.
        'spiffe_id'      => 'urn:oauth2:' . $subject,
        'spiffe_path'    => ['urn:oauth2:' . $subject],
        'data'           => $body,
        'bearer_jwt'     => $token,
        'jwt_claims'     => [
            'iss' => $claims['iss'] ?? null,
            'aud' => $claims['aud'] ?? null,
            'sub' => $subject,
            'exp' => $claims['exp'] ?? null,
            'jti' => $claims['jti'] ?? null,
        ],
    ];

    try {
        $conn = new AMQPStreamConnection($rbHost, $rbPort, $rbUser, $rbPass);
        $ch = $conn->channel();
        $ch->exchange_declare($exchange, 'direct', false, true, false);
        $msg = new AMQPMessage(
            json_encode($envelope, JSON_UNESCAPED_SLASHES),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]
        );
        $ch->basic_publish($msg, $exchange, $routingKey);
        $ch->close();
        $conn->close();
    } catch (\Throwable $e) {
        fwrite(STDERR, '[profile-e] publish failed: ' . $e->getMessage() . "\n");
        $res->status(500);
        $res->header('Content-Type', 'application/json');
        $res->end(json_encode(['error' => 'publish_failed']));
        return;
    }

    $res->status(202);
    $res->header('Content-Type', 'application/json');
    $res->end(json_encode(['status' => 'accepted', 'id' => $envelope['id']]));
});

fwrite(STDOUT, "[profile-e] OAuth2 Bearer JWT gateway listening on {$host}:{$port}\n");
fwrite(STDOUT, "[profile-e] expected iss={$expIss} aud={$expAud}\n");
$server->start();
