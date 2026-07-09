<?php

declare(strict_types=1);

namespace Milpa\DevTools\Make\Generators;

use Milpa\DevTools\Make\ConventionDetector;
use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\GenerationResult;
use Milpa\DevTools\Make\GeneratorInterface;
use Milpa\DevTools\Make\MarkerInserter;
use Milpa\DevTools\Make\Markers;
use Milpa\DevTools\Make\PlannedFile;
use Milpa\DevTools\Make\StubRenderer;
use Milpa\DevTools\Support\ComposerAutoload;

/**
 * Generates a controller in one of two conventions — see {@see Flavor} — auto-detected per app root
 * by {@see ConventionDetector} (override with `GenerationContext`'s `flavor` option, e.g.
 * `--flavor=runtime`):
 *
 * - **Legacy**: extends {@see \Milpa\app\Providers\BaseController} with one routed method per
 *   `--methods` entry (`#[Route]`, `array $params = []`, `HttpResponse`). Follows the framework's
 *   controller convention exactly so {@see \Milpa\DevTools\Verify\ControllerVerifier} passes on the
 *   output.
 * - **Runtime**: a plain, dependency-free `final class` with a single
 *   `index(ServerRequestInterface $request): ResponseInterface` method — the `milpa/runtime` +
 *   skeleton convention, modelled on the skeleton's `HomeController`. An orphaned controller class
 *   boots nothing on its own, so this path ALSO wires a booting `GET <path> → Controller::index`
 *   route: a minimal `RouteProviderInterface` plugin is generated alongside the controller when the
 *   target plugin area doesn't exist yet, or the exact route snippet to add by hand is returned via
 *   {@see GenerationResult::$guidance} when it does — see {@see self::wireRoute()}.
 */
final class ControllerGenerator implements GeneratorInterface
{
    /**
     * RESTful (verb, path-suffix) per conventional method name — LEGACY flavor only (see class
     * docblock). Chosen so every (path, verb) pair generated for a given base route is unique —
     * `index`/`store` share the collection path with different verbs, `show`/`update`/`destroy`
     * share the member path with different verbs. Any other method name gets its own GET sub-path so
     * it can never collide either.
     *
     * @var array<string, array{verb: string, suffix: string}>
     */
    private const ROUTES = [
        'index' => ['verb' => 'GET', 'suffix' => ''],
        'store' => ['verb' => 'POST', 'suffix' => ''],
        'show' => ['verb' => 'GET', 'suffix' => '/{id}'],
        'update' => ['verb' => 'PUT', 'suffix' => '/{id}'],
        'destroy' => ['verb' => 'DELETE', 'suffix' => '/{id}'],
    ];

    private string $stubs;

    public function __construct(
        private readonly StubRenderer $renderer = new StubRenderer(),
        private readonly ConventionDetector $detector = new ConventionDetector(),
        private readonly MarkerInserter $markers = new MarkerInserter(),
    ) {
        $this->stubs = \dirname(__DIR__) . '/stubs';
    }

    /** The `<what>` token this generator answers to: `'controller'`. */
    public function name(): string
    {
        return 'controller';
    }

    /** Renders the controller (+ route wiring for runtime) per the detected/overridden {@see Flavor}. */
    public function generate(GenerationContext $context): GenerationResult
    {
        $flavor = $this->detector->detect($context->root, $context->option('flavor'));

        return $flavor === Flavor::Runtime
            ? $this->generateRuntime($context)
            : $this->generateLegacy($context);
    }

    private function generateLegacy(GenerationContext $context): GenerationResult
    {
        $namespace = 'Milpa\\Plugins\\' . $context->plugin . '\\Controllers';
        $base = $context->option('route') ?? '/' . strtolower(str_replace('Controller', '', $context->name));
        $slug = trim(str_replace('/', '_', $base), '_') ?: 'index';

        $methodNames = array_filter(array_map('trim', explode(',', $context->option('methods') ?? 'index')));
        $methods = [];
        foreach ($methodNames as $method) {
            $route = self::ROUTES[$method] ?? ['verb' => 'GET', 'suffix' => '/' . $method];
            $methods[] = $this->renderer->render($this->stubs . '/controller-method.stub', [
                'path' => $base . $route['suffix'],
                'verb' => $route['verb'],
                'routeName' => $slug . '_' . $method,
                'method' => $method,
            ]);
        }

        $contents = $this->renderer->render($this->stubs . '/controller.php.stub', [
            'namespace' => $namespace,
            'class' => $context->name,
            'methods' => implode('', $methods),
        ]);

        $path = $context->root . '/plugins/' . $context->plugin . '/Controllers/' . $context->name . '.php';

        return new GenerationResult(
            files: [new PlannedFile($path, $contents)],
            verifyKind: 'controller',
            verifyTarget: $namespace . '\\' . $context->name,
            flavor: Flavor::Legacy,
        );
    }

    private function generateRuntime(GenerationContext $context): GenerationResult
    {
        [$appNamespace, $appDir] = ComposerAutoload::primaryNamespace($context->root) ?? ['App', 'src'];
        $appDir = trim($appDir, '/');

        $controllerNamespace = $appNamespace . '\\Plugins\\' . $context->plugin . '\\Controllers';
        $controllerPath = $context->root . '/' . $appDir . '/Plugins/' . $context->plugin
            . '/Controllers/' . $context->name . '.php';

        $contents = $this->renderer->render($this->stubs . '/controller.runtime.php.stub', [
            'namespace' => $controllerNamespace,
            'class' => $context->name,
        ]);

        $files = [new PlannedFile($controllerPath, $contents)];

        ['file' => $pluginFile, 'guidance' => $guidance] = $this->wireRoute(
            $context,
            $appNamespace,
            $appDir,
            $controllerNamespace,
        );
        if ($pluginFile !== null) {
            $files[] = $pluginFile;
        }

        return new GenerationResult(
            files: $files,
            verifyKind: 'controller',
            verifyTarget: $controllerNamespace . '\\' . $context->name,
            flavor: Flavor::Runtime,
            guidance: $guidance,
        );
    }

    /**
     * Decides how the generated controller reaches a booting route — the load-bearing part of the
     * runtime path, since an orphaned controller class does nothing on its own (see the class
     * docblock):
     *
     * - No `RouteProviderInterface` plugin exists yet at the target area's conventional path
     *   (`{appDir}/Plugins/{plugin}/{plugin}.php`) → generate a minimal one, wired to the new
     *   controller, plus guidance to register it in `config/plugins.php` (a plain PHP array file —
     *   editing it deterministically is out of {@see \Milpa\DevTools\Make\WriteGuard}'s model, so
     *   that registration stays a manual step). It now ALSO carries the
     *   {@see \Milpa\DevTools\Make\Markers::ROUTES} anchor for a later run.
     * - One already exists AND carries the anchor (F1) → the `Route` entry is INSERTED at the marker
     *   via {@see \Milpa\DevTools\Make\MarkerInserter} — `$file` becomes the merged plugin, marked
     *   {@see \Milpa\DevTools\Make\PlannedFile::$merge} so {@see \Milpa\DevTools\Make\WriteGuard} does
     *   not require `--force` to write it.
     * - One already exists but carries no anchor → unchanged pre-F1 behavior: it is NOT edited
     *   (parsing/rewriting arbitrary host PHP is exactly the fragile AST surgery this generator's
     *   deterministic `PlannedFile`/`WriteGuard` model exists to avoid). The exact `Route` snippet to
     *   add by hand is returned instead.
     *
     * Existence is checked on the FILESYSTEM only (`is_file()`), not via reflection/autoloading —
     * consistent with the rest of this deterministic generate step, and safe to call from a
     * `--dry-run` before anything is installed/autoloadable.
     *
     * @return array{file: ?PlannedFile, guidance: string}
     */
    private function wireRoute(
        GenerationContext $context,
        string $appNamespace,
        string $appDir,
        string $controllerNamespace,
    ): array {
        $path = $context->option('path') ?? '/' . strtolower(str_replace('Controller', '', $context->name));
        $slug = trim(str_replace('/', '_', $path), '_') ?: 'index';
        $routeName = $slug . '_index';

        $pluginNamespace = $appNamespace . '\\Plugins\\' . $context->plugin;
        $pluginPath = $context->root . '/' . $appDir . '/Plugins/' . $context->plugin . '/' . $context->plugin . '.php';
        $pluginFqcn = $pluginNamespace . '\\' . $context->plugin;
        $controllerFqcn = $controllerNamespace . '\\' . $context->name;

        if (is_file($pluginPath)) {
            $existing = (string) file_get_contents($pluginPath);
            if ($this->markers->hasMarker($existing, Markers::ROUTES)) {
                $routeSnippet = "new \\Milpa\\Http\\Routing\\Route(\n"
                    . "    path: '{$path}',\n"
                    . "    methods: \\Milpa\\Http\\HttpMethod::GET,\n"
                    . "    name: '{$routeName}',\n"
                    . "    handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\{$controllerFqcn}::class, 'index'),\n"
                    . '),';

                $merged = $this->markers->insertBefore($existing, Markers::ROUTES, $routeSnippet, $context->flag('force'));

                $guidance = "Auto-wired into the existing plugin at {$pluginPath} (// {" . Markers::ROUTES . '} marker found).';

                return ['file' => new PlannedFile($pluginPath, $merged, merge: true), 'guidance' => $guidance];
            }

            $snippet = "new Route(\n"
                . "    path: '{$path}',\n"
                . "    methods: HttpMethod::GET,\n"
                . "    name: '{$routeName}',\n"
                . "    handler: new HandlerReference({$context->name}::class, 'index'),\n"
                . '),';

            $guidance = "A RouteProviderInterface plugin already exists at {$pluginPath} — it is left "
                . "untouched (editing existing host code is outside this generator's deterministic "
                . "write model). Add a `use {$controllerNamespace}\\{$context->name};` import and this "
                . "entry to its routes():\n\n{$snippet}";

            return ['file' => null, 'guidance' => $guidance];
        }

        $pluginContents = $this->renderer->render($this->stubs . '/plugin.runtime.php.stub', [
            'namespace' => $pluginNamespace,
            'class' => $context->plugin,
            'controllerNamespace' => $controllerNamespace,
            'controllerClass' => $context->name,
            'path' => $path,
            'routeName' => $routeName,
        ]);

        $guidance = "New plugin — register it so the kernel boots it: add {$pluginFqcn}::class to the "
            . 'list returned by config/plugins.php.';

        return ['file' => new PlannedFile($pluginPath, $pluginContents), 'guidance' => $guidance];
    }
}
