<?php

declare(strict_types=1);

namespace Milpa\DevTools\Make;

/** A file a generator intends to write: its absolute path and full contents. */
final class PlannedFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $contents,
    ) {
    }
}
