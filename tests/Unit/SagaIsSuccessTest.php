<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\Saga;

/**
 * Pins down the {@see Saga::isSuccess()} truth-table.
 *
 * The current implementation only returns true when the meaning-data array
 * carries a `code` key whose stringified value equals exactly `'200'`.
 * Anything else — int 200, 201, 204, missing key, response body without
 * code — is considered a failure that triggers compensation.
 *
 * If those rules ever loosen (e.g. accept 2xx generally), these assertions
 * should fail and force a deliberate review.
 */
final class SagaIsSuccessTest extends TestCase
{
    private \ReflectionMethod $isSuccess;
    private Saga $saga;

    protected function setUp(): void
    {
        $eventBus = $this->createMock(EventBus::class);
        $this->saga = new class($eventBus) extends Saga {};
        $this->isSuccess = new \ReflectionMethod($this->saga, 'isSuccess');
        $this->isSuccess->setAccessible(true);
    }

    private function check(array $info): bool
    {
        return (bool) $this->isSuccess->invoke($this->saga, $info);
    }

    public function testStringTwoHundredIsSuccess(): void
    {
        $this->assertTrue($this->check(['code' => '200']));
    }

    public function testIntTwoHundredIsSuccess(): void
    {
        // Cast to string '200' inside isSuccess(); int 200 still matches.
        $this->assertTrue($this->check(['code' => 200]));
    }

    public function testCreatedIsCurrentlyTreatedAsFailure(): void
    {
        $this->assertFalse(
            $this->check(['code' => 201]),
            'Pinning current behaviour: HTTP 201 Created is not accepted by Saga::isSuccess.',
        );
    }

    public function testAcceptedIsCurrentlyTreatedAsFailure(): void
    {
        $this->assertFalse(
            $this->check(['code' => 202]),
            'Pinning current behaviour: HTTP 202 Accepted is not accepted by Saga::isSuccess.',
        );
    }

    public function testNoContentIsCurrentlyTreatedAsFailure(): void
    {
        $this->assertFalse(
            $this->check(['code' => 204]),
            'Pinning current behaviour: HTTP 204 No Content is not accepted by Saga::isSuccess.',
        );
    }

    public function testRedirectIsFailure(): void
    {
        $this->assertFalse($this->check(['code' => 302]));
    }

    public function testClientErrorIsFailure(): void
    {
        $this->assertFalse($this->check(['code' => 400]));
        $this->assertFalse($this->check(['code' => 404]));
    }

    public function testServerErrorIsFailure(): void
    {
        $this->assertFalse($this->check(['code' => 500]));
        $this->assertFalse($this->check(['code' => 503]));
    }

    public function testMissingCodeIsFailure(): void
    {
        $this->assertFalse($this->check([]));
        $this->assertFalse($this->check(['msg' => 'ok', 'data' => []]));
    }

    public function testNullCodeIsFailure(): void
    {
        $this->assertFalse($this->check(['code' => null]));
    }

    public function testStringWithSurroundingWhitespaceIsFailure(): void
    {
        // strict equality after string-cast: ' 200' !== '200'
        $this->assertFalse($this->check(['code' => ' 200']));
        $this->assertFalse($this->check(['code' => '200 ']));
    }
}
