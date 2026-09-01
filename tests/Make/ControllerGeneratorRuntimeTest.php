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

    /**
     * The fail-closed control (P0.2): a plugin file the surgeon REFUSES — no class declaration — is
     * the only case left where the route lands as guidance, and the guidance NAMES the file and the
     * reason, with the fully-qualified entry so following it needs no import edits.
     */
    public function testAnUnwirablePluginFallsBackToGuidanceNamingTheFileAndReason(): void
    {
        $pluginDir = $this->root . '/src/Plugins/BlogPlugin';
        mkdir($pluginDir, 0o775, true);
        $existing = "<?php\n// hand-written file with no class — nothing to wire into\n";
        file_put_contents($pluginDir . '/BlogPlugin.php', $existing);

        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'PostController',
            options: ['flavor' => 'runtime', 'path' => '/posts'],
            root: $this->root,
        );

        $result = (new ControllerGenerator())->generate($ctx);

        $this->assertCount(1, $result->files, 'the unwirable plugin file must not be (re)written');
        $this->assertSame('PostController.php', basename($result->files[0]->path));
        $this->assertSame($existing, file_get_contents($pluginDir . '/BlogPlugin.php'), 'unwirable plugin file must be untouched on disk');

        $guidance = (string) $result->guidance;
        $this->assertStringContainsString('already exists', $guidance);
        $this->assertStringContainsString('could not be auto-wired', $guidance);
        $this->assertStringContainsString('no class declaration found', $guidance, 'the REASON is named, not implied');
        $this->assertStringContainsString($pluginDir . '/BlogPlugin.php', $guidance, 'the FILE is named');
        $this->assertStringContainsString("path: '/posts'", $guidance);
        $this->assertStringContainsString(
            "handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\App\\Plugins\\BlogPlugin\\Controllers\\PostController::class, 'index')",
            $guidance,
        );
    }

    /**
     * F1: an EXISTING plugin that carries the `// {coa:routes}` marker gets its `Route` entry
     * INSERTED at the anchor instead of only a guidance snippet — mirrors
     * {@see ServiceGeneratorTest::testExistingMarkedPluginInsertsTheRegistrationAtTheAnchorNotDuplicated()}
     * for the routes concern.
     */
    public function testExistingMarkedPluginInsertsTheRouteAtTheAnchorNotDuplicated(): void
    {
        $pluginDir = $this->root . '/src/Plugins/BlogPlugin';
        mkdir($pluginDir, 0o775, true);
        $marked = "<?php\n\nfinal class BlogPlugin\n{\n    public function routes(): array\n    {\n        return [\n            // {coa:routes}\n        ];\n    }\n}\n";
        file_put_contents($pluginDir . '/BlogPlugin.php', $marked);

        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'PostController',
            options: ['flavor' => 'runtime', 'path' => '/posts'],
            root: $this->root,
        );

        $result = (new ControllerGenerator())->generate($ctx);

        $this->assertCount(2, $result->files, 'the controller class + the MERGED plugin');
        $mergedPlugin = $this->fileNamed($result->files, 'BlogPlugin.php');
        $this->assertTrue($mergedPlugin->merge);

        $code = $mergedPlugin->contents;
        $this->assertStringContainsString("path: '/posts',", $code);
        $this->assertStringContainsString('methods: \\Milpa\\Http\\HttpMethod::GET,', $code);
        $this->assertStringContainsString(
            "handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\App\\Plugins\\BlogPlugin\\Controllers\\PostController::class, 'index'),",
            $code,
        );
        $this->assertSame(1, substr_count($code, '// {coa:routes}'), 'the anchor is preserved for a later run');
        $this->assertPhpLints($code);

        $this->assertNotNull($result->guidance);
        $this->assertStringContainsString('Auto-wired', (string) $result->guidance);

        // Idempotent re-run: with the merge landed, the same make adds nothing and re-plans nothing
        // (the route needle short-circuits to "already wired" before any splice).
        file_put_contents($pluginDir . '/BlogPlugin.php', $code);
        $result2 = (new ControllerGenerator())->generate($ctx);
        $this->assertCount(1, $result2->files, 'only the controller — the wired plugin is not re-planned');
        $this->assertStringContainsString('Already wired', (string) $result2->guidance);
        $this->assertSame($code, file_get_contents($pluginDir . '/BlogPlugin.php'), 'the file on disk is untouched');
    }

    /**
     * P0.2 CLOSURE for the routes concern: a plugin with a routes() but NO marker used to get the
     * exact Route entry back as prose — now it is inserted structurally into the literal return
     * array (here one whose last entry even lacks its trailing comma), read back from the plan.
     */
    public function testExistingUnmarkedPluginGetsTheRouteSplicedIntoItsReturnArray(): void
    {
        $pluginDir = $this->root . '/src/Plugins/BlogPlugin';
        mkdir($pluginDir, 0o775, true);
        $unmarked = "<?php\n\nfinal class BlogPlugin\n{\n    public function routes(): array\n    {\n"
            . "        return [\n            'placeholder'\n        ];\n    }\n}\n";
        file_put_contents($pluginDir . '/BlogPlugin.php', $unmarked);

        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'PostController',
            options: ['flavor' => 'runtime', 'path' => '/posts'],
            root: $this->root,
        );

        $result = (new ControllerGenerator())->generate($ctx);

        $this->assertCount(2, $result->files, 'the controller class + the MERGED plugin');
        $merged = $this->fileNamed($result->files, 'BlogPlugin.php');
        $this->assertTrue($merged->merge);
        $this->assertStringContainsString("'placeholder',", $merged->contents, 'the missing trailing comma is added, not tripped over');
        $this->assertStringContainsString("path: '/posts',", $merged->contents);
        $this->assertStringContainsString(
            "handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\App\\Plugins\\BlogPlugin\\Controllers\\PostController::class, 'index'),",
            $merged->contents,
        );
        $this->assertPhpLints($merged->contents);
        $this->assertStringContainsString('structurally', (string) $result->guidance);
        $this->assertSame($unmarked, file_get_contents($pluginDir . '/BlogPlugin.php'), 'the generator only PLANS; disk is the handler\'s job');

        // Idempotent re-run once the merge is landed.
        file_put_contents($pluginDir . '/BlogPlugin.php', $merged->contents);
        $again = (new ControllerGenerator())->generate($ctx);
        $this->assertCount(1, $again->files, 'only the controller — the wired plugin is not re-planned');
        $this->assertStringContainsString('Already wired', (string) $again->guidance);
    }

    /**
     * P0.2: routes() absent entirely — the method AND the `RouteProviderInterface` declaration are
     * added, because a routes() the kernel never calls would be dead code wearing a wiring.
     */
    public function testExistingPluginWithoutRoutesGetsTheMethodAndTheInterfaceAdded(): void
    {
        $pluginDir = $this->root . '/src/Plugins/BlogPlugin';
        mkdir($pluginDir, 0o775, true);
        $bootOnly = "<?php\n\nfinal class BlogPlugin\n{\n    public function boot(): void\n    {\n    }\n}\n";
        file_put_contents($pluginDir . '/BlogPlugin.php', $bootOnly);

        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'PostController',
            options: ['flavor' => 'runtime', 'path' => '/posts'],
            root: $this->root,
        );

        $result = (new ControllerGenerator())->generate($ctx);
        $merged = $this->fileNamed($result->files, 'BlogPlugin.php');

        $this->assertStringContainsString('implements \\Milpa\\Runtime\\Http\\RouteProviderInterface', $merged->contents);
        $this->assertStringContainsString('public function routes(): array', $merged->contents);
        $this->assertStringContainsString("path: '/posts',", $merged->contents);
        $this->assertPhpLints($merged->contents);
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
