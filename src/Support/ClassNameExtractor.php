<?php

declare(strict_types=1);

namespace Milpa\DevTools\Support;

/**
 * Extracts a FQCN from a PHP source file by regexing its `namespace`/`class` declarations — no
 * autoloading, no tokenizer, just enough to let the `coa:verify-*` CLI entry points accept either a
 * FQCN or a file path. Shared by the `verify-controller.php` / `verify-entity.php` CLI shims so the
 * "accept a path" convenience does not get re-implemented twice.
 */
final class ClassNameExtractor
{
    /**
     * Extracts the FQCN declared in `$filePath`; `null` when no `class` declaration is found.
     *
     * @return class-string|null
     */
    public static function fromFile(string $filePath): ?string
    {
        $source = file_get_contents($filePath);
        if ($source === false) {
            return null;
        }

        $namespace = '';
        if (preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $source, $m) === 1) {
            $namespace = $m[1];
        }

        if (preg_match('/^class\s+(\w+)/m', $source, $m) !== 1) {
            return null;
        }
        $class = $m[1];

        /** @var class-string */
        return $namespace !== '' ? $namespace . '\\' . $class : $class;
    }
}
