<?php

declare(strict_types=1);

namespace Milpa\DevTools\Validators;

/** The outcome of a {@see CapabilityGraphValidator} run. */
final class CapabilityGraphResult
{
    /**
     * @param list<string> $violations   unmet hard requires + dependency cycles (fatal)
     * @param list<string> $degradations unprovided `suggests` (informational, never fatal)
     */
    public function __construct(
        public readonly int $pluginCount,
        public readonly array $violations,
        public readonly array $degradations,
    ) {
    }

    /** True when there are no unmet requires and no dependency cycles. */
    public function ok(): bool
    {
        return $this->violations === [];
    }
}
