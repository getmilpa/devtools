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
 * PHPUnit's cache directory for one run, outside the house, removed after it (greenhouse decisions/0523).
 *
 * `--do-not-cache-result` alone is not enough: measured on the skeleton (evidence/1057), PHPUnit 11 still creates
 * the `cacheDirectory` its configuration names — an empty `.phpunit.cache/` inside the house after every run.
 */
final class TemporaryCache
{
    /** A fresh directory under the system temp area; its path is what `--cache-directory` receives. */
    public static function create(): string
    {
        $dir = sys_get_temp_dir() . '/milpa-phpunit-cache-' . bin2hex(random_bytes(6));
        @mkdir($dir, 0700, true);

        return $dir;
    }

    /** Remove the directory and whatever PHPUnit left in it. */
    public static function remove(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
