<?php

declare(strict_types=1);

namespace SDPMlab\ZtEventGateway\Ingress;

final class CanonicalOrderRequest
{
    public const SCHEMA_VERSION = 1;
    public const ENVELOPE_TYPE = 'gateway.request';

    /**
     * @param array<string, mixed> $input
     * @return array{userKey: string, productList: list<array{p_key: int, amount: int}>, total: int}
     */
    public static function normalizeOrderData(array $input): array
    {
        $userKey = self::extractUserKey($input);
        $productList = self::extractProductList($input);
        $total = self::extractTotal($input);

        return [
            'userKey' => $userKey,
            'productList' => $productList,
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param bool                 $requireSpiffeIdentity When true (default) the envelope
     *        MUST carry a non-empty spiffe_id and spiffe_path. When false, both fields
     *        become optional — used when the master SPIFFE_ENABLED toggle is off so the
     *        canonical envelope can still be validated for schema/route/id/data while
     *        the zero-trust identity layer is intentionally absent.
     *
     * @return array{
     *     route: string,
     *     traceId: string,
     *     spiffeId: string,
     *     spiffePath: list<string>,
     *     eventData: array{userKey: string, productList: list<array{p_key: int, amount: int}>, total: int, traceId: string}
     * }
     */
    public static function validateEnvelope(array $payload, bool $requireSpiffeIdentity = true): array
    {
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
            'eventData' => $eventData,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function extractUserKey(array $input): string
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

        throw new \InvalidArgumentException('Missing required field: userKey.');
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
