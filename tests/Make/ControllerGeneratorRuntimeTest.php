<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\Generators\ControllerGenerator;
use Milpa\DevTools\Make\PlannedFile;

/**
 * Covers the RUNTIME flavor of {@see ControllerGenerator}: the stub it renders, and — the
 * load-bearing part (F1) — that generating a controller wires an actually booting `GET <path> →
 * Controller::index` route instead of leaving an orphan class. Unlike {@see ControllerGeneratorTest}
 * (legacy, which only ever composes `$root` into a path STRING), route-wiring genuinely inspects the
 * filesystem under `$root` to decide "does a RouteProviderInterface plugin already exist here", so
 * these tests use a REAL temp directory.
 */
final class ControllerGeneratorRuntimeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-ctrl-runtime-' . uniqid();
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

    public function testGeneratesAValidPlainPsr7ControllerStub(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'PostController',
            options: ['flavor' => 'runtime', 'path' => '/posts'],
            root: $this->root,
        );

        $result = (new ControllerGenerator())->generate($ctx);
        $controller = $this->fileNamed($result->files, 'PostController.php');

        $this->assertStringEndsWith('/src/Plugins/BlogPlugin/Controllers/PostController.php', $controller->path);

        $code = $controller->contents;
        $this->assertStringContainsString('namespace App\\Plugins\\BlogPlugin\\Controllers;', $code);
        $this->assertStringContainsString('final class PostController', $code);
        $this->assertStringContainsString('use Psr\\Http\\Message\\ResponseInterface;', $code);
        $this->assertStringContainsString('use Psr\\Http\\Message\\ServerRequestInterface;', $code);
        $this->assertStringContainsString('public function index(ServerRequestInterface $request): ResponseInterface', $code);
        $this->assertStringNotContainsString('BaseController', $code);
        $this->assertStringNotContainsString('#[Route', $code);
        $this->assertStringNotContainsString('Symfony\\Component\\HttpFoundation\\Request', $code);

        $this->assertPhpLints($code);

        $this->assertSame(Flavor::Runtime, $result->flavor);
        $this->assertSame('controller', $result->verifyKind);
        $this->assertSame('App\\Plugins\\BlogPlugin\\Controllers\\PostController', $result->verifyTarget);
    }

    public function testNoExistingPluginGeneratesABootingRouteProviderPluginToo(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'PostController',
            options: ['flavor' => 'runtime', 'path' => '/posts'],
            root: $this->root,
        );

        $result = (new ControllerGenerator())->generate($ctx);

        $this->assertCount(2, $result->files, 'expected controller + plugin when no plugin exists yet');
        $plugin = $this->fileNamed($result->files, 'BlogPlugin.php');

        $this->assertStringEndsWith('/src/Plugins/BlogPlugin/BlogPlugin.php', $plugin->path);

        $code = $plugin->contents;
        $this->assertStringContainsString('namespace App\\Plugins\\BlogPlugin;', $code);
        $this->assertStringContainsString('use App\\Plugins\\BlogPlugin\\Controllers\\PostController;', $code);
        $this->assertStringContainsString('implements PluginInterface, RouteProviderInterface', $code);
        $this->assertStringContainsString("path: '/posts'", $code);
        $this->assertStringContainsString('methods: HttpMethod::GET', $code);
        $this->assertStringContainsString("handler: new HandlerReference(PostController::class, 'index')", $code);
        $this->assertStringContainsString("name: 'BlogPlugin',", $code);

        $this->assertPhpLints($code);

        $this->assertNotNull($result->guidance);
        $this->assertStringContainsString('config/plugins.php', (string) $result->guidance);
        $this->assertStringContainsString('App\\Plugins\\BlogPlugin\\BlogPlugin::class', (string) $result->guidance);
    }

    public function testExistingPluginIsNotEditedAndGetsARouteSnippetInGuidanceInstead(): void
    {
        $pluginDir = $this->root . '/src/Plugins/BlogPlugin';
        mkdir($pluginDir, 0o775, true);
        $existing = "<?php\n// hand-written plugin — must not be touched\n";
        file_put_contents($pluginDir . '/BlogPlugin.php', $existing);

        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'PostController',
            options: ['flavor' => 'runtime', 'path' => '/posts'],
            root: $this->root,
        );

        $result = (new ControllerGenerator())->generate($ctx);

        $this->assertCount(1, $result->files, 'the existing plugin file must not be (re)written');
        $this->assertSame('PostController.php', basename($result->files[0]->path));
        $this->assertSame($existing, file_get_contents($pluginDir . '/BlogPlugin.php'), 'existing plugin file must be untouched on disk');

        $this->assertNotNull($result->guidance);
        $guidance = (string) $result->guidance;
        $this->assertStringContainsString('already exists', $guidance);
        $this->assertStringContainsString('new Route(', $guidance);
        $this->assertStringContainsString("path: '/posts'", $guidance);
        $this->assertStringContainsString("handler: new HandlerReference(PostController::class, 'index')", $guidance);
        $this->assertStringContainsString('use App\\Plugins\\BlogPlugin\\Controllers\\PostController;', $guidance);
    }

    public function testDefaultPathIsDerivedFromTheControllerName(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'PostController',
            options: ['flavor' => 'runtime'],
            root: $this->root,
        );

        $result = (new ControllerGenerator())->generate($ctx);
        $plugin = $this->fileNamed($result->files, 'BlogPlugin.php');

        $this->assertStringContainsString("path: '/post'", $plugin->contents);
    }

    /** Confirms `GenerationContext`'s `flavor` option reaches {@see \Milpa\DevTools\Make\ConventionDetector} correctly. */
    public function testExplicitLegacyFlavorOptionOverridesRuntimeLookingRoot(): void
    {
        $ctx = new GenerationContext(
            plugin: 'MarketingPlugin',
            name: 'PostController',
            options: ['flavor' => 'legacy'],
            root: $this->root,
        );

        $result = (new ControllerGenerator())->generate($ctx);

        $this->assertSame(Flavor::Legacy, $result->flavor);
        $this->assertStringContainsString('extends BaseController', $result->files[0]->contents);
    }

    /**
     * The CONTROLLER path (either flavor) must never touch `doctrine/orm` — run in a fresh process so
     * "never loaded" is a real, order-independent claim rather than an artifact of some earlier test
     * in the same PHPUnit run having already loaded it.
     */
    #[RunInSeparateProcess]
    public function testControllerGenerationNeverLoadsDoctrine(): void
    {
        $this->assertFalse(
            class_exists('Doctrine\\ORM\\Mapping\\Entity', false),
            'precondition: Doctrine must not already be loaded in this fresh process',
        );

        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'PostController',
            options: ['flavor' => 'runtime'],
            root: $this->root,
        );
        (new ControllerGenerator())->generate($ctx);

        $this->assertFalse(
            class_exists('Doctrine\\ORM\\Mapping\\Entity', false),
            'ControllerGenerator must never trigger autoloading of a Doctrine class',
        );
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
