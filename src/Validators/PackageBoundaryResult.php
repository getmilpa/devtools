<?php

/**
 * This file is part of Milpa DevTools — the developer tooling of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Validators;

/**
 * What one {@see PackageBoundaryRule} found.
 *
 * Violations and unresolved references are kept apart because they are different news. A violation
 * says the boundary was crossed; an unresolved class says the check could not see — an uninstalled
 * package, a stale autoloader, a typo. Merging them would let a rule that answered nothing read like
 * a rule that found nothing, and those are opposite states.
 */
final class PackageBoundaryResult
{
    /**
     * @param list<string> $violations references into a forbidden package, with the file that made them
     * @param list<string> $unresolved classes that could not be located, so ownership is unknown
     */
    public function __construct(
        public readonly string $label,
        public readonly array $violations,
        public readonly array $unresolved,
    ) {
    }

    /**
     * Clean only when the boundary held AND every reference could be checked.
     *
     * An unresolved reference fails the rule on purpose: a check that cannot see must not report
     * success, or the first broken autoloader turns the gate green forever.
     */
    public function ok(): bool
    {
        return $this->violations === [] && $this->unresolved === [];
    }
}
