<?php

/**
 * This file is part of Milpa DevTools — the generate-verify-inspect developer loop of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Operations\TestDiscoveryHandler;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/**
 * Static test discovery: classes, criteria, and assertions are inspected without invoking PHPUnit.
 */
final class TestDiscoveryHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-test-discovery-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/tests/Plugins/Billing', 0o775, true);
        mkdir($this->root . '/tests/Plugins/Shipping', 0o775, true);
        file_put_contents($this->root . '/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    /** Lists test classes and supports artifact, plugin, and criterion filters without running tests. */
    public function testListsAndFiltersTestsWithoutExecutingThem(): void
    {
        $marker = $this->root . '/test-file-loaded';
        $this->writeTest(
            'Billing',
            'InvoiceTest',
            "use PHPUnit\\Framework\\Attributes\\Test;\n\n"
            . "file_put_contents(" . var_export($marker, true) . ", 'loaded');\n\n"
            . <<<'PHP'

final class InvoiceTest extends \PHPUnit\Framework\TestCase
{
    /** Rejects duplicate invoice numbers. */
    public function testRejectsDuplicateInvoiceNumbers(): void
    {
        $invoice = 'invoice-1';
        $message = "checking {$invoice}";
        self::assertFalse(false);
        $this->assertSame(expected: 'invoice-1', actual: $invoice, message: $message);
    }

    #[Test]
    public function acceptsFreshNumber(): void
    {
        $this->expectException(\RuntimeException::class);
    }

    public function helperMethod(): void
    {
        self::assertTrue(true);
    }
}
PHP,
        );
        $this->writeTest(
            'Shipping',
            'ParcelTest',
            "final class ParcelTest extends \\PHPUnit\\Framework\\TestCase\n"
            . "{\n    public function testTracksAParcel(): void { self::assertTrue(true); }\n}\n",
        );

        $all = $this->handler()->handleList([]);
        $byPlugin = $this->handler()->handleList(['plugin' => 'Billing']);
        $byArtifact = $this->handler()->handleList(['artifact' => 'Parcel']);
        $byCriterion = $this->handler()->handleList(['criterion' => 'duplicate invoice']);

        self::assertTrue($all['ok']);
        self::assertSame(['InvoiceTest', 'ParcelTest'], array_column($all['tests'], 'name'));
        self::assertFileDoesNotExist($marker, 'discovery must not execute top-level test-file code');
        self::assertSame(['InvoiceTest'], array_column($byPlugin['tests'], 'name'));
        self::assertSame(['ParcelTest'], array_column($byArtifact['tests'], 'name'));
        self::assertSame(['InvoiceTest'], array_column($byCriterion['tests'], 'name'));
    }

    /** Shows each test method's criterion and static assertion calls without invoking the method. */
    public function testShowsWhatEachMethodAsserts(): void
    {
        $this->writeTest(
            'Billing',
            'InvoiceTest',
            <<<'PHP'
use PHPUnit\Framework\Attributes\Test;

final class InvoiceTest extends \PHPUnit\Framework\TestCase
{
    /** Rejects duplicate invoice numbers. */
    public function testRejectsDuplicateInvoiceNumbers(): void
    {
        $invoice = 'invoice-1';
        $message = "checking {$invoice}";
        self::assertFalse(false);
        $this->assertSame(expected: 'invoice-1', actual: $invoice, message: $message);
    }

    #[Test]
    public function acceptsFreshNumber(): void
    {
        $this->expectException(\RuntimeException::class);
    }

    #[\PHPUnit\Framework\Attributes\Group('Test')]
    public function groupedHelper(): void
    {
        self::assertTrue(true);
    }
}
PHP,
        );

        $result = $this->handler()->handleShow(['name' => 'InvoiceTest', 'plugin' => 'Billing']);

        self::assertTrue($result['ok']);
        self::assertSame('Invoice', $result['test']['artifact']);
        self::assertSame('tests/Plugins/Billing/InvoiceTest.php', $result['test']['path']);

        $methods = [];
        foreach ($result['test']['methods'] as $method) {
            $methods[$method['name']] = $method;
        }
        self::assertSame('Rejects duplicate invoice numbers.', $methods['testRejectsDuplicateInvoiceNumbers']['criterion']);
        self::assertSame(['assertFalse', 'assertSame'], $methods['testRejectsDuplicateInvoiceNumbers']['assertions']);
        self::assertSame('accepts Fresh Number', $methods['acceptsFreshNumber']['criterion']);
        self::assertSame(['expectException'], $methods['acceptsFreshNumber']['assertions']);
        self::assertArrayNotHasKey('groupedHelper', $methods, 'an attribute argument named Test is not the #[Test] attribute');
    }

    /** Missing filters and tests return `ok:false`, preserving not-found as data. */
    public function testNotFoundIsAResultForBothOperations(): void
    {
        $empty = $this->handler()->handleList(['criterion' => 'never declared']);
        $missingName = $this->handler()->handleShow([]);
        $missingTest = $this->handler()->handleShow(['name' => 'MissingTest']);

        self::assertFalse($empty['ok']);
        self::assertSame([], $empty['tests']);
        self::assertFalse($missingName['ok']);
        self::assertStringContainsString('name', (string) $missingName['error']);
        self::assertFalse($missingTest['ok']);
        self::assertStringContainsString('MissingTest', (string) $missingTest['error']);
    }

    private function handler(): TestDiscoveryHandler
    {
        return new TestDiscoveryHandler(new RootResolver($this->root));
    }

    private function writeTest(string $plugin, string $class, string $body): void
    {
        file_put_contents(
            $this->root . '/tests/Plugins/' . $plugin . '/' . $class . '.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests\\Plugins\\{$plugin};\n\n{$body}\n",
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
