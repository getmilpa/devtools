<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Operations\EditHandler;
use Milpa\DevTools\Operations\ImplementHandler;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Current-file patches keep bounded input and the existing code judges for large sources. */
final class CurrentEditBoundsTest extends TestCase
{
    private string $root;
    private string $file;
    private string $original;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-current-edit-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src/Plugins/Demo/Services', 0o775, true);
        $this->file = $this->root . '/src/Plugins/Demo/Services/LargeService.php';
        $this->original = "<?php\ndeclare(strict_types=1);\nnamespace App\\Plugins\\Demo\\Services;\n"
            . '/* ' . str_repeat('retained ', 1300) . " */\n"
            . "final class LargeService { public function greet(): string { return 'hello'; } }\n";
        file_put_contents($this->file, $this->original);
        file_put_contents($this->file . ImplementHandler::STAGING_SUFFIX, 'unrelated unfinished assembly');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return array<string, mixed> */
    private function edit(array $edits, ?ImplementHandler $lander = null): array
    {
        return (new EditHandler(new RootResolver($this->root), $lander))->handle([
            'plugin' => 'Demo', 'class' => 'LargeService', 'edits' => $edits,
        ]);
    }

    private function assertUnchanged(): void
    {
        self::assertSame($this->original, file_get_contents($this->file));
        self::assertSame('unrelated unfinished assembly', file_get_contents($this->file . ImplementHandler::STAGING_SUFFIX));
    }

    public function testSmallPatchCanRepairLargeCurrentFileWithoutConsumingStaging(): void
    {
        self::assertGreaterThan(ImplementHandler::MAX_INLINE_BYTES, strlen($this->original));
        $result = $this->edit([['find' => "return 'hello';", 'replace' => "return 'repaired';"]]);
        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertSame(1, $result['edits_applied']);
        self::assertSame(str_replace("return 'hello';", "return 'repaired';", $this->original), file_get_contents($this->file));
        self::assertStringContainsString('syntax, strict_types, class, namespace', $result['verified']);
        self::assertSame('unrelated unfinished assembly', file_get_contents($this->file . ImplementHandler::STAGING_SUFFIX));
    }

    public function testExactByteBudgetAllowsUtf8AndOneAdditionalByteRefuses(): void
    {
        $replacement = str_repeat('é', 4093) . 'x';
        self::assertSame(8192, strlen('hello') + strlen($replacement));
        $tooLarge = $this->edit([['find' => 'hello', 'replace' => $replacement . 'y']]);
        self::assertFalse($tooLarge['ok']);
        self::assertStringContainsString('MAX_INLINE_BYTES', $tooLarge['error']);
        $this->assertUnchanged();
        $accepted = $this->edit([['find' => 'hello', 'replace' => $replacement]]);
        self::assertTrue($accepted['ok'], $accepted['error'] ?? '');
        self::assertSame(str_replace('hello', $replacement, $this->original), file_get_contents($this->file));
    }

    public function testBudgetCountsFindAndReplaceAcrossAllPairs(): void
    {
        $result = $this->edit([
            ['find' => 'hello', 'replace' => str_repeat('a', 4089)],
            ['find' => 'LargeService', 'replace' => str_repeat('b', 4089)],
        ]);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('total find + replace', $result['error']);
        $this->assertUnchanged();
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function rejectedPairs(): iterable
    {
        yield 'empty' => [[], 'nonempty list'];
        yield 'not a list' => [['named' => ['find' => 'hello', 'replace' => 'x']], 'nonempty list'];
        yield 'non-array pair' => [['hello'], 'each edit requires'];
        yield 'empty find' => [[['find' => '', 'replace' => 'x']], 'each edit requires'];
        yield 'missing replacement' => [[['find' => 'hello']], 'each edit requires'];
        yield 'typed replacement' => [[['find' => 'hello', 'replace' => 1]], 'each edit requires'];
        yield 'missing match' => [[['find' => 'absent', 'replace' => 'x']], 'matches nothing'];
        yield 'ambiguous match' => [[['find' => 'retained', 'replace' => 'x']], 'ambiguous'];
        yield 'syntax' => [[['find' => "return 'hello';", 'replace' => 'return (;']], 'syntax'];
        yield 'strict types' => [[['find' => 'declare(strict_types=1);', 'replace' => '']], 'strict_types'];
        yield 'class identity' => [[['find' => 'class LargeService', 'replace' => 'class DifferentService']], 'does not declare'];
    }

    #[DataProvider('rejectedPairs')]
    public function testRefusalsPreserveLargeSourceAndStaging(array $edits, string $error): void
    {
        $result = $this->edit($edits);
        self::assertFalse($result['ok']);
        self::assertStringContainsString($error, $result['error']);
        $this->assertUnchanged();
    }

    public function testInlineCannotSelectTheEditPathOrBypassItsOriginalCeiling(): void
    {
        $handler = new ImplementHandler(new RootResolver($this->root));
        $result = $handler->handle(['plugin' => 'Demo', 'class' => 'LargeService', 'content' => $this->original,
            'from_edit' => true, 'skip_limit' => true]);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('MAX_INLINE_BYTES', $result['error']);
        $this->assertUnchanged();
    }

    public function testCurrentEditDoesNotAcceptRecordedSourcesOrTestOnlyTargets(): void
    {
        $handler = new ImplementHandler(new RootResolver($this->root));
        $input = ['plugin' => 'Demo', 'class' => 'LargeService', 'edits' => [['find' => 'hello', 'replace' => 'x']]];
        self::assertFalse($handler->edit($input + ['source' => ['seq' => 1]])['ok']);
        mkdir($this->root . '/tests/Plugins/Demo', 0o775, true);
        file_put_contents($this->root . '/tests/Plugins/Demo/OnlyTest.php', '<?php class OnlyTest {}');
        $input['class'] = 'OnlyTest';
        self::assertFalse($handler->edit($input)['ok']);
        $this->assertUnchanged();
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIdentifiers(): iterable
    {
        yield 'plugin' => ['plugin'];
        yield 'class' => ['class'];
    }

    #[DataProvider('invalidIdentifiers')]
    public function testTargetCannotEscapeByIdentifier(string $key): void
    {
        $input = ['plugin' => 'Demo', 'class' => 'LargeService', 'edits' => [['find' => 'hello', 'replace' => 'x']]];
        $input[$key] = '../outside';
        self::assertFalse((new ImplementHandler(new RootResolver($this->root)))->edit($input)['ok']);
        $this->assertUnchanged();
    }

    /** @return iterable<string, array{string}> */
    public static function judges(): iterable
    {
        yield 'static analysis' => ['static-analysis'];
        yield 'behavior' => ['behavior'];
    }

    #[DataProvider('judges')]
    public function testSameJudgeRejectsLargePatchAndRestoresSource(string $phase): void
    {
        $runner = $this->root . '/reject.sh';
        $report = $phase === 'behavior' ? 'Tests: 1, Assertions: 1, Failures: 1.' : json_encode([
            'totals' => ['errors' => 0, 'file_errors' => 1], 'errors' => [],
            'files' => [$this->file => ['errors' => 1, 'messages' => [[
                'message' => 'Unknown class in candidate', 'identifier' => 'class.notFound', 'line' => 5, 'ignorable' => true,
            ]]]],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($runner, "#!/bin/sh\nprintf '%s\\n' " . escapeshellarg($report) . "\nexit 1\n");
        chmod($runner, 0o755);
        if ($phase === 'behavior') {
            mkdir($this->root . '/tests/Plugins/Demo', 0o775, true);
            file_put_contents($this->root . '/tests/Plugins/Demo/LargeServiceTest.php', '<?php // judge seam');
        }
        $handler = new ImplementHandler(
            new RootResolver($this->root),
            analyzer: $phase === 'static-analysis' ? $runner : null,
            behaviorRunner: $phase === 'behavior' ? $runner : null
        );
        $result = $this->edit([['find' => 'hello', 'replace' => 'bad']], $handler);
        self::assertFalse($result['ok']);
        self::assertSame($phase, $result['diagnostic']['phase']);
        self::assertTrue($result['diagnostic']['rolled_back']);
        self::assertSame(hash('sha256', $this->original), $result['diagnostic']['restored_sha256']);
        $this->assertUnchanged();
    }
}
