<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress;

use PHPUnit\Framework\TestCase;
use SDPMlab\ZtEventGateway\Ingress\CanonicalOrderRequest;

final class CanonicalOrderRequestTest extends TestCase
{
    public function testNormalizeOrderDataAcceptsAliases(): void
    {
        $normalized = CanonicalOrderRequest::normalizeOrderData([
            'user_id' => 7,
            'product_list' => [
                ['p_key' => '1', 'amount' => '2'],
            ],
            'amount' => '120',
        ]);

        $this->assertSame('7', $normalized['userKey']);
        $this->assertSame([['p_key' => 1, 'amount' => 2]], $normalized['productList']);
        $this->assertSame(120, $normalized['total']);
    }

    public function testValidateEnvelopeRejectsMissingSchemaVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CanonicalOrderRequest::validateEnvelope([
            'type' => CanonicalOrderRequest::ENVELOPE_TYPE,
            'route' => 'OrderCreateRequestedEvent',
            'id' => 'trace-id',
            'spiffe_id' => 'spiffe://zt.local/php-gateway',
            'spiffe_path' => ['spiffe://zt.local/php-gateway'],
            'data' => [
                'userKey' => '1',
                'productList' => [['p_key' => 1, 'amount' => 1]],
            ],
        ]);
    }

    public function testValidateEnvelopeAddsTraceIdToEventData(): void
    {
        $validated = CanonicalOrderRequest::validateEnvelope([
            'schema_version' => 1,
            'type' => CanonicalOrderRequest::ENVELOPE_TYPE,
            'route' => 'OrderCreateRequestedEvent',
            'id' => 'trace-id',
            'spiffe_id' => 'spiffe://zt.local/php-gateway',
            'spiffe_path' => ['spiffe://zt.local/php-gateway'],
            'data' => [
                'userKey' => '1',
                'productList' => [['p_key' => 1, 'amount' => 1]],
                'total' => 0,
            ],
        ]);

        $this->assertSame('trace-id', $validated['eventData']['traceId']);
    }

    public function testValidateEnvelopeAcceptsMissingSpiffeIdentityWhenNotRequired(): void
    {
        // SPIFFE_ENABLED=0 path: the envelope is still checked for
        // schema/type/route/id/data, but spiffe_id and spiffe_path are
        // allowed to be empty so the gateway can emit a canonical envelope
        // without a SPIFFE identity layer.
        $validated = CanonicalOrderRequest::validateEnvelope([
            'schema_version' => 1,
            'type' => CanonicalOrderRequest::ENVELOPE_TYPE,
            'route' => 'OrderCreateRequestedEvent',
            'id' => 'trace-id',
            'spiffe_id' => '',
            'spiffe_path' => [],
            'data' => [
                'userKey' => '1',
                'productList' => [['p_key' => 1, 'amount' => 1]],
                'total' => 0,
            ],
        ], requireSpiffeIdentity: false);

        $this->assertSame('', $validated['spiffeId']);
        $this->assertSame([], $validated['spiffePath']);
        $this->assertSame('trace-id', $validated['eventData']['traceId']);
    }

    public function testValidateEnvelopeWithoutSpiffeFieldsStillChecksStructure(): void
    {
        // Even with requireSpiffeIdentity=false, a bad schema_version must
        // still throw — only the identity fields are relaxed.
        $this->expectException(\InvalidArgumentException::class);

        CanonicalOrderRequest::validateEnvelope([
            'type' => CanonicalOrderRequest::ENVELOPE_TYPE,
            'route' => 'OrderCreateRequestedEvent',
            'id' => 'trace-id',
            'data' => [
                'userKey' => '1',
                'productList' => [['p_key' => 1, 'amount' => 1]],
            ],
        ], requireSpiffeIdentity: false);
    }

    public function testNormalizeOrderDataRejectsMissingUserKeyByDefault(): void
    {
        // Default behavior (KEYCLOAK_INGRESS_ENABLED=0 or off) — body must
        // still carry a userKey.
        $this->expectException(\InvalidArgumentException::class);

        CanonicalOrderRequest::normalizeOrderData([
            'productList' => [['p_key' => 1, 'amount' => 1]],
            'total' => 100,
        ]);
    }

    public function testNormalizeOrderDataAllowsMissingUserKeyWhenIngressInjected(): void
    {
        // KEYCLOAK_INGRESS_ENABLED=1 path — controller will inject userKey
        // from KeycloakTokenContext.sub, so the body need not carry one.
        $normalized = CanonicalOrderRequest::normalizeOrderData([
            'productList' => [['p_key' => 1, 'amount' => 1]],
            'total' => 100,
        ], requireUserKey: false);

        $this->assertSame('', $normalized['userKey']);
        $this->assertSame([['p_key' => 1, 'amount' => 1]], $normalized['productList']);
        $this->assertSame(100, $normalized['total']);
    }

    public function testNormalizeOrderDataAcceptsKeycloakSubAsUserKey(): void
    {
        // When the controller injects a Keycloak `sub` UUID into the body
        // before normaliser runs, the normaliser should preserve it as-is
        // (no int coercion) so downstream Saga + downstream services receive
        // the verified identity untouched.
        $normalized = CanonicalOrderRequest::normalizeOrderData([
            'userKey' => '11111111-1111-1111-1111-111111111111',
            'productList' => [['p_key' => 1, 'amount' => 1]],
            'total' => 100,
        ]);

        $this->assertSame('11111111-1111-1111-1111-111111111111', $normalized['userKey']);
    }
}
