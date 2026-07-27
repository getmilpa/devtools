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

    /**
     * The alias-import gap (post-carril hardening): an anchored substring needle like `use Foo\Bar;`
     * (exact, no trailing content allowed) is evaded by `use Foo\Bar as B;` — the aliased line never
     * contains the needle's exact anchor text. A `forbiddenPatterns` regex closes it by recognizing
     * the alias-import shape itself instead of demanding one exact trailing character.
     */
    public function testForbiddenPatternCatchesAnAliasImportTheSubstringNeedleMisses(): void
    {
        file_put_contents(
            $this->root . '/core/src/Widget.php',
            "<?php\n\nuse Doctrine\\ORM as D;\n\nclass Widget {}\n",
        );

        $needleOnlyReport = (new BoundaryValidator())->validate(
            [new BoundaryRule('core stays framework-agnostic', 'core/src', ['use Doctrine\ORM;'])],
            $this->root,
        );
        $this->assertTrue(
            $needleOnlyReport->ok(),
            'control: the exact-import needle alone must NOT catch the alias-import (proves the gap is real)',
        );

        $patternReport = (new BoundaryValidator())->validate(
            [new BoundaryRule(
                'core stays framework-agnostic',
                'core/src',
                ['use Doctrine\ORM;'],
                [],
                ['~use\s+Doctrine\\\\ORM\s+as\s+~'],
            )],
            $this->root,
        );

        $this->assertFalse($patternReport->ok(), 'forbiddenPatterns must catch the alias-import the needle evades');
        $this->assertSame(1, $patternReport->totalViolations());
        $this->assertStringContainsString('Widget.php:3', $patternReport->results[0]->violations[0]);
    }

    /** BC: a rule with no forbiddenPatterns behaves exactly like before this feature existed. */
    public function testEmptyForbiddenPatternsIsIdenticalToPriorBehavior(): void
    {
        file_put_contents(
            $this->root . '/core/src/Widget.php',
            "<?php\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\nclass Widget {}\n",
        );

        $withoutPatterns = (new BoundaryValidator())->validate(
            [new BoundaryRule('core stays framework-agnostic', 'core/src', ['Doctrine\\'])],
            $this->root,
        );
        $withExplicitEmptyPatterns = (new BoundaryValidator())->validate(
            [new BoundaryRule('core stays framework-agnostic', 'core/src', ['Doctrine\\'], [], [])],
            $this->root,
        );

        $this->assertFalse($withoutPatterns->ok());
        $this->assertSame($withoutPatterns->totalViolations(), $withExplicitEmptyPatterns->totalViolations());
        $this->assertSame(
            $withoutPatterns->results[0]->violations,
            $withExplicitEmptyPatterns->results[0]->violations,
        );
    }

    /** Comments are exempt from forbiddenPatterns too, same as they are for plain needles. */
    public function testCommentLinesAreExemptFromForbiddenPatternsToo(): void
    {
        file_put_contents(
            $this->root . '/core/src/Widget.php',
            "<?php\n\n// use Doctrine\\ORM as D;\n/* use Doctrine\\ORM as D; */\n\nclass Widget {}\n",
        );

        $report = (new BoundaryValidator())->validate(
            [new BoundaryRule(
                'core stays framework-agnostic',
                'core/src',
                [],
                [],
                ['~use\s+Doctrine\\\\ORM\s+as\s+~'],
            )],
            $this->root,
        );

        $this->assertTrue($report->ok(), 'comment lines must stay exempt from forbiddenPatterns');
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
