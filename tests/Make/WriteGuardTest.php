<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Make\WriteGuard;

final class WriteGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/milpa-wg-' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
    }

    /**
     * Recursively removes a directory tree. testWritesAndCreatesDirs() creates a `nested/` subdir,
     * so a flat `glob()` + `unlink()` (the previous implementation) tried to `unlink()` a directory —
     * a real PHP warning on every run — and left the temp dir behind.
     */
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
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    public function testWritesAndCreatesDirs(): void
    {
        $path = $this->dir . '/nested/File.php';
        (new WriteGuard())->write($path, 'hello');

        $this->assertFileExists($path);
        $this->assertSame('hello', file_get_contents($path));
    }

    public function testExistingFileWithoutForceThrows(): void
    {
        $path = $this->dir . '/File.php';
        $guard = new WriteGuard();
        $guard->write($path, 'a');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        $guard->assertWritable($path, false);
    }

    public function testExistingFileWithForceIsOk(): void
    {
        $path = $this->dir . '/File.php';
        $guard = new WriteGuard();
        $guard->write($path, 'a');
        $guard->assertWritable($path, true);

        $this->addToAssertionCount(1);
    }
}
