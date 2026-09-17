<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\DevTools\Operations;

/** Counts from the existing PHPUnit judge; an absent summary stays unknown. */
final class PhpUnitSummary
{
    /** Read the native PHPUnit summary without inventing absent counts.
     * @return array{tests: int|null, assertions: int|null, failures: int|null, errors: int|null} */
    public static function counts(string $output): array
    {
        $read = static function (string $pattern) use ($output): ?int {
            return preg_match($pattern, $output, $m) === 1 ? (int) $m[1] : null;
        };

        if (preg_match('/OK \((\d+) tests?, (\d+) assertions?\)/', $output, $m) === 1) {
            return ['tests' => (int) $m[1], 'assertions' => (int) $m[2], 'failures' => 0, 'errors' => 0];
        }

        $tests = $read('/\bTests: (\d+)/');

        return [
            'tests' => $tests,
            'assertions' => $read('/\bAssertions: (\d+)/'),
            'failures' => $tests === null ? null : ($read('/\bFailures: (\d+)/') ?? 0),
            'errors' => $tests === null ? null : ($read('/\bErrors: (\d+)/') ?? 0),
        ];
    }

}
