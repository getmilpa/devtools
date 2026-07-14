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
 * Reads the parts of an app's own `composer.json` the Make layer needs to place generated files
 * correctly: its PSR-4 autoload map, used by {@see \Milpa\DevTools\Make\ConventionDetector} to test
 * whether a given FQCN resolves to a real file under the app root, and by the runtime
 * `ControllerGenerator` to find the app's primary namespace/source directory. Pure filesystem + JSON
 * parsing — no autoloading, no `Composer\InstalledVersions` (that is {@see RootResolver}'s job; this
 * class reads a SPECIFIC app root's manifest, not "where am I installed").
 */
final class ComposerAutoload
{
    /**
     * The `autoload.psr-4` map declared in `$root/composer.json`, normalized to `[prefix => dir]`
     * with the directory's trailing slash trimmed; `[]` when the file is missing, unparsable, or
     * declares no PSR-4 autoloading.
     *
     * @return array<string, string>
     */
    public static function psr4(string $root): array
    {
        $psr4 = self::read($root)['autoload']['psr-4'] ?? null;
        if (!is_array($psr4)) {
            return [];
        }

        $map = [];
        foreach ($psr4 as $prefix => $dir) {
            if (is_string($prefix) && is_string($dir)) {
                $map[$prefix] = rtrim($dir, '/');
            }
        }

        return $map;
    }

    /**
     * The app's primary PSR-4 namespace + source directory — the first entry in its
     * `autoload.psr-4` map, e.g. `['App', 'src']` for `{"App\\": "src/"}`. `null` when
     * `$root/composer.json` declares no PSR-4 autoloading at all.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function primaryNamespace(string $root): ?array
    {
        $psr4 = self::psr4($root);
        if ($psr4 === []) {
            return null;
        }

        $prefix = array_key_first($psr4);

        return [rtrim($prefix, '\\'), $psr4[$prefix]];
    }

    /** @return array<string, mixed> */
    private static function read(string $root): array
    {
        $path = $root . '/composer.json';
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
