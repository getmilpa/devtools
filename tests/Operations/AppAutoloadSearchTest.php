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

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Operations\ContractSearchHandler;
use Milpa\DevTools\Support\ComposerAutoload;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/** Runtime topology controls discovery without executing candidates or exporting outside files. */
final class AppAutoloadSearchTest extends TestCase
{
    private string $temporary;
    private string $root;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir() . '/milpa-autoload-search-' . bin2hex(random_bytes(6));
        $this->root = $this->temporary . '/app';
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->temporary);
    }

    public function testFindsAnOrdinaryRuntimeComponentWithoutExecutingIt(): void
    {
        $this->manifest(['App\\' => 'src/']);
        $sentinel = $this->root . '/executed';
        $this->write('src/Research/ResearchDesk.php', '<?php namespace App\\Research; file_put_contents('
            . var_export($sentinel, true) . ", 'executed'); final class ResearchDesk {}\n");
        foreach (['ResearchDesk', 'App\\Research\\ResearchDesk'] as $query) {
            $result = $this->search($query);
            self::assertTrue($result['ok']);
            self::assertSame([['fqcn' => 'App\\Research\\ResearchDesk', 'kind' => 'class', 'source' => 'app', 'path' => 'src/Research/ResearchDesk.php']], $result['matches']);
            self::assertFalse($result['truncated']);
        }
        self::assertFileDoesNotExist($sentinel);
    }

    public function testAllRuntimePrefixesAndDirectoryListsAreSearchedOnce(): void
    {
        $this->manifest(['App\\' => ['src/', 'modules/'], 'Acme\\' => 'domain/', 'Overlap\\' => 'src/Domain/']);
        $this->write('src/Domain/SearchAlpha.php', '<?php namespace App\\Domain; class SearchAlpha {}');
        $this->write('modules/SearchBeta.php', '<?php namespace App; interface SearchBeta {}');
        $this->write('domain/SearchGamma.php', '<?php namespace Acme; enum SearchGamma {}');
        $result = $this->search('Search');
        self::assertTrue($result['ok']);
        self::assertSame(['Acme\\SearchGamma', 'App\\Domain\\SearchAlpha', 'App\\SearchBeta'], array_column($result['matches'], 'fqcn'));
        self::assertSame(['enum', 'class', 'interface'], array_column($result['matches'], 'kind'));
        self::assertSame(['app', 'app', 'app'], array_column($result['matches'], 'source'));
    }

    public function testDevelopmentAndUndeclaredTreesDoNotEnlargeTheRuntimeRoots(): void
    {
        $this->manifest(['App\\' => 'src/'], ['App\\Tests\\' => 'tests/']);
        $this->write('src/SearchRuntime.php', '<?php namespace App; class SearchRuntime {}');
        $this->write('tests/SearchTestOnly.php', '<?php namespace App\\Tests; class SearchTestOnly {}');
        $this->write('scratch/SearchUnmapped.php', '<?php namespace Scratch; class SearchUnmapped {}');
        self::assertSame(['App\\SearchRuntime'], array_column($this->search('Search')['matches'], 'fqcn'));
    }

    public function testLegacyPluginRootsStillWorkWithoutAManifest(): void
    {
        $this->write('src/Plugins/SearchConventional.php', '<?php namespace App\\Plugins; class SearchConventional {}');
        $this->write('plugins/SearchLegacy.php', '<?php namespace Legacy; class SearchLegacy {}');
        $this->write('src/SearchUndeclared.php', '<?php namespace App; class SearchUndeclared {}');
        self::assertSame(['App\\Plugins\\SearchConventional', 'Legacy\\SearchLegacy'], array_column($this->search('Search')['matches'], 'fqcn'));
    }

    public function testVendorKeepsItsProvenanceEvenWhenTheAppDeclaresTheWholeRoot(): void
    {
        $this->manifest(['App\\' => '.', 'DuplicateVendor\\' => 'vendor/acme/library/src/']);
        $this->write('SearchLocal.php', '<?php namespace App; class SearchLocal {}');
        $this->write('vendor/acme/library/src/SearchVendor.php', '<?php namespace Acme; class SearchVendor {}');
        $this->write('vendor/composer/autoload_psr4.php', '<?php return '
            . var_export(['Acme\\' => [$this->root . '/vendor/acme/library/src']], true) . ';');
        $vendor = ['fqcn' => 'Acme\\SearchVendor', 'kind' => 'class', 'source' => 'vendor', 'path' => 'vendor/acme/library/src/SearchVendor.php', 'package' => 'acme/library'];
        self::assertSame([$vendor, ['fqcn' => 'App\\SearchLocal', 'kind' => 'class', 'source' => 'app', 'path' => 'SearchLocal.php']], $this->search('Search')['matches']);
        self::assertSame([$vendor], $this->search('Search', 'acme/library')['matches']);
        self::assertFalse($this->search('Search', 'other/package')['ok']);
    }

    public function testCanonicalBoundariesRejectOutsideMappingsAndLinksButAllowInsideAbsoluteRoots(): void
    {
        $outside = $this->temporary . '/app-sibling';
        mkdir($outside);
        file_put_contents($outside . '/SearchExternal.php', '<?php namespace External; class SearchExternal {}');
        $this->write('domain/SearchInside.php', '<?php namespace Domain; class SearchInside {}');
        mkdir($this->root . '/src');
        self::assertTrue(symlink($outside, $this->root . '/linked'));
        self::assertTrue(symlink($outside, $this->root . '/src/nested'));
        self::assertTrue(symlink($outside . '/SearchExternal.php', $this->root . '/src/SearchExternal.php'));
        $this->manifest([
            'App\\' => 'src/', 'Domain\\' => $this->root . '/domain',
            'External\\' => $outside, 'RelativeEscape\\' => '../app-sibling',
            'Linked\\' => 'linked', 'Missing\\' => 'absent',
        ]);
        self::assertSame([['fqcn' => 'Domain\\SearchInside', 'kind' => 'class', 'source' => 'app', 'path' => 'domain/SearchInside.php']], $this->search('Search')['matches']);
        self::assertFileExists($outside . '/SearchExternal.php');
    }

    public function testMalformedMapEntriesAreIgnoredAndDirectoriesAreDeduplicated(): void
    {
        $this->manifest(['App\\' => ['src/', 42, null, 'src/'], 'Domain\\' => 'domain/', 'Bad\\' => false, 0 => 'ignored/']);
        self::assertSame(['src/', 'domain/'], ComposerAutoload::runtimeDirectories($this->root));
        $this->write('src/SearchGood.php', '<?php namespace App; class SearchGood {}');
        $this->write('ignored/SearchIgnored.php', '<?php namespace Bad; class SearchIgnored {}');
        self::assertSame(['App\\SearchGood'], array_column($this->search('Search')['matches'], 'fqcn'));
    }

    public function testAbsentAndMalformedManifestsHaveNoRuntimeDirectories(): void
    {
        self::assertSame([], ComposerAutoload::runtimeDirectories($this->root));
        foreach (['{', 'null', '{"autoload":{"psr-4":42}}', '{"autoload":42}'] as $manifest) {
            $this->write('composer.json', $manifest);
            self::assertSame([], ComposerAutoload::runtimeDirectories($this->root));
        }
    }

    /** @param array<array-key, mixed> $runtime @param array<string, string> $development */
    private function manifest(array $runtime, array $development = []): void
    {
        $this->write('composer.json', json_encode([
            'autoload' => ['psr-4' => $runtime], 'autoload-dev' => ['psr-4' => $development],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function search(string $query, string $package = ''): array
    {
        return (new ContractSearchHandler(new RootResolver($this->root)))->handle(['q' => $query, 'package' => $package]);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o775, true);
        }
        file_put_contents($path, $contents);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
