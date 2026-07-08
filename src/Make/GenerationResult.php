<?php

declare(strict_types=1);

namespace Milpa\DevTools\Make;

/** The output of a generator: the files to write, and how to verify the produced class. */
final class GenerationResult
{
    /**
     * @param list<PlannedFile>          $files
     * @param 'controller'|'entity'|null $verifyKind
     */
    public function __construct(
        public readonly array $files,
        public readonly ?string $verifyKind = null,
        public readonly ?string $verifyTarget = null,
    ) {
    }
}
