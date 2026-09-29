<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Operations\TestBaselineHandler;
use Milpa\DevTools\Support\ProcessRunner;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/**
 * The baseline/delta pair, driven through a fake process runner that writes a canned JUnit report to
 * the path the handler asked PHPUnit to log to — so the behavior is exercised without running PHPUnit
 * inside PHPUnit.
 */
final class TestBaselineHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-testdelta-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/vendor/bin', 0777, true);
        // The handler only checks the binary exists; the fake runner stands in for what it does.
        file_put_contents($this->root . '/vendor/bin/phpunit', "#!/bin/sh\n");
    }

    protected function tearDown(): void
    {
        foreach ([$this->root, $this->root . '-baselines'] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($dir);
        }
    }

    /** Where this house's baseline of the given name lives: outside the house (decisions/0523). */
    private function baselineFile(string $name = 'baseline'): string
    {
        return $this->root . '-baselines/' . substr(hash('sha256', (string) realpath($this->root)), 0, 16) . '/' . $name . '.json';
    }

    /** Write a baseline file by hand, as a previous run (or a corrupted one) would have left it. */
    private function writeBaseline(string $contents): void
    {
        $file = $this->baselineFile();
        mkdir(\dirname($file), 0777, true);
        file_put_contents($file, $contents);
    }

    /**
     * Every path under the house, so a test can assert the run left nothing in it.
     *
     * @return list<string>
     */
    private function houseTree(): array
    {
        $paths = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $paths[] = substr($f->getPathname(), \strlen($this->root));
        }
        sort($paths);

        return $paths;
    }

    /** A runner that writes the given JUnit XML to the --log-junit path and returns the given exit. */
    private function runnerWriting(string $junit, int $exit = 0): ProcessRunner
    {
        return new class ($junit, $exit) extends ProcessRunner {
            public function __construct(private string $junit, private int $exit)
            {
            }

            public function run(array $command, string $cwd, int $timeoutSeconds): array
            {
                $i = array_search('--log-junit', $command, true);
                if ($i !== false && isset($command[$i + 1])) {
                    file_put_contents((string) $command[$i + 1], $this->junit);
                }

                return ['exit' => $this->exit, 'output' => 'Tests: 2, Failures: 1.'];
            }
        };
    }

    private function junit(string $barStatus): string
    {
        $bar = $barStatus === 'failed'
            ? '<testcase name="testBar" class="X"><failure>nope</failure></testcase>'
            : '<testcase name="testBar" class="X"/>';

        return '<?xml version="1.0"?><testsuites><testsuite name="S">'
            . '<testcase name="testFoo" class="X"/>' . $bar
            . '</testsuite></testsuites>';
    }

    private function handler(string $junit): TestBaselineHandler
    {
        return new TestBaselineHandler(new RootResolver($this->root), $this->runnerWriting($junit), baselines: $this->root . '-baselines');
    }

    public function testBaselineRecordsASnapshotOfWhichTestsPassedAndFailed(): void
    {
        $before = $this->houseTree();
        $r = $this->handler($this->junit('failed'))->handleBaseline([]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame(2, $r['tests']);
        self::assertSame(1, $r['failures']);
        self::assertFileExists($this->baselineFile());
        self::assertSame($this->baselineFile(), $r['snapshot']);
        // decisions/0523: recording a baseline leaves nothing in the house — it used to write .milpa/test-baseline.json.
        self::assertSame($before, $this->houseTree());
    }

    public function testANamedBaselineIsKeptApartAndDiffedByItsName(): void
    {
        $this->handler($this->junit('passed'))->handleBaseline(['snapshot' => 'before-blog.json']);
        $r = $this->handler($this->junit('failed'))->handleDelta(['snapshot' => 'before-blog']);

        self::assertFileExists($this->baselineFile('before-blog'));
        self::assertFileDoesNotExist($this->baselineFile());
        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame(['X::testBar'], $r['new_failures']);
    }

    public function testWhatTheTestsWroteIntoTheHouseIsReportedByBaselineAndDelta(): void
    {
        $root = $this->root;
        $runner = new class ($this->junit('passed'), $root) extends ProcessRunner {
            public function __construct(private string $junit, private string $root)
            {
            }

            public function run(array $command, string $cwd, int $timeoutSeconds): array
            {
                $i = array_search('--log-junit', $command, true);
                file_put_contents((string) $command[(int) $i + 1], $this->junit);
                file_put_contents($this->root . '/written-by-a-test.txt', (string) microtime(true));

                return ['exit' => 0, 'output' => 'OK'];
            }
        };
        $handler = new TestBaselineHandler(new RootResolver($this->root), $runner, baselines: $this->root . '-baselines');

        self::assertSame(['written-by-a-test.txt'], $handler->handleBaseline([])['house_writes'] ?? null);
        self::assertSame(['written-by-a-test.txt'], $handler->handleDelta([])['house_writes'] ?? null);
        self::assertSame([], $this->handler($this->junit('passed'))->handleBaseline([])['house_writes'] ?? null);
    }

    public function testTheSuiteRunsWithoutAResultCache(): void
    {
        $runner = $this->spyRunner($this->junit('passed'));
        (new TestBaselineHandler(new RootResolver($this->root), $runner, baselines: $this->root . '-baselines'))->handleBaseline([]);

        self::assertContains('--do-not-cache-result', (array) $runner->command);
        $i = array_search('--cache-directory', (array) $runner->command, true);
        self::assertIsInt($i);
        self::assertStringStartsWith(sys_get_temp_dir() . '/milpa-phpunit-cache-', (string) ((array) $runner->command)[$i + 1]);
        self::assertDirectoryDoesNotExist((string) ((array) $runner->command)[$i + 1], 'removed after the run');
    }

    public function testWithoutAnInjectedDirectoryBaselinesLiveInTheSystemTempArea(): void
    {
        $r = (new TestBaselineHandler(new RootResolver($this->root), $this->runnerWriting($this->junit('passed'))))->handleBaseline([]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertStringStartsWith(sys_get_temp_dir() . '/milpa-test-baselines/', (string) $r['snapshot']);
        self::assertStringStartsNotWith((string) realpath($this->root), (string) $r['snapshot']);
        @unlink((string) $r['snapshot']);
        @rmdir(\dirname((string) $r['snapshot']));
    }

    public function testDeltaWithoutABaselineFailsClosedAndSaysToRecordOne(): void
    {
        $r = $this->handler($this->junit('failed'))->handleDelta([]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('test:baseline', (string) $r['error']);
    }

    public function testDeltaNamesANewFailureIntroducedSinceTheBaseline(): void
    {
        // Baseline: testBar passes.
        $this->handler($this->junit('passed'))->handleBaseline([]);
        // Delta: testBar now fails -> a regression.
        $r = $this->handler($this->junit('failed'))->handleDelta([]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertTrue($r['regressed']);
        self::assertSame(['X::testBar'], $r['new_failures']);
        self::assertSame([], $r['unchanged_failures']);
    }

    public function testDeltaSeesAFailureResolvedSinceTheBaseline(): void
    {
        $this->handler($this->junit('failed'))->handleBaseline([]);
        $r = $this->handler($this->junit('passed'))->handleDelta([]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertFalse($r['regressed']);
        self::assertSame(['X::testBar'], $r['resolved_failures']);
    }

    public function testAnUnchangedFailureIsNotCountedAsARegression(): void
    {
        $this->handler($this->junit('failed'))->handleBaseline([]);
        $r = $this->handler($this->junit('failed'))->handleDelta([]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertFalse($r['regressed']);
        self::assertSame(['X::testBar'], $r['unchanged_failures']);
        self::assertSame([], $r['new_failures']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paths(): iterable
    {
        yield 'absolute' => ['/etc/passwd'];
        yield 'into the house' => ['.milpa/test-baseline.json'];
        yield 'parent' => ['../x'];
        yield 'dot-dot name' => ['..'];
        yield 'hidden' => ['.x'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('paths')]
    public function testASnapshotThatIsAPathIsRefused(string $path): void
    {
        $r = $this->handler($this->junit('passed'))->handleBaseline(['snapshot' => $path]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('not a path', (string) $r['error']);
        self::assertDirectoryDoesNotExist($this->root . '/.milpa');
    }

    public function testNoJUnitReportIsReportedAsUnmeasurable(): void
    {
        // A runner that writes nothing to the junit path.
        $runner = new class () extends ProcessRunner {
            public function run(array $command, string $cwd, int $timeoutSeconds): array
            {
                return ['exit' => 2, 'output' => 'fatal'];
            }
        };
        $handler = new TestBaselineHandler(new RootResolver($this->root), $runner);

        $r = $handler->handleBaseline([]);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('no JUnit report', (string) $r['error']);
    }

    public function testAMalformedSnapshotIsRefusedWithoutRunningTheSuite(): void
    {
        $this->writeBaseline('{not-json');

        $runner = $this->spyRunner($this->junit('failed'));
        $r = (new TestBaselineHandler(new RootResolver($this->root), $runner, baselines: $this->root . '-baselines'))->handleDelta([]);

        self::assertFalse($r['ok']);
        self::assertFalse($r['ran']);
        self::assertNull($runner->command, 'must not run the suite against an unreadable baseline');
        self::assertStringContainsString('not readable', (string) $r['error']);
        self::assertStringContainsString('test:baseline', (string) $r['error']);
    }

    public function testASnapshotMissingResultsIsMalformed(): void
    {
        $this->writeBaseline((string) json_encode(['version' => 1]));

        $r = $this->handler($this->junit('failed'))->handleDelta([]);

        self::assertFalse($r['ok']);
        self::assertFalse($r['ran']);
        self::assertStringContainsString('not readable', (string) $r['error']);
    }

    public function testDeltaAgainstAnEmptyBaselineTreatsCurrentFailuresAsNew(): void
    {
        $this->writeBaseline((string) json_encode(['version' => 1, 'results' => []]));

        $r = $this->handler($this->junit('failed'))->handleDelta([]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertTrue($r['regressed']);
        self::assertSame(['X::testBar'], $r['new_failures']);
        self::assertSame([], $r['unchanged_failures']);
        self::assertSame(0, $r['baseline_failures']);
    }

    public function testDeltaRefusesASnapshotOutsideTheRootWithoutRunning(): void
    {
        $runner = $this->spyRunner($this->junit('failed'));
        $r = (new TestBaselineHandler(new RootResolver($this->root), $runner, baselines: $this->root . '-baselines'))
            ->handleDelta(['snapshot' => '/etc/passwd']);

        self::assertFalse($r['ok']);
        self::assertFalse($r['ran']);
        self::assertNull($runner->command);
        self::assertStringContainsString('not a path', (string) $r['error']);
    }

    public function testDeltaReportsWhenTheSuiteCannotBeMeasured(): void
    {
        $this->handler($this->junit('passed'))->handleBaseline([]);

        $runner = new class () extends ProcessRunner {
            public function run(array $command, string $cwd, int $timeoutSeconds): array
            {
                return ['exit' => 2, 'output' => 'fatal'];
            }
        };
        $r = (new TestBaselineHandler(new RootResolver($this->root), $runner, baselines: $this->root . '-baselines'))->handleDelta([]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no JUnit report', (string) $r['error']);
    }

    public function testWithoutPhpunitItSaysSoAndDoesNotPretendToHaveRun(): void
    {
        unlink($this->root . '/vendor/bin/phpunit');

        $r = (new TestBaselineHandler(new RootResolver($this->root)))->handleBaseline([]);

        self::assertFalse($r['ok']);
        self::assertFalse($r['ran']);
        self::assertStringContainsString('composer require --dev phpunit/phpunit', (string) $r['error']);
    }

    public function testTheFilterAndTimeoutReachTheCommand(): void
    {
        $runner = $this->spyRunner($this->junit('passed'));
        $r = (new TestBaselineHandler(new RootResolver($this->root), $runner, baselines: $this->root . '-baselines'))
            ->handleBaseline(['filter' => 'testBar', 'timeout' => 0]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertContains('--filter', (array) $runner->command);
        self::assertContains('testBar', (array) $runner->command);
        self::assertSame(1, $runner->timeout, 'zero seconds is not a timeout; it clamps to one');

        (new TestBaselineHandler(new RootResolver($this->root), $runner, baselines: $this->root . '-baselines'))
            ->handleBaseline(['timeout' => 99999]);
        self::assertSame(3600, $runner->timeout);
    }

    public function testAMalformedJUnitReportIsUnmeasurable(): void
    {
        $runner = new class () extends ProcessRunner {
            public function run(array $command, string $cwd, int $timeoutSeconds): array
            {
                $i = array_search('--log-junit', $command, true);
                if ($i !== false && isset($command[$i + 1])) {
                    file_put_contents((string) $command[$i + 1], '<not xml');
                }

                return ['exit' => 1, 'output' => 'broken'];
            }
        };
        $r = (new TestBaselineHandler(new RootResolver($this->root), $runner, baselines: $this->root . '-baselines'))->handleBaseline([]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('could not read the JUnit report', (string) $r['error']);
    }

    public function testLongOutputIsTruncatedFromTheStartAndSaysSo(): void
    {
        $noise = str_repeat('x', 20000);
        $runner = new class ($this->junit('passed'), $noise) extends ProcessRunner {
            public function __construct(private string $junit, private string $output)
            {
            }

            public function run(array $command, string $cwd, int $timeoutSeconds): array
            {
                $i = array_search('--log-junit', $command, true);
                if ($i !== false && isset($command[$i + 1])) {
                    file_put_contents((string) $command[$i + 1], $this->junit);
                }

                return ['exit' => 0, 'output' => $this->output . "\nOK (1 test, 1 assertion)"];
            }
        };
        $r = (new TestBaselineHandler(new RootResolver($this->root), $runner, baselines: $this->root . '-baselines'))->handleBaseline([]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertStringContainsString('output trimmed', $r['output']);
        self::assertStringContainsString('OK (1 test, 1 assertion)', $r['output']);
        self::assertLessThan(20000, \strlen($r['output']));
    }

    public function testItCannotCreateTheSnapshotDirectoryWhenAFileOccupiesThePath(): void
    {
        mkdir($this->root . '-baselines', 0777, true);
        file_put_contents(\dirname($this->baselineFile()), 'not a directory');

        $r = $this->handler($this->junit('passed'))->handleBaseline([]);

        self::assertFalse($r['ok']);
        self::assertTrue($r['ran'], 'the suite ran; writing the snapshot is what failed');
        self::assertStringContainsString('could not create', (string) $r['error']);
    }

    public function testItCannotWriteTheSnapshotOntoADirectory(): void
    {
        mkdir($this->baselineFile('vendor'), 0777, true);
        $r = $this->handler($this->junit('passed'))->handleBaseline(['snapshot' => 'vendor']);

        self::assertFalse($r['ok']);
        self::assertTrue($r['ran']);
        self::assertStringContainsString('could not write the snapshot', (string) $r['error']);
    }

    private function spyRunner(string $junit): ProcessRunner
    {
        return new class ($junit) extends ProcessRunner {
            /** @var list<string>|null */
            public ?array $command = null;
            public ?int $timeout = null;

            public function __construct(private string $junit)
            {
            }

            public function run(array $command, string $cwd, int $timeoutSeconds): array
            {
                $this->command = $command;
                $this->timeout = $timeoutSeconds;
                $i = array_search('--log-junit', $command, true);
                if ($i !== false && isset($command[$i + 1])) {
                    file_put_contents((string) $command[$i + 1], $this->junit);
                }

                return ['exit' => 0, 'output' => 'OK'];
            }
        };
    }
}
