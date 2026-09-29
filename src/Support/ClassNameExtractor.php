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
        $source = is_file($filePath) ? file_get_contents($filePath) : false;
        if ($source === false) {
            return null;
        }

        $namespace = '';
        if (preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $source, $m) === 1) {
            $namespace = $m[1];
        }

        // Modifiers first: `make` writes `final class`, and an extractor that only read a bare `class` said «no class»
        // about every file this package generates.
        if (preg_match('/^(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $source, $m) !== 1) {
            return null;
        }
        $class = $m[1];

        /** @var class-string */
        return $namespace !== '' ? $namespace . '\\' . $class : $class;
    }
}
