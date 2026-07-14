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

namespace Milpa\DevTools\Make;

/**
 * Filesystem safety for generators: refuses to clobber an existing file unless `--force`, and
 * creates parent directories on write. `assertWritable()` is called for every planned file up front
 * so a blocked target aborts the whole `coa:make` run before anything is written.
 */
final class WriteGuard
{
    /**
     * Throws unless `$path` is free to write: absent, `$force` is true, or `$merge` is true.
     *
     * @param bool $merge Set from the originating {@see PlannedFile::$merge} — a MARKER-BASED,
     *                    idempotent-safe merge into an already-existing plugin file (see
     *                    {@see MarkerInserter}) is exactly the "safe to overwrite even without
     *                    `--force`" case this guard exists to distinguish from an accidental
     *                    clobber of unrelated hand-written content.
     */
    public function assertWritable(string $path, bool $force, bool $merge = false): void
    {
        if (is_file($path) && !$force && !$merge) {
            throw new \RuntimeException("{$path} already exists (use --force to overwrite)");
        }
    }

    /** Writes `$contents` to `$path`, creating parent directories as needed. */
    public function write(string $path, string $contents): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create directory {$dir}");
        }
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("cannot write {$path}");
        }
    }
}
