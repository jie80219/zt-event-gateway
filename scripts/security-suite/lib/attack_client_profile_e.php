<?php

declare(strict_types=1);

ini_set('display_errors', 'stderr');

/**
 * Profile-E attack payload builder — OAuth 2.0 Bearer + Static JWT (HS256).
 *
 * Mirrors scripts/security-suite/lib/attack_client.php but produces JWT Bearer
 * tokens instead of LSVID/SPIFFE envelopes. See
 * docs/experiment-comparison-targets.md §2.6 for the comparison design.
 *
 * Usage (CLI):
 *   php attack_client_profile_e.php <case_id>
 *
 * Output (stdout JSON):
 *   {
 *     "case_id":       "F01",
 *     "category":      "token-forgery",
 *     "description":   "...",
 *     "applicability": "applicable" | "structural_gap" | "control",
 *     "bearer":        "eyJ..."  | null,   // Authorization: Bearer <token>
 *     "body":          { ...flat order body... },
 *     "expect_status": "accept" | "reject" | "accepted_violation" | "n/a",
 *     "extra":         { ...debug info... }
 *   }
 *
 * "structural_gap" marks cases that do not apply to a bearer-JWT gateway
 * (no nested chain, no mTLS, no SHM) — stage2 driver skips them on the HTTP
 * layer but still records them as "accepted_violation" when exercised via the
 * AMQP layer (Q01/Q02 and their equivalents).
 */

// Shared seed so the JWT `jti`s are stable across re-runs.
srand(20260424);

/** Read key from env — must match docker-compose.profile-e.yml / profile env file. */
function profile_e_jwt_key(): string
{
    $k = getenv('PROFILE_E_JWT_HS256_KEY') ?: '';
    if ($k === '') {
        fwrite(STDERR, "[attack-e] PROFILE_E_JWT_HS256_KEY not set\n");
        exit(3);
    }
    return $k;
}

function profile_e_jwt_iss(): string
{
    return getenv('PROFILE_E_JWT_ISSUER') ?: 'https://idp.example.test';
}

function profile_e_jwt_aud(): string
{
    return getenv('PROFILE_E_JWT_AUDIENCE') ?: 'zt-event-gateway';
}

function b64url_encode(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64url_decode(string $s): string
{
    $pad = 4 - (strlen($s) % 4);
    if ($pad < 4) {
        $s .= str_repeat('=', $pad);
    }
    return base64_decode(strtr($s, '-_', '+/'));
}

/**
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $headerOverrides
 */
function jwt_hs256(array $payload, string $key, array $headerOverrides = []): string
{
    $header = array_merge(['typ' => 'JWT', 'alg' => 'HS256'], $headerOverrides);
    $h = b64url_encode(json_encode($header));
    $p = b64url_encode(json_encode($payload));
    $sig = hash_hmac('sha256', "$h.$p", $key, true);
    return "$h.$p." . b64url_encode($sig);
}

/** Legitimate JWT claims — used as the starting point for all forgeries. */
function legit_claims(): array
{
    $now = time();
    return [
        'iss' => profile_e_jwt_iss(),
        'aud' => profile_e_jwt_aud(),
        'sub' => 'user-42',
        'iat' => $now,
        'nbf' => $now - 5,
        'exp' => $now + 900,
        'jti' => bin2hex(random_bytes(8)),
    ];
}

function legit_jwt(): string
{
    return jwt_hs256(legit_claims(), profile_e_jwt_key());
}

function default_body(): array
{
    return [
        'userKey'     => '1',
        'productList' => [['p_key' => 1, 'amount' => 1]],
        'total'       => 100,
    ];
}

function tamper_segment(string $token, int $idx, callable $transform): string
{
    $parts = explode('.', $token);
    if (!isset($parts[$idx])) {
        throw new RuntimeException("no segment {$idx}");
    }
    $decoded = b64url_decode($parts[$idx]);
    $parts[$idx] = b64url_encode($transform($decoded));
    return implode('.', $parts);
}

/**
 * @return array{bearer: ?string, applicability: string, expect_status: string, extra: array<string,mixed>}
 */
function build_profile_e_case(string $caseId): array
{
    $key = profile_e_jwt_key();

    switch ($caseId) {
        // ── token-forgery ─────────────────────────────────────────────
        case 'F01': // alg=none
            $tok = tamper_segment(legit_jwt(), 0, function (string $h): string {
                $j = json_decode($h, true);
                $j['alg'] = 'none';
                return json_encode($j);
            });
            return ['bearer' => $tok, 'applicability' => 'applicable', 'expect_status' => 'reject', 'extra' => ['mutation' => 'alg=none']];

        case 'F02': // payload tamper (sub/aud rewrite, sig untouched)
            $tok = tamper_segment(legit_jwt(), 1, function (string $p): string {
                $j = json_decode($p, true);
                $j['sub'] = 'attacker';
                $j['aud'] = 'other-service';
                return json_encode($j);
            });
            return ['bearer' => $tok, 'applicability' => 'applicable', 'expect_status' => 'reject', 'extra' => ['mutation' => 'sub/aud rewrite']];

        case 'F03': // random-byte signature
            $parts = explode('.', legit_jwt());
            $parts[2] = b64url_encode(random_bytes(32));
            return ['bearer' => implode('.', $parts), 'applicability' => 'applicable', 'expect_status' => 'reject', 'extra' => ['mutation' => 'sig=random']];

        case 'F04': // no Authorization header
            return ['bearer' => null, 'applicability' => 'applicable', 'expect_status' => 'reject', 'extra' => ['mutation' => 'no bearer header']];

        // ── trust-domain ─────────────────────────────────────────────
        case 'T01': // foreign issuer — signed with same key but iss wrong
            $c = legit_claims();
            $c['iss'] = 'https://evil.example';
            return ['bearer' => jwt_hs256($c, $key), 'applicability' => 'applicable', 'expect_status' => 'reject', 'extra' => ['mutation' => 'iss=evil']];

        case 'T02': // audience mismatch
            $c = legit_claims();
            $c['aud'] = 'unknown-service';
            return ['bearer' => jwt_hs256($c, $key), 'applicability' => 'applicable', 'expect_status' => 'reject', 'extra' => ['mutation' => 'aud=unknown']];

        case 'T03':
            // In SPIFFE world T03 == envelope.spiffe_id != L0.sub. In JWT world
            // the gateway builds the envelope itself from the JWT sub, so an
            // attacker cannot forge a mismatched envelope at the HTTP ingress.
            // Marked structural_gap on HTTP; the same attack via direct AMQP
            // (Q01/Q02 class) WILL succeed because the Profile-E worker has no
            // identity validation. Captured there instead.
            return ['bearer' => null, 'applicability' => 'structural_gap', 'expect_status' => 'n/a', 'extra' => ['reason' => 'gateway builds envelope from JWT claims; no user-supplied envelope id to conflict with']];

        // ── chain-attack ─────────────────────────────────────────────
        case 'C01': case 'C02': case 'C03':
            // JWT has no nested chain.  There is literally no "nested.aud" or
            // "enclosing.iss" to attack.  Marked structural_gap.
            return ['bearer' => null, 'applicability' => 'structural_gap', 'expect_status' => 'n/a', 'extra' => ['reason' => 'OAuth2 Bearer has no nested signing chain']];

        // ── time-attack ──────────────────────────────────────────────
        case 'E01': // expired
            $c = legit_claims();
            $c['iat'] = time() - 2000;
            $c['nbf'] = time() - 2000;
            $c['exp'] = time() - 60;
            return ['bearer' => jwt_hs256($c, $key), 'applicability' => 'applicable', 'expect_status' => 'reject', 'extra' => ['mutation' => 'exp in past']];

        case 'E02': // nbf in future (outside 10s skew)
            $c = legit_claims();
            $c['nbf'] = time() + 3600;
            return ['bearer' => jwt_hs256($c, $key), 'applicability' => 'applicable', 'expect_status' => 'reject', 'extra' => ['mutation' => 'nbf=now+3600']];

        case 'E03': // within skew — control, should be accepted
            $c = legit_claims();
            $c['nbf'] = time() + 5;
            return ['bearer' => jwt_hs256($c, $key), 'applicability' => 'control', 'expect_status' => 'accept', 'extra' => ['mutation' => 'nbf=now+5 (within skew)']];

        // ── replay ───────────────────────────────────────────────────
        case 'R01': case 'R02':
            // JWT gateway has no JTI replay cache in Profile E.  Sending the
            // same token twice will succeed twice — that's the measurable gap.
            return ['bearer' => legit_jwt(), 'applicability' => 'applicable', 'expect_status' => 'accepted_violation', 'extra' => ['mutation' => 'reuse same JWT twice', 'send_twice' => true, 'reason' => 'Profile E has no JTI cache']];

        // ── mtls ─────────────────────────────────────────────────────
        case 'M01': case 'M02': case 'M03':
            return ['bearer' => null, 'applicability' => 'structural_gap', 'expect_status' => 'n/a', 'extra' => ['reason' => 'Profile E has no mTLS to downstream']];

        // ── amqp-inject ──────────────────────────────────────────────
        case 'Q01': case 'Q02':
            // Handled by stage3 — direct AMQP publish bypasses the gateway
            // entirely, and Profile-E worker has no LSVID validation to catch
            // it. Expected outcome: accepted_violation (measurable gap).
            return ['bearer' => null, 'applicability' => 'applicable', 'expect_status' => 'accepted_violation', 'extra' => ['reason' => 'Profile E worker has no app-layer auth → direct AMQP inject bypass', 'target' => 'order_queue']];

        // ── shm-tamper ───────────────────────────────────────────────
        case 'S01': case 'S02':
            return ['bearer' => null, 'applicability' => 'structural_gap', 'expect_status' => 'n/a', 'extra' => ['reason' => 'Profile E does not use SPIFFE SHM']];

        // ── control ──────────────────────────────────────────────────
        case 'HAPPY':
            return ['bearer' => legit_jwt(), 'applicability' => 'control', 'expect_status' => 'accept', 'extra' => ['mutation' => 'legitimate baseline']];

        // ── chain-depth (D01–D05) ────────────────────────────────────
        case 'D01': case 'D02': case 'D03': case 'D04': case 'D05':
            return ['bearer' => null, 'applicability' => 'structural_gap', 'expect_status' => 'n/a', 'extra' => ['reason' => 'Profile E has no nested chain — depth cases do not apply']];

        default:
            throw new InvalidArgumentException("Unknown case_id: {$caseId}");
    }
}

// Delegate metadata to the primary attack_client.
require_once __DIR__ . '/attack_client.php';

// ── CLI entry point ────────────────────────────────────────────────────────
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? __FILE__) !== __FILE__) {
    return;
}

$caseId = $argv[1] ?? '';
if ($caseId === '' || $caseId === '--list') {
    // Reuse the primary metadata so case IDs stay aligned across profiles.
    foreach (caseMetadata() as $id => $m) {
        printf("%-6s %-16s %-22s %s\n", $id, $m['category'], $m['layer'], $m['desc']);
    }
    exit(0);
}

$meta = caseMetadata()[$caseId] ?? null;
if ($meta === null) {
    fwrite(STDERR, "Unknown case_id: {$caseId}\n");
    exit(2);
}

$built = build_profile_e_case($caseId);
$out = [
    'case_id'        => $caseId,
    'category'       => $meta['category'],
    'description'    => $meta['desc'],
    'layer_expected' => 'jwt-gateway',
    'applicability'  => $built['applicability'],
    'bearer'         => $built['bearer'],
    'body'           => default_body(),
    'expect_status'  => $built['expect_status'],
    'extra'          => $built['extra'],
];

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
