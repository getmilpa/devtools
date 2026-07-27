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

namespace Milpa\DevTools\Validators;

/** The outcome of a {@see MetadataParityValidator} run for one plugin. */
final class MetadataParityResult
{
    /** @param list<string> $divergent Graph fields whose manifest value differs from the attribute. */
    public function __construct(
        public readonly string $manifestPath,
        public readonly array $divergent,
    ) {
    }

    /** True when every compared graph field matches. */
    public function ok(): bool
    {
        return $this->divergent === [];
    }
}
