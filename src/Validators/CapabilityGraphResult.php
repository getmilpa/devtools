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
