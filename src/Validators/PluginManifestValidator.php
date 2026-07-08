<?php

declare(strict_types=1);

namespace Milpa\DevTools\Validators;

/**
 * Validates a Milpa plugin manifest (`milpa.json`) against the shape defined by
 * `schema/milpa-plugin.schema.json` (capability-spec.md §3). Hand-rolled (no JSON-schema library),
 * covering the rules that matter: required top-level fields, a composer-style `name`, a semver
 * `version`, and the shape of every capability record (canonical `capabilities.*`) or legacy FQCN
 * (`contracts.*`).
 *
 * Ported 1:1 from `scripts/library/validate-plugin-manifest.php` (B5 / T011).
 */
final class PluginManifestValidator
{
    private const SEMVER = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/';
    private const FQCN = '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/';

    /** Validates the `milpa.json` manifest at `$path` and returns every shape error found. */
    public function validate(string $path): ManifestValidationResult
    {
        return new ManifestValidationResult($path, $this->errors($path));
    }

    /** @return list<string> */
    private function errors(string $path): array
    {
        if (!is_file($path)) {
            return ["file not found: {$path}"];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return ["cannot read: {$path}"];
        }

        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['invalid JSON: ' . $e->getMessage()];
        }

        if (!is_array($data)) {
            return ['manifest root must be a JSON object'];
        }

        $errors = [];

        foreach (['name', 'version', 'type', 'namespace', 'entrypoint'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || trim($data[$field]) === '') {
                $errors[] = "missing or empty required field: {$field}";
            }
        }

        if (isset($data['name']) && is_string($data['name'])
            && preg_match('#^[a-z0-9]([a-z0-9._-]*)/[a-z0-9]([a-z0-9._-]*)$#', $data['name']) !== 1) {
            $errors[] = "name must be composer-style vendor/plugin (lowercase): '{$data['name']}'";
        }

        if (isset($data['version']) && is_string($data['version']) && preg_match(self::SEMVER, $data['version']) !== 1) {
            $errors[] = "version must be semver: '{$data['version']}'";
        }

        if (isset($data['capabilities'])) {
            if (!is_array($data['capabilities'])) {
                $errors[] = 'capabilities must be an object';
            } else {
                $errors = [...$errors, ...$this->capabilityErrors($data['capabilities'])];
            }
        }

        if (isset($data['contracts'])) {
            if (!is_array($data['contracts'])) {
                $errors[] = 'contracts must be an object';
            } else {
                $errors = [...$errors, ...$this->contractErrors($data['contracts'])];
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $capabilities
     *
     * @return list<string>
     */
    private function capabilityErrors(array $capabilities): array
    {
        $errors = [];
        $requiredByKind = [
            'provides' => ['id', 'interface', 'contractVersion', 'service'],
            'requires' => ['id', 'interface', 'constraint'],
            'suggests' => ['id', 'interface', 'constraint'],
        ];

        foreach ($requiredByKind as $kind => $requiredKeys) {
            if (!isset($capabilities[$kind])) {
                continue;
            }
            if (!is_array($capabilities[$kind])) {
                $errors[] = "capabilities.{$kind} must be an array";
                continue;
            }
            foreach ($capabilities[$kind] as $i => $record) {
                $at = "capabilities.{$kind}[{$i}]";
                if (!is_array($record)) {
                    $errors[] = "{$at} must be an object";
                    continue;
                }
                foreach ($requiredKeys as $key) {
                    if (!isset($record[$key]) || !is_string($record[$key]) || trim($record[$key]) === '') {
                        $errors[] = "{$at}: missing required key '{$key}'";
                    }
                }
                if (isset($record['interface']) && is_string($record['interface']) && preg_match(self::FQCN, $record['interface']) !== 1) {
                    $errors[] = "{$at}.interface is not a valid FQCN: '{$record['interface']}'";
                }
                if ($kind === 'provides') {
                    if (isset($record['service']) && is_string($record['service']) && preg_match(self::FQCN, $record['service']) !== 1) {
                        $errors[] = "{$at}.service is not a valid FQCN: '{$record['service']}'";
                    }
                    if (isset($record['contractVersion']) && is_string($record['contractVersion']) && preg_match(self::SEMVER, $record['contractVersion']) !== 1) {
                        $errors[] = "{$at}.contractVersion must be semver: '{$record['contractVersion']}'";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $contracts
     *
     * @return list<string>
     */
    private function contractErrors(array $contracts): array
    {
        $errors = [];
        foreach (['provides', 'requires', 'suggests'] as $kind) {
            if (!isset($contracts[$kind])) {
                continue;
            }
            if (!is_array($contracts[$kind])) {
                $errors[] = "contracts.{$kind} must be an array";
                continue;
            }
            foreach ($contracts[$kind] as $i => $fqcnEntry) {
                if (!is_string($fqcnEntry) || preg_match(self::FQCN, $fqcnEntry) !== 1) {
                    $errors[] = "contracts.{$kind}[{$i}] is not a valid FQCN string";
                }
            }
        }

        return $errors;
    }
}
