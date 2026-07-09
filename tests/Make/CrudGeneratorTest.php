<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Milpa\Data\FileRepository;
use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\Generators\CrudGenerator;
use Milpa\DevTools\Make\PlannedFile;

/**
 * Covers {@see CrudGenerator}: that it COMPOSES {@see \Milpa\DevTools\Make\Generators\EntityGenerator}
 * for the entity (never reimplementing field/property/accessor code), renders a genuinely new 5-method
 * REST controller shape neither `make:controller` nor any prior stub covers, and wires both concerns
 * into ONE combined repository+routes plugin — mirroring
 * {@see EntityGeneratorRuntimeTest}/{@see ControllerGeneratorRuntimeTest}'s real-temp-directory setup.
 *
 * Unlike {@see PluginGeneratorTest}/{@see ServiceGeneratorTest}, the generated PLUGIN file here is
 * never `require`d in these tests, only lint-checked (`php -l`) and asserted on its source text — it
 * `implements Milpa\Runtime\Http\RouteProviderInterface` and its `routes()` constructs
 * `Milpa\Http\Routing\Route`/`HandlerReference`/`HttpMethod`, and neither `milpa/runtime` nor
 * `milpa/http` is a `milpa/devtools` dependency (confirmed: absent from `vendor/`) — `implements`
 * forces immediate interface resolution at class-declaration time, so actually `require`-ing it here
 * would fatal. {@see ControllerGeneratorRuntimeTest} already established this exact same constraint
 * for its own `RouteProviderInterface`-implementing plugin (never `require`d there either, only
 * linted) — this test class mirrors that precedent. See the F1b report's Fricciones.
 */
final class CrudGeneratorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-crud-runtime-' . uniqid();
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

    public function testGeneratesEntityControllerAndPluginAtTheCorrectPaths(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'Task',
            options: ['flavor' => 'runtime', 'fields' => 'title:string:200, status:string:20, priority:int'],
            root: $this->root,
        );

        $result = (new CrudGenerator())->generate($ctx);

        $this->assertCount(3, $result->files, 'expected entity + controller + plugin');

        $entity = $this->fileNamed($result->files, 'Task.php');
        $this->assertStringEndsWith('/src/Plugins/BoardPlugin/Entities/Task.php', $entity->path);

        $controller = $this->fileNamed($result->files, 'TaskController.php');
        $this->assertStringEndsWith('/src/Plugins/BoardPlugin/Controllers/TaskController.php', $controller->path);

        $plugin = $this->fileNamed($result->files, 'BoardPlugin.php');
        $this->assertStringEndsWith('/src/Plugins/BoardPlugin/BoardPlugin.php', $plugin->path);

        $this->assertSame(Flavor::Runtime, $result->flavor);
        $this->assertSame('controller', $result->verifyKind);
        $this->assertSame('App\\Plugins\\BoardPlugin\\Controllers\\TaskController', $result->verifyTarget);
    }

    public function testEntityImplementsEntityInterfaceAndCarriesTheParsedFields(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'Task',
            options: ['flavor' => 'runtime', 'fields' => 'title:string, status:string, priority:int'],
            root: $this->root,
        );

        $result = (new CrudGenerator())->generate($ctx);
        $entity = $this->fileNamed($result->files, 'Task.php');
        $code = $entity->contents;

        $this->assertStringContainsString('namespace App\\Plugins\\BoardPlugin\\Entities;', $code);
        $this->assertStringContainsString('use Milpa\\Data\\EntityInterface;', $code);
        $this->assertStringContainsString('final readonly class Task implements EntityInterface', $code);
        $this->assertStringContainsString('public string $title,', $code);
        $this->assertStringContainsString('public string $status,', $code);
        $this->assertStringContainsString('public int $priority,', $code);

        $this->assertPhpLints($code);
    }

    public function testControllerHasExactlyTheFiveCrudMethodsWithPsr7Signatures(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'Task',
            options: ['flavor' => 'runtime', 'fields' => 'title:string'],
            root: $this->root,
        );

        $result = (new CrudGenerator())->generate($ctx);
        $controller = $this->fileNamed($result->files, 'TaskController.php');
        $code = $controller->contents;

        $this->assertStringContainsString('namespace App\\Plugins\\BoardPlugin\\Controllers;', $code);
        $this->assertStringContainsString('use App\\Plugins\\BoardPlugin\\Entities\\Task;', $code);
        $this->assertStringContainsString('use Milpa\\Data\\RepositoryInterface;', $code);
        $this->assertStringContainsString('use Psr\\Http\\Message\\ResponseInterface;', $code);
        $this->assertStringContainsString('use Psr\\Http\\Message\\ServerRequestInterface;', $code);
        $this->assertStringContainsString('final class TaskController', $code);

        foreach (['index', 'show', 'create', 'update', 'delete'] as $method) {
            $this->assertStringContainsString(
                "public function {$method}(ServerRequestInterface \$request): ResponseInterface",
                $code,
                "expected a single-argument PSR-7 {$method}() method",
            );
        }

        $this->assertPhpLints($code);
    }

    public function testNoExistingPluginGeneratesACombinedRepositoryAndRoutesPlugin(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'Task',
            options: ['flavor' => 'runtime', 'fields' => 'title:string'],
            root: $this->root,
        );

        $result = (new CrudGenerator())->generate($ctx);
        $plugin = $this->fileNamed($result->files, 'BoardPlugin.php');
        $code = $plugin->contents;

        $this->assertStringContainsString('namespace App\\Plugins\\BoardPlugin;', $code);
        $this->assertStringContainsString('use App\\Plugins\\BoardPlugin\\Entities\\Task;', $code);
        $this->assertStringContainsString('use App\\Plugins\\BoardPlugin\\Controllers\\TaskController;', $code);
        $this->assertStringContainsString('use Milpa\\Data\\FileRepository;', $code);
        $this->assertStringContainsString('use Milpa\\Runtime\\Http\\RouteProviderInterface;', $code);
        $this->assertStringContainsString('use Milpa\\Runtime\\Support\\RootResolver;', $code);
        $this->assertStringContainsString('implements PluginInterface, RouteProviderInterface', $code);

        // boot() registers the repository AND the controller — the controller's constructor needs
        // the repository as a constructor arg, so it cannot rely on ambient DI autowiring for an
        // interface-typed dependency (see the generator's class docblock / the F1b report's Fricciones).
        $this->assertStringContainsString("Task::class . 'Repository'", $code);
        $this->assertStringContainsString("new FileRepository((new RootResolver())->resolve() . '/var/tasks.json', Task::class)", $code);
        $this->assertStringContainsString('TaskController::class,', $code);
        $this->assertStringContainsString('new TaskController($repository));', $code);

        // routes() covers all 5 REST verb/path pairs, each pointing at TaskController.
        $this->assertStringContainsString("path: '/tasks',", $code);
        $this->assertStringContainsString("path: '/tasks/{id}',", $code);
        $this->assertStringContainsString('methods: HttpMethod::GET,', $code);
        $this->assertStringContainsString('methods: HttpMethod::POST,', $code);
        $this->assertStringContainsString('methods: HttpMethod::PUT,', $code);
        $this->assertStringContainsString('methods: HttpMethod::DELETE,', $code);
        foreach (['index', 'show', 'create', 'update', 'delete'] as $method) {
            $this->assertStringContainsString(
                "handler: new HandlerReference(TaskController::class, '{$method}'),",
                $code,
            );
        }

        $this->assertPhpLints($code);

        $this->assertNotNull($result->guidance);
        $this->assertStringContainsString('config/plugins.php', (string) $result->guidance);
        $this->assertStringContainsString('App\\Plugins\\BoardPlugin\\BoardPlugin::class', (string) $result->guidance);
    }

    /**
     * The round-trip proof at the source-text level: the plugin's routes() handlers reference
     * `TaskController::class` (the SAME class the controller file declares) and its boot() registers
     * the repository under `Task::class . 'Repository'` (the SAME id key
     * `crud-controller.runtime.php.stub`'s constructor expects to receive via that repository) — the
     * three generated pieces genuinely reference each other, not just independently plausible shapes.
     */
    public function testGeneratedPiecesReferenceEachOtherConsistently(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'Task',
            options: ['flavor' => 'runtime', 'fields' => 'title:string'],
            root: $this->root,
        );

        $result = (new CrudGenerator())->generate($ctx);
        $controllerCode = $this->fileNamed($result->files, 'TaskController.php')->contents;
        $pluginCode = $this->fileNamed($result->files, 'BoardPlugin.php')->contents;

        // the plugin wires the controller with the same repository it registers under the entity's
        // conventional repository id.
        $this->assertStringContainsString("Task::class . 'Repository'", $pluginCode);
        $this->assertStringContainsString('new TaskController($repository)', $pluginCode);

        // the controller's constructor genuinely accepts a RepositoryInterface (what the plugin
        // constructs and passes in).
        $this->assertStringContainsString('RepositoryInterface<Task> $repository', $controllerCode);
        $this->assertStringContainsString('private readonly RepositoryInterface $repository', $controllerCode);
    }

    public function testCustomTableOptionDrivesBothTheRepositoryFileAndTheRouteSlug(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'Task',
            options: ['flavor' => 'runtime', 'fields' => 'title:string', 'table' => 'board_tasks'],
            root: $this->root,
        );

        $result = (new CrudGenerator())->generate($ctx);
        $plugin = $this->fileNamed($result->files, 'BoardPlugin.php');

        $this->assertStringContainsString("/var/board_tasks.json'", $plugin->contents);
        $this->assertStringContainsString("path: '/board_tasks',", $plugin->contents);
        $this->assertStringContainsString("path: '/board_tasks/{id}',", $plugin->contents);
    }

    public function testExistingPluginIsNotEditedAndGetsCombinedGuidanceInstead(): void
    {
        $pluginDir = $this->root . '/src/Plugins/BoardPlugin';
        mkdir($pluginDir, 0o775, true);
        $existing = "<?php\n// hand-written plugin — must not be touched\n";
        file_put_contents($pluginDir . '/BoardPlugin.php', $existing);

        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'Task',
            options: ['flavor' => 'runtime', 'fields' => 'title:string'],
            root: $this->root,
        );

        $result = (new CrudGenerator())->generate($ctx);

        $this->assertCount(2, $result->files, 'entity + controller only — the existing plugin file must not be (re)written');
        $this->assertSame('Task.php', basename($result->files[0]->path));
        $this->assertSame('TaskController.php', basename($result->files[1]->path));
        $this->assertSame($existing, file_get_contents($pluginDir . '/BoardPlugin.php'), 'existing plugin file must be untouched on disk');

        $this->assertNotNull($result->guidance);
        $guidance = (string) $result->guidance;
        $this->assertStringContainsString('already exists', $guidance);
        $this->assertStringContainsString('registerService(', $guidance);
        $this->assertStringContainsString("Task::class . 'Repository'", $guidance);
        $this->assertStringContainsString('TaskController::class,', $guidance);
        $this->assertStringContainsString("new Route(path: '/tasks'", $guidance);
        $this->assertStringContainsString('Controller/route wiring:', $guidance);
    }

    public function testLegacyFlavorThrowsAClearError(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'Task',
            options: ['flavor' => 'legacy'],
            root: $this->root,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--flavor=runtime');
        (new CrudGenerator())->generate($ctx);
    }

    /**
     * The load-bearing proof, scoped to what this test process can actually load (see the class
     * docblock — `milpa/runtime`/`milpa/http`/`nyholm/psr7` are not `milpa/devtools` dependencies, so
     * the ROUTE-WIRING plugin stays a source-text/lint proof only, same as
     * {@see ControllerGeneratorRuntimeTest}):
     *
     * - The entity genuinely round-trips (construct -> toArray() -> fromArray()) AND persists through
     *   a real `Milpa\Data\FileRepository` — mirrors
     *   {@see EntityGeneratorRuntimeTest::testGeneratedEntityRoundTripsAndPersistsThroughAFileRepository()}
     *   exactly, proving the composed entity is exactly as usable as one `make:entity` alone produces.
     * - The controller's public API is reflected on the ACTUALLY LOADED class (not asserted from
     *   source text): its constructor takes exactly one `Milpa\Data\RepositoryInterface` parameter,
     *   and each of the 5 CRUD methods takes exactly one `Psr\Http\Message\ServerRequestInterface`
     *   parameter and returns `Psr\Http\Message\ResponseInterface` — both PSR interfaces genuinely
     *   load in this process (`psr/http-message` is a devtools require-dev dependency), unlike
     *   `Nyholm\Psr7\Response`/`Milpa\Http\Routing\RouteResult`, which the method BODIES reference but
     *   are never triggered here since none of the 5 methods is actually INVOKED — only reflected.
     *
     * Run in a separate process: `require`s real, fixed-FQCN classes — isolating it avoids any "class
     * already declared" risk, mirroring {@see ControllerGeneratorRuntimeTest}/{@see EntityGeneratorRuntimeTest}.
     */
    #[RunInSeparateProcess]
    public function testGeneratedEntityAndControllerAreGenuinelyLoadableAndConsistent(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'Task',
            options: ['flavor' => 'runtime', 'fields' => 'title:string, status:string, priority:int'],
            root: $this->root,
        );

        $result = (new CrudGenerator())->generate($ctx);

        $entityFile = $this->fileNamed($result->files, 'Task.php');
        $controllerFile = $this->fileNamed($result->files, 'TaskController.php');

        foreach ([$entityFile, $controllerFile] as $file) {
            if (!is_dir(\dirname($file->path))) {
                mkdir(\dirname($file->path), 0o775, true);
            }
            file_put_contents($file->path, $file->contents);
        }
        require $entityFile->path;
        require $controllerFile->path;

        $entityFqcn = 'App\\Plugins\\BoardPlugin\\Entities\\Task';
        $this->assertTrue(class_exists($entityFqcn, false));

        $task = new $entityFqcn(id: null, title: 'Ship it', status: 'todo', priority: 1);
        $row = $task->toArray();
        $this->assertSame(['id' => null, 'title' => 'Ship it', 'status' => 'todo', 'priority' => 1], $row);

        $dataFile = $this->root . '/var/tasks.json';
        $repo = new FileRepository($dataFile, $entityFqcn);
        $id = $repo->save($entityFqcn::fromArray($row));

        $this->assertFileExists($dataFile);
        $found = $repo->find($id);
        $this->assertInstanceOf($entityFqcn, $found);
        $this->assertSame('Ship it', $found->title);

        $controllerFqcn = 'App\\Plugins\\BoardPlugin\\Controllers\\TaskController';
        $this->assertTrue(class_exists($controllerFqcn, false));

        $reflection = new \ReflectionClass($controllerFqcn);
        $ctorParams = $reflection->getConstructor()?->getParameters() ?? [];
        $this->assertCount(1, $ctorParams);
        $this->assertSame('Milpa\\Data\\RepositoryInterface', $ctorParams[0]->getType()?->getName());

        foreach (['index', 'show', 'create', 'update', 'delete'] as $methodName) {
            $method = $reflection->getMethod($methodName);
            $this->assertTrue($method->isPublic());

            $params = $method->getParameters();
            $this->assertCount(
                1,
                $params,
                "{$methodName}() must take exactly the request — path params arrive via a request "
                . 'attribute (see the stub docblock), never an extra method argument',
            );
            $this->assertSame('Psr\\Http\\Message\\ServerRequestInterface', $params[0]->getType()?->getName());
            $this->assertSame('Psr\\Http\\Message\\ResponseInterface', $method->getReturnType()?->getName());
        }
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
