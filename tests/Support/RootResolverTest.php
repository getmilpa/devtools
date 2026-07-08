<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Support;

use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Support\RootNotFoundException;
use Milpa\DevTools\Support\RootResolver;

final class RootResolverTest extends TestCase
{
    public function testExplicitRootWins(): void
    {
        $resolver = new RootResolver(__DIR__);

        $this->assertSame(realpath(__DIR__), $resolver->resolve());
    }

    public function testExplicitRootThatDoesNotExistThrows(): void
    {
        $resolver = new RootResolver('/definitely/does/not/exist/' . uniqid());

        $this->expectException(RootNotFoundException::class);
        $resolver->resolve();
    }

    public function testInstalledVersionsResolvesToARealDirectoryWithAComposerJson(): void
    {
        // No explicit root: falls through to Composer\InstalledVersions::getRootPackage() (this
        // package's own composer.json when the suite runs standalone) or, failing that, the
        // getcwd()-walk fallback. Either way the answer must be a real directory that actually
        // owns a composer.json — the one topology guarantee callers rely on.
        $root = (new RootResolver())->resolve();

        $this->assertDirectoryExists($root);
        $this->assertFileExists($root . '/composer.json');
    }

    /**
     * Proves the getcwd()-walk fallback in isolation (not just as a side effect of #3, which may be
     * satisfied by the InstalledVersions branch alone): a synthetic root with its own composer.json,
     * invoked from three levels below it, must resolve to THAT root — not a parent's, not a sibling's.
     */
    public function testCwdWalkFindsTheNearestAncestorComposerJson(): void
    {
        $tmp = sys_get_temp_dir() . '/milpa-devtools-root-' . uniqid();
        $nested = $tmp . '/a/b/c';
        mkdir($nested, 0o775, true);
        file_put_contents($tmp . '/composer.json', '{}');
        $expected = realpath($tmp); // captured before cleanup removes the directory below

        $previousCwd = getcwd();
        $this->assertNotFalse($previousCwd);
        chdir($nested);

        try {
            $method = new \ReflectionMethod(RootResolver::class, 'fromCwdWalk');
            $method->setAccessible(true);
            /** @var string|null $found */
            $found = $method->invoke(new RootResolver());
        } finally {
            chdir($previousCwd);
            $this->removeDirectory($tmp);
        }

        $this->assertSame($expected, $found);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
