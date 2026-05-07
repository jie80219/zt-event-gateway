<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\Ingress;

final class CanonicalOrderRequest
{
    public const SCHEMA_VERSION = 1;
    public const ENVELOPE_TYPE = 'gateway.request';

    /**
     * @param array<string, mixed> $input
     * @param bool $requireUserKey When true, the body must carry userKey/user_id/etc.
     *     Set false when the controller plans to inject userKey from a verified
     *     identity source (e.g. Keycloak ingress JWT `sub`).
     * @return array{userKey: string, productList: list<array{p_key: int, amount: int}>, total: int}
     */
    public static function normalizeOrderData(array $input, bool $requireUserKey = true): array
    {
        $userKey = self::extractUserKey($input, $requireUserKey);
        $productList = self::extractProductList($input);
        $total = self::extractTotal($input);

        return [
            'userKey' => $userKey,
            'productList' => $productList,
            'total' => $total,
        ];
    }

    /**
     * Validate the canonical order request envelope.
     *
     * Supports two independent identity layers carried side-by-side on the
     * same envelope schema (v1):
     *   - SPIFFE: `spiffe_id` + `spiffe_path` + optional top-level `lsvid`
     *   - Keycloak: `authorization.{jwt,client_id}` + `token_path`
     *
     * Each layer is gated by its own require flag — neither, either, or
     * both may be required depending on which auth stacks are active. When
     * a layer is not required, its fields become optional but still get
     * normalized into the return shape (empty string / empty array) so
     * downstream consumers can treat them uniformly.
     *
     * @param array<string, mixed> $payload
     * @param bool                 $requireSpiffeIdentity When true (default) the envelope
     *        MUST carry a non-empty spiffe_id and spiffe_path.
     * @param bool                 $requireAuthorization  When true the envelope MUST carry
     *        a non-empty authorization.jwt and authorization.client_id (Keycloak).
     *
     * @return array{
     *     route: string,
     *     traceId: string,
     *     spiffeId: string,
     *     spiffePath: list<string>,
     *     jwt: string,
     *     clientId: string,
     *     tokenPath: list<string>,
     *     eventData: array{userKey: string, productList: list<array{p_key: int, amount: int}>, total: int, traceId: string}
     * }
     */
    public static function validateEnvelope(
        array $payload,
        bool $requireSpiffeIdentity = true,
        bool $requireAuthorization = false,
    ): array {
        $schemaVersion = $payload['schema_version'] ?? null;
        if (!is_int($schemaVersion)) {
            if (!is_string($schemaVersion) || !ctype_digit($schemaVersion)) {
                throw new \InvalidArgumentException('Missing or invalid schema_version.');
            }
            $schemaVersion = (int) $schemaVersion;
        }
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported schema_version: %s',
                (string) ($payload['schema_version'] ?? 'null')
            ));
        }

        $type = $payload['type'] ?? null;
        if (!is_string($type) || $type !== self::ENVELOPE_TYPE) {
            throw new \InvalidArgumentException('Invalid envelope type.');
        }

        $route = $payload['route'] ?? null;
        if (!is_string($route) || $route === '') {
            throw new \InvalidArgumentException('Missing request route.');
        }

        $traceId = $payload['id'] ?? null;
        if (!is_string($traceId) || $traceId === '') {
            throw new \InvalidArgumentException('Missing request id.');
        }

        // ── SPIFFE identity ─────────────────────────────────────
        $rawSpiffeId = $payload['spiffe_id'] ?? null;
        if ($requireSpiffeIdentity) {
            if (!is_string($rawSpiffeId) || $rawSpiffeId === '') {
                throw new \InvalidArgumentException('Missing source SPIFFE identity.');
            }
            $spiffeId = $rawSpiffeId;
        } else {
            $spiffeId = is_string($rawSpiffeId) ? $rawSpiffeId : '';
        }

        $spiffePath = $payload['spiffe_path'] ?? null;
        $normalizedPath = [];
        if ($requireSpiffeIdentity) {
            if (!is_array($spiffePath) || $spiffePath === []) {
                throw new \InvalidArgumentException('Missing SPIFFE path.');
            }
            foreach ($spiffePath as $segment) {
                if (!is_string($segment) || $segment === '') {
                    throw new \InvalidArgumentException('Invalid SPIFFE path segment.');
                }
                $normalizedPath[] = $segment;
            }
        } elseif (is_array($spiffePath)) {
            foreach ($spiffePath as $segment) {
                if (!is_string($segment) || $segment === '') {
                    throw new \InvalidArgumentException('Invalid SPIFFE path segment.');
                }
                $normalizedPath[] = $segment;
            }
        }

        // ── Keycloak authorization ──────────────────────────────
        $authorization = $payload['authorization'] ?? null;
        $jwt = '';
        $clientId = '';
        if ($requireAuthorization) {
            if (!is_array($authorization)) {
                throw new \InvalidArgumentException('Missing authorization block.');
            }
            $jwtRaw = $authorization['jwt'] ?? null;
            if (!is_string($jwtRaw) || $jwtRaw === '') {
                throw new \InvalidArgumentException('Missing authorization.jwt.');
            }
            $jwt = $jwtRaw;
            $clientIdRaw = $authorization['client_id'] ?? null;
            if (!is_string($clientIdRaw) || $clientIdRaw === '') {
                throw new \InvalidArgumentException('Missing authorization.client_id.');
            }
            $clientId = $clientIdRaw;
        } elseif (is_array($authorization)) {
            // Optional: accept what's present but don't fail if missing/malformed.
            if (is_string($authorization['jwt'] ?? null)) {
                $jwt = (string) $authorization['jwt'];
            }
            if (is_string($authorization['client_id'] ?? null)) {
                $clientId = (string) $authorization['client_id'];
            }
        }

        $tokenPath = $payload['token_path'] ?? null;
        $normalizedTokenPath = [];
        if (is_array($tokenPath)) {
            foreach ($tokenPath as $segment) {
                if (!is_string($segment) || $segment === '') {
                    throw new \InvalidArgumentException('Invalid token_path segment.');
                }
                $normalizedTokenPath[] = $segment;
            }
        }

        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid request data.');
        }

        $eventData = self::normalizeOrderData($data);
        $eventData['traceId'] = $traceId;

        return [
            'route' => $route,
            'traceId' => $traceId,
            'spiffeId' => $spiffeId,
            'spiffePath' => $normalizedPath,
            'jwt' => $jwt,
            'clientId' => $clientId,
            'tokenPath' => $normalizedTokenPath,
            'eventData' => $eventData,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function extractUserKey(array $input, bool $required = true): string
    {
        foreach (['userKey', 'user_id', 'customerId', 'customer_id'] as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_int($value)) {
                return (string) $value;
            }
        }

        if ($required) {
            throw new \InvalidArgumentException('Missing required field: userKey.');
        }
        return '';
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array{p_key: int, amount: int}>
     */
    private static function extractProductList(array $input): array
    {
        $rawList = $input['productList'] ?? $input['product_list'] ?? null;
        if (!is_array($rawList) || $rawList === []) {
            throw new \InvalidArgumentException('Missing required field: productList.');
        }

        $productList = [];
        foreach ($rawList as $index => $rawProduct) {
            if (!is_array($rawProduct)) {
                throw new \InvalidArgumentException(sprintf('Invalid productList item at index %d.', $index));
            }

            $pKey = self::extractPositiveInt($rawProduct, ['p_key', 'productId', 'product_id'], "productList[{$index}].p_key");
            $amount = self::extractPositiveInt($rawProduct, ['amount', 'qty', 'quantity'], "productList[{$index}].amount");

            $productList[] = [
                'p_key' => $pKey,
                'amount' => $amount,
            ];
        }

        return $productList;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function extractTotal(array $input): int
    {
        if (!array_key_exists('total', $input) && !array_key_exists('amount', $input)) {
            return 0;
        }

        return self::extractNonNegativeInt($input, ['total', 'amount'], 'total');
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $keys
     */
    private static function extractPositiveInt(array $input, array $keys, string $fieldName): int
    {
        $value = self::extractIntLike($input, $keys, $fieldName);
        if ($value <= 0) {
            throw new \InvalidArgumentException(sprintf('Field %s must be > 0.', $fieldName));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $keys
     */
    private static function extractNonNegativeInt(array $input, array $keys, string $fieldName): int
    {
        $value = self::extractIntLike($input, $keys, $fieldName);
        if ($value < 0) {
            throw new \InvalidArgumentException(sprintf('Field %s must be >= 0.', $fieldName));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $keys
     */
    private static function extractIntLike(array $input, array $keys, string $fieldName): int
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value) && $value !== '' && preg_match('/^-?\d+$/', $value) === 1) {
                return (int) $value;
            }
        }

        throw new \InvalidArgumentException(sprintf('Missing required field: %s.', $fieldName));
    }
}
