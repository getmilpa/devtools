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

/** A file a generator intends to write: its absolute path and full contents. */
final class PlannedFile
{
    /**
     * @param bool $merge Whether `$contents` is a MARKER-BASED merge of new wiring into a plugin
     *                    file that already exists on disk (see {@see MarkerInserter}), as opposed
     *                    to brand new content. `false` (the default) preserves this class's pre-F1
     *                    meaning — every existing caller of `new PlannedFile(...)` keeps producing
     *                    a plan {@see WriteGuard::assertWritable()} still refuses to clobber
     *                    without `--force`. `true` is a generator's own promise that the merge is
     *                    idempotent-safe (re-running it does not duplicate the insertion) and is
     *                    meant to be written even when the target already exists —
     *                    {@see WriteGuard::assertWritable()} honors it by skipping the "already
     *                    exists" guard for that one file.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $contents,
        public readonly bool $merge = false,
    ) {
    }
}
