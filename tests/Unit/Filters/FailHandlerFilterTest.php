<?php

declare(strict_types=1);

namespace Tests\Unit\Filters;

use Filters\FailHandlerFilter;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SDPMlab\Anser\Exception\ActionException;
use SDPMlab\Anser\Service\ActionInterface;

/**
 * Pins the truth-table of {@see FailHandlerFilter} so future fixes that
 * disambiguate timeout / DNS / refused / 5xx are caught as deliberate
 * regressions instead of silent behaviour drift.
 *
 * Today the filter collapses every server-side and connection-level
 * failure into `code => 500`, which is the source of the saga's inability
 * to decide whether a step is retryable.
 */
final class FailHandlerFilterTest extends TestCase
{
    /** @var array<string, int|false> file -> length-before-test (false = absent) */
    private static array $logFileBaseline = [];

    private const LOG_FILES = [
        'actionClientErrorlog.txt',
        'actionServerErrorlog.txt',
        'actionConnectErrorlog.txt',
    ];

    public static function setUpBeforeClass(): void
    {
        if (!defined('LOG_PATH')) {
            // Test runs in isolation (e.g. PHPUnit picks this file first):
            // direct LOG_PATH at a writable temp directory so the filter's
            // file_put_contents() does not error out.
            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zt-fail-filter-test' . DIRECTORY_SEPARATOR;
            if (!is_dir($tmp)) {
                mkdir($tmp, 0o775, true);
            }
            define('LOG_PATH', $tmp);
        }

        // Watermark each log file so tearDownAfterClass can roll back any
        // entries the filter writes during this test class. Without this,
        // running the suite leaves stray entries in the project Logs/ dir.
        foreach (self::LOG_FILES as $name) {
            $path = LOG_PATH . $name;
            self::$logFileBaseline[$path] = file_exists($path) ? filesize($path) : false;
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$logFileBaseline as $path => $baseline) {
            if ($baseline === false) {
                if (file_exists($path)) {
                    @unlink($path);
                }
            } else {
                // Truncate back to the original length so any unrelated
                // production lines are preserved.
                $fp = @fopen($path, 'rb+');
                if ($fp !== false) {
                    @ftruncate($fp, (int) $baseline);
                    fclose($fp);
                }
            }
        }
        self::$logFileBaseline = [];
    }

    /**
     * Capture the closure that {@see FailHandlerFilter::beforeCallService()}
     * registers on the action. We can then invoke it with synthetic
     * exceptions to inspect the resulting meaning-data.
     *
     * @return array{0: \Closure, 1: ActionInterface}
     */
    private function captureFailHandler(): array
    {
        $captured = null;
        $action = $this->createMock(ActionInterface::class);
        $action->method('failHandler')
            ->willReturnCallback(function (\Closure $cb) use (&$captured, $action) {
                $captured = $cb;
                return $action;
            });

        (new FailHandlerFilter())->beforeCallService($action);

        $this->assertInstanceOf(\Closure::class, $captured);
        return [$captured, $action];
    }

    private function makeResponse(int $status, string $body = ''): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);
        return $response;
    }

    /**
     * The filter calls back into the action via $e->getAction(); we wire
     * the action onto the exception via reflection because ActionException's
     * protected property is normally only set through its named constructors.
     */
    private function bindActionToException(ActionException $e, ActionInterface $action): void
    {
        $ref = new \ReflectionProperty($e, 'action');
        $ref->setAccessible(true);
        $ref->setValue($e, $action);
    }

    // ── Client error branch ────────────────────────────────────

    public function testClientError_setsCodeFromResponseStatus(): void
    {
        [$handler, $action] = $this->captureFailHandler();

        $captured = null;
        $action->method('setMeaningData')->willReturnCallback(function ($data) use (&$captured, $action) {
            $captured = $data;
            return $action;
        });
        $response = $this->makeResponse(404, json_encode(['error' => 'order not found']));
        $action->method('getResponse')->willReturn($response);

        $exception = new ActionException('msg', $response, null, null, false);
        $this->bindActionToException($exception, $action);

        $handler($exception);

        $this->assertSame(404, $captured['code']);
        $this->assertSame('order not found', $captured['msg']);
        $this->assertArrayHasKey('requestRawBody', $captured);
    }

    public function testClientError_unknownErrorBodyFallsBackToString(): void
    {
        [$handler, $action] = $this->captureFailHandler();

        $captured = null;
        $action->method('setMeaningData')->willReturnCallback(function ($data) use (&$captured, $action) {
            $captured = $data;
            return $action;
        });
        $response = $this->makeResponse(403, '{}');
        $action->method('getResponse')->willReturn($response);

        $exception = new ActionException('forbidden', $response, null, null, false);
        $this->bindActionToException($exception, $action);

        $handler($exception);

        $this->assertSame(403, $captured['code']);
        $this->assertSame('unknow error', $captured['msg']);
    }

    // ── Server error branch ────────────────────────────────────

    public function testServerError_alwaysFlattensTo500(): void
    {
        // Pinning the conflation: even a 503 / 504 collapses to 500 in the
        // meaning-data, so the saga cannot tell a transient overload from a
        // hard error.
        foreach ([500, 502, 503, 504] as $status) {
            [$handler, $action] = $this->captureFailHandler();

            $captured = null;
            $action->method('setMeaningData')->willReturnCallback(function ($data) use (&$captured, $action) {
                $captured = $data;
                return $action;
            });
            $response = $this->makeResponse($status, '{"error":"x"}');
            $action->method('getResponse')->willReturn($response);

            $exception = new ActionException('srv', $response, null, null, false);
            $this->bindActionToException($exception, $action);

            $handler($exception);

            $this->assertSame(
                500,
                $captured['code'],
                "HTTP {$status} should currently map to code=500 (conflation pin)",
            );
            $this->assertSame('server error', $captured['msg']);
        }
    }

    // ── Connect error branch ───────────────────────────────────

    public function testConnectError_alsoMappedTo500(): void
    {
        // This is the headline limitation: connect errors and server errors
        // are indistinguishable downstream of this filter. If/when the filter
        // grows distinct codes (e.g. 599 for connect), update this test
        // intentionally.
        [$handler, $action] = $this->captureFailHandler();

        $captured = null;
        $action->method('setMeaningData')->willReturnCallback(function ($data) use (&$captured, $action) {
            $captured = $data;
            return $action;
        });

        $exception = new ActionException(
            'Connection refused: order-service:8082',
            response: null,
            request: null,
            action: null,
            isConnectError: true,
        );
        $this->bindActionToException($exception, $action);

        $handler($exception);

        $this->assertSame(500, $captured['code'], 'Connect error currently collapses to 500.');
        $this->assertSame('Connection refused: order-service:8082', $captured['msg']);
        $this->assertArrayNotHasKey(
            'requestRawBody',
            $captured,
            'Connect branch has no response body — `requestRawBody` must not be present.',
        );
    }

    public function testConnectError_isClientErrorAndIsServerErrorBothFalse(): void
    {
        // Sanity: ensure the branch ordering in FailHandlerFilter actually
        // reaches the connect handler (i.e. ActionException without response
        // returns false from isClientError/isServerError).
        $exception = new ActionException(
            'timeout',
            response: null,
            request: null,
            action: null,
            isConnectError: true,
        );

        $this->assertFalse($exception->isClientError());
        $this->assertFalse($exception->isServerError());
        $this->assertTrue($exception->isConnectError());
    }
}
