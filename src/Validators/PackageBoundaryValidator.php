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
 * Enforces {@see PackageBoundaryRule} by asking where each referenced class actually lives.
 *
 * Reads the `use` statements of every scanned file, resolves each imported class to the file that
 * defines it, and reads the owning package out of that path. A class under
 * `vendor/milpa/live-web/src/...` belongs to `milpa/live-web` no matter what namespace it declares —
 * which is the whole point, since in this family the namespace is shared and the package is not.
 *
 * **A class that cannot be resolved is reported, never skipped.** Silence there would make the check
 * strongest exactly when it is least able to see: a typo, an uninstalled package or a stale
 * autoloader would empty the evidence and the rule would pass for having found nothing to object to.
 * Unresolved entries surface as their own finding so the reader knows the difference between "no
 * violations" and "no answers".
 */
final class PackageBoundaryValidator
{
    /** @var callable(string): ?string */
    private $locate;

    /**
     * @param (callable(string): ?string)|null $locate resolves a class name to the file defining it.
     *                                                 Defaults to reflection, which needs the class to
     *                                                 be autoloadable — true in any host whose vendor
     *                                                 tree is installed, and the seam every test uses
     *                                                 instead of building one
     */
    public function __construct(?callable $locate = null)
    {
        $this->locate = $locate ?? static function (string $class): ?string {
            if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
                return null;
            }

            $file = (new \ReflectionClass($class))->getFileName();

            return \is_string($file) ? $file : null;
        };
    }

    /**
     * Runs every rule against a tree and reports each one separately.
     *
     * One result per rule rather than a merged verdict: rules protect different boundaries, and a
     * single pass/fail would hide which one broke behind a number.
     *
     * @param list<PackageBoundaryRule> $rules
     *
     * @return list<PackageBoundaryResult>
     */
    public function validate(array $rules, string $root): array
    {
        $results = [];
        foreach ($rules as $rule) {
            $results[] = $this->checkRule($rule, $root);
        }

        return $results;
    }

    private function checkRule(PackageBoundaryRule $rule, string $root): PackageBoundaryResult
    {
        $dir = rtrim($root, '/') . '/' . ltrim($rule->dir, '/');
        if (!is_dir($dir)) {
            return new PackageBoundaryResult($rule->label, [], ["directory not found: {$rule->dir}"]);
        }

        $violations = [];
        $unresolved = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($dir) + 1);
            if ($this->isExempt($relative, $rule)) {
                continue;
            }
            // A fixture may legitimately reference the forbidden side — the same exemption
            // {@see BoundaryValidator} makes, for the same reason.
            if (str_contains($relative, '/Tests/') || str_starts_with($relative, 'Tests/')) {
                continue;
            }

            foreach ($this->importsOf((string) file_get_contents($file->getPathname())) as $class) {
                // Outside the watched prefixes, ownership is not this rule's question: Symfony,
                // Doctrine and the scanned code's own classes are out of scope by construction.
                // Saying so beats reporting them as unknown, which is how a real finding gets
                // buried under a thousand irrelevant ones.
                if (!$this->isWatched($class, $rule)) {
                    continue;
                }

                $owner = $this->ownerOf($class);

                if ($owner === null) {
                    $unresolved[] = "{$rule->dir}/{$relative} → {$class}";
                    continue;
                }

                if (\in_array($owner, $rule->forbiddenPackages, true)) {
                    $violations[] = "{$rule->dir}/{$relative} → {$class} (owned by {$owner})";
                }
            }
        }

        sort($violations);
        sort($unresolved);

        return new PackageBoundaryResult($rule->label, $violations, $unresolved);
    }

    /**
     * Whether the host has exempted this file.
     *
     * An entry ending in `/` exempts a directory, because the real exemption in this family is
     * structural: a package that OWNS a surface — the admin panel and its `Tui/`, `Http/`, `View/`
     * adapters — legitimately speaks it, while the same package's component code must not. Listing
     * every file inside such a directory would be a list that rots the moment a file is added, and
     * rots by growing silently permissive.
     */
    private function isExempt(string $relative, PackageBoundaryRule $rule): bool
    {
        foreach ($rule->whitelist as $entry) {
            if (str_ends_with($entry, '/')) {
                if (str_starts_with($relative, $entry) || str_contains($relative, '/' . $entry)) {
                    return true;
                }
                continue;
            }

            if ($relative === $entry) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every class this file imports.
     *
     * Only `use` statements: a fully-qualified reference written inline is legal and rare, and
     * pretending to catch it with a regex would claim a completeness this cannot have. What it does
     * catch is the aliased import — `use Foo\Bar as Baz` — which is precisely what defeats a
     * text-matching rule, because the alias erases the name the needle was looking for.
     *
     * @return list<string>
     */
    private function importsOf(string $source): array
    {
        // Only the header, cut at the first type declaration. `use` means two different things in
        // PHP: an import above the class, and a trait composition inside it. Reading the whole file
        // treated every `use SomeTrait;` as an import of an unqualified name that resolves to
        // nothing — 1186 of them on this monorepo, drowning eleven real findings in noise nobody
        // would read.
        $parts = preg_split('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s/mi', $source, 2);
        $header = \is_array($parts) ? $parts[0] : $source;

        preg_match_all('/^\s*use\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*(?:as\s+\w+\s*)?;/m', $header, $matches);

        /** @var list<string> $imports */
        $imports = array_values(array_unique($matches[1]));

        return $imports;
    }

    /**
     * Whether this rule has an opinion about who owns the class.
     *
     * No prefixes declared means every reference is watched — the strictest reading, and the right
     * default for a rule that would otherwise quietly narrow itself.
     */
    private function isWatched(string $class, PackageBoundaryRule $rule): bool
    {
        if ($rule->watchedPrefixes === []) {
            return true;
        }

        foreach ($rule->watchedPrefixes as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The composer package a class belongs to, read from where its file sits.
     *
     * Path-derived on purpose. The namespace cannot answer this — three packages in this family
     * declare the same root — and a manifest lookup would answer about the package that *declares*
     * a prefix rather than the one that ships the file.
     */
    private function ownerOf(string $class): ?string
    {
        $file = ($this->locate)($class);
        if ($file === null) {
            return null;
        }

        if (preg_match('~/vendor/([^/]+)/([^/]+)/~', $file, $m) === 1) {
            return $m[1] . '/' . $m[2];
        }

        // Not under vendor: a path repository, a monorepo package, or the host's own source. The
        // last segment before /src/ is the best available name, and saying so beats guessing.
        if (preg_match('~/([^/]+)/src/~', $file, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
