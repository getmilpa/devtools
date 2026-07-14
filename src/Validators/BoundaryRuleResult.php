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

/** The outcome of checking one {@see BoundaryRule}: which file:line references tripped it, if any. */
final class BoundaryRuleResult
{
    /** @param list<string> $violations each formatted "relative/path.php:LINE  <offending line>" */
    public function __construct(
        public readonly string $label,
        public readonly string $dir,
        public readonly bool $skipped,
        public readonly array $violations,
    ) {
    }

    /** True when the rule found no violations. */
    public function ok(): bool
    {
        return $this->violations === [];
    }
}
