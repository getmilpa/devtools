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
 * `contract:search` — finds class, interface, and enum NAMES across the app's plugins and its
 * installed vendor code, so an installed capability exists for the agent BEFORE it guesses an API.
 *
 * The debt this pays (measured on a live cattle run): installed packages existed for execution but
 * not epistemologically — `artifact:contract` reached only the app's own plugins, so the agent
 * deduced vendor APIs, planned probes, and weighed static-analysis errors as an introspection API.
 * Search answers the question that comes before the contract: what is the right name to ask for.
 *
 * Candidates come from the app's plugin trees, from `vendor/composer/autoload_psr4.php` (prefixes
 * mapped to directories, PHP files enumerated), and from `autoload_classmap.php` when present — an
 * optimized classmap is never required. Matching is on file and declaration names, and kinds come
 * from the token scanner: no candidate file is ever required or executed by searching it.
 */
final class ContractSearchHandler
{
    /** Answers stay small on purpose: a search that returns everything is a dump, not an answer. */
    private const MAX_MATCHES = 25;

    public function __construct(private readonly RootResolver $roots = new RootResolver())
    {
    }

    /**
     * Searches declared type names and returns up to 25 matches, each with its FQCN, kind, and
     * provenance (`app`, or `vendor` plus the owning package). No match is `ok:false` with a reason,
     * never an exception.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, matches: list<array{fqcn: string, kind: string, source: string, package?: string}>, truncated?: bool, error?: string}
     */
    public function handle(array $input): array
    {
        $q = \is_string($input['q'] ?? null) ? trim($input['q']) : '';
        if ($q === '') {
            return ['ok' => false, 'matches' => [], 'error' => 'name a query: contract:search needs `q`'];
        }
        $package = \is_string($input['package'] ?? null) ? trim($input['package']) : '';
        if ($package !== '' && preg_match('#^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$#', $package) !== 1) {
            return ['ok' => false, 'matches' => [], 'error' => 'package must look like «vendor/name» — no paths'];
        }

        // A backslash in the query means «match the full name»; the last segment still prefilters
        // file candidates, because under PSR-4 the file basename IS the short class name.
        $shortNeedle = strtolower(str_contains($q, '\\') ? substr((string) strrchr('\\' . $q, '\\'), 1) : $q);
        $fqcnNeedle = str_contains($q, '\\') ? strtolower(trim($q, '\\')) : null;

        $root = $this->roots->resolve();
        $candidates = $package === '' ? $this->appCandidates($root, $shortNeedle) : [];
        foreach ($this->vendorCandidates($root, $shortNeedle, $package) as $file => $meta) {
            $candidates[$file] ??= $meta;
        }
        ksort($candidates);

        // Only now do the surviving candidates get OPENED (tokenized, never required): the scan
        // confirms what each file actually declares and yields the true namespace for the FQCN.
        $matches = [];
        $truncated = false;
        foreach ($candidates as $file => $meta) {
            foreach (DeclarationScanner::scan((string) $file) as $declaration) {
                if (! str_contains(strtolower($declaration['name']), $shortNeedle)) {
                    continue;
                }
                if ($fqcnNeedle !== null && ! str_contains(strtolower($declaration['fqcn']), $fqcnNeedle)) {
                    continue;
                }
                if (isset($matches[$declaration['fqcn']])) {
                    continue;
                }
                if (\count($matches) >= self::MAX_MATCHES) {
                    $truncated = true;
                    break 2;
                }
                $row = ['fqcn' => $declaration['fqcn'], 'kind' => $declaration['kind'], 'source' => $meta['source']];
                if ($meta['package'] !== null) {
                    $row['package'] = $meta['package'];
                }
                $matches[$declaration['fqcn']] = $row;
            }
        }

        if ($matches === []) {
            return ['ok' => false, 'matches' => [], 'error' => "no class, interface, or enum name matches «{$q}»"];
        }
        ksort($matches);

        return ['ok' => true, 'matches' => array_values($matches), 'truncated' => $truncated];
    }

    /**
     * Candidate files under the app's own plugin trees whose basename matches the query.
     *
     * @return array<string, array{source: string, package: string|null}>
     */
    private function appCandidates(string $root, string $shortNeedle): array
    {
        [, $appDir] = ComposerAutoload::primaryNamespace($root) ?? ['App', 'src'];
        $candidates = [];
        foreach ([$root . '/' . trim($appDir, '/') . '/Plugins', $root . '/plugins'] as $pluginRoot) {
            foreach ($this->matchingFiles($pluginRoot, $shortNeedle) as $file) {
                $candidates[$file] = ['source' => 'app', 'package' => null];
            }
        }

        return $candidates;
    }

    /**
     * Candidate files under `vendor/`, from the PSR-4 map plus the classmap when present.
     *
     * @return array<string, array{source: string, package: string|null}>
     */
    private function vendorCandidates(string $root, string $shortNeedle, string $package): array
    {
        $vendor = realpath($root . '/vendor');
        if ($vendor === false) {
            return [];
        }

        $candidates = [];
        foreach ($this->autoloadMap($root, 'autoload_psr4.php') as $dirs) {
            foreach (\is_array($dirs) ? $dirs : [$dirs] as $dir) {
                $real = \is_string($dir) ? realpath($dir) : false;
                if ($real === false || ! str_starts_with($real, $vendor . '/')) {
                    continue;
                }
                $owner = $this->packageOf($vendor, $real);
                if ($package !== '' && $owner !== $package) {
                    continue;
                }
                foreach ($this->matchingFiles($real, $shortNeedle) as $file) {
                    $candidates[$file] ??= ['source' => 'vendor', 'package' => $owner];
                }
            }
        }

        foreach ($this->autoloadMap($root, 'autoload_classmap.php') as $fqcn => $path) {
            $short = substr((string) strrchr('\\' . (string) $fqcn, '\\'), 1);
            if (! str_contains(strtolower($short), $shortNeedle)) {
                continue;
            }
            $real = \is_string($path) ? realpath($path) : false;
            if ($real === false || ! str_starts_with($real, $vendor . '/')) {
                continue;
            }
            $owner = $this->packageOf($vendor, $real);
            if ($package !== '' && $owner !== $package) {
                continue;
            }
            $candidates[$real] ??= ['source' => 'vendor', 'package' => $owner];
        }

        return $candidates;
    }

    /**
     * One of Composer's generated `vendor/composer/autoload_*.php` maps, or `[]` when absent — the
     * generated map is trusted machinery, the only PHP this operation ever includes.
     *
     * @return array<array-key, mixed>
     */
    private function autoloadMap(string $root, string $file): array
    {
        $path = $root . '/vendor/composer/' . $file;
        if (! is_file($path)) {
            return [];
        }
        $map = include $path;

        return \is_array($map) ? $map : [];
    }

    /** The owning `vendor/name` package of a path under vendor/, or `null` when it has none. */
    private function packageOf(string $vendor, string $path): ?string
    {
        $segments = explode('/', trim(substr($path, \strlen($vendor)), '/'));

        return \count($segments) >= 3 ? $segments[0] . '/' . $segments[1] : null;
    }

    /**
     * PHP files under a directory whose basename contains the needle — the name-only prefilter that
     * keeps the search from opening files that could not match.
     *
     * @return list<string>
     */
    private function matchingFiles(string $dir, string $shortNeedle): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && str_contains(strtolower($file->getBasename('.php')), $shortNeedle)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
