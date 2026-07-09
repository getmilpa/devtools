<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Milpa\Attributes\PluginMetadata;
use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\Generators\PluginGenerator;
use Milpa\DevTools\Make\PlannedFile;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Psr\Container\ContainerInterface;

/**
 * Covers {@see PluginGenerator}: the standalone `#[PluginMetadata]` + `PluginInterface` stub it
 * renders (RUNTIME flavor — the only one it supports, see {@see testLegacyFlavorThrowsAClearError()}),
 * the optional `provides`/`requires` capability arguments, and — the load-bearing proof — that the
 * generated file is a genuinely valid, instantiable `PluginInterface` implementation. Mirrors
 * {@see ControllerGeneratorRuntimeTest}/{@see EntityGeneratorRuntimeTest}'s real-temp-directory setup.
 */
final class PluginGeneratorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-plugin-runtime-' . uniqid();
        mkdir($this->root, 0o775, true);
        file_put_contents(
            $this->root . '/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], JSON_PRETTY_PRINT),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testGeneratesAValidStandalonePluginInterfaceStub(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'BoardPlugin',
            options: ['flavor' => 'runtime'],
            root: $this->root,
        );

        $result = (new PluginGenerator())->generate($ctx);
        $plugin = $this->fileNamed($result->files, 'BoardPlugin.php');

        $this->assertCount(1, $result->files);
        $this->assertStringEndsWith('/src/Plugins/BoardPlugin/BoardPlugin.php', $plugin->path);

        $code = $plugin->contents;
        $this->assertStringContainsString('namespace App\\Plugins\\BoardPlugin;', $code);
        $this->assertStringContainsString('use Milpa\\Attributes\\PluginMetadata;', $code);
        $this->assertStringContainsString('use Milpa\\Interfaces\\Di\\DIContainerInterface;', $code);
        $this->assertStringContainsString('use Milpa\\Interfaces\\Plugin\\PluginInterface;', $code);
        $this->assertStringContainsString('final class BoardPlugin implements PluginInterface', $code);
        $this->assertStringContainsString('ToolProviderInterface', $code);
        $this->assertStringNotContainsString('RouteProviderInterface', $code);
        $this->assertStringContainsString("name: 'BoardPlugin',", $code);
        $this->assertStringContainsString('public function boot(): void', $code);
        $this->assertStringContainsString('public function install(): void', $code);
        $this->assertStringContainsString('public function uninstall(): void', $code);
        $this->assertStringContainsString('public function enable(): void', $code);
        $this->assertStringContainsString('public function disable(): void', $code);
        $this->assertStringContainsString('public function registerTools(ToolRegistryInterface $registry): void', $code);
        $this->assertStringContainsString('public function getPromptSections(): array', $code);

        // F1: the wiring-marker anchors a follow-up make:service/make:tool auto-wires into (see
        // MarkerInserterTest / ServiceGeneratorTest::testExistingMarkedPluginAutoWiresAtTheServicesMarker()).
        $this->assertStringContainsString('// {coa:services}', $code);
        $this->assertStringContainsString('// {coa:tools}', $code);
        $this->assertStringContainsString('// {coa:tool-prompts}', $code);

        $this->assertPhpLints($code);

        $this->assertSame(Flavor::Runtime, $result->flavor);
        $this->assertSame('App\\Plugins\\BoardPlugin\\BoardPlugin', $result->verifyTarget);
        $this->assertNotNull($result->guidance);
        $this->assertStringContainsString('config/plugins.php', (string) $result->guidance);
        $this->assertStringContainsString('App\\Plugins\\BoardPlugin\\BoardPlugin::class', (string) $result->guidance);
    }

    /** No `--flavor` override on a fresh PSR-4-only root (no milpa.json, no legacy BaseController) auto-detects Runtime. */
    public function testFlavorAutoDetectsToRuntimeOnAFreshRoot(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'BoardPlugin',
            options: [],
            root: $this->root,
        );

        $result = (new PluginGenerator())->generate($ctx);

        $this->assertSame(Flavor::Runtime, $result->flavor);
    }

    public function testProvidesAndRequiresRenderAsQuotedArrayLiterals(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'BoardPlugin',
            options: ['flavor' => 'runtime', 'provides' => 'board.capability, task.crud', 'requires' => 'auth.session'],
            root: $this->root,
        );

        $result = (new PluginGenerator())->generate($ctx);
        $code = $result->files[0]->contents;

        $this->assertStringContainsString("provides: ['board.capability', 'task.crud'],", $code);
        $this->assertStringContainsString("requires: ['auth.session'],", $code);

        $this->assertPhpLints($code);
    }

    public function testNoProvidesOrRequiresOmitsBothArgumentsCleanly(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'BoardPlugin',
            options: ['flavor' => 'runtime'],
            root: $this->root,
        );

        $result = (new PluginGenerator())->generate($ctx);
        $code = $result->files[0]->contents;

        $this->assertStringNotContainsString('provides:', $code);
        $this->assertStringNotContainsString('requires:', $code);
        // no dangling blank line between `type: 'Service',` and the attribute's closing `)]`.
        $this->assertStringContainsString("type: 'Service',\n)]", $code);

        $this->assertPhpLints($code);
    }

    public function testLegacyFlavorThrowsAClearError(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'BoardPlugin',
            options: ['flavor' => 'legacy'],
            root: $this->root,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--flavor=runtime');
        (new PluginGenerator())->generate($ctx);
    }

    /**
     * The load-bearing proof: the generated file is a genuinely valid, instantiable
     * `Milpa\Interfaces\Plugin\PluginInterface` implementation carrying a real `#[PluginMetadata]`
     * attribute — not just a shape assertion on the generated source text. Run in a separate process:
     * this test `require`s the generated file, which declares a real, fixed-FQCN class
     * (`App\Plugins\BoardPlugin\BoardPlugin`) — isolating it avoids any "class already declared" risk,
     * mirroring {@see EntityGeneratorRuntimeTest::testGeneratedEntityRoundTripsAndPersistsThroughAFileRepository()}.
     */
    #[RunInSeparateProcess]
    public function testGeneratedPluginIsAValidInstantiablePluginInterfaceImplementation(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'BoardPlugin',
            options: ['flavor' => 'runtime', 'provides' => 'board.capability'],
            root: $this->root,
        );

        $result = (new PluginGenerator())->generate($ctx);
        $file = $this->fileNamed($result->files, 'BoardPlugin.php');

        mkdir(\dirname($file->path), 0o775, true);
        file_put_contents($file->path, $file->contents);
        require $file->path;

        $fqcn = $result->verifyTarget;
        $this->assertTrue(class_exists($fqcn, false));

        $reflection = new \ReflectionClass($fqcn);
        $this->assertTrue($reflection->implementsInterface(PluginInterface::class));

        $attributes = $reflection->getAttributes(PluginMetadata::class);
        $this->assertCount(1, $attributes);
        $metadata = $attributes[0]->newInstance();
        $this->assertSame('BoardPlugin', $metadata->name);
        $this->assertSame(['board.capability'], $metadata->provides);

        $container = new class () implements DIContainerInterface {
            public function getContainer(): ContainerInterface
            {
                return $this;
            }

            public function registerService(string $id, string|object $classOrInstance): void
            {
            }

            public function compileContainer(): void
            {
            }

            public function get(string $id): mixed
            {
                return null;
            }

            public function has(string $id): bool
            {
                return false;
            }

            public function resolve(string $className, bool $singleton = true): mixed
            {
                return null;
            }

            public function tryGet(string $id): mixed
            {
                return null;
            }
        };

        /** @var PluginInterface $plugin */
        $plugin = new $fqcn($container);
        $plugin->boot();
        $plugin->install();
        $plugin->enable();
        $plugin->disable();
        $plugin->uninstall();

        // F1: the standalone stub ALSO genuinely implements ToolProviderInterface from the start
        // (carrying the {coa:tools}/{coa:tool-prompts} markers a follow-up make:tool auto-wires
        // into) — milpa/core (ToolProviderInterface's package) is a require-dev dependency of
        // milpa/devtools, so — unlike RouteProviderInterface (milpa/runtime, not a dependency here,
        // see the class docblock) — this is genuinely `require`-able and instantiable here, not just
        // lint-checkable.
        $this->assertTrue($reflection->implementsInterface('Milpa\\Interfaces\\Tooling\\ToolProviderInterface'));
        $toolRegistry = new class () implements \Milpa\Interfaces\Tooling\ToolRegistryInterface {
            public function register(
                string $name,
                string $description,
                array $inputSchema,
                callable $callback,
                ?\Milpa\ValueObjects\Tooling\ToolOptions $options = null,
            ): void {
            }
        };
        $plugin->registerTools($toolRegistry);
        $this->assertSame([], $plugin->getPromptSections(), 'no tool wired yet — the {coa:tool-prompts} marker is the only entry');

        $this->addToAssertionCount(1);
    }

    /** @param list<PlannedFile> $files */
    private function fileNamed(array $files, string $basename): PlannedFile
    {
        foreach ($files as $file) {
            if (basename($file->path) === $basename) {
                return $file;
            }
        }

        $this->fail("no planned file named {$basename} among: " . implode(', ', array_map(
            static fn (PlannedFile $f): string => basename($f->path),
            $files,
        )));
    }

    private function assertPhpLints(string $code): void
    {
        $tmp = $this->root . '/lint-' . uniqid() . '.php';
        file_put_contents($tmp, $code);
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $exitCode);
        unlink($tmp);

        $this->assertSame(0, $exitCode, "php -l failed:\n" . implode("\n", $output));
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
