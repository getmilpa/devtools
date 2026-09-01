<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use Milpa\DevTools\Make\PluginSurgeon;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see PluginSurgeon}: that every structural insertion lands at the one anchor it claims
 * (end of a method body, end of a literal return array, end of the class body, the declaration's
 * implements list), survives the tokenizer traps a text splice would fall into (braces inside
 * strings and comments), stays valid PHP (`php -l` on every merged result), and — the fail-closed
 * half — REFUSES with a named reason everything it cannot locate unambiguously.
 */
final class PluginSurgeonTest extends TestCase
{
    private PluginSurgeon $surgeon;

    protected function setUp(): void
    {
        $this->surgeon = new PluginSurgeon();
    }

    public function testInsertsAtTheEndOfAMethodBodyAfterItsExistingStatements(): void
    {
        $source = "<?php\n\nfinal class P\n{\n    public function boot(): void\n    {\n        \$this->ready = true;\n    }\n}\n";

        $merged = $this->surgeon->insertIntoMethod($source, 'boot', "\$this->container->registerService(\n    'x',\n    1,\n);");

        $this->assertLessThan(
            strpos($merged, 'registerService'),
            strpos($merged, '$this->ready = true;'),
            'the snippet must land AFTER the existing statements, at the end of the body',
        );
        $this->assertStringContainsString("        \$this->container->registerService(\n            'x',\n            1,\n        );", $merged);
        $this->assertPhpLints($merged);
    }

    /**
     * The control that separates token counting from text hacking: a `}` inside a string literal and
     * another inside a comment sit between the anchor and the method's real closing brace — a naive
     * splice would close the method early; the tokenizer atomizes both, so the insertion still lands
     * inside the body.
     */
    public function testBracesInsideStringsAndCommentsDoNotFoolTheAnchor(): void
    {
        $source = "<?php\n\nfinal class P\n{\n    public function boot(): void\n    {\n"
            . "        \$brace = '}';\n"
            . "        // } not a real closing brace either\n"
            . "        \$interpolated = \"{\$brace}\";\n"
            . "    }\n}\n";

        $merged = $this->surgeon->insertIntoMethod($source, 'boot', '$this->wired = true;');

        $this->assertLessThan(
            strpos($merged, '$this->wired = true;'),
            strpos($merged, 'not a real closing brace'),
            'the snippet must land after ALL the decoy braces, inside the real body',
        );
        $this->assertPhpLints($merged);
    }

    public function testInsertsIntoAOneLineMethodBody(): void
    {
        $source = "<?php\n\nfinal class P\n{\n    public function boot(): void {}\n}\n";

        $merged = $this->surgeon->insertIntoMethod($source, 'boot', '$this->wired = true;');

        $this->assertStringContainsString('$this->wired = true;', $merged);
        $this->assertPhpLints($merged);
    }

    public function testAppendsAMethodBeforeTheClassClosingBraceWithABlankSeparator(): void
    {
        $source = "<?php\n\nfinal class P\n{\n    public function install(): void\n    {\n    }\n}\n";

        $merged = $this->surgeon->appendMethod(
            $source,
            $this->surgeon->wrapMethod('public function boot(): void', '$this->wired = true;'),
        );

        $this->assertStringContainsString("    }\n\n    public function boot(): void\n    {\n        \$this->wired = true;\n    }\n}", $merged);
        $this->assertPhpLints($merged);
    }

    public function testAppendsIntoAnEmptyClassBodyWithoutALeadingBlankLine(): void
    {
        $source = "<?php\n\nfinal class P\n{\n}\n";

        $merged = $this->surgeon->appendMethod(
            $source,
            $this->surgeon->wrapMethod('public function boot(): void', '$this->wired = true;'),
        );

        $this->assertStringContainsString("{\n    public function boot(): void", $merged);
        $this->assertPhpLints($merged);
    }

    public function testInsertsIntoAReturnArrayPreservingExistingEntries(): void
    {
        $source = "<?php\n\nfinal class P\n{\n    public function routes(): array\n    {\n        return [\n            'first',\n        ];\n    }\n}\n";

        $merged = $this->surgeon->insertIntoReturnArray($source, 'routes', "'second',");

        $this->assertLessThan(strpos($merged, "'second',"), strpos($merged, "'first',"));
        $this->assertPhpLints($merged);
    }

    /** A hand-written list whose last entry lacks the trailing comma gets one before the splice. */
    public function testInsertsIntoAReturnArrayAddingTheMissingTrailingComma(): void
    {
        $source = "<?php\n\nfinal class P\n{\n    public function routes(): array\n    {\n        return [\n            'first'\n        ];\n    }\n}\n";

        $merged = $this->surgeon->insertIntoReturnArray($source, 'routes', "'second',");

        $this->assertStringContainsString("'first',", $merged);
        $this->assertStringContainsString("'second',", $merged);
        $this->assertPhpLints($merged);
    }

    public function testInsertsIntoAnEmptyAndASingleLineReturnArray(): void
    {
        $multiline = "<?php\n\nfinal class P\n{\n    public function routes(): array\n    {\n        return [\n        ];\n    }\n}\n";
        $merged = $this->surgeon->insertIntoReturnArray($multiline, 'routes', "'only',");
        $this->assertStringContainsString("'only',", $merged);
        $this->assertPhpLints($merged);

        $oneLine = "<?php\n\nfinal class P\n{\n    public function routes(): array\n    {\n        return [];\n    }\n}\n";
        $merged = $this->surgeon->insertIntoReturnArray($oneLine, 'routes', "'only',");
        $this->assertStringContainsString("'only',", $merged);
        $this->assertPhpLints($merged);
    }

    /** Fail closed: a method that builds its result some other way is refused, with the reason named. */
    public function testRefusesAMethodWithoutALiteralReturnArray(): void
    {
        $source = "<?php\n\nfinal class P\n{\n    public function routes(): array\n    {\n        \$r = [];\n\n        return \$r;\n    }\n}\n";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not return a literal array');
        $this->surgeon->insertIntoReturnArray($source, 'routes', "'x',");
    }

    public function testEnsureImplementsAddsAppendsAndStaysIdempotent(): void
    {
        $bare = "<?php\n\nfinal class P\n{\n}\n";
        $added = $this->surgeon->ensureImplements($bare, 'Milpa\\Runtime\\Http\\RouteProviderInterface');
        $this->assertStringContainsString('final class P implements \\Milpa\\Runtime\\Http\\RouteProviderInterface', $added);

        $listed = "<?php\n\ninterface A {}\n\nfinal class P implements A\n{\n}\n";
        $appended = $this->surgeon->ensureImplements($listed, 'Milpa\\Runtime\\Http\\RouteProviderInterface');
        $this->assertStringContainsString('implements A, \\Milpa\\Runtime\\Http\\RouteProviderInterface', $appended);
        $this->assertPhpLints($appended);

        $this->assertSame(
            $appended,
            $this->surgeon->ensureImplements($appended, 'Milpa\\Runtime\\Http\\RouteProviderInterface'),
            'a declaration already carrying the interface must come back byte-identical',
        );
    }

    public function testHasMethodSeesConcreteMethodsOnly(): void
    {
        $source = "<?php\n\nfinal class P\n{\n    public function boot(): void\n    {\n    }\n}\n";

        $this->assertTrue($this->surgeon->hasMethod($source, 'boot'));
        $this->assertFalse($this->surgeon->hasMethod($source, 'routes'));
        $this->assertFalse($this->surgeon->hasMethod('not php at all', 'boot'));
    }

    /** Functions nested inside a method body are not class methods — their names must never match. */
    public function testANestedFunctionInsideAMethodIsNotMistakenForAClassMethod(): void
    {
        $source = "<?php\n\nfinal class P\n{\n    public function boot(): void\n    {\n"
            . "        function routes()\n        {\n            return 1;\n        }\n    }\n}\n";

        $this->assertFalse($this->surgeon->hasMethod($source, 'routes'), 'a conditionally declared nested function is not a class method');
        $this->assertTrue($this->surgeon->hasMethod($source, 'boot'), 'while the real method around it still is');
    }

    public function testDiagnoseNamesEachRefusal(): void
    {
        $this->assertNull(
            $this->surgeon->diagnose("<?php\n\nfinal class P\n{\n    public function boot(): void\n    {\n    }\n}\n"),
            'a parseable single-class file has no refusal to name',
        );

        $this->assertSame(
            'no class declaration found',
            $this->surgeon->diagnose("<?php\n// a file with no class in it\n"),
        );

        $mangled = $this->surgeon->diagnose("<?php\nfinal class P\n{\n    public function boot(): void\n    {\n");
        $this->assertNotNull($mangled, 'a truncated file must be refused');
        $this->assertNotSame('', $mangled, 'and the refusal must carry a nameable reason');
    }

    /** `Foo::class` constants and anonymous classes reuse the `class` token — neither is a declaration. */
    public function testClassConstantAndAnonymousClassAreNotMistakenForTheDeclaration(): void
    {
        $this->assertSame(
            'no class declaration found',
            $this->surgeon->diagnose("<?php\n\n\$x = \\Some\\Thing::class;\n\$y = new class () {\n};\n"),
        );
    }

    private function assertPhpLints(string $code): void
    {
        $tmp = sys_get_temp_dir() . '/milpa-devtools-surgeon-lint-' . uniqid() . '.php';
        file_put_contents($tmp, $code);
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $exitCode);
        unlink($tmp);

        $this->assertSame(0, $exitCode, "php -l failed:\n" . implode("\n", $output) . "\n---\n" . $code);
    }
}
