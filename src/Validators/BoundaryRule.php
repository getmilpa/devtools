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
 * namespace prefixes, or matches one of the `$forbiddenPatterns` regexes. `$whitelist` exempts
 * specific relative file paths inside `$dir`; files under a `Tests/` subdirectory are always exempt
 * (a fixture may legitimately reference the forbidden side).
 *
 * `$forbidden` (substring needles) vs `$forbiddenPatterns` (PCRE regexes) — when to use which:
 * a plain substring needle is enough for most bans, but some needles must be ANCHORED (end in `;` or
 * `()`, or be a namespace prefix ending in a literal `\`) purely to avoid false-positiving on a
 * legitimate sibling namespace (e.g. banning the extinct class `Milpa\Runtime` without also banning
 * the live package namespace `Milpa\Runtime\*` forces the needle `use Milpa\Runtime;`, exact). An
 * anchored needle is exactly what a `use Forbidden\Name as Alias;` import evades: the aliased line
 * never contains the anchor text the needle demands. `$forbiddenPatterns` exists for precisely that
 * case — write a regex that recognizes the alias-import shape itself (`~use\s+Forbidden\\\\Name\s+as\s+~`)
 * instead of demanding one exact trailing character. Prefer a plain `$forbidden` substring whenever it
 * cannot be evaded by aliasing (e.g. a bare class/interface name, or a namespace prefix broad enough
 * that any realistic reference to a descendant still contains it); reach for `$forbiddenPatterns` only
 * for the anchored needles that alias-imports can dodge.
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
     * @param list<string> $forbiddenPatterns complete PCRE patterns (own delimiters included)
     */
    public function __construct(
        public readonly string $label,
        public readonly string $dir,
        public readonly array $forbidden,
        public readonly array $whitelist = [],
        public readonly array $forbiddenPatterns = [],
    ) {
    }
}
