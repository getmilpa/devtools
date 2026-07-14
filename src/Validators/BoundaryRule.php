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

/**
 * One architectural-boundary rule for {@see BoundaryValidator}: scan `$dir` (relative to the root
 * passed to the validator) and fail if any non-comment PHP line references one of the `$forbidden`
 * namespace prefixes. `$whitelist` exempts specific relative file paths inside `$dir`; files under a
 * `Tests/` subdirectory are always exempt (a fixture may legitimately reference the forbidden side).
 *
 * The RULES THEMSELVES are host-specific policy — e.g. "the core package stays framework-agnostic" —
 * not something this package can know. This monorepo's rules live in `scripts/library/boundary-rules.php`
 * (the single source both `coa:doctor` and the `validate-boundaries.php` CLI shim read from).
 */
final class BoundaryRule
{
    /**
     * @param list<string> $forbidden
     * @param list<string> $whitelist
     */
    public function __construct(
        public readonly string $label,
        public readonly string $dir,
        public readonly array $forbidden,
        public readonly array $whitelist = [],
    ) {
    }
}
