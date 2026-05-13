<?php

declare(strict_types=1);

namespace AnserGateway\Filters;

use Keycloak\JwtValidatorRegistry;
use Keycloak\KeycloakTokenContext;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

/**
 * North-south ingress filter — validates the inbound Authorization Bearer JWT
 * issued by Keycloak before any controller runs.
 *
 * Layered next to {@see SpiffeLsvidFilter} (east-west / outbound) but operates
 * on the opposite direction: external HTTP clients → Gateway. SPIFFE JWT-SVID
 * cannot fill this slot because external callers (browsers, mobile, 3rd-party
 * services) cannot be SPIRE-attested.
 *
 * Behavior:
 *   - Off by default (KEYCLOAK_INGRESS_ENABLED=0). Fail-closed when on.
 *   - Skips paths in KEYCLOAK_INGRESS_SKIP_PATHS (comma-separated globs).
 *   - Validates iss + aud + sig + exp via {@see JwtValidatorRegistry}.
 *   - On success: stashes claims into {@see KeycloakTokenContext} so the
 *     controller / downstream propagation can audit the calling principal.
 */
class KeycloakIngressJwtFilter implements FilterInterface
{
    public function before(Request $request, $arguments = null)
    {
        if (!self::isEnabled()) {
            return;
        }

        $uri = $this->extractPath($request);
        if ($this->isSkipped($uri)) {
            return;
        }

        $method = strtoupper($request->method());
        if ($method === 'OPTIONS') {
            return;
        }

        $authHeader = (string) ($request->header('authorization') ?? '');
        if ($authHeader === '') {
            return $this->reject(401, 'invalid_request', 'Missing Authorization header');
        }

        if (!preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authHeader, $m)) {
            return $this->reject(401, 'invalid_token', 'Authorization header must be Bearer scheme');
        }

        $jwt = $m[1];

        $validator = JwtValidatorRegistry::tryGet();
        if ($validator === null) {
            return $this->reject(503, 'server_error', 'Keycloak ingress validator not initialised');
        }

        $audience = self::expectedAudience();
        if ($audience === '') {
            return $this->reject(503, 'server_error', 'KEYCLOAK_INGRESS_AUDIENCE is not configured');
        }

        try {
            $claims = $validator->validate($jwt, $audience);
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "[gateway-ingress] JWT rejected: %s\n",
                $e->getMessage(),
            ));
            return $this->reject(401, 'invalid_token', $e->getMessage());
        }

        $callerClientId = (string) ($claims['azp'] ?? $claims['client_id'] ?? '');
        KeycloakTokenContext::set($jwt, $callerClientId, $claims);

        return;
    }

    public function after(Request $request, Response $response, $arguments = null)
    {
        KeycloakTokenContext::clear();
        return $response;
    }

    public static function isEnabled(): bool
    {
        // Ingress enforcement defaults to follow KEYCLOAK_ENABLED — when an
        // operator turns on the Keycloak stack, ingress validation is on by
        // default (fail-closed). Explicit override:
        //   KEYCLOAK_INGRESS_ENABLED=0  → disable (opt-out)
        //   KEYCLOAK_INGRESS_ENABLED=1  → force-enable
        // Unset / empty → mirror KEYCLOAK_ENABLED.
        $explicit = getenv('KEYCLOAK_INGRESS_ENABLED');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit !== '0';
        }
        return (getenv('KEYCLOAK_ENABLED') ?: '0') !== '0';
    }

    public static function expectedAudience(): string
    {
        $aud = getenv('KEYCLOAK_INGRESS_AUDIENCE');
        if (is_string($aud) && $aud !== '') {
            return $aud;
        }
        $clientId = getenv('KEYCLOAK_CLIENT_ID');
        return is_string($clientId) ? $clientId : '';
    }

    private function extractPath(Request $request): string
    {
        $uri = (string) $request->uri();
        $qPos = strpos($uri, '?');
        return $qPos === false ? $uri : substr($uri, 0, $qPos);
    }

    private function isSkipped(string $uri): bool
    {
        $raw = getenv('KEYCLOAK_INGRESS_SKIP_PATHS');
        $patterns = is_string($raw) && $raw !== ''
            ? array_filter(array_map('trim', explode(',', $raw)))
            : ['/api/health', '/'];

        $uriLower = strtolower($uri);
        foreach ($patterns as $pattern) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
            if (preg_match($regex, $uriLower) === 1) {
                return true;
            }
        }
        return false;
    }

    private function reject(int $status, string $error, string $description): Response
    {
        $body = json_encode([
            'status'            => $status,
            'error'             => $error,
            'error_description' => $description,
        ], JSON_UNESCAPED_UNICODE);

        return (new Response())
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('WWW-Authenticate', sprintf('Bearer error="%s"', $error))
            ->withBody($body === false ? '{}' : $body);
    }
}
