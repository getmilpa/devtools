<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Validators;

use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Validators\BoundaryRule;
use Milpa\DevTools\Validators\BoundaryValidator;

final class BoundaryValidatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-boundary-' . uniqid();
        mkdir($this->root . '/core/src', 0o775, true);
        mkdir($this->root . '/core/src/Tests', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCleanTreeHasNoViolations(): void
    {
        file_put_contents($this->root . '/core/src/Widget.php', "<?php\n\nclass Widget {}\n");

        $report = (new BoundaryValidator())->validate(
            [new BoundaryRule('core stays framework-agnostic', 'core/src', ['Doctrine\\'])],
            $this->root,
        );

        $this->assertTrue($report->ok());
        $this->assertSame(0, $report->totalViolations());
    }

    public function testForbiddenNamespaceReferenceIsCaughtWithFileAndLine(): void
    {
        file_put_contents(
            $this->root . '/core/src/Widget.php',
            "<?php\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\nclass Widget {}\n",
        );

        $report = (new BoundaryValidator())->validate(
            [new BoundaryRule('core stays framework-agnostic', 'core/src', ['Doctrine\\'])],
            $this->root,
        );

        $this->assertFalse($report->ok());
        $this->assertSame(1, $report->totalViolations());
        $this->assertStringContainsString('Widget.php:3', $report->results[0]->violations[0]);
    }

    public function testCommentLinesAndTestsSubdirectoryAreExempt(): void
    {
        file_put_contents(
            $this->root . '/core/src/Widget.php',
            "<?php\n\n// see Doctrine\\ORM\\Mapping for inspiration\n/* Doctrine\\ORM too */\n\nclass Widget {}\n",
        );
        file_put_contents(
            $this->root . '/core/src/Tests/WidgetTest.php',
            "<?php\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\nclass WidgetTest {}\n",
        );

        $report = (new BoundaryValidator())->validate(
            [new BoundaryRule('core stays framework-agnostic', 'core/src', ['Doctrine\\'])],
            $this->root,
        );

        $this->assertTrue($report->ok());
    }

    public function testMissingDirectoryIsSkippedNotFailed(): void
    {
        $report = (new BoundaryValidator())->validate(
            [new BoundaryRule('nonexistent rule', 'no/such/dir', ['Doctrine\\'])],
            $this->root,
        );

        $this->assertTrue($report->ok());
        $this->assertTrue($report->results[0]->skipped);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
