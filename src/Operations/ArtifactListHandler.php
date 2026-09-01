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

use Milpa\DevTools\Support\ComposerAutoload;
use Milpa\DevTools\Support\DeclarationScanner;
use Milpa\DevTools\Support\RootResolver;

/**
 * Lists class, interface, and enum declarations in the app's plugins without loading their files.
 *
 * The operation returns declaration metadata only. It reads declarations through the shared
 * {@see DeclarationScanner} — tokens, not `require` — so top-level code and static initializers
 * never run merely because an agent asked what exists.
 */
final class ArtifactListHandler
{
    public function __construct(private readonly RootResolver $roots = new RootResolver())
    {
    }

    /**
     * Returns the artifacts declared by one plugin or by every plugin in the app.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, artifacts: list<array{name: string, fqcn: string, plugin: string, kind: string, path: string}>, error?: string}
     */
    public function handle(array $input): array
    {
        $plugin = \is_string($input['plugin'] ?? null) ? trim($input['plugin']) : '';
        if ($plugin !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $plugin) !== 1) {
            return $this->failure('plugin must be one identifier: letters, numbers, and underscores only');
        }

        $root = $this->roots->resolve();
        [, $appDir] = ComposerAutoload::primaryNamespace($root) ?? ['App', 'src'];
        $pluginRoots = [
            $root . '/' . trim($appDir, '/') . '/Plugins',
            $root . '/plugins',
        ];

        if ($plugin !== '') {
            $pluginDirs = [];
            foreach ($pluginRoots as $pluginRoot) {
                $candidate = $pluginRoot . '/' . $plugin;
                if (is_dir($candidate)) {
                    $pluginDirs[] = $candidate;
                }
            }
            if ($pluginDirs === []) {
                return $this->failure("no plugin «{$plugin}» under the runtime or legacy plugin trees");
            }
        } else {
            $pluginDirs = [];
            foreach ($pluginRoots as $pluginRoot) {
                array_push($pluginDirs, ...array_values(array_filter(glob($pluginRoot . '/*') ?: [], 'is_dir')));
            }
            sort($pluginDirs);
        }

        $artifacts = [];
        foreach ($pluginDirs as $pluginDir) {
            $pluginName = basename($pluginDir);
            $files = [];
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($pluginDir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
            sort($files);

            foreach ($files as $file) {
                foreach (DeclarationScanner::scan($file) as $declaration) {
                    $artifacts[] = [
                        'name' => $declaration['name'],
                        'fqcn' => $declaration['fqcn'],
                        'plugin' => $pluginName,
                        'kind' => $declaration['kind'],
                        'path' => $this->relativePath($file, $root),
                    ];
                }
            }
        }

        usort($artifacts, static fn (array $left, array $right): int => $left['fqcn'] <=> $right['fqcn']);
        if ($artifacts === []) {
            return $this->failure($plugin !== ''
                ? "no artifact declarations in plugin «{$plugin}»"
                : 'no plugin artifact declarations found');
        }

        return ['ok' => true, 'artifacts' => $artifacts];
    }

    /** Returns a stable path relative to the host app root. */
    private function relativePath(string $path, string $root): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';

        return str_starts_with($path, $prefix) ? substr($path, \strlen($prefix)) : $path;
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
