<?php

declare(strict_types=1);

namespace Keycloak;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

/**
 * Thin HTTP client wrapping Keycloak's token and JWKS endpoints.
 *
 * Only the Client Credentials grant is supported (service-to-service).
 */
final class KeycloakClient
{
    private Client $http;

    public function __construct(
        private readonly string $tokenUri,
        private readonly string $jwksUri,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly int $timeoutSeconds = 10,
    ) {
        $config = [
            RequestOptions::TIMEOUT => $timeoutSeconds,
            RequestOptions::CONNECT_TIMEOUT => $timeoutSeconds,
            RequestOptions::HTTP_ERRORS => true,
        ];
        // Run 4: keep the Keycloak TCP connection warm across token/JWKS
        // refreshes (the watcher refreshes on a 1s loop). Zero-security-loss:
        // token contents and TLS verification of the Keycloak endpoint are
        // unchanged; only the socket is reused. Disable with
        // KEYCLOAK_HTTP_KEEPALIVE=0.
        if (getenv('KEYCLOAK_HTTP_KEEPALIVE') !== '0') {
            $config[RequestOptions::HEADERS] = ['Connection' => 'keep-alive'];
            $config['curl'] = [
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_TCP_KEEPIDLE  => 30,
                CURLOPT_TCP_KEEPINTVL => 15,
            ];
        }
        $this->http = new Client($config);
    }

    /**
     * @return array{access_token:string, token_type:string, expires_in:int, expires_at:int}
     */
    public function fetchClientCredentialsToken(): array
    {
        $res = $this->http->post($this->tokenUri, [
            RequestOptions::FORM_PARAMS => [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
        ]);

        $body = json_decode((string) $res->getBody(), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($body) || !isset($body['access_token'], $body['expires_in'])) {
            throw new \RuntimeException('Keycloak token endpoint returned malformed response');
        }

        $expiresIn = (int) $body['expires_in'];
        return [
            'access_token' => (string) $body['access_token'],
            'token_type'   => (string) ($body['token_type'] ?? 'Bearer'),
            'expires_in'   => $expiresIn,
            'expires_at'   => time() + $expiresIn,
        ];
    }

    /**
     * Fetch the realm JWKS. Returns the raw JSON document (string) so the
     * shared-memory writer can atomically publish it without re-encoding.
     */
    public function fetchJwks(): string
    {
        $res = $this->http->get($this->jwksUri);
        return (string) $res->getBody();
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }
}
