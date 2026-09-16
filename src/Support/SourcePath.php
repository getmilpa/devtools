<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\DevTools\Support;

/** The shared realpath boundary for line reads and source pages. */
final class SourcePath
{
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

    /** A stable relative name for a file already admitted by inside(). */
    public static function relative(string $file, string $root): string
    {
        $file = str_replace('\\', '/', $file);
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';

        return str_starts_with($file, $prefix) ? substr($file, \strlen($prefix)) : $file;
    }
}
