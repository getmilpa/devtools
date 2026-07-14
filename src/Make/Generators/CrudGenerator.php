<?php

/**
 * This file is part of Milpa DevTools — the generate-verify-inspect developer loop of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

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
 * Generates the compound: a full HTTP+persistence resource in one shot, by COMPOSING
 * {@see EntityGenerator} (entity class + its `--fields` DSL — never reimplemented here) with a new
 * 5-method CRUD controller and a combined repository+routes wiring plugin this generator owns.
 *
 * `make:controller`'s runtime stub only ever renders a single `index()` method (see its class
 * docblock), so a REST resource controller (`index`/`show`/`create`/`update`/`delete`) is a genuinely
 * new shape — {@see self::generateRuntime()} renders it from its own `crud-controller.runtime.php.stub`
 * rather than calling `ControllerGenerator::generate()` at all. Likewise, wiring both a
 * `Milpa\Data\FileRepository` AND 5 routes into one plugin's `boot()`/`routes()` is a shape neither
 * `entity-plugin.runtime.php.stub` (repository only) nor `plugin.runtime.php.stub` (a single GET
 * route) covers alone, so it gets its own `crud-plugin.runtime.php.stub` too — see
 * {@see self::wireCrudPlugin()}.
 *
 * `EntityGenerator::generate()` IS reused for the entity class itself (property/accessor generation
 * from the `--fields` DSL is exactly the "do not reimplement" concern this composition avoids
 * duplicating). Calling it in isolation would ALSO plan its own repository-only wiring plugin at this
 * same target path when none exists on disk yet (see {@see EntityGenerator::wireRepository()}) — that
 * planned file is superseded by this generator's own combined repo+routes plugin and dropped rather
 * than written twice to the same path; see {@see self::generateRuntime()}.
 *
 * Only a RUNTIME convention exists — see {@see generate()} for why LEGACY throws.
 */
final class CrudGenerator implements GeneratorInterface
{
    private string $stubs;

    public function __construct(
        private readonly EntityGenerator $entityGenerator = new EntityGenerator(),
        private readonly StubRenderer $renderer = new StubRenderer(),
        private readonly ConventionDetector $detector = new ConventionDetector(),
        private readonly MarkerInserter $markers = new MarkerInserter(),
    ) {
        $this->stubs = \dirname(__DIR__) . '/stubs';
    }

    /** The `<what>` token this generator answers to: `'crud'`. */
    public function name(): string
    {
        return 'crud';
    }

    /**
     * Renders the entity + controller + wiring plugin per the detected/overridden {@see Flavor}.
     *
     * @throws \RuntimeException When the detected/forced flavor is {@see Flavor::Legacy} — see
     *                           {@see generateLegacy()}.
     */
    public function generate(GenerationContext $context): GenerationResult
    {
        $flavor = $this->detector->detect($context->root, $context->option('flavor'));

        return $flavor === Flavor::Runtime
            ? $this->generateRuntime($context)
            : $this->generateLegacy($context);
    }

    /**
     * The legacy Milpa host convention has no compound CRUD scaffold to target in this engine — its
     * controller/entity conventions each have their own fixed shape, but nothing composes them into
     * one command (a legacy CRUD resource is hand-wired: a controller with `#[Route]`-attributed
     * methods calling into Doctrine directly, with no single stubbed shape to generate against, the
     * same reasoning {@see ServiceGenerator::generateLegacy()} and {@see PluginGenerator::generateLegacy()}
     * already give for their own concerns). Throws a clear, actionable message instead of emitting a
     * guess.
     *
     * @throws \RuntimeException Always.
     */
    private function generateLegacy(GenerationContext $context): GenerationResult
    {
        throw new \RuntimeException(
            'make:crud has no legacy convention to scaffold — a composed entity+controller+routes '
            . 'REST resource is a runtime-only concept in this engine (the legacy host has no single '
            . 'CRUD shape to stub against, only its separate controller/entity conventions); use '
            . '--flavor=runtime (the default outside a legacy host).',
        );
    }

    private function generateRuntime(GenerationContext $context): GenerationResult
    {
        [$appNamespace, $appDir] = ComposerAutoload::primaryNamespace($context->root) ?? ['App', 'src'];
        $appDir = trim($appDir, '/');

        $entityResult = $this->generateEntity($context);

        $entityNamespace = $appNamespace . '\\Plugins\\' . $context->plugin . '\\Entities';
        $pluginPath = $context->root . '/' . $appDir . '/Plugins/' . $context->plugin . '/' . $context->plugin . '.php';

        // EntityGenerator, called in isolation above, would ALSO plan its own repository-only wiring
        // plugin at this exact path when none exists on disk yet — see the class docblock. This
        // generator supersedes that with its own combined repo+routes plugin below, so drop it here
        // rather than let two PlannedFiles target the same path.
        $files = array_values(array_filter(
            $entityResult->files,
            static fn (PlannedFile $file): bool => $file->path !== $pluginPath,
        ));

        $controllerNamespace = $appNamespace . '\\Plugins\\' . $context->plugin . '\\Controllers';
        $controllerClass = $context->name . 'Controller';
        $controllerPath = $context->root . '/' . $appDir . '/Plugins/' . $context->plugin
            . '/Controllers/' . $controllerClass . '.php';
        $table = $context->option('table') ?? strtolower($context->name) . 's';

        $controllerContents = $this->renderer->render($this->stubs . '/crud-controller.runtime.php.stub', [
            'namespace' => $controllerNamespace,
            'class' => $controllerClass,
            'entityNamespace' => $entityNamespace,
            'entityClass' => $context->name,
            'table' => $table,
        ]);
        $files[] = new PlannedFile($controllerPath, $controllerContents);

        [
            'file' => $pluginFile,
            'guidance' => $routeGuidance,
            'suppressEntityGuidance' => $suppressEntityGuidance,
        ] = $this->wireCrudPlugin(
            $context,
            $appNamespace,
            $appDir,
            $entityNamespace,
            $controllerNamespace,
            $controllerClass,
            $table,
        );
        if ($pluginFile !== null) {
            $files[] = $pluginFile;
        }

        return new GenerationResult(
            files: $files,
            // GenerationResult::$verifyKind is typed 'controller'|'entity'|null — it has no
            // multi-target mechanism for a compound result that produced BOTH. 'controller' is
            // reported because ControllerVerifier is the more informative single check on the
            // runtime flavor, and the entity already went through EntityGenerator::generate()'s own
            // code path — the exact same one `make:entity` alone would use — so its shape is already
            // proven by that generator's own verify story. See the F1b report's Fricciones.
            verifyKind: 'controller',
            verifyTarget: $controllerNamespace . '\\' . $controllerClass,
            flavor: Flavor::Runtime,
            // F1: once autoWireCrudPlugin() has actually spliced the repository+controller
            // registration into the existing plugin's // {coa:services} marker, EntityGenerator's OWN
            // "add this to its boot() by hand" guidance (produced by the SEPARATE generateEntity()
            // call above, which knows nothing about the marker insertion) describes a step that is
            // already done — combining it in would read as self-contradictory ("add this" right next
            // to "auto-wired already"). $suppressEntityGuidance drops it in exactly that one case.
            guidance: $this->combineGuidance($suppressEntityGuidance ? null : $entityResult->guidance, $routeGuidance),
        );
    }

    /**
     * Delegates entity generation to {@see EntityGenerator} — property/accessor code from the
     * `--fields` DSL is never reimplemented here (see the class docblock). Forces `flavor=runtime`
     * (make:crud has no legacy convention of its own, see {@see generateLegacy()}) and passes
     * `--fields`/`--table` straight through via the same options array.
     */
    private function generateEntity(GenerationContext $context): GenerationResult
    {
        $entityOptions = $context->options;
        $entityOptions['flavor'] = 'runtime';

        $entityContext = new GenerationContext($context->plugin, $context->name, $entityOptions, $context->root);

        return $this->entityGenerator->generate($entityContext);
    }

    /**
     * Decides how the generated entity+controller reach a booting repository + route table — the
     * load-bearing part of this generator (see the class docblock). Mirrors
     * {@see EntityGenerator::wireRepository()} / {@see ControllerGenerator::wireRoute()}'s exist-check
     * pattern exactly, combined into ONE plugin file covering BOTH concerns:
     *
     * - No `PluginInterface` plugin exists yet at the target area's conventional path -> a combined
     *   `crud-plugin.runtime.php.stub` is generated: its `boot()` constructs a `FileRepository` and
     *   registers it AND the controller (already carrying that repository) into the container; its
     *   `routes()` returns all 5 REST routes — now ALSO carrying both
     *   {@see \Milpa\DevTools\Make\Markers::SERVICES}/{@see \Milpa\DevTools\Make\Markers::ROUTES}
     *   anchors for a later run.
     * - One already exists AND carries either anchor (F1) -> the repository+controller registration
     *   and/or the 5 REST routes are INSERTED at the matching marker(s) via
     *   {@see \Milpa\DevTools\Make\MarkerInserter} — `$file` becomes the merged plugin, marked
     *   {@see \Milpa\DevTools\Make\PlannedFile::$merge} so {@see \Milpa\DevTools\Make\WriteGuard} does
     *   not require `--force` to write it. Whichever anchor is MISSING (a plugin that only implements
     *   one of `boot()`/`routes()`'s conventions) still falls back to guidance for that half.
     * - One already exists but carries NEITHER anchor -> unchanged pre-F1 behavior: it is NOT edited.
     *   The exact snippets to hand-add are returned via guidance instead.
     *
     * Existence is checked on the FILESYSTEM only (`is_file()`), consistent with the rest of this
     * deterministic generate step.
     *
     * @return array{file: ?PlannedFile, guidance: string, suppressEntityGuidance: bool}
     */
    private function wireCrudPlugin(
        GenerationContext $context,
        string $appNamespace,
        string $appDir,
        string $entityNamespace,
        string $controllerNamespace,
        string $controllerClass,
        string $table,
    ): array {
        $pluginNamespace = $appNamespace . '\\Plugins\\' . $context->plugin;
        $pluginPath = $context->root . '/' . $appDir . '/Plugins/' . $context->plugin . '/' . $context->plugin . '.php';
        $pluginFqcn = $pluginNamespace . '\\' . $context->plugin;
        $repositoryId = "{$context->name}::class . 'Repository'";

        if (is_file($pluginPath)) {
            $existing = (string) file_get_contents($pluginPath);
            $hasServicesMarker = $this->markers->hasMarker($existing, Markers::SERVICES);
            $hasRoutesMarker = $this->markers->hasMarker($existing, Markers::ROUTES);

            if ($hasServicesMarker || $hasRoutesMarker) {
                return $this->autoWireCrudPlugin(
                    $context,
                    $existing,
                    $pluginPath,
                    $entityNamespace,
                    $controllerNamespace,
                    $controllerClass,
                    $table,
                    $hasServicesMarker,
                    $hasRoutesMarker,
                );
            }

            $bootSnippet = "\$repository = new FileRepository((new RootResolver())->resolve() . '/var/{$table}.json', {$context->name}::class);\n\n"
                . "\$this->container->registerService(\n"
                . "    {$repositoryId},\n"
                . "    \$repository,\n"
                . ");\n"
                . "\$this->container->registerService(\n"
                . "    {$controllerClass}::class,\n"
                . "    new {$controllerClass}(\$repository),\n"
                . ');';

            $routesSnippet = "new Route(path: '/{$table}', methods: HttpMethod::GET, name: '{$table}_index', "
                . "handler: new HandlerReference({$controllerClass}::class, 'index')),\n"
                . "new Route(path: '/{$table}/{id}', methods: HttpMethod::GET, name: '{$table}_show', "
                . "handler: new HandlerReference({$controllerClass}::class, 'show')),\n"
                . "new Route(path: '/{$table}', methods: HttpMethod::POST, name: '{$table}_create', "
                . "handler: new HandlerReference({$controllerClass}::class, 'create')),\n"
                . "new Route(path: '/{$table}/{id}', methods: HttpMethod::PUT, name: '{$table}_update', "
                . "handler: new HandlerReference({$controllerClass}::class, 'update')),\n"
                . "new Route(path: '/{$table}/{id}', methods: HttpMethod::DELETE, name: '{$table}_delete', "
                . "handler: new HandlerReference({$controllerClass}::class, 'delete')),";

            $guidance = "A plugin already exists at {$pluginPath} — it is left untouched (editing "
                . "existing host code is outside this generator's deterministic write model). Add "
                . "`use {$entityNamespace}\\{$context->name};`, `use {$controllerNamespace}\\{$controllerClass};`, "
                . '`use Milpa\\Data\\FileRepository;`, `use Milpa\\Http\\HttpMethod;`, '
                . '`use Milpa\\Http\\Routing\\HandlerReference;`, `use Milpa\\Http\\Routing\\Route;`, '
                . '`use Milpa\\Runtime\\Http\\RouteProviderInterface;` and `use Milpa\\Runtime\\Support\\RootResolver;` '
                . "imports, implement RouteProviderInterface if not already, add this to its boot():\n\n{$bootSnippet}\n\n"
                . "and this to its routes():\n\n{$routesSnippet}\n\n"
                . "Resolve the repository later via \$container->get({$repositoryId}).";

            return ['file' => null, 'guidance' => $guidance, 'suppressEntityGuidance' => false];
        }

        $pluginContents = $this->renderer->render($this->stubs . '/crud-plugin.runtime.php.stub', [
            'namespace' => $pluginNamespace,
            'class' => $context->plugin,
            'entityNamespace' => $entityNamespace,
            'entityClass' => $context->name,
            'controllerNamespace' => $controllerNamespace,
            'controllerClass' => $controllerClass,
            'table' => $table,
        ]);

        $guidance = "New plugin — register it so the kernel boots it: add {$pluginFqcn}::class to the "
            . 'list returned by config/plugins.php. Its boot() wires a FileRepository for '
            . "{$context->name} and registers {$controllerClass}; resolve the repository later via "
            . "\$container->get({$repositoryId}).";

        return ['file' => new PlannedFile($pluginPath, $pluginContents), 'guidance' => $guidance, 'suppressEntityGuidance' => false];
    }

    /**
     * F1: auto-wires into `$existing` at whichever of {@see Markers::SERVICES}/{@see Markers::ROUTES}
     * it carries — called only once {@see wireCrudPlugin()} has confirmed at least one is present.
     * Each insertion uses fully-qualified inline class references (`\Foo\Bar::class`) rather than
     * adding `use` imports, so this never has to touch (or even inspect) `$existing`'s import block —
     * a second, riskier anchor this deterministic splice deliberately avoids needing.
     *
     * `suppressEntityGuidance` is true exactly when {@see Markers::SERVICES} was found (the
     * repository+controller registration was itself auto-wired) — see {@see generateRuntime()} for
     * why that specific case needs to drop {@see EntityGenerator}'s own separate guidance.
     *
     * @return array{file: PlannedFile, guidance: string, suppressEntityGuidance: bool}
     */
    private function autoWireCrudPlugin(
        GenerationContext $context,
        string $existing,
        string $pluginPath,
        string $entityNamespace,
        string $controllerNamespace,
        string $controllerClass,
        string $table,
        bool $hasServicesMarker,
        bool $hasRoutesMarker,
    ): array {
        $repositoryId = "{$context->name}::class . 'Repository'";
        $entityFqcn = $entityNamespace . '\\' . $context->name;
        $controllerFqcn = $controllerNamespace . '\\' . $controllerClass;
        $force = $context->flag('force');

        $merged = $existing;
        $wiredMarkers = [];
        $missingGuidance = [];

        if ($hasServicesMarker) {
            $bootSnippet = "\$repository = new \\Milpa\\Data\\FileRepository((new \\Milpa\\Runtime\\Support\\RootResolver())->resolve() . '/var/{$table}.json', \\{$entityFqcn}::class);\n\n"
                . "\$this->container->registerService(\n"
                . "    \\{$entityFqcn}::class . 'Repository',\n"
                . "    \$repository,\n"
                . ");\n"
                . "\$this->container->registerService(\n"
                . "    \\{$controllerFqcn}::class,\n"
                . "    new \\{$controllerFqcn}(\$repository),\n"
                . ');';

            $merged = $this->markers->insertBefore($merged, Markers::SERVICES, $bootSnippet, $force);
            $wiredMarkers[] = Markers::SERVICES;
        } else {
            $missingGuidance[] = "No // {" . Markers::SERVICES . "} marker — add the repository+controller "
                . "registration to its boot() by hand:\n\n\$repository = new FileRepository((new RootResolver())"
                . "->resolve() . '/var/{$table}.json', {$context->name}::class);\n\n\$this->container->registerService("
                . "{$repositoryId}, \$repository);\n\$this->container->registerService({$controllerClass}::class, "
                . "new {$controllerClass}(\$repository));";
        }

        if ($hasRoutesMarker) {
            $routesSnippet = "new \\Milpa\\Http\\Routing\\Route(path: '/{$table}', methods: \\Milpa\\Http\\HttpMethod::GET, "
                . "name: '{$table}_index', handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\{$controllerFqcn}::class, 'index')),\n"
                . "new \\Milpa\\Http\\Routing\\Route(path: '/{$table}/{id}', methods: \\Milpa\\Http\\HttpMethod::GET, "
                . "name: '{$table}_show', handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\{$controllerFqcn}::class, 'show')),\n"
                . "new \\Milpa\\Http\\Routing\\Route(path: '/{$table}', methods: \\Milpa\\Http\\HttpMethod::POST, "
                . "name: '{$table}_create', handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\{$controllerFqcn}::class, 'create')),\n"
                . "new \\Milpa\\Http\\Routing\\Route(path: '/{$table}/{id}', methods: \\Milpa\\Http\\HttpMethod::PUT, "
                . "name: '{$table}_update', handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\{$controllerFqcn}::class, 'update')),\n"
                . "new \\Milpa\\Http\\Routing\\Route(path: '/{$table}/{id}', methods: \\Milpa\\Http\\HttpMethod::DELETE, "
                . "name: '{$table}_delete', handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\{$controllerFqcn}::class, 'delete')),";

            $merged = $this->markers->insertBefore($merged, Markers::ROUTES, $routesSnippet, $force);
            $wiredMarkers[] = Markers::ROUTES;
        } else {
            $missingGuidance[] = "No // {" . Markers::ROUTES . '} marker — add the 5 REST routes to its '
                . "routes() by hand (see make:controller's own guidance shape for the Route/HandlerReference "
                . 'imports needed).';
        }

        $wiredList = implode(', ', array_map(static fn (string $marker): string => '// {' . $marker . '}', $wiredMarkers));
        $guidance = "Auto-wired into the existing plugin at {$pluginPath} ({$wiredList} marker(s) found). "
            . "Resolve the repository later via \$container->get({$repositoryId}).";
        if ($missingGuidance !== []) {
            $guidance .= "\n\n" . implode("\n\n", $missingGuidance);
        }

        return [
            'file' => new PlannedFile($pluginPath, $merged, merge: true),
            'guidance' => $guidance,
            'suppressEntityGuidance' => $hasServicesMarker,
        ];
    }

    /**
     * Combines {@see EntityGenerator}'s own wiring guidance with this generator's route/controller
     * wiring guidance into one clearly-delimited string — `GenerationResult::$guidance` has no
     * multi-field mechanism for a compound result produced from two sub-generations, and neither is
     * silently dropped in favor of the other, even though both describe the same target plugin file
     * (redundant in the "no existing plugin" case, but not incorrect — see the F1b report's
     * Fricciones for the tradeoff this made).
     */
    private function combineGuidance(?string $entityGuidance, string $routeGuidance): string
    {
        if ($entityGuidance === null || trim($entityGuidance) === '') {
            return $routeGuidance;
        }

        return "Entity/repository wiring (from make:entity's own generator):\n{$entityGuidance}\n\n"
            . "Controller/route wiring:\n{$routeGuidance}";
    }
}
