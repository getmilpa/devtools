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

/** A deterministic artifact generator (one per `coa:make <what>`). */
interface GeneratorInterface
{
    /** The `<what>` token this generator answers to, e.g. 'entity'. */
    public function name(): string;

    /** Renders the artifact for `$context` and returns the planned file(s) plus its verify target. */
    public function generate(GenerationContext $context): GenerationResult;
}
