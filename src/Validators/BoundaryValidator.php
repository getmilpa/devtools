<?php

declare(strict_types=1);

namespace Milpa\DevTools\Validators;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A rules-based architectural-boundary gate: each {@see BoundaryRule} scans a directory and reports
 * every non-comment PHP line referencing one of its forbidden namespace prefixes. The ENGINE is
 * generic (any host, any rule set); the RULES are host policy — see {@see BoundaryRule}.
 *
 * Ported 1:1 from the monorepo's `scripts/library/validate-boundaries.php` (B5 / ADR-001 / D9).
 */
final class BoundaryValidator
{
    /**
     * Checks every rule in `$rules` against `$root` and returns the combined report.
     *
     * @param list<BoundaryRule> $rules
     */
    public function validate(array $rules, string $root): BoundaryReport
    {
        $results = [];
        foreach ($rules as $rule) {
            $results[] = $this->checkRule($rule, $root);
        }

        return new BoundaryReport($results);
    }

    private function checkRule(BoundaryRule $rule, string $root): BoundaryRuleResult
    {
        $dir = rtrim($root, '/') . '/' . $rule->dir;

        if (!is_dir($dir)) {
            return new BoundaryRuleResult($rule->label, $rule->dir, true, []);
        }

        $violations = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $rel = str_replace($dir . '/', '', $file->getPathname());
            if (str_starts_with($rel, 'Tests/') || in_array($rel, $rule->whitelist, true)) {
                continue;
            }

            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $i => $line) {
                $trimmed = ltrim($line);
                if ($trimmed === ''
                    || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '//')
                    || str_starts_with($trimmed, '#')
                    || str_starts_with($trimmed, '/*')
                ) {
                    continue;
                }

                foreach ($rule->forbidden as $needle) {
                    if (str_contains($line, $needle)) {
                        $violations[] = sprintf('%s:%d  %s', $rel, $i + 1, trim($line));
                        break;
                    }
                }
            }
        }

        return new BoundaryRuleResult($rule->label, $rule->dir, false, $violations);
    }
}
