<?php

/**
 * This file is part of Milpa DevTools — the coa generate-verify-inspect toolset.
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
 * What a test run wrote into the house, by content — the witness beside `test`'s declaration (greenhouse decisions/0523).
 *
 * `test` declares an `ephemeral` mutation: the operation itself keeps nothing (no result cache, baselines outside the
 * house). But it runs the app's OWN tests, which are code this operation does not control, and a test can write the
 * house's tree. So the claim is checked, not trusted: the tree is digested before and after the run, and every path
 * whose bytes changed, appeared or vanished is reported. A same-bytes rewrite is not a write — measured on the
 * skeleton (evidence/1057): its boot tests rewrite `config/app.php` with the bytes it already had.
 *
 * The house's closure reads the report: a test run that wrote the house counts as a change to it, whatever the
 * declaration says. Left out: `vendor/`, `node_modules/`, `.git/` (not the house's own tree), the rehearsal copies
 * and boot candidates under `var/trials/` and `var/boot-candidates/` (copies the house never boots from; 754 of 845
 * files of evidence/1050's house), and the session's own log and run leases under `var/` (the leg that called the
 * test writes those itself).
 */
final class HouseWrites
{
    /** Directories that are not the house's own tree, relative to the root. */
    private const SKIPPED = ['vendor', 'node_modules', '.git', 'var/agent-runs', 'var/trials', 'var/boot-candidates'];

    /** Files the calling leg writes itself: the session log and its rotations. */
    private const SKIPPED_FILE = '#^var/agent-sessions\.jsonl#';

    /**
     * The house's tree as relative path → sha256 of its bytes, or null when it cannot be read whole.
     *
     * @return array<string, string>|null
     */
    public function digest(string $root): ?array
    {
        $root = rtrim($root, '/');
        if (! is_dir($root)) {
            return null;
        }
        $state = [];
        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    static function (\SplFileInfo $file) use ($root): bool {
                        $relative = substr($file->getPathname(), \strlen($root) + 1);

                        return ! ($file->isDir() && \in_array($relative, self::SKIPPED, true));
                    },
                ),
            );
            foreach ($files as $file) {
                $relative = substr($file->getPathname(), \strlen($root) + 1);
                if ($file->isLink() || ! $file->isFile() || preg_match(self::SKIPPED_FILE, $relative) === 1) {
                    continue;
                }
                $hash = @hash_file('sha256', $file->getPathname());
                if ($hash === false) {
                    return null;
                }
                $state[$relative] = $hash;
            }
        } catch (\UnexpectedValueException) {
            return null;
        }
        ksort($state);

        return $state;
    }

    /**
     * The paths whose bytes changed, appeared or vanished between two digests — or null when either is unknown.
     *
     * @param array<string, string>|null $before
     * @param array<string, string>|null $after
     *
     * @return list<string>|null
     */
    public function between(?array $before, ?array $after): ?array
    {
        if ($before === null || $after === null) {
            return null;
        }
        $paths = array_map(strval(...), array_keys(array_diff_assoc($after, $before) + array_diff_key($before, $after)));
        sort($paths);

        return $paths;
    }
}
