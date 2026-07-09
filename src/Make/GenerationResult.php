<?php

declare(strict_types=1);

namespace Milpa\DevTools\Make;

/** The output of a generator: the files to write, how to verify the produced class, and any advice. */
final class GenerationResult
{
    /**
     * @param list<PlannedFile>          $files      files this generator actually plans to write
     * @param 'controller'|'entity'|null $verifyKind
     * @param Flavor|null                $flavor     the {@see Flavor} this generation targeted — both
     *                                               `ControllerGenerator` and `EntityGenerator` set this (as of F3);
     *                                               pass it straight through to {@see VerifyRunner::run()}
     * @param string|null                $guidance   advisory text for a manual step the caller should print
     *                                               (e.g. a route snippet to hand-add, or a reminder to register a
     *                                               freshly generated plugin in `config/plugins.php`) — never a
     *                                               substitute for a file in `$files`, only ever additional
     */
    public function __construct(
        public readonly array $files,
        public readonly ?string $verifyKind = null,
        public readonly ?string $verifyTarget = null,
        public readonly ?Flavor $flavor = null,
        public readonly ?string $guidance = null,
    ) {
    }
}
