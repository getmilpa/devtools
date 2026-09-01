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
use Milpa\DevTools\Test\JUnitParser;
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
 */
final class TestBaselineHandler
{
    /** How much captured output is echoed back; the summary and failures live at the end. */
    private const MAX_OUTPUT = 12000;

    /** Where the snapshot lives when the caller does not name one. */
    private const DEFAULT_SNAPSHOT = '.milpa/test-baseline.json';

    public function __construct(
        private readonly RootResolver $roots = new RootResolver(),
        private readonly ProcessRunner $runner = new ProcessRunner(),
        private readonly JUnitParser $parser = new JUnitParser(),
        private readonly TestDelta $delta = new TestDelta(),
    ) {
    }

    /**
     * Runs the suite and records which tests passed and failed as the baseline to compare against later.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, ran: bool, snapshot?: string, tests?: int, failures?: int, output?: string, command?: string, error?: string}
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
            return ['ok' => false, 'ran' => true, 'error' => '«snapshot» must stay inside ' . $root];
        }

        $dir = \dirname($snapshot);
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
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
        ];
    }

    /**
     * Runs the suite again and reports the new, resolved, and unchanged failures against the baseline.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, ran: bool, regressed?: bool, new_failures?: list<string>, resolved_failures?: list<string>, unchanged_failures?: list<string>, baseline_failures?: int, current_failures?: int, output?: string, command?: string, error?: string}
     */
    public function handleDelta(array $input): array
    {
        $root = $this->roots->resolve();

        $snapshot = $this->snapshotPath($root, $input);
        if ($snapshot === null) {
            return ['ok' => false, 'ran' => false, 'error' => '«snapshot» must stay inside ' . $root];
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
        ];
    }

    /**
     * Runs PHPUnit with a JUnit log, parses per-test results, and returns them with the raw output.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ran: bool, results: array<string, string>, output: string, command: string, error?: string}
     */
    private function runSuite(string $root, array $input): array
    {
        $binary = $root . '/vendor/bin/phpunit';
        if (! is_file($binary)) {
            return ['ran' => false, 'results' => [], 'output' => '', 'command' => '', 'error' => "phpunit is not installed in {$root} — run: composer require --dev phpunit/phpunit"];
        }

        $junit = (string) tempnam(sys_get_temp_dir(), 'milpa-junit-');
        $command = [\PHP_BINARY, $binary, '--colors=never', '--log-junit', $junit];

        $filter = \is_string($input['filter'] ?? null) ? trim($input['filter']) : '';
        if ($filter !== '') {
            $command[] = '--filter';
            $command[] = $filter;
        }

        $timeout = \is_int($input['timeout'] ?? null) ? $input['timeout'] : 300;
        $timeout = max(1, min(3600, $timeout));

        $result = $this->runner->run($command, $root, $timeout);
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

        return ['ran' => true, 'results' => $results, 'output' => $result['output'], 'command' => implode(' ', $command)];
    }

    /**
     * Resolves the snapshot path from input or the default, refusing anything outside the app root.
     *
     * @param array<string, mixed> $input
     */
    private function snapshotPath(string $root, array $input): ?string
    {
        $given = \is_string($input['snapshot'] ?? null) ? trim($input['snapshot']) : '';
        $relative = $given !== '' ? $given : self::DEFAULT_SNAPSHOT;
        $candidate = str_starts_with($relative, '/') ? $relative : $root . '/' . $relative;

        $realRoot = realpath($root);
        if ($realRoot === false) {
            return null;
        }

        // The file may not exist yet, so validate the deepest existing ancestor stays inside the root.
        $ancestor = $candidate;
        while (! file_exists($ancestor) && \dirname($ancestor) !== $ancestor) {
            $ancestor = \dirname($ancestor);
        }
        $realAncestor = realpath($ancestor);
        if ($realAncestor === false) {
            return null;
        }

        return str_starts_with($realAncestor, $realRoot . '/') || $realAncestor === $realRoot ? $candidate : null;
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
