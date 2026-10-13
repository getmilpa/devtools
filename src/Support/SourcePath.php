<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\DevTools\Support;

/**
 * The shared realpath boundary for the operations that return a file's content by path: line reads, source
 * pages, and `test`. One question answers for all of them (greenhouse decisions/0618): **which house is this path
 * from, and is it that house's source or its state?**
 *
 * The rules are asked about the file each path resolves to, not about how the path was spelled, so two spellings of
 * one file get one answer (greenhouse decisions/0618). {@see canonical()} walks from the root down and, at each
 * level, asks the directory which entry the component actually IS — by device + inode — and takes that entry's
 * STORED name; the state and test rules read that canonical path, matching the segment case-insensitively, the same
 * way the secret rule does. When a directory on the way cannot be listed the path cannot be canonicalised: the
 * answer then fails CLOSED (state, not source; not a test), because a file whose place in the house cannot be
 * verified is not handed out as source.
 */
final class SourcePath
{
    /** Top-level directories that are a house's STATE, not its source. */
    private const STATE_DIRS = ['var', 'storage'];

    /**
     * The roots, under a house's `var/`, of a COPY of that house (greenhouse decisions/0618, D20). A trial, an
     * exercise and a boot candidate are each a copy of the house's tree: their SOURCE is source, their own
     * `var/`/`storage/` is state. These patterns mirror where milpa/app-runtime makes the copies —
     * `TrialWorkspace` (`var/trials/<id>/copy`, `var/exercises/<id>/copy`) and `BootCandidate`
     * (`var/boot-candidates/<id>`). devtools does not depend on app-runtime, so the list lives here; if
     * app-runtime adds a copy kind this must gain it (a stated coupling, greenhouse evidence/1199). Matched over
     * the canonical path and case-insensitively, so a folded or recased spelling of a copy root still strips.
     *
     * @var list<string>
     */
    private const COPY_ROOTS = [
        '#^(var/trials/[^/]+/copy)/#i',
        '#^(var/exercises/[^/]+/copy)/#i',
        '#^(var/boot-candidates/[^/]+)/#i',
    ];

    /** Resolve a regular file strictly inside the host, after resolving links and traversal. */
    public static function inside(string $root, string $path): ?string
    {
        $candidate = str_starts_with($path, '/') ? $path : $root . '/' . $path;
        $real = realpath($candidate);
        $rootReal = realpath($root);
        if ($real === false || $rootReal === false || !is_file($real)) {
            return null;
        }

        return str_starts_with($real, $rootReal . '/') ? $real : null;
    }

    /** A stable relative name for a file already admitted by inside() — the caller's spelling, unfolded. */
    public static function relative(string $file, string $root): string
    {
        $file = str_replace('\\', '/', $file);
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';

        return str_starts_with($file, $prefix) ? substr($file, \strlen($prefix)) : $file;
    }

    /**
     * The path of $file relative to $root with the STORED name of every segment — the name the directory actually
     * holds, found by identity (device + inode), not by how the caller spelled it. Returns null when a directory on
     * the way cannot be listed (so the path's identity cannot be established) or the file is outside the root.
     */
    public static function canonical(string $file, string $root): ?string
    {
        $rootReal = realpath($root);
        $fileReal = realpath($file);
        if ($rootReal === false || $fileReal === false || !str_starts_with($fileReal, $rootReal . '/')) {
            return null;
        }
        $current = $rootReal;
        $names = [];
        foreach (explode('/', substr($fileReal, \strlen($rootReal) + 1)) as $segment) {
            $stored = self::storedName($current, $current . '/' . $segment);
            if ($stored === null) {
                return null;
            }
            $names[] = $stored;
            $current .= '/' . $stored;
        }

        return implode('/', $names);
    }

    /**
     * Whether a file already admitted by {@see inside()} is the STATE of the house it belongs to (greenhouse
     * decisions/0618, D20), judged over its canonical path and matched case-insensitively — the same comparison the
     * secret rule uses. A file inside a COPY of the house is judged against the copy's root. A path that cannot be
     * canonicalised is treated as state (the safe direction).
     */
    public static function isHouseState(string $file, string $root): bool
    {
        $canonical = self::canonical($file, $root);
        if ($canonical === null) {
            return true;
        }

        return \in_array(strtolower(self::firstSegment($canonical)), self::STATE_DIRS, true);
    }

    /**
     * Whether a file already admitted by {@see inside()} is a TEST of the house it belongs to — a `.php` file or a
     * directory under that house's (or a copy's) `tests/` (greenhouse decisions/0618, D22), judged over its
     * canonical path, case-insensitively. Any other path is refused; a path that cannot be canonicalised is not a
     * test.
     */
    public static function isTest(string $file, string $root): bool
    {
        $canonical = self::canonical($file, $root);
        if ($canonical === null || strtolower(self::firstSegment($canonical)) !== 'tests') {
            return false;
        }

        return is_dir($file) || str_ends_with(strtolower($canonical), '.php');
    }

    /** The first path segment of a canonical path, relative to the house it belongs to (the real house, or the copy that contains it). */
    private static function firstSegment(string $canonical): string
    {
        foreach (self::COPY_ROOTS as $pattern) {
            if (preg_match($pattern, $canonical, $match) === 1) {
                $canonical = substr($canonical, \strlen($match[1]) + 1);
                break;
            }
        }

        return explode('/', $canonical)[0];
    }

    /** The name the directory $dir actually holds for the entry that IS $target (same device + inode); null when $dir cannot be listed, the spelling when the entry is gone. */
    private static function storedName(string $dir, string $target): ?string
    {
        $want = @lstat($target);
        if ($want === false) {
            return basename($target);
        }
        $entries = self::entriesOf($dir);
        if ($entries === null) {
            return null;
        }

        return self::matchByIdentity($entries, $want['dev'], $want['ino']) ?? basename($target);
    }

    /**
     * The entries of a directory as `[name, device, inode]` tuples, or null when it cannot be listed. A LIST, not a
     * map keyed by name: a name that is a decimal integer must stay a string, and a PHP array would make it an
     * integer key (greenhouse decisions/0618, F-NUM).
     *
     * @return null|list<array{0: string, 1: int, 2: int}>
     */
    private static function entriesOf(string $dir): ?array
    {
        $names = @scandir($dir);
        if ($names === false) {
            return null;
        }
        $entries = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $have = @lstat($dir . '/' . $name);
            if ($have !== false) {
                $entries[] = [$name, $have['dev'], $have['ino']];
            }
        }

        return $entries;
    }

    /**
     * The name of the entry whose device AND inode equal ($dev, $ino), or null if none — the one place the identity
     * comparison lives, so a test can pin that both numbers count (not the inode alone) without a second disk. The
     * name is cast to string because a decimal-integer directory name must not come back as an int (F-NUM).
     *
     * @internal
     *
     * @param list<array{0: string, 1: int, 2: int}> $entries name, device, inode
     */
    public static function matchByIdentity(array $entries, int $dev, int $ino): ?string
    {
        foreach ($entries as [$name, $edev, $eino]) {
            if ($edev === $dev && $eino === $ino) {
                return (string) $name;
            }
        }

        return null;
    }
}
