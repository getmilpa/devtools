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
use Milpa\DevTools\Make\PluginSurgeon;
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
 * a `Milpa\Data` repository AND 5 routes into one plugin's `boot()`/`routes()` is a shape neither
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
        private readonly PluginSurgeon $surgeon = new PluginSurgeon(),
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
            // F1: once wireExistingPlugin() has actually spliced the repository+controller
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
     *   `crud-plugin.runtime.php.stub` is generated: its `boot()` builds the repository through
     *   `RepositoryFactory::fromConfig()` (so the backend is the app's `storage` config, not a
     *   hardcoded JSON file) and
     *   registers it AND the controller (already carrying that repository) into the container; its
     *   `routes()` returns all 5 REST routes — now ALSO carrying both
     *   {@see \Milpa\DevTools\Make\Markers::SERVICES}/{@see \Milpa\DevTools\Make\Markers::ROUTES}
     *   anchors for a later run.
     * - One already exists -> BOTH concerns are MATERIALIZED into it, each half resolved
     *   independently: an already-present registration/route set is left alone, a `// {coa:*}`
     *   anchor takes the {@see \Milpa\DevTools\Make\MarkerInserter} splice, and an unmarked half is
     *   inserted structurally via {@see \Milpa\DevTools\Make\PluginSurgeon} — into `boot()` /
     *   `routes()`'s literal return array, adding the method (and, for routes, the
     *   `RouteProviderInterface` declaration) when absent. The merged plugin is marked
     *   {@see \Milpa\DevTools\Make\PlannedFile::$merge} so {@see \Milpa\DevTools\Make\WriteGuard}
     *   does not require `--force`. Only a half the surgeon refuses (unparseable file, no literal
     *   return array) falls back to guidance NAMING the reason — see {@see self::wireExistingPlugin()}.
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
            return $this->wireExistingPlugin(
                $context,
                (string) file_get_contents($pluginPath),
                $pluginPath,
                $entityNamespace,
                $controllerNamespace,
                $controllerClass,
                $table,
            );
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
            . "list returned by config/plugins.php. Its boot() builds the {$context->name} repository "
            . "from the app's 'storage' config via RepositoryFactory — set storage.driver in "
            . 'config/app.php to file, sqlite, mysql or memory (with its path/dsn); with no storage '
            . "block it defaults to a JSON file at var/{$table}.json — and registers {$controllerClass}. "
            . "Resolve the repository later via \$container->get({$repositoryId}).";

        return ['file' => new PlannedFile($pluginPath, $pluginContents), 'guidance' => $guidance, 'suppressEntityGuidance' => false];
    }

    /**
     * Materializes the repository+controller registration AND the 5 REST routes into an EXISTING
     * plugin file, each half resolved independently through the same ladder: a half already present
     * (semantic needle — the same one {@see \Milpa\DevTools\Make\PostconditionVerifier} checks) is
     * left alone; a `// {coa:*}` anchor takes the {@see \Milpa\DevTools\Make\MarkerInserter} splice;
     * an unmarked half is inserted structurally via {@see \Milpa\DevTools\Make\PluginSurgeon}; and
     * only a half the surgeon refuses falls back to guidance NAMING the reason. Every snippet uses
     * fully-qualified inline class references (`\Foo\Bar::class`) rather than `use` imports, so no
     * path ever has to touch (or even inspect) `$existing`'s import block — a second, riskier
     * anchor this deterministic splice deliberately avoids needing.
     *
     * `suppressEntityGuidance` is true exactly when the boot() half was handled here (wired, or
     * found already wired) — {@see EntityGenerator}'s own separate "add this to its boot()" advice,
     * produced by the isolated {@see generateEntity()} call, would then describe a step already done
     * and read as self-contradictory next to "Auto-wired"; see {@see generateRuntime()}.
     *
     * @return array{file: ?PlannedFile, guidance: string, suppressEntityGuidance: bool}
     */
    private function wireExistingPlugin(
        GenerationContext $context,
        string $existing,
        string $pluginPath,
        string $entityNamespace,
        string $controllerNamespace,
        string $controllerClass,
        string $table,
    ): array {
        $repositoryId = "{$context->name}::class . 'Repository'";
        $entityFqcn = $entityNamespace . '\\' . $context->name;
        $controllerFqcn = $controllerNamespace . '\\' . $controllerClass;
        $force = $context->flag('force');
        $reason = $this->surgeon->diagnose($existing);

        $merged = $existing;
        $wired = [];
        $fallbacks = [];
        $bootHandled = false;

        $bootSnippet = $this->fullyQualifiedBootSnippet($entityFqcn, $controllerFqcn, $table);
        if (str_contains($merged, $repositoryId)) {
            $bootHandled = true;
        } elseif ($this->markers->hasMarker($merged, Markers::SERVICES)) {
            $merged = $this->markers->insertBefore($merged, Markers::SERVICES, $bootSnippet, $force);
            $wired[] = 'boot() at // {' . Markers::SERVICES . '}';
            $bootHandled = true;
        } elseif ($reason === null) {
            try {
                $merged = $this->surgeon->hasMethod($merged, 'boot')
                    ? $this->surgeon->insertIntoMethod($merged, 'boot', $bootSnippet)
                    : $this->surgeon->appendMethod(
                        $merged,
                        $this->surgeon->wrapMethod('public function boot(): void', $bootSnippet),
                    );
                $wired[] = 'boot(), structurally';
                $bootHandled = true;
            } catch (\RuntimeException $e) {
                $fallbacks[] = 'The repository+controller registration could not be inserted ('
                    . $e->getMessage() . ") — add this to its boot() (fully qualified, no imports "
                    . "needed):\n\n{$bootSnippet}";
            }
        } else {
            $fallbacks[] = "The repository+controller registration could not be inserted ({$reason}) "
                . "— add this to its boot() (fully qualified, no imports needed):\n\n{$bootSnippet}";
        }

        $routesSnippet = $this->fullyQualifiedRoutesSnippet($controllerFqcn, $table);
        if (str_contains($merged, "'{$table}_index'")) {
            // all 5 names travel together in every shape this engine emits; index stands for the set.
        } elseif ($this->markers->hasMarker($merged, Markers::ROUTES)) {
            $merged = $this->markers->insertBefore($merged, Markers::ROUTES, $routesSnippet, $force);
            $wired[] = 'routes() at // {' . Markers::ROUTES . '}';
        } elseif ($reason === null) {
            try {
                if ($this->surgeon->hasMethod($merged, 'routes')) {
                    $merged = $this->surgeon->insertIntoReturnArray($merged, 'routes', $routesSnippet);
                } else {
                    $merged = $this->surgeon->appendMethod(
                        $this->surgeon->ensureImplements($merged, 'Milpa\\Runtime\\Http\\RouteProviderInterface'),
                        $this->surgeon->wrapMethod(
                            '/** @return list<\\Milpa\\Http\\Routing\\Route> */' . "\npublic function routes(): array",
                            "return [\n" . (string) preg_replace('/^(?=.)/m', '    ', $routesSnippet) . "\n];",
                        ),
                    );
                }
                $wired[] = 'routes(), structurally';
            } catch (\RuntimeException $e) {
                $fallbacks[] = 'The 5 REST routes could not be inserted (' . $e->getMessage()
                    . ") — add these to its routes() (fully qualified, no imports needed):\n\n{$routesSnippet}";
            }
        } else {
            $fallbacks[] = "The 5 REST routes could not be inserted ({$reason}) — add these to its "
                . "routes() (fully qualified, no imports needed):\n\n{$routesSnippet}";
        }

        if ($merged === $existing) {
            $guidance = $fallbacks === []
                ? "Already wired: {$pluginPath} already registers the {$context->name} repository and "
                    . 'declares its routes — nothing to add. Resolve the repository later via '
                    . "\$container->get({$repositoryId})."
                : "A plugin already exists at {$pluginPath} but could not be auto-wired — the file is "
                    . "left untouched.\n\n" . implode("\n\n", $fallbacks);

            return ['file' => null, 'guidance' => $guidance, 'suppressEntityGuidance' => $bootHandled];
        }

        $guidance = "Auto-wired into the existing plugin at {$pluginPath} (" . implode('; ', $wired) . '). '
            . "Resolve the repository later via \$container->get({$repositoryId}).";
        if ($fallbacks !== []) {
            $guidance .= "\n\n" . implode("\n\n", $fallbacks);
        }

        return [
            'file' => new PlannedFile($pluginPath, $merged, merge: true),
            'guidance' => $guidance,
            'suppressEntityGuidance' => $bootHandled,
        ];
    }

    /**
     * The combined repository+controller `boot()` registration, fully qualified inline — grafted
     * through RepositoryFactory, exactly like EntityGenerator's own snippet, not a hardcoded
     * FileRepository: `milpa/data` ships four backends behind one interface and the factory picks by
     * config; pinning the generated wiring to JSON files made the choice for the app and made
     * `make entity` and `make crud` answer the same question two different ways.
     */
    private function fullyQualifiedBootSnippet(string $entityFqcn, string $controllerFqcn, string $table): string
    {
        return "\$storage = \$this->container->get(\\Milpa\\Runtime\\Config::class)->get('storage', [\n"
            . "    'driver' => 'file',\n"
            . "    'path' => (new \\Milpa\\Runtime\\Support\\RootResolver())->resolve() . '/var/{$table}.json',\n"
            . "]);\n"
            . "\\assert(\\is_array(\$storage));\n"
            . "\n"
            . "\$repository = \\Milpa\\Data\\RepositoryFactory::fromConfig(\$storage, \\{$entityFqcn}::class);\n\n"
            . "\$this->container->registerService(\n"
            . "    \\{$entityFqcn}::class . 'Repository',\n"
            . "    \$repository,\n"
            . ");\n"
            . "\$this->container->registerService(\n"
            . "    \\{$controllerFqcn}::class,\n"
            . "    new \\{$controllerFqcn}(\$repository),\n"
            . ');';
    }

    /** The 5 REST route entries (one per line, trailing commas), fully qualified inline. */
    private function fullyQualifiedRoutesSnippet(string $controllerFqcn, string $table): string
    {
        return "new \\Milpa\\Http\\Routing\\Route(path: '/{$table}', methods: \\Milpa\\Http\\HttpMethod::GET, "
            . "name: '{$table}_index', handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\{$controllerFqcn}::class, 'index')),\n"
            . "new \\Milpa\\Http\\Routing\\Route(path: '/{$table}/{id}', methods: \\Milpa\\Http\\HttpMethod::GET, "
            . "name: '{$table}_show', handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\{$controllerFqcn}::class, 'show')),\n"
            . "new \\Milpa\\Http\\Routing\\Route(path: '/{$table}', methods: \\Milpa\\Http\\HttpMethod::POST, "
            . "name: '{$table}_create', handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\{$controllerFqcn}::class, 'create')),\n"
            . "new \\Milpa\\Http\\Routing\\Route(path: '/{$table}/{id}', methods: \\Milpa\\Http\\HttpMethod::PUT, "
            . "name: '{$table}_update', handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\{$controllerFqcn}::class, 'update')),\n"
            . "new \\Milpa\\Http\\Routing\\Route(path: '/{$table}/{id}', methods: \\Milpa\\Http\\HttpMethod::DELETE, "
            . "name: '{$table}_delete', handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\{$controllerFqcn}::class, 'delete')),";
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
