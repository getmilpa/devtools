<?php

declare(strict_types=1);

namespace Milpa\DevTools\Validators;

/**
 * Autoloads each plugin manifest's declared providers and asserts they are real:
 *   - `capabilities.provides[]` (typed records): the `interface` MUST autoload, the `service` MUST
 *     autoload, and the service MUST implement/extend the interface;
 *   - `contracts.provides[]` (legacy bare FQCNs): the declared interface MUST autoload.
 *
 * Requires the host's Composer autoloader to already be active (it reflects real, loaded types), so
 * an unresolved FQCN surfaces as a violation rather than a fatal.
 *
 * Ported 1:1 from `scripts/library/validate-provider-implements.php` (B5 / T013).
 */
final class ProviderImplementsValidator
{
    /**
     * Autoloads and checks every declared provider across `$manifestFiles`.
     *
     * @param list<string> $manifestFiles
     */
    public function validate(array $manifestFiles): ProviderImplementsResult
    {
        $typeExists = static fn (string $fqcn): bool => interface_exists($fqcn) || class_exists($fqcn) || trait_exists($fqcn);

        $violations = [];
        $checked = 0;

        foreach ($manifestFiles as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $manifest = json_decode($raw, true);
            if (!is_array($manifest)) {
                $violations[] = "{$file}: invalid JSON";
                continue;
            }

            $records = $manifest['capabilities']['provides'] ?? [];
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!is_array($record)) {
                        continue;
                    }
                    $interface = is_string($record['interface'] ?? null) ? ltrim($record['interface'], '\\') : null;
                    $service = is_string($record['service'] ?? null) ? ltrim($record['service'], '\\') : null;
                    if ($interface === null || $service === null) {
                        continue;
                    }

                    $checked++;
                    if (!$typeExists($interface)) {
                        $violations[] = "{$file}: interface does not autoload — {$interface}";
                        continue;
                    }
                    if (!class_exists($service)) {
                        $violations[] = "{$file}: service does not autoload — {$service}";
                        continue;
                    }
                    if (!is_a($service, $interface, true)) {
                        $violations[] = "{$file}: {$service} does not implement {$interface}";
                    }
                }
            }

            $legacy = $manifest['contracts']['provides'] ?? [];
            if (is_array($legacy)) {
                foreach ($legacy as $fqcn) {
                    if (!is_string($fqcn)) {
                        continue;
                    }
                    $checked++;
                    if (!$typeExists(ltrim($fqcn, '\\'))) {
                        $violations[] = "{$file}: declared provider interface does not autoload — {$fqcn}";
                    }
                }
            }
        }

        return new ProviderImplementsResult($checked, $violations);
    }
}
