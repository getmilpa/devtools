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
 * A boundary drawn by which package owns a class, not by how the class is spelled.
 *
 * {@see BoundaryRule} bans namespace prefixes, which works whenever the forbidden side has a name of
 * its own. It cannot work when the boundary runs *through* a namespace — and in this family it does:
 * `Milpa\Live\Rendering\ComponentRendererRegistry` ships in `milpa/live`, the render-agnostic core a
 * plugin may depend on, while `Milpa\Live\Rendering\DashboardHtmlRenderer` ships in `milpa/live-web`,
 * one surface among several. Same prefix, opposite sides.
 *
 * The consequence is worse than a missing rule: the coupling does not *look* like coupling. Two
 * import lines, identical in shape, one fine and one binding a plugin to the web. A reviewer cannot
 * see it, and a text rule would need every surface class enumerated by hand — a list that goes stale
 * the first time a surface adds a class, and goes stale silently.
 *
 * So this rule names **packages**, and the validator resolves each referenced class to the file it
 * lives in, hence to its owner. What a plugin may use stays a question about architecture ("does
 * this belong to a surface?") instead of a question about strings.
 *
 * The rules themselves are host policy — which directories hold plugins, which packages are
 * surfaces. This package only knows how to enforce one.
 */
final class PackageBoundaryRule
{
    /**
     * @param string       $label             what this rule protects, in the words of whoever will read the failure
     * @param string       $dir               directory to scan, relative to the root given to the validator
     * @param list<string> $forbiddenPackages composer package names the scanned code must not reach into
     * @param list<string> $whitelist         relative paths inside `$dir` exempt from the rule
     * @param list<string> $watchedPrefixes   class prefixes this rule has an opinion about. Empty
     *                                        means all of them — strictest, and the right default
     *                                        for a rule that would otherwise narrow itself by
     *                                        accident. Naming `Milpa\\` keeps the finding about
     *                                        the family instead of about every vendor in the tree
     */
    public function __construct(
        public readonly string $label,
        public readonly string $dir,
        public readonly array $forbiddenPackages,
        public readonly array $whitelist = [],
        public readonly array $watchedPrefixes = [],
    ) {
    }
}
