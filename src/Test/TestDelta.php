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

namespace Milpa\DevTools\Test;

/**
 * Compares two test-result maps and names what changed, so "it already failed" becomes checkable.
 *
 * Without a baseline, a red suite cannot be attributed: a regression and a pre-existing failure look
 * identical. This pure diff splits the current failures into the ones the baseline already had
 * (unchanged), the ones that are new (a regression this change introduced), and the ones the baseline
 * had that are now gone (resolved). A test present now but absent from the baseline is treated as new
 * if it is failing — a fresh red is a regression regardless of whether the test itself is new.
 */
final class TestDelta
{
    /**
     * Diffs a baseline map against the current map, both `"Class::method" => status`.
     *
     * @param array<string, string> $baseline
     * @param array<string, string> $current
     *
     * @return array{new_failures: list<string>, resolved_failures: list<string>, unchanged_failures: list<string>, regressed: bool, baseline_failures: int, current_failures: int}
     */
    public function compare(array $baseline, array $current): array
    {
        $new = [];
        $unchanged = [];
        foreach ($current as $id => $status) {
            if (! $this->isFailing($status)) {
                continue;
            }
            if (isset($baseline[$id]) && $this->isFailing($baseline[$id])) {
                $unchanged[] = $id;
            } else {
                $new[] = $id;
            }
        }

        $resolved = [];
        foreach ($baseline as $id => $status) {
            if (! $this->isFailing($status)) {
                continue;
            }
            if (! isset($current[$id]) || ! $this->isFailing($current[$id])) {
                $resolved[] = $id;
            }
        }

        sort($new);
        sort($resolved);
        sort($unchanged);

        return [
            'new_failures' => $new,
            'resolved_failures' => $resolved,
            'unchanged_failures' => $unchanged,
            'regressed' => $new !== [],
            'baseline_failures' => \count(array_filter($baseline, $this->isFailing(...))),
            'current_failures' => \count(array_filter($current, $this->isFailing(...))),
        ];
    }

    /**
     * A failing outcome is one that broke: a failed assertion or an error. Skipped and passed do not.
     */
    private function isFailing(string $status): bool
    {
        return $status === 'failed' || $status === 'errored';
    }
}
