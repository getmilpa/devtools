<?php

declare(strict_types=1);

namespace Milpa\DevTools\Validators;

/**
 * Validates the capability graph across a set of plugin manifests: every hard `requires` must be
 * satisfied by some plugin's `provides`, and the plugin dependency graph must be acyclic. Unprovided
 * `suggests` are reported as a degradation path, never a failure.
 *
 * Reads both capability formats: the typed `capabilities.*` records and the legacy bare-FQCN
 * `contracts.*` arrays; matching is by interface FQCN.
 *
 * Ported 1:1 from `scripts/library/validate-capability-graph.php` (B5 / T014) — this class takes the
 * already-resolved list of manifest file paths (the caller globs; kept filesystem-glob-decoupled so
 * it is trivially testable against a synthetic fixture set).
 */
final class CapabilityGraphValidator
{
    /**
     * Validates the capability graph across every manifest in `$manifestFiles`.
     *
     * @param list<string> $manifestFiles
     */
    public function validate(array $manifestFiles): CapabilityGraphResult
    {
        $norm = static fn (string $fqcn): string => ltrim($fqcn, '\\');

        $interfacesFor = static function (array $manifest, string $kind) use ($norm): array {
            $out = [];
            if (isset($manifest['capabilities'][$kind]) && is_array($manifest['capabilities'][$kind])) {
                foreach ($manifest['capabilities'][$kind] as $record) {
                    if (is_array($record) && isset($record['interface']) && is_string($record['interface'])) {
                        $out[] = $norm($record['interface']);
                    }
                }
            }
            if (isset($manifest['contracts'][$kind]) && is_array($manifest['contracts'][$kind])) {
                foreach ($manifest['contracts'][$kind] as $fqcn) {
                    if (is_string($fqcn)) {
                        $out[] = $norm($fqcn);
                    }
                }
            }

            return $out;
        };

        /** @var array<string, array{provides: list<string>, requires: list<string>, suggests: list<string>, deps: list<string>}> $plugins */
        $plugins = [];
        /** @var array<string, list<string>> $providedBy */
        $providedBy = [];

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
            $provides = $interfacesFor($manifest, 'provides');
            $deps = [];
            if (isset($manifest['dependencies']['plugins']) && is_array($manifest['dependencies']['plugins'])) {
                foreach (array_keys($manifest['dependencies']['plugins']) as $dep) {
                    $deps[] = (string) $dep;
                }
            }

            $plugins[$name] = [
                'provides' => $provides,
                'requires' => $interfacesFor($manifest, 'requires'),
                'suggests' => $interfacesFor($manifest, 'suggests'),
                'deps' => $deps,
            ];

            foreach ($provides as $interface) {
                $providedBy[$interface][] = $name;
            }
        }

        $violations = [];
        $degradations = [];

        foreach ($plugins as $name => $plugin) {
            foreach ($plugin['requires'] as $interface) {
                if (!isset($providedBy[$interface])) {
                    $violations[] = "unmet require: '{$name}' needs '{$interface}' but no plugin provides it";
                }
            }
            foreach ($plugin['suggests'] as $interface) {
                if (!isset($providedBy[$interface])) {
                    $degradations[] = "'{$name}' suggests '{$interface}' (absent → runs degraded)";
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
