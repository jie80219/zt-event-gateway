<?php

declare(strict_types=1);

namespace Tests\Unit;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;
use SDPMlab\ZtEventGateway\HandlerScanner;

class HandlerScannerTest extends TestCase
{
    private HandlerScanner $scanner;
    private vfsStreamDirectory $root;

    protected function setUp(): void
    {
        $this->scanner = new HandlerScanner();
        $this->root = vfsStream::setup('sagas');
    }

    // ── scanEventTypesFromFile ──────────────────────────────

    public function testScanEventTypesFindsAnnotatedMethods(): void
    {
        $content = <<<'PHP'
        <?php
        namespace App\Sagas;

        use SDPMlab\AnserEDA\Attributes\EventHandler;
        use App\Events\OrderCreatedEvent;
        use App\Events\PaymentProcessedEvent;

        class TestSaga {
            #[EventHandler]
            public function onOrderCreated(OrderCreatedEvent $event) {}

            #[EventHandler]
            public function onPaymentProcessed(PaymentProcessedEvent $event) {}
        }
        PHP;

        $file = vfsStream::newFile('TestSaga.php')->at($this->root)->setContent($content);

        $types = $this->scanner->scanEventTypesFromFile($file->url());

        $this->assertCount(2, $types);
        $this->assertContains('OrderCreatedEvent', $types);
        $this->assertContains('PaymentProcessedEvent', $types);
    }

    public function testScanEventTypesReturnsEmptyForNoHandlers(): void
    {
        $content = <<<'PHP'
        <?php
        namespace App\Sagas;

        class EmptySaga {
            public function doSomething(): void {}
        }
        PHP;

        $file = vfsStream::newFile('EmptySaga.php')->at($this->root)->setContent($content);

        $types = $this->scanner->scanEventTypesFromFile($file->url());

        $this->assertSame([], $types);
    }

    public function testScanEventTypesDeduplicates(): void
    {
        $content = <<<'PHP'
        <?php
        namespace App\Sagas;

        class DupSaga {
            #[EventHandler]
            public function handler1(OrderCreatedEvent $event) {}

            #[EventHandler]
            public function handler2(OrderCreatedEvent $event) {}
        }
        PHP;

        $file = vfsStream::newFile('DupSaga.php')->at($this->root)->setContent($content);

        $types = $this->scanner->scanEventTypesFromFile($file->url());

        $this->assertCount(1, $types);
        $this->assertSame('OrderCreatedEvent', $types[0]);
    }

    public function testScanEventTypesThrowsForMissingFile(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Saga file not found');

        $this->scanner->scanEventTypesFromFile('/nonexistent/file.php');
    }

    // ── scanEventTypesFromFiles ─────────────────────────────

    public function testScanFromFilesAggregatesMultiple(): void
    {
        $file1 = vfsStream::newFile('Saga1.php')->at($this->root)->setContent(<<<'PHP'
        <?php
        class Saga1 {
            #[EventHandler]
            public function on(OrderCreatedEvent $e) {}
        }
        PHP);

        $file2 = vfsStream::newFile('Saga2.php')->at($this->root)->setContent(<<<'PHP'
        <?php
        class Saga2 {
            #[EventHandler]
            public function on(PaymentProcessedEvent $e) {}
        }
        PHP);

        $types = $this->scanner->scanEventTypesFromFiles([$file1->url(), $file2->url()]);

        $this->assertCount(2, $types);
        $this->assertContains('OrderCreatedEvent', $types);
        $this->assertContains('PaymentProcessedEvent', $types);
    }

    public function testScanFromFilesContinuesOnMissing(): void
    {
        $file = vfsStream::newFile('GoodSaga.php')->at($this->root)->setContent(<<<'PHP'
        <?php
        class GoodSaga {
            #[EventHandler]
            public function on(OrderCreatedEvent $e) {}
        }
        PHP);

        $types = $this->scanner->scanEventTypesFromFiles([
            '/nonexistent/bad.php',
            $file->url(),
        ]);

        $this->assertCount(1, $types);
        $this->assertSame('OrderCreatedEvent', $types[0]);
    }

    // ── scanEventTypesFromDirectory ─────────────────────────

    public function testScanFromDirectoryScansAllPhp(): void
    {
        // glob() does not work with vfsStream, so use a real temp directory.
        $tmpDir = sys_get_temp_dir() . '/handler-scanner-test-' . uniqid();
        mkdir($tmpDir, 0755, true);

        try {
            file_put_contents($tmpDir . '/A.php', <<<'PHP'
            <?php
            class A {
                #[EventHandler]
                public function on(EventA $e) {}
            }
            PHP);

            file_put_contents($tmpDir . '/B.php', <<<'PHP'
            <?php
            class B {
                #[EventHandler]
                public function on(EventB $e) {}
            }
            PHP);

            $types = $this->scanner->scanEventTypesFromDirectory($tmpDir);

            $this->assertCount(2, $types);
            $this->assertContains('EventA', $types);
            $this->assertContains('EventB', $types);
        } finally {
            array_map('unlink', glob($tmpDir . '/*.php'));
            rmdir($tmpDir);
        }
    }

    public function testScanFromDirectoryThrowsForMissingDir(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Directory not found');

        $this->scanner->scanEventTypesFromDirectory('/nonexistent/dir');
    }
}
