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

namespace Milpa\DevTools\Make\Generators;

use Milpa\DevTools\Make\PlannedFile;
use Milpa\DevTools\Support\ComposerAutoload;

/**
 * Materialises a backed string enum as a CONSEQUENCE of an entity field, never as a command.
 *
 * The golden rule (greenhouse decisions/0180 + Rod's «agent declares semantics, the house chooses
 * mechanics»): the agent declares the enum's cases — they are domain values, its to decide — inside
 * the entity's `enum:Class(a,b,c)` field. The house makes the REPRESENTATION. There is deliberately no
 * `make:enum` command: an enum is not a unit the agent thinks in, it is what an entity's field implies.
 * This is a pure helper for {@see EntityGenerator}, not a `GeneratorInterface` in the make registry.
 */
final class EnumGenerator
{
    /**
     * Plans the enum file for a plugin — the seam {@see EntityGenerator} calls so an entity's
     * `enum:Class(cases)` field is materialised, not left a dangling reference.
     *
     * @param list<string> $cases
     */
    public static function plannedFile(string $root, string $plugin, string $class, array $cases): PlannedFile
    {
        [$appNamespace, $appDir] = ComposerAutoload::primaryNamespace($root) ?? ['App', 'src'];
        $namespace = $appNamespace . '\\Plugins\\' . $plugin . '\\Enums';
        $path = $root . '/' . $appDir . '/Plugins/' . $plugin . '/Enums/' . $class . '.php';

        return new PlannedFile($path, self::render($namespace, $class, $cases));
    }

    /**
     * A backed string enum: the case NAME is a valid identifier derived from the declared case; the
     * backing VALUE is the declared case string verbatim, so persistence (`Enum::from($row[...])`)
     * round-trips the stored string. `alta` → `case alta = 'alta';`.
     *
     * @param list<string> $cases
     */
    public static function render(string $namespace, string $class, array $cases): string
    {
        $lines = '';
        foreach ($cases as $case) {
            $value = trim($case);
            if ($value === '') {
                continue;
            }
            $ident = self::identifier($value);
            $lines .= "    case {$ident} = '" . str_replace("'", "\\'", $value) . "';\n";
        }

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nenum {$class}: string\n{\n{$lines}}\n";
    }

    /** A declared case string as a valid PHP case identifier — non-word chars become `_`. */
    private static function identifier(string $case): string
    {
        $id = (string) preg_replace('/[^A-Za-z0-9_]/', '_', $case);
        if ($id === '' || is_numeric($id[0])) {
            $id = 'c_' . $id;
        }

        return $id;
    }
}
