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

use Milpa\Services\CapabilityMatcher;

/**
 * Validates the capability graph across a set of plugin manifests: every hard `requires` must be
 * satisfied by some plugin's `provides`, and the plugin dependency graph must be acyclic. Unprovided
 * `suggests` are reported as a degradation path, never a failure.
 *
 * Reads both capability formats: the typed `capabilities.*` records and the legacy bare-FQCN
 * `contracts.*` arrays. Matching is NOT decided here — it comes from {@see CapabilityMatcher}, the
 * single criterion the resolver and the pre-boot check also consume. This class used to match by
 * interface FQCN alone, which made it strictly weaker than the engine: it ignored a record
 * identified only by `id`, and it ignored `oneOf`, so it reported violations the engine never had
 * (`settlement-q-p17.md`).
 *
 * Ported 1:1 from `scripts/library/validate-capability-graph.php` (B5 / T014) — this class takes the
 * already-resolved list of manifest file paths (the caller globs; kept filesystem-glob-decoupled so
 * it is trivially testable against a synthetic fixture set).
 */
final class CapabilityGraphValidator
{
    /**
     * @param CapabilityMatcher $matcher The single identity criterion, shared with the resolver
     *                                   and the pre-boot check.
     */
    public function __construct(private readonly CapabilityMatcher $matcher = new CapabilityMatcher())
    {
    }

    /**
     * Validates the capability graph across every manifest in `$manifestFiles`.
     *
     * @param list<string> $manifestFiles
     */
    public function validate(array $manifestFiles): CapabilityGraphResult
    {
        /**
         * The raw capability entries one manifest declares for a kind, in both formats. They stay
         * RAW on purpose: reducing a record to its `interface` here is what made this validator
         * strictly weaker than the resolver — a record identified only by `id`, or a requirement
         * with `oneOf` alternatives, became invisible and produced a violation the engine never had.
         *
         * @return list<string|array<string, mixed>>
         */
        $entriesFor = static function (array $manifest, string $kind): array {
            $out = [];
            foreach ([$manifest['capabilities'][$kind] ?? null, $manifest['contracts'][$kind] ?? null] as $source) {
                foreach (is_array($source) ? $source : [] as $entry) {
                    if (is_string($entry) || is_array($entry)) {
                        $out[] = $entry;
                    }
                }
            }

            return $out;
        };

        /** @var array<string, array{provides: list<string|array<string, mixed>>, requires: list<string|array<string, mixed>>, suggests: list<string|array<string, mixed>>, deps: list<string>}> $plugins */
        $plugins = [];
        /** @var array<string, true> $provided */
        $provided = [];

        foreach ($manifestFiles as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $manifest = json_decode($raw, true);
            if (!is_array($manifest)) {
                continue;
            }

            $name = is_string($manifest['name'] ?? null) ? $manifest['name'] : $file;
            $provides = $entriesFor($manifest, 'provides');
            $deps = [];
            if (isset($manifest['dependencies']['plugins']) && is_array($manifest['dependencies']['plugins'])) {
                foreach (array_keys($manifest['dependencies']['plugins']) as $dep) {
                    $deps[] = (string) $dep;
                }
            }

            $plugins[$name] = [
                'provides' => $provides,
                'requires' => $entriesFor($manifest, 'requires'),
                'suggests' => $entriesFor($manifest, 'suggests'),
                'deps' => $deps,
            ];

            foreach ($provides as $entry) {
                foreach ($this->matcher->identitiesOffered($entry) as $identity) {
                    $provided[$identity] = true;
                }
            }
        }

        $violations = [];
        $degradations = [];

        foreach ($plugins as $name => $plugin) {
            foreach ($plugin['requires'] as $entry) {
                if (!$this->isProvided($entry, $provided)) {
                    $violations[] = "unmet require: '{$name}' needs '{$this->label($entry)}' but no plugin provides it";
                }
            }
            foreach ($plugin['suggests'] as $entry) {
                if (!$this->isProvided($entry, $provided)) {
                    $degradations[] = "'{$name}' suggests '{$this->label($entry)}' (absent → runs degraded)";
                }
            }
        }

        $cycle = $this->findCycle($plugins);
        if ($cycle !== null) {
            $violations[] = "dependency cycle: {$cycle}";
        }

        return new CapabilityGraphResult(count($plugins), $violations, $degradations);
    }

    /**
     * Whether some plugin provides what one `requires`/`suggests` entry asks for — counting `oneOf`
     * alternatives, which the engine counts and this validator used to ignore.
     *
     * @param string|array<string, mixed> $entry
     * @param array<string, true>         $provided
     */
    private function isProvided(string|array $entry, array $provided): bool
    {
        $accepted = $this->matcher->identitiesAccepted($entry);

        // An entry with no readable identity is not a violation: teaching a malformed record is
        // PluginManifestValidator's job, and reporting it twice as two different failures would
        // send the reader to fix the wrong thing.
        return $accepted === [] || array_intersect($accepted, array_keys($provided)) !== [];
    }

    /**
     * How an entry is named in a message: as it was written, not as it was canonicalized.
     *
     * @param string|array<string, mixed> $entry
     */
    private function label(string|array $entry): string
    {
        if (is_string($entry)) {
            return $entry;
        }

        foreach (['id', 'interface'] as $key) {
            $value = $entry[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '(sin identidad)';
    }

    /**
     * @param array<string, array{deps: list<string>}> $plugins
     */
    private function findCycle(array $plugins): ?string
    {
        $state = []; // 0=unvisited, 1=in-progress, 2=done
        $cycle = null;

        $visit = static function (string $node, array $path) use (&$visit, &$state, &$cycle, $plugins): void {
            if ($cycle !== null) {
                return;
            }
            $state[$node] = 1;
            $path[] = $node;
            foreach ($plugins[$node]['deps'] ?? [] as $dep) {
                if (!isset($plugins[$dep])) {
                    continue;
                }
                if (($state[$dep] ?? 0) === 1) {
                    $start = array_search($dep, $path, true);
                    $cycle = implode(' → ', array_slice($path, $start === false ? 0 : $start)) . " → {$dep}";

                    return;
                }
                if (($state[$dep] ?? 0) === 0) {
                    $visit($dep, $path);
                }
            }
            $state[$node] = 2;
        };

        foreach (array_keys($plugins) as $node) {
            if (($state[$node] ?? 0) === 0) {
                $visit($node, []);
            }
        }

        return $cycle;
    }
}
