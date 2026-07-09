<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Make\ConventionDetector;
use Milpa\DevTools\Make\Flavor;

final class ConventionDetectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-convention-' . uniqid();
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAmbiguousFreshRootDefaultsToRuntime(): void
    {
        // No composer.json at all — the genuinely ambiguous case.
        $this->assertSame(Flavor::Runtime, (new ConventionDetector())->detect($this->root));
    }

    public function testRuntimeAppFixtureWithConfigPluginsPhpDetectsAsRuntime(): void
    {
        $this->writeComposerJson(['App\\' => 'src/']);
        mkdir($this->root . '/config', 0o775, true);
        file_put_contents($this->root . '/config/plugins.php', "<?php\nreturn [];\n");

        $this->assertSame(Flavor::Runtime, (new ConventionDetector())->detect($this->root));
    }

    public function testRootLevelMilpaJsonDetectsAsLegacy(): void
    {
        $this->writeComposerJson(['App\\' => 'src/']);
        file_put_contents($this->root . '/milpa.json', '{}');

        $this->assertSame(Flavor::Legacy, (new ConventionDetector())->detect($this->root));
    }

    /**
     * The real signal that makes THIS monorepo's own legacy host detectable: `Milpa\app\Providers\
     * BaseController` resolves to a real file under its `composer.json`'s own `Milpa\` PSR-4 root —
     * a pure filesystem check (not `class_exists()`, see {@see ConventionDetector}'s docblock for why).
     */
    public function testBaseControllerResolvableUnderPsr4DetectsAsLegacy(): void
    {
        $this->writeComposerJson(['Milpa\\' => 'src/']);
        mkdir($this->root . '/src/app/Providers', 0o775, true);
        file_put_contents($this->root . '/src/app/Providers/BaseController.php', "<?php\nclass BaseController {}\n");

        $this->assertSame(Flavor::Legacy, (new ConventionDetector())->detect($this->root));
    }

    /** Same PSR-4 root, but the file the mapping predicts is absent — must NOT be classified legacy. */
    public function testMilpaPsr4RootWithoutTheActualFileIsNotLegacy(): void
    {
        $this->writeComposerJson(['Milpa\\' => 'src/']);

        $this->assertSame(Flavor::Runtime, (new ConventionDetector())->detect($this->root));
    }

    public function testExplicitOverrideWinsOverLegacySignals(): void
    {
        file_put_contents($this->root . '/milpa.json', '{}');

        $this->assertSame(Flavor::Runtime, (new ConventionDetector())->detect($this->root, 'runtime'));
    }

    public function testExplicitOverrideWinsOverAmbiguousDefault(): void
    {
        $this->assertSame(Flavor::Legacy, (new ConventionDetector())->detect($this->root, 'legacy'));
    }

    public function testInvalidOverrideThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("invalid --flavor 'bogus'");
        (new ConventionDetector())->detect($this->root, 'bogus');
    }

    /** @param array<string, string> $psr4 */
    private function writeComposerJson(array $psr4): void
    {
        file_put_contents(
            $this->root . '/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => $psr4]], JSON_PRETTY_PRINT),
        );
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
