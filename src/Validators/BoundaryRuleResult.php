<?php

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
