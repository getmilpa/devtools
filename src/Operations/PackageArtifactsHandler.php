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

namespace Milpa\DevTools\Operations;

use Milpa\DevTools\Support\DeclarationScanner;
use Milpa\DevTools\Support\RootResolver;

/**
 * `package:artifacts` — lists the classes, interfaces, and enums one installed package declares
 * through its own autoload roots, so «what does milpa/data ship» is a question with an answer
 * instead of a guess.
 *
 * The package directory resolves via `vendor/<package>/composer.json` and its `autoload.psr-4`
 * roots — the package's own declaration of what it autoloads, present for every Composer install,
 * optimized or not. Files are inventoried through the token scanner: nothing is required or
 * executed by asking what a package contains.
 *
 * Not found is `ok:false` with a reason, never an exception (same contract as {@see ValidateHandler}).
 */
final class PackageArtifactsHandler
{
    public function __construct(private readonly RootResolver $roots = new RootResolver())
    {
    }

    /**
     * Lists the artifacts the package's PSR-4 autoload roots declare, in the same row shape
     * `contract:search` answers with.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, artifacts: list<array{fqcn: string, kind: string, source: string, package: string}>, error?: string}
     */
    public function handle(array $input): array
    {
        $package = \is_string($input['package'] ?? null) ? trim($input['package']) : '';
        if ($package === '') {
            return $this->failure('name the package: package:artifacts needs `package` (e.g. «milpa/data»)');
        }
        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$#', $package) !== 1) {
            return $this->failure('package must look like «vendor/name» — no paths');
        }

        $root = $this->roots->resolve();
        $packageDir = $root . '/vendor/' . $package;
        if (! is_file($packageDir . '/composer.json')) {
            return $this->failure("«{$package}» is not installed under vendor/ — check the name, or run composer install");
        }

        $psr4 = $this->psr4Roots($packageDir);
        if ($psr4 === []) {
            return $this->failure("«{$package}» declares no PSR-4 autoload roots — nothing to enumerate");
        }

        $artifacts = [];
        foreach ($psr4 as $relative) {
            $base = realpath($packageDir . '/' . $relative);
            if ($base === false || ! is_dir($base)) {
                continue;
            }
            foreach ($this->phpFiles($base) as $file) {
                foreach (DeclarationScanner::scan($file) as $declaration) {
                    $artifacts[$declaration['fqcn']] ??= [
                        'fqcn' => $declaration['fqcn'],
                        'kind' => $declaration['kind'],
                        'source' => 'vendor',
                        'package' => $package,
                    ];
                }
            }
        }

        if ($artifacts === []) {
            return $this->failure("«{$package}» autoload roots declare no classes, interfaces, or enums");
        }
        ksort($artifacts);

        return ['ok' => true, 'artifacts' => array_values($artifacts)];
    }

    /**
     * The package's own `autoload.psr-4` root directories, flattened — a prefix may declare one
     * directory or a list, and the prefix itself is not needed because the scanner reads each
     * file's true namespace.
     *
     * @return list<string>
     */
    private function psr4Roots(string $packageDir): array
    {
        $contents = file_get_contents($packageDir . '/composer.json');
        if ($contents === false) {
            return [];
        }
        $decoded = json_decode($contents, true);
        $psr4 = \is_array($decoded) ? ($decoded['autoload']['psr-4'] ?? null) : null;
        if (! \is_array($psr4)) {
            return [];
        }

        $roots = [];
        foreach ($psr4 as $dirs) {
            foreach (\is_array($dirs) ? $dirs : [$dirs] as $dir) {
                if (\is_string($dir)) {
                    $roots[] = trim($dir, '/');
                }
            }
        }

        return $roots;
    }

    /**
     * Every PHP file under the directory, in stable order.
     *
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Represents a lookup failure as data instead of throwing through every projection.
     *
     * @return array{ok: false, artifacts: list<never>, error: string}
     */
    private function failure(string $error): array
    {
        return ['ok' => false, 'artifacts' => [], 'error' => $error];
    }
}
