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

use Milpa\DevTools\Support\RootResolver;

/**
 * `source:read` — returns a raw slice of one source file inside the app root, by line range.
 *
 * The debt this pays (measured on a live cattle run): the agent said, literally, «I don't have a
 * read file tool». Without one, `edit`'s find/replace contract is unusable — a find must match the
 * file VERBATIM, and text remembered or deduced rarely does — so every change fell back to
 * `implement` reconstructing the whole file from memory. Read first, then find/replace exactly what
 * was read.
 *
 * The path discipline is {@see TestHandler}'s: a path that resolves outside the root is refused
 * before anything is opened, because once this is an operation the input no longer comes only from
 * a person typing on their own machine. Truncation is declared, never silent, and the default slice
 * is small on purpose — ask again with `from` rather than paging a whole file into the context.
 *
 * Not found is `ok:false` with a reason, never an exception (same contract as {@see ValidateHandler}).
 */
final class SourceReadHandler
{
    /** The default slice — enough to see a class's shape without paying for the whole file. */
    private const DEFAULT_LINES = 120;

    /** The ceiling per call: a bigger question is asked again with `from`, not answered bigger. */
    private const MAX_LINES = 400;

    public function __construct(private readonly RootResolver $roots = new RootResolver())
    {
    }

    /**
     * Reads up to `lines` lines starting at 1-based `from`, with honest accounting: how many lines
     * the file has, how many came back, and whether anything after the slice was left out.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, path?: string, from?: int, lines?: int, total_lines?: int, content?: string, truncated?: bool, error?: string}
     */
    public function handle(array $input): array
    {
        $path = \is_string($input['path'] ?? null) ? trim($input['path']) : '';
        if ($path === '') {
            return ['ok' => false, 'error' => 'name the file: source:read needs `path`'];
        }

        $root = $this->roots->resolve();
        $file = $this->insideRoot($root, $path);
        if ($file === null) {
            return ['ok' => false, 'error' => "`path` has to be a file inside {$root} — got: {$path}"];
        }

        $from = \is_int($input['from'] ?? null) ? max(1, $input['from']) : 1;
        $budget = \is_int($input['lines'] ?? null) ? $input['lines'] : self::DEFAULT_LINES;
        $budget = max(1, min(self::MAX_LINES, $budget));

        $all = file($file);
        if ($all === false) {
            return ['ok' => false, 'error' => "could not read {$path}"];
        }

        $total = \count($all);
        if ($from > $total && $total > 0) {
            return ['ok' => false, 'error' => "`from` is past the end — {$path} has {$total} lines"];
        }

        $slice = \array_slice($all, $from - 1, $budget);

        return [
            'ok' => true,
            'path' => $this->relativePath($file, $root),
            'from' => $from,
            'lines' => \count($slice),
            'total_lines' => $total,
            'content' => implode('', $slice),
            'truncated' => $from - 1 + \count($slice) < $total,
        ];
    }

    /**
     * The absolute path when it is a real FILE inside the root; `null` otherwise. Same realpath
     * discipline as {@see TestHandler}: symlinks and `..` are resolved BEFORE the containment check,
     * so a path that merely looks inside cannot escape.
     */
    private function insideRoot(string $root, string $path): ?string
    {
        $candidate = str_starts_with($path, '/') ? $path : $root . '/' . $path;
        $real = realpath($candidate);
        $rootReal = realpath($root);

        if ($real === false || $rootReal === false || ! is_file($real)) {
            return null;
        }

        return str_starts_with($real, $rootReal . '/') ? $real : null;
    }

    /** Returns a stable path relative to the host app root. */
    private function relativePath(string $path, string $root): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';

        return str_starts_with($path, $prefix) ? substr($path, \strlen($prefix)) : $path;
    }
}
