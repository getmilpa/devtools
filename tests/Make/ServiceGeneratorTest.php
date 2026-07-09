<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\Generators\ServiceGenerator;
use Milpa\DevTools\Make\PlannedFile;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Psr\Container\ContainerInterface;

/**
 * Covers {@see ServiceGenerator}: the plain service (+ optional companion interface) stub it renders,
 * and — the load-bearing part, mirroring {@see ControllerGeneratorRuntimeTest}/
 * {@see EntityGeneratorRuntimeTest} exactly — that generating a service wires an actually booting DI
 * registration instead of leaving an orphan class, either by scaffolding a fresh plugin (no existing
 * one at the target area) or by returning the exact registration snippet as guidance (existing plugin,
 * left untouched). Real-temp-directory setup, same as the other RUNTIME generator test classes.
 */
final class ServiceGeneratorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-service-runtime-' . uniqid();
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

    public function testGeneratesAPlainServiceClassWithNoInterface(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'WorkflowService',
            options: ['flavor' => 'runtime'],
            root: $this->root,
        );

        $result = (new ServiceGenerator())->generate($ctx);
        $service = $this->fileNamed($result->files, 'WorkflowService.php');

        $this->assertStringEndsWith('/src/Plugins/BoardPlugin/Services/WorkflowService.php', $service->path);

        $code = $service->contents;
        $this->assertStringContainsString('namespace App\\Plugins\\BoardPlugin\\Services;', $code);
        $this->assertStringContainsString('final class WorkflowService', $code);
        $this->assertStringNotContainsString('implements', $code);
        $this->assertStringContainsString('public function __construct()', $code);
        $this->assertStringContainsString('public function handle(mixed $payload): mixed', $code);
        $this->assertStringContainsString('public function isReady(): bool', $code);

        $this->assertPhpLints($code);

        $this->assertSame(Flavor::Runtime, $result->flavor);
        $this->assertSame('App\\Plugins\\BoardPlugin\\Services\\WorkflowService', $result->verifyTarget);
    }

    public function testWithInterfaceFlagAlsoGeneratesACompanionInterfaceTheServiceImplements(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'WorkflowService',
            options: ['flavor' => 'runtime', 'interface' => true],
            root: $this->root,
        );

        $result = (new ServiceGenerator())->generate($ctx);

        $interfaceFile = $this->fileNamed($result->files, 'WorkflowServiceInterface.php');
        $this->assertStringEndsWith(
            '/src/Plugins/BoardPlugin/Services/WorkflowServiceInterface.php',
            $interfaceFile->path,
        );
        $interfaceCode = $interfaceFile->contents;
        $this->assertStringContainsString('namespace App\\Plugins\\BoardPlugin\\Services;', $interfaceCode);
        $this->assertStringContainsString('interface WorkflowServiceInterface', $interfaceCode);
        $this->assertStringContainsString('public function handle(mixed $payload): mixed;', $interfaceCode);
        $this->assertStringContainsString('public function isReady(): bool;', $interfaceCode);

        $serviceFile = $this->fileNamed($result->files, 'WorkflowService.php');
        $serviceCode = $serviceFile->contents;
        $this->assertStringContainsString('final class WorkflowService implements WorkflowServiceInterface', $serviceCode);
        // same namespace as the interface — no explicit `use` needed, PHP resolves it automatically.
        $this->assertStringNotContainsString('use App\\Plugins\\BoardPlugin\\Services\\WorkflowServiceInterface;', $serviceCode);

        $this->assertPhpLints($interfaceCode);
        $this->assertPhpLints($serviceCode);
    }

    public function testNoExistingPluginGeneratesABootingWiringPluginRegisteringTheServiceClass(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'WorkflowService',
            options: ['flavor' => 'runtime'],
            root: $this->root,
        );

        $result = (new ServiceGenerator())->generate($ctx);

        $this->assertCount(2, $result->files, 'expected service + plugin when no plugin exists yet');
        $plugin = $this->fileNamed($result->files, 'BoardPlugin.php');
        $this->assertStringEndsWith('/src/Plugins/BoardPlugin/BoardPlugin.php', $plugin->path);

        $code = $plugin->contents;
        $this->assertStringContainsString('namespace App\\Plugins\\BoardPlugin;', $code);
        $this->assertStringContainsString('use App\\Plugins\\BoardPlugin\\Services\\WorkflowService;', $code);
        $this->assertStringContainsString('implements PluginInterface', $code);
        $this->assertStringNotContainsString('RouteProviderInterface', $code);
        $this->assertStringContainsString('WorkflowService::class,', $code);
        $this->assertStringContainsString('new WorkflowService(),', $code);

        $this->assertPhpLints($code);

        $this->assertNotNull($result->guidance);
        $this->assertStringContainsString('config/plugins.php', (string) $result->guidance);
        $this->assertStringContainsString('App\\Plugins\\BoardPlugin\\BoardPlugin::class', (string) $result->guidance);
    }

    public function testNoExistingPluginWithInterfaceFlagRegistersUnderTheInterface(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'WorkflowService',
            options: ['flavor' => 'runtime', 'interface' => true],
            root: $this->root,
        );

        $result = (new ServiceGenerator())->generate($ctx);
        $plugin = $this->fileNamed($result->files, 'BoardPlugin.php');
        $code = $plugin->contents;

        $this->assertStringContainsString('use App\\Plugins\\BoardPlugin\\Services\\WorkflowService;', $code);
        $this->assertStringContainsString('use App\\Plugins\\BoardPlugin\\Services\\WorkflowServiceInterface;', $code);
        $this->assertStringContainsString('WorkflowServiceInterface::class,', $code);
        $this->assertStringContainsString('new WorkflowService(),', $code);

        $this->assertPhpLints($code);
    }

    public function testExistingPluginIsNotEditedAndGetsARegistrationSnippetInGuidanceInstead(): void
    {
        $pluginDir = $this->root . '/src/Plugins/BoardPlugin';
        mkdir($pluginDir, 0o775, true);
        $existing = "<?php\n// hand-written plugin — must not be touched\n";
        file_put_contents($pluginDir . '/BoardPlugin.php', $existing);

        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'WorkflowService',
            options: ['flavor' => 'runtime'],
            root: $this->root,
        );

        $result = (new ServiceGenerator())->generate($ctx);

        $this->assertCount(1, $result->files, 'the existing plugin file must not be (re)written');
        $this->assertSame('WorkflowService.php', basename($result->files[0]->path));
        $this->assertSame($existing, file_get_contents($pluginDir . '/BoardPlugin.php'), 'existing plugin file must be untouched on disk');

        $this->assertNotNull($result->guidance);
        $guidance = (string) $result->guidance;
        $this->assertStringContainsString('already exists', $guidance);
        $this->assertStringContainsString('registerService(', $guidance);
        $this->assertStringContainsString('WorkflowService::class,', $guidance);
        $this->assertStringContainsString('new WorkflowService(),', $guidance);
        $this->assertStringContainsString('use App\\Plugins\\BoardPlugin\\Services\\WorkflowService;', $guidance);
    }

    /**
     * F1: an EXISTING plugin that carries the `// {coa:services}` marker gets the registration
     * INSERTED at the anchor instead of only a guidance snippet — the load-bearing fix for the
     * greenhouse "~57 lines hand-merged" friction. Mirrors {@see PluginGenerator}'s own standalone
     * stub, which carries this exact marker from `make:plugin` onward.
     */
    public function testExistingMarkedPluginAutoWiresAtTheServicesMarker(): void
    {
        $pluginDir = $this->root . '/src/Plugins/BoardPlugin';
        mkdir($pluginDir, 0o775, true);
        $marked = "<?php\n\nfinal class BoardPlugin\n{\n    public function boot(): void\n    {\n        // {coa:services}\n    }\n}\n";
        file_put_contents($pluginDir . '/BoardPlugin.php', $marked);

        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'WorkflowService',
            options: ['flavor' => 'runtime'],
            root: $this->root,
        );

        $result = (new ServiceGenerator())->generate($ctx);

        $this->assertCount(2, $result->files, 'the service class + the MERGED plugin (not a 3rd, separately-planned plugin file)');
        $this->assertSame('WorkflowService.php', basename($result->files[0]->path));
        $mergedPlugin = $this->fileNamed($result->files, 'BoardPlugin.php');
        $this->assertTrue($mergedPlugin->merge);
    }

    public function testExistingMarkedPluginInsertsTheRegistrationAtTheAnchorNotDuplicated(): void
    {
        $pluginDir = $this->root . '/src/Plugins/BoardPlugin';
        mkdir($pluginDir, 0o775, true);
        $marked = "<?php\n\nfinal class BoardPlugin\n{\n    public function boot(): void\n    {\n        // {coa:services}\n    }\n}\n";
        file_put_contents($pluginDir . '/BoardPlugin.php', $marked);

        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'WorkflowService',
            options: ['flavor' => 'runtime'],
            root: $this->root,
        );

        $result = (new ServiceGenerator())->generate($ctx);
        $mergedPlugin = $this->fileNamed($result->files, 'BoardPlugin.php');

        $this->assertTrue($mergedPlugin->merge, 'a marker-based merge must not require --force to write');
        $code = $mergedPlugin->contents;
        $this->assertStringContainsString(
            "\$this->container->registerService(\n            \\App\\Plugins\\BoardPlugin\\Services\\WorkflowService::class,\n            new \\App\\Plugins\\BoardPlugin\\Services\\WorkflowService(),\n        );",
            $code,
        );
        // the anchor is preserved — a later make:service run can insert at it again.
        $this->assertSame(1, substr_count($code, '// {coa:services}'));
        $this->assertPhpLints($code);

        $this->assertNotNull($result->guidance);
        $this->assertStringContainsString('Auto-wired', (string) $result->guidance);
        $this->assertStringContainsString('coa:services', (string) $result->guidance);

        // idempotent-safe: re-running against the JUST-MERGED plugin does not duplicate the insertion.
        file_put_contents($pluginDir . '/BoardPlugin.php', $code);
        $result2 = (new ServiceGenerator())->generate($ctx);
        $mergedAgain = $this->fileNamed($result2->files, 'BoardPlugin.php');
        $this->assertSame($code, $mergedAgain->contents, 're-running the same make:service must not duplicate the registration');
        $this->assertSame(1, substr_count($mergedAgain->contents, 'new \\App\\Plugins\\BoardPlugin\\Services\\WorkflowService()'));
    }

    /** `--force` re-inserts even though the exact same registration is already present. */
    public function testForceReinsertsIntoAMarkedPluginEvenWhenAlreadyWired(): void
    {
        $pluginDir = $this->root . '/src/Plugins/BoardPlugin';
        mkdir($pluginDir, 0o775, true);
        $marked = "<?php\n\nfinal class BoardPlugin\n{\n    public function boot(): void\n    {\n        // {coa:services}\n    }\n}\n";
        file_put_contents($pluginDir . '/BoardPlugin.php', $marked);

        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'WorkflowService',
            options: ['flavor' => 'runtime'],
            root: $this->root,
        );

        $result = (new ServiceGenerator())->generate($ctx);
        $once = $this->fileNamed($result->files, 'BoardPlugin.php')->contents;
        file_put_contents($pluginDir . '/BoardPlugin.php', $once);

        $forcedCtx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'WorkflowService',
            options: ['flavor' => 'runtime', 'force' => true],
            root: $this->root,
        );
        $result2 = (new ServiceGenerator())->generate($forcedCtx);
        $twice = $this->fileNamed($result2->files, 'BoardPlugin.php')->contents;

        $this->assertNotSame($once, $twice);
        $this->assertSame(2, substr_count($twice, 'new \\App\\Plugins\\BoardPlugin\\Services\\WorkflowService()'));
    }

    public function testExistingPluginWithInterfaceFlagListsBothImportsInGuidance(): void
    {
        $pluginDir = $this->root . '/src/Plugins/BoardPlugin';
        mkdir($pluginDir, 0o775, true);
        file_put_contents($pluginDir . '/BoardPlugin.php', "<?php\n// hand-written plugin\n");

        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'WorkflowService',
            options: ['flavor' => 'runtime', 'interface' => true],
            root: $this->root,
        );

        $result = (new ServiceGenerator())->generate($ctx);
        $guidance = (string) $result->guidance;

        $this->assertStringContainsString('use App\\Plugins\\BoardPlugin\\Services\\WorkflowService;', $guidance);
        $this->assertStringContainsString('use App\\Plugins\\BoardPlugin\\Services\\WorkflowServiceInterface;', $guidance);
        $this->assertStringContainsString(' and ', $guidance);
        $this->assertStringContainsString('imports', $guidance);
        $this->assertStringContainsString('WorkflowServiceInterface::class,', $guidance);
    }

    public function testLegacyFlavorThrowsAClearError(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'WorkflowService',
            options: ['flavor' => 'legacy'],
            root: $this->root,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--flavor=runtime');
        (new ServiceGenerator())->generate($ctx);
    }

    /**
     * The load-bearing proof: the generated service + wiring plugin actually work together — a real
     * `Milpa\Interfaces\Plugin\PluginInterface` implementation whose `boot()` genuinely calls
     * `registerService()` with the service instance, not just a shape assertion on the generated
     * source text. Run in a separate process, mirroring
     * {@see EntityGeneratorRuntimeTest::testGeneratedEntityRoundTripsAndPersistsThroughAFileRepository()}.
     */
    #[RunInSeparateProcess]
    public function testGeneratedPluginBootRegistersTheServiceIntoTheContainer(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'WorkflowService',
            options: ['flavor' => 'runtime', 'interface' => true],
            root: $this->root,
        );

        $result = (new ServiceGenerator())->generate($ctx);

        foreach ($result->files as $file) {
            if (!is_dir(\dirname($file->path))) {
                mkdir(\dirname($file->path), 0o775, true);
            }
            file_put_contents($file->path, $file->contents);
        }
        // Load order matters: the interface and service must exist before the plugin that references them.
        require $this->fileNamed($result->files, 'WorkflowServiceInterface.php')->path;
        require $this->fileNamed($result->files, 'WorkflowService.php')->path;
        require $this->fileNamed($result->files, 'BoardPlugin.php')->path;

        $serviceFqcn = $result->verifyTarget;
        $this->assertTrue(class_exists($serviceFqcn, false));
        $this->assertTrue(is_a($serviceFqcn, 'App\\Plugins\\BoardPlugin\\Services\\WorkflowServiceInterface', true));

        $pluginFqcn = 'App\\Plugins\\BoardPlugin\\BoardPlugin';
        $this->assertTrue(class_exists($pluginFqcn, false));
        $reflection = new \ReflectionClass($pluginFqcn);
        $this->assertTrue($reflection->implementsInterface(PluginInterface::class));

        $container = new class () implements DIContainerInterface {
            /** @var array<string, object> */
            private array $registered = [];

            public function getContainer(): ContainerInterface
            {
                return $this;
            }

            public function registerService(string $id, string|object $classOrInstance): void
            {
                if (\is_object($classOrInstance)) {
                    $this->registered[$id] = $classOrInstance;
                }
            }

            public function compileContainer(): void
            {
            }

            public function get(string $id): mixed
            {
                return $this->registered[$id] ?? null;
            }

            public function has(string $id): bool
            {
                return isset($this->registered[$id]);
            }

            public function resolve(string $className, bool $singleton = true): mixed
            {
                return null;
            }

            public function tryGet(string $id): mixed
            {
                return $this->registered[$id] ?? null;
            }
        };

        /** @var PluginInterface $plugin */
        $plugin = new $pluginFqcn($container);
        $plugin->boot();

        $interfaceFqcn = 'App\\Plugins\\BoardPlugin\\Services\\WorkflowServiceInterface';
        $this->assertTrue($container->has($interfaceFqcn), 'boot() must register the service under its interface');
        $this->assertInstanceOf($serviceFqcn, $container->get($interfaceFqcn));
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
