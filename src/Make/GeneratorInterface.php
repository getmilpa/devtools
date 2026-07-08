<?php

declare(strict_types=1);

namespace Milpa\DevTools\Make;

/** A deterministic artifact generator (one per `coa:make <what>`). */
interface GeneratorInterface
{
    /** The `<what>` token this generator answers to, e.g. 'entity'. */
    public function name(): string;

    /** Renders the artifact for `$context` and returns the planned file(s) plus its verify target. */
    public function generate(GenerationContext $context): GenerationResult;
}
