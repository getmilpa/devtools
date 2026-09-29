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

namespace Milpa\DevTools\Operations;

use Milpa\DevTools\Support\ProcessRunner;
use Milpa\DevTools\Support\RootResolver;
use Milpa\DevTools\Test\HouseWrites;
use Milpa\DevTools\Test\JUnitParser;
use Milpa\DevTools\Test\TemporaryCache;
use Milpa\DevTools\Test\TestDelta;

/**
 * Records a test baseline and reports the delta against it, so a regression can be told apart from a
 * failure that was already there.
 *
 * The `test` operation already answers "does the suite pass right now" ({@see TestHandler}). What it
 * cannot answer is "did MY change break this" — a red suite looks the same whether the failure is new
 * or pre-existing, and "it already failed" stays an unverifiable claim. This handler closes that gap:
 * `test:baseline` snapshots which tests pass and fail, and `test:delta` runs again and names the new,
 * resolved, and unchanged failures against that snapshot.
 *
 * Like {@see TestHandler} it runs the suite in a real subprocess (running PHPUnit inside PHPUnit is a
 * recursion nobody wants to debug) via an injected {@see ProcessRunner} seam, and reads per-test
 * identity from PHPUnit's JUnit log rather than the human summary, because counts cannot distinguish
 * one broken test from another.
 *
 * NEITHER LEAVES ANYTHING IN THE HOUSE (greenhouse decisions/0523). A baseline is the instrument's own note,
 * not the house's state: it lives in the system's temp area, keyed by the house's root, and PHPUnit is told
 * not to cache its results. So both operations declare an `ephemeral` mutation, and that one declaration is
 * what the terminal's unsigned door (decisions/0522) and the house's closure both read: running the suite
 * needs no signature and is not a change to the house. It used to write `.milpa/test-baseline.json` inside
 * the house, which is a change that lasts, and declaring it ephemeral then would have been a lie.
 */
final class TestBaselineHandler
{
    /** How much captured output is echoed back; the summary and failures live at the end. */
    private const MAX_OUTPUT = 12000;

    /** The baseline's name when the caller does not give one. */
    private const DEFAULT_SNAPSHOT = 'baseline';

    /**
     * @param string|null $baselines the directory baselines live under, outside every house; null is the
     *                               system temp area's `milpa-test-baselines`
     */
    public function __construct(
        private readonly RootResolver $roots = new RootResolver(),
        private readonly ProcessRunner $runner = new ProcessRunner(),
        private readonly JUnitParser $parser = new JUnitParser(),
        private readonly TestDelta $delta = new TestDelta(),
        private readonly ?string $baselines = null,
        private readonly HouseWrites $witness = new HouseWrites(),
    ) {
    }

    /**
     * Runs the suite and records which tests passed and failed as the baseline to compare against later.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, ran: bool, snapshot?: string, tests?: int, failures?: int, output?: string, command?: string, house_writes?: list<string>|null, error?: string}
     */
    public function handleBaseline(array $input): array
    {
        $root = $this->roots->resolve();
        $run = $this->runSuite($root, $input);
        if (isset($run['error'])) {
            return ['ok' => false, 'ran' => $run['ran'], 'error' => $run['error']];
        }

        $snapshot = $this->snapshotPath($root, $input);
        if ($snapshot === null) {
            return ['ok' => false, 'ran' => true, 'error' => $this->notAName($input)];
        }

        $dir = \dirname($snapshot);
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            return ['ok' => false, 'ran' => true, 'error' => "could not create {$dir} for the snapshot"];
        }

        $failures = \count(array_filter($run['results'], static fn (string $s): bool => $s === 'failed' || $s === 'errored'));
        $payload = ['version' => 1, 'command' => $run['command'], 'results' => $run['results']];
        if (@file_put_contents($snapshot, json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)) === false) {
            return ['ok' => false, 'ran' => true, 'error' => "could not write the snapshot to {$snapshot}"];
        }

        return [
            'ok' => true,
            'ran' => true,
            'snapshot' => $snapshot,
            'tests' => \count($run['results']),
            'failures' => $failures,
            'output' => $this->trim($run['output']),
            'command' => $run['command'],
            // What the app's own tests wrote into the house — the declaration's witness (decisions/0523).
            'house_writes' => $run['house_writes'] ?? null,
        ];
    }

    /**
     * Runs the suite again and reports the new, resolved, and unchanged failures against the baseline.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, ran: bool, regressed?: bool, new_failures?: list<string>, resolved_failures?: list<string>, unchanged_failures?: list<string>, baseline_failures?: int, current_failures?: int, output?: string, command?: string, house_writes?: list<string>|null, error?: string}
     */
    public function handleDelta(array $input): array
    {
        $root = $this->roots->resolve();

        $snapshot = $this->snapshotPath($root, $input);
        if ($snapshot === null) {
            return ['ok' => false, 'ran' => false, 'error' => $this->notAName($input)];
        }
        if (! is_file($snapshot)) {
            return ['ok' => false, 'ran' => false, 'error' => "no baseline at {$snapshot} — run test:baseline first"];
        }

        $decoded = json_decode((string) file_get_contents($snapshot), true);
        if (! \is_array($decoded) || ! isset($decoded['results']) || ! \is_array($decoded['results'])) {
            return ['ok' => false, 'ran' => false, 'error' => "the baseline at {$snapshot} is not readable — re-record it with test:baseline"];
        }
        /** @var array<string, string> $baseline */
        $baseline = array_map(strval(...), $decoded['results']);

        $run = $this->runSuite($root, $input);
        if (isset($run['error'])) {
            return ['ok' => false, 'ran' => $run['ran'], 'error' => $run['error']];
        }

        $comparison = $this->delta->compare($baseline, $run['results']);

        return [
            // The DELTA succeeded as a measurement whenever it could compare; whether the suite is red
            // is reported in the fields, not by collapsing a regression into ok:false. A caller that
            // wants "no new failures" reads `regressed`.
            'ok' => true,
            'ran' => true,
            'regressed' => $comparison['regressed'],
            'new_failures' => $comparison['new_failures'],
            'resolved_failures' => $comparison['resolved_failures'],
            'unchanged_failures' => $comparison['unchanged_failures'],
            'baseline_failures' => $comparison['baseline_failures'],
            'current_failures' => $comparison['current_failures'],
            'output' => $this->trim($run['output']),
            'command' => $run['command'],
            'house_writes' => $run['house_writes'] ?? null,
        ];
    }

    /**
     * Runs PHPUnit with a JUnit log, parses per-test results, and returns them with the raw output.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ran: bool, results: array<string, string>, output: string, command: string, house_writes?: list<string>|null, error?: string}
     */
    private function runSuite(string $root, array $input): array
    {
        $binary = $root . '/vendor/bin/phpunit';
        if (! is_file($binary)) {
            return ['ran' => false, 'results' => [], 'output' => '', 'command' => '', 'error' => "phpunit is not installed in {$root} — run: composer require --dev phpunit/phpunit"];
        }

        $junit = (string) tempnam(sys_get_temp_dir(), 'milpa-junit-');
        // No result cache, and PHPUnit's cache directory outside the house: the run leaves nothing behind in it
        // (decisions/0523).
        $cache = TemporaryCache::create();
        $command = [\PHP_BINARY, $binary, '--colors=never', '--do-not-cache-result', '--cache-directory', $cache, '--log-junit', $junit];

        $filter = \is_string($input['filter'] ?? null) ? trim($input['filter']) : '';
        if ($filter !== '') {
            $command[] = '--filter';
            $command[] = $filter;
        }

        $timeout = \is_int($input['timeout'] ?? null) ? $input['timeout'] : 300;
        $timeout = max(1, min(3600, $timeout));

        $before = $this->witness->digest($root);
        try {
            $result = $this->runner->run($command, $root, $timeout);
        } finally {
            TemporaryCache::remove($cache);
        }
        $writes = $this->witness->between($before, $this->witness->digest($root));
        $report = is_file($junit) ? (string) file_get_contents($junit) : '';
        @unlink($junit);

        if (trim($report) === '') {
            return ['ran' => false, 'results' => [], 'output' => $this->trim($result['output']), 'command' => implode(' ', $command), 'error' => 'phpunit produced no JUnit report — the suite could not be measured'];
        }

        try {
            $results = $this->parser->parse($report);
        } catch (\InvalidArgumentException $e) {
            return ['ran' => false, 'results' => [], 'output' => $this->trim($result['output']), 'command' => implode(' ', $command), 'error' => 'could not read the JUnit report: ' . $e->getMessage()];
        }

        return ['ran' => true, 'results' => $results, 'output' => $result['output'], 'command' => implode(' ', $command), 'house_writes' => $writes];
    }

    /**
     * The baseline's file: `<baselines>/<house>/<name>.json`, outside the house — or null when `snapshot` is not a
     * plain name (a path, a parent reference, anything that could reach back into a house).
     *
     * @param array<string, mixed> $input
     */
    private function snapshotPath(string $root, array $input): ?string
    {
        $given = \is_string($input['snapshot'] ?? null) ? trim($input['snapshot']) : '';
        $name = $given !== '' ? preg_replace('/\.json$/', '', $given) : self::DEFAULT_SNAPSHOT;
        if (! \is_string($name) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*(\.[A-Za-z0-9_-]+)*$/D', $name) !== 1 || \strlen($name) > 64) {
            return null;
        }
        $realRoot = realpath($root);
        if ($realRoot === false) {
            return null;
        }

        return $this->baselineDirectory() . '/' . substr(hash('sha256', $realRoot), 0, 16) . '/' . $name . '.json';
    }

    /** Where every house's baselines live: never inside a house. */
    private function baselineDirectory(): string
    {
        return rtrim($this->baselines ?? sys_get_temp_dir() . '/milpa-test-baselines', '/');
    }

    /**
     * The refusal of a `snapshot` that is not a plain name.
     *
     * @param array<string, mixed> $input
     */
    private function notAName(array $input): string
    {
        $given = \is_string($input['snapshot'] ?? null) ? $input['snapshot'] : '';

        return "«snapshot» names a baseline (letters, digits, «.», «_», «-»), not a path — got «{$given}». "
            . 'A baseline lives outside the house, under ' . $this->baselineDirectory() . ', so recording one changes nothing the house keeps';
    }

    /** Keeps the END of the output, where the summary and failures are. */
    private function trim(string $output): string
    {
        if (\strlen($output) <= self::MAX_OUTPUT) {
            return $output;
        }

        return '[…output trimmed, keeping the last ' . self::MAX_OUTPUT . " characters…]\n" . substr($output, -self::MAX_OUTPUT);
    }
}
