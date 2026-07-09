<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\Generators\ToolGenerator;
use Milpa\DevTools\Make\PlannedFile;

/**
 * Covers {@see ToolGenerator}: the `#[Tool]`-attributed class it renders (RUNTIME flavor — the only
 * one it supports, see {@see testLegacyFlavorThrowsAClearError()}), the tool-name/description
 * derivation, and — the load-bearing part, mirroring {@see ServiceGeneratorTest} exactly — that
 * generating a tool wires an actually scanning `ToolProviderInterface::registerTools()` instead of
 * leaving an orphan class, either by scaffolding a fresh plugin or by returning the exact snippet as
 * guidance when a plugin already exists.
 *
 * `milpa/tool-runtime` is NOT a dependency of `milpa/devtools` (see `composer.json` — this package has
 * zero `#[Tool]`/`ToolScanner` coupling of its own, deliberately, the same way the CONTROLLER path has
 * zero Doctrine coupling) and is not vendored here either (confirmed: no `vendor/milpa/tool-runtime`,
 * and `Milpa\ToolRuntime\Attributes\Tool` does not autoload in this package's own test process) — so
 * the load-bearing round-trip test below reflects the generated `#[Tool]` attribute by its STRING FQCN
 * (`getAttributes('Milpa\\ToolRuntime\\Attributes\\Tool')` + `getArguments()`) rather than calling
 * `ReflectionAttribute::newInstance()`, which would try to autoload a class this test process cannot
 * provide. See the F1b report's Fricciones for the full rationale.
 */
final class ToolGeneratorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-tool-runtime-' . uniqid();
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

    public function testGeneratesAValidToolAttributedClass(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'CompleteTaskTool',
            options: ['flavor' => 'runtime', 'description' => 'Mark a task done'],
            root: $this->root,
        );

        $result = (new ToolGenerator())->generate($ctx);
        $tool = $this->fileNamed($result->files, 'CompleteTaskTool.php');

        $this->assertStringEndsWith('/src/Plugins/BoardPlugin/Tools/CompleteTaskTool.php', $tool->path);

        $code = $tool->contents;
        $this->assertStringContainsString('namespace App\\Plugins\\BoardPlugin\\Tools;', $code);
        $this->assertStringContainsString('use Milpa\\ToolRuntime\\Attributes\\Tool;', $code);
        $this->assertStringContainsString('use Milpa\\ToolRuntime\\ToolResult;', $code);
        $this->assertStringContainsString('final class CompleteTaskTool', $code);
        $this->assertStringContainsString("#[Tool(name: 'complete_task', description: 'Mark a task done')]", $code);
        $this->assertStringContainsString('): ToolResult', $code);

        $this->assertPhpLints($code);

        $this->assertSame(Flavor::Runtime, $result->flavor);
        $this->assertNull($result->verifyKind);
        $this->assertSame('App\\Plugins\\BoardPlugin\\Tools\\CompleteTaskTool', $result->verifyTarget);
    }

    public function testToolNameDefaultsToSnakeCaseWithTrailingToolSuffixStripped(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'CompleteTaskTool',
            options: ['flavor' => 'runtime'],
            root: $this->root,
        );

        $result = (new ToolGenerator())->generate($ctx);
        $code = $result->files[0]->contents;

        $this->assertStringContainsString("name: 'complete_task'", $code);
    }

    public function testToolNameOverrideOptionWins(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'CompleteTaskTool',
            options: ['flavor' => 'runtime', 'tool-name' => 'finish_task'],
            root: $this->root,
        );

        $result = (new ToolGenerator())->generate($ctx);
        $code = $result->files[0]->contents;

        $this->assertStringContainsString("name: 'finish_task'", $code);
    }

    public function testDescriptionDefaultsToAGenericSentenceWhenOmitted(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'CompleteTaskTool',
            options: ['flavor' => 'runtime'],
            root: $this->root,
        );

        $result = (new ToolGenerator())->generate($ctx);
        $code = $result->files[0]->contents;

        $this->assertStringContainsString("description: 'CompleteTaskTool tool.'", $code);
    }

    public function testDescriptionSingleQuotesAreEscapedForThePhpStringLiteral(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'CompleteTaskTool',
            options: ['flavor' => 'runtime', 'description' => "Mark a task as 'done'"],
            root: $this->root,
        );

        $result = (new ToolGenerator())->generate($ctx);
        $code = $result->files[0]->contents;

        $this->assertStringContainsString("description: 'Mark a task as \\'done\\''", $code);
        $this->assertPhpLints($code);
    }

    public function testNoExistingPluginGeneratesABootingToolProviderPluginToo(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'CompleteTaskTool',
            options: ['flavor' => 'runtime'],
            root: $this->root,
        );

        $result = (new ToolGenerator())->generate($ctx);

        $this->assertCount(2, $result->files, 'expected tool + plugin when no plugin exists yet');
        $plugin = $this->fileNamed($result->files, 'BoardPlugin.php');
        $this->assertStringEndsWith('/src/Plugins/BoardPlugin/BoardPlugin.php', $plugin->path);

        $code = $plugin->contents;
        $this->assertStringContainsString('namespace App\\Plugins\\BoardPlugin;', $code);
        $this->assertStringContainsString('use App\\Plugins\\BoardPlugin\\Tools\\CompleteTaskTool;', $code);
        $this->assertStringContainsString('use Milpa\\Interfaces\\Tooling\\ToolProviderInterface;', $code);
        $this->assertStringContainsString('use Milpa\\Interfaces\\Tooling\\ToolRegistryInterface;', $code);
        $this->assertStringContainsString('use Milpa\\ToolRuntime\\ToolScanner;', $code);
        $this->assertStringContainsString('implements PluginInterface, ToolProviderInterface', $code);
        $this->assertStringContainsString('public function registerTools(ToolRegistryInterface $registry): void', $code);
        $this->assertStringContainsString('(new ToolScanner($registry))->scan(new CompleteTaskTool());', $code);
        $this->assertStringContainsString('public function getPromptSections(): array', $code);
        $this->assertStringContainsString("'CompleteTaskTool exposes the complete_task tool.'", $code);

        $this->assertPhpLints($code);

        $this->assertNotNull($result->guidance);
        $this->assertStringContainsString('config/plugins.php', (string) $result->guidance);
        $this->assertStringContainsString('App\\Plugins\\BoardPlugin\\BoardPlugin::class', (string) $result->guidance);
    }

    public function testExistingPluginIsNotEditedAndGetsARegisterToolsSnippetInGuidanceInstead(): void
    {
        $pluginDir = $this->root . '/src/Plugins/BoardPlugin';
        mkdir($pluginDir, 0o775, true);
        $existing = "<?php\n// hand-written plugin — must not be touched\n";
        file_put_contents($pluginDir . '/BoardPlugin.php', $existing);

        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'CompleteTaskTool',
            options: ['flavor' => 'runtime'],
            root: $this->root,
        );

        $result = (new ToolGenerator())->generate($ctx);

        $this->assertCount(1, $result->files, 'the existing plugin file must not be (re)written');
        $this->assertSame('CompleteTaskTool.php', basename($result->files[0]->path));
        $this->assertSame($existing, file_get_contents($pluginDir . '/BoardPlugin.php'), 'existing plugin file must be untouched on disk');

        $this->assertNotNull($result->guidance);
        $guidance = (string) $result->guidance;
        $this->assertStringContainsString('already exists', $guidance);
        $this->assertStringContainsString('registerTools(ToolRegistryInterface $registry): void', $guidance);
        $this->assertStringContainsString('(new ToolScanner($registry))->scan(new CompleteTaskTool());', $guidance);
        $this->assertStringContainsString('getPromptSections(): array', $guidance);
        $this->assertStringContainsString('use App\\Plugins\\BoardPlugin\\Tools\\CompleteTaskTool;', $guidance);
        $this->assertStringContainsString('ToolProviderInterface', $guidance);
    }

    public function testGuidanceWarnsWhenToolRuntimeIsNotDetectedInTheTargetApp(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'CompleteTaskTool',
            options: ['flavor' => 'runtime'],
            root: $this->root,
        );

        $result = (new ToolGenerator())->generate($ctx);
        $guidance = (string) $result->guidance;

        $this->assertStringContainsString('milpa/tool-runtime', $guidance);
        $this->assertStringContainsString('composer require milpa/tool-runtime', $guidance);
        $this->assertStringContainsString('milpa/mcp-server', $guidance);
    }

    /** No detection warning once a `vendor/milpa/tool-runtime` directory is present under the app root. */
    public function testGuidanceOmitsTheDependencyWarningWhenToolRuntimeVendorDirIsPresent(): void
    {
        mkdir($this->root . '/vendor/milpa/tool-runtime', 0o775, true);

        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'CompleteTaskTool',
            options: ['flavor' => 'runtime'],
            root: $this->root,
        );

        $result = (new ToolGenerator())->generate($ctx);
        $guidance = (string) $result->guidance;

        $this->assertStringNotContainsString('composer require milpa/tool-runtime', $guidance);
    }

    public function testLegacyFlavorThrowsAClearError(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'CompleteTaskTool',
            options: ['flavor' => 'legacy'],
            root: $this->root,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--flavor=runtime');
        (new ToolGenerator())->generate($ctx);
    }

    /**
     * The load-bearing proof: the generated tool class + wiring plugin actually work together — a
     * real `#[Tool]` attribute carrying the expected name/description on a real method, and a real
     * `Milpa\Interfaces\Plugin\PluginInterface` implementation that ALSO genuinely `implements`
     * `Milpa\Interfaces\Tooling\ToolProviderInterface` — not just a shape assertion on the generated
     * source text. Run in a separate process, mirroring
     * {@see PluginGeneratorTest::testGeneratedPluginIsAValidInstantiablePluginInterfaceImplementation()}.
     *
     * `Milpa\ToolRuntime\Attributes\Tool` and `Milpa\Interfaces\Tooling\ToolProviderInterface` are
     * reflected by STRING FQCN rather than imported — see the class docblock for why: the FIRST is not
     * autoloadable in this test process at all (`milpa/tool-runtime` is not a devtools dependency),
     * while the SECOND (`milpa/core`) genuinely IS available here (`milpa/core` is a require-dev
     * dependency) but is kept string-based too, for symmetry with the tool attribute check and so this
     * test does not accidentally depend on import order.
     */
    #[RunInSeparateProcess]
    public function testGeneratedToolAndPluginAreGenuinelyWiredTogether(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'CompleteTaskTool',
            options: ['flavor' => 'runtime', 'description' => 'Mark a task done'],
            root: $this->root,
        );

        $result = (new ToolGenerator())->generate($ctx);

        foreach ($result->files as $file) {
            if (!is_dir(\dirname($file->path))) {
                mkdir(\dirname($file->path), 0o775, true);
            }
            // Milpa\ToolRuntime\Attributes\Tool / ToolResult / ToolScanner are referenced by the
            // generated files' `use` statements but are NOT autoloadable in this test process (see
            // the class docblock) — declaring the classes is safe regardless (PHP does not resolve a
            // `use` import, nor a method-body reference, until it is actually EXECUTED or REFLECTED
            // via newInstance()), which is exactly what this test avoids doing below.
            file_put_contents($file->path, $file->contents);
        }
        require $this->fileNamed($result->files, 'CompleteTaskTool.php')->path;
        require $this->fileNamed($result->files, 'BoardPlugin.php')->path;

        $toolFqcn = $result->verifyTarget;
        $this->assertTrue(class_exists($toolFqcn, false));

        $toolReflection = new \ReflectionClass($toolFqcn);
        $method = $toolReflection->getMethod('run');
        $attributes = $method->getAttributes('Milpa\\ToolRuntime\\Attributes\\Tool');
        $this->assertCount(1, $attributes, 'the tool method must carry exactly one #[Tool] attribute');

        $arguments = $attributes[0]->getArguments();
        $this->assertSame('complete_task', $arguments['name'] ?? null);
        $this->assertSame('Mark a task done', $arguments['description'] ?? null);

        $pluginFqcn = 'App\\Plugins\\BoardPlugin\\BoardPlugin';
        $this->assertTrue(class_exists($pluginFqcn, false));
        $pluginReflection = new \ReflectionClass($pluginFqcn);
        $this->assertTrue(
            $pluginReflection->implementsInterface('Milpa\\Interfaces\\Tooling\\ToolProviderInterface'),
            'the wiring plugin must genuinely implement ToolProviderInterface, not just resemble one in source text',
        );
        $this->assertTrue($pluginReflection->hasMethod('registerTools'));
        $this->assertTrue($pluginReflection->hasMethod('getPromptSections'));
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
