<?php

declare(strict_types=1);

namespace Milpa\DevTools\Make;

/**
 * Filesystem safety for generators: refuses to clobber an existing file unless `--force`, and
 * creates parent directories on write. `assertWritable()` is called for every planned file up front
 * so a blocked target aborts the whole `coa:make` run before anything is written.
 */
final class WriteGuard
{
    /** Throws unless `$path` is free to write: absent, or `$force` is true. */
    public function assertWritable(string $path, bool $force): void
    {
        if (is_file($path) && !$force) {
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
