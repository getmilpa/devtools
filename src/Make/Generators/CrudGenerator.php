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
use Milpa\DevTools\Make\FieldParser;
use Milpa\DevTools\Make\FieldSpec;
use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\GenerationResult;
use Milpa\DevTools\Make\GeneratorInterface;
use Milpa\DevTools\Make\MarkerInserter;
use Milpa\DevTools\Make\Markers;
use Milpa\DevTools\Make\PlannedFile;
use Milpa\DevTools\Make\PluginSurgeon;
use Milpa\DevTools\Make\StubLocator;
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
    private StubLocator $stubs;

    public function __construct(
        private readonly EntityGenerator $entityGenerator = new EntityGenerator(),
        private readonly StubRenderer $renderer = new StubRenderer(),
        private readonly ConventionDetector $detector = new ConventionDetector(),
        private readonly MarkerInserter $markers = new MarkerInserter(),
        private readonly PluginSurgeon $surgeon = new PluginSurgeon(),
        private readonly StubLocator $locator = new StubLocator(),
    ) {
        $this->stubs = $this->locator;
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
        // THE APP'S STUBS FIRST: bound here, once per generation, so every helper below reads the same
        // resolution — a copy under <root>/stubs/ wins for that one name, the package fills the rest.
        $this->stubs = $this->locator->at($context->root);
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

        // THE DECLARED VISIBILITY, resolved BEFORE a byte is planned (greenhouse decisions/0460).
        // A visibility that names a field the entity does not have, or one that is not a boolean,
        // is refused rather than generated: it would read as a boundary while withholding nothing,
        // and a boundary that is only a word is worse than an admitted absence.
        $publicWhen = $this->declaredVisibility($context);

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

        $controllerContents = $this->renderer->render($this->stubs->path('crud-controller.runtime.php.stub'), [
            'namespace' => $controllerNamespace,
            'class' => $controllerClass,
            'entityNamespace' => $entityNamespace,
            'entityClass' => $context->name,
            'table' => $table,
            'visibleToMethod' => self::visibleToMethod(
                $context->name,
                $publicWhen,
                self::gateNamespace($appNamespace . '\\Plugins\\' . $context->plugin) . '\\' . self::callerClass($context->name),
            ),
        ]);
        $files[] = new PlannedFile($controllerPath, $controllerContents);

        // THE WRITE GATE travels WITH the plugin instead of being imported from the framework, so
        // its owner can widen it, narrow it or replace it. Generated for both paths — a new plugin
        // and an existing one — because the routes it guards are generated for both.
        $files[] = new PlannedFile(
            $context->root . '/' . $appDir . '/Plugins/' . $context->plugin . '/Http/'
                . self::gateClass($context->name) . '.php',
            $this->renderer->render($this->stubs->path('crud-writes-gate.runtime.php.stub'), [
                'namespace' => self::gateNamespace($appNamespace . '\\Plugins\\' . $context->plugin),
                'class' => self::gateClass($context->name),
                'callerClass' => self::callerClass($context->name),
                'entityClass' => $context->name,
                'pluginClass' => $context->plugin,
                'table' => $table,
            ]),
        );

        // THE ONE AUTHORITY on who is recognised, asked by the gate AND by the controller's
        // visibility. Generated always, because the gate always asks it.
        $files[] = new PlannedFile(
            $context->root . '/' . $appDir . '/Plugins/' . $context->plugin . '/Http/'
                . self::callerClass($context->name) . '.php',
            $this->renderer->render($this->stubs->path('crud-caller.runtime.php.stub'), [
                'namespace' => self::gateNamespace($appNamespace . '\\Plugins\\' . $context->plugin),
                'class' => self::callerClass($context->name),
            ]),
        );

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
                self::gateNamespace($pluginNamespace) . '\\' . self::gateClass($context->name),
            );
        }

        $pluginContents = $this->renderer->render($this->stubs->path('crud-plugin.runtime.php.stub'), [
            'namespace' => $pluginNamespace,
            'class' => $context->plugin,
            'entityNamespace' => $entityNamespace,
            'entityClass' => $context->name,
            'controllerNamespace' => $controllerNamespace,
            'controllerClass' => $controllerClass,
            'gateNamespace' => self::gateNamespace($pluginNamespace),
            'gateClass' => self::gateClass($context->name),
            'table' => $table,
        ]);

        $guidance = "New plugin — register it so the kernel boots it: add {$pluginFqcn}::class to the "
            . "list returned by config/plugins.php. Its boot() builds the {$context->name} repository "
            . "from the app's 'storage' config via RepositoryFactory — set storage.driver in "
            . 'config/app.php to file, sqlite, mysql or memory (with its path/dsn); with no storage '
            . "block it defaults to a JSON file at var/{$table}.json — and registers {$controllerClass}. "
            . "Resolve the repository later via \$container->get({$repositoryId}). "
            // SAID, not left to be found as a 401 in a browser. The reads are open and the three
            // writes are not, which is the opposite of what this scaffold used to hand over.
            . 'The two read routes are anonymous; POST, PUT and DELETE are declared behind '
            . self::gateClass($context->name)
            . ', which refuses a caller nobody recognised — enable milpa/auth to open them, or drop '
            . 'it from those routes deliberately.';

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
        string $gateFqcn,
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

        $bootSnippet = $this->fullyQualifiedBootSnippet($entityFqcn, $controllerFqcn, $table, $gateFqcn);
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

        $routesSnippet = $this->fullyQualifiedRoutesSnippet($controllerFqcn, $table, $gateFqcn);
        if (str_contains($merged, "'{$table}_index'")) {
            // all 5 names travel together in every shape this engine emits; index stands for the set.
            //
            // BUT THE GATE DOES NOT TRAVEL WITH THEM on a plugin an older scaffold wrote: its five
            // routes are there and its three writes carry no middleware, so «already wired» was
            // answering «nothing to add» while the postcondition refused the run for exactly that
            // (greenhouse decisions/0459). A guidance that contradicts its own verdict sends the
            // reader to look for the wrong thing, so the missing half is NAMED here. Not spliced:
            // editing five existing route declarations is surgery this generator does not do, and a
            // half-applied gate is worse than one a human applied on purpose.
            $ungated = [];
            foreach (['create', 'update', 'delete'] as $verb) {
                $at = strpos($merged, "'{$table}_{$verb}'");
                if ($at === false || ! str_contains(substr($merged, $at, 400), 'middleware')) {
                    $ungated[] = "{$table}_{$verb}";
                }
            }
            if ($ungated !== []) {
                $fallbacks[] = 'Its write routes (' . implode(', ', $ungated) . ') declare no middleware, '
                    . 'so anyone who can reach this app can call them. Add '
                    . "middleware: [\\{$gateFqcn}::class] to each of those Route declarations — the gate "
                    . 'itself was just written for you. This is the one step make will not take on an '
                    . 'existing plugin: rewriting route declarations you already own.';
            }
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
            . "Resolve the repository later via \$container->get({$repositoryId}). "
            // SAID, not left to be found as a 401 in a browser. The reads are open and the three
            // writes are not, which is the opposite of what this scaffold used to hand over.
            . 'The two read routes are anonymous; POST, PUT and DELETE are declared behind '
            . self::gateClass($context->name)
            . ', which refuses a caller nobody recognised — enable milpa/auth to open them, or drop '
            . 'it from those routes deliberately.';
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
    private function fullyQualifiedBootSnippet(string $entityFqcn, string $controllerFqcn, string $table, string $gateFqcn): string
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
            . ");\n"
            // THE GATE, registered in the SAME snippet as the controller it guards. Separately, a
            // splice that landed the routes and not this would declare a middleware the container
            // cannot produce — and that fails the dispatch closed, so the routes would 500 instead
            // of refusing. One snippet, or the halves can arrive apart.
            . "\$this->container->registerService(\n"
            . "    \\{$gateFqcn}::class,\n"
            . "    new \\{$gateFqcn}(),\n"
            . ');';
    }

    /**
     * Where the generated write gate lives: `<Plugin>\\Http`, beside the plugin that declares it.
     *
     * A method and not an inline string because FOUR call sites need the same answer — the plugin
     * stub's import, its boot() registration, the routes that declare it, and the postcondition
     * that checks it — and four copies of a name is how one of them ends up spelling it differently.
     */
    private static function gateNamespace(string $pluginNamespace): string
    {
        return $pluginNamespace . '\\Http';
    }

    /** The gate's class name for an entity: `Post` → `PostWritesGate`. */
    private static function gateClass(string $entity): string
    {
        return $entity . 'WritesGate';
    }

    /**
     * The field a caller must match to read a row, as DECLARED — or null when none was.
     *
     * Refuses rather than warns, and refuses before anything is written: a `--public-when` naming a
     * field that is not there, or one that is not a boolean, produces a controller whose comment
     * promises a boundary its criteria cannot enforce. The refusal names what the entity does have,
     * because a refusal the reader cannot act on costs more than none.
     */
    private function declaredVisibility(GenerationContext $context): ?string
    {
        $declared = $context->option('public-when');
        if ($declared === null || trim($declared) === '') {
            return null;
        }
        $declared = trim($declared);

        $fields = (new FieldParser())->parse($context->option('fields') ?? '', supportsRelations: false);
        foreach ($fields as $field) {
            if ($field->name !== $declared) {
                continue;
            }
            if ($field->phpType !== 'bool') {
                throw new \InvalidArgumentException(sprintf(
                    '--public-when=%s names a %s field, and visibility is a yes or no: a row is public '
                    . 'or it is not. Declare a bool field (e.g. «%s:bool») and name that one.',
                    $declared,
                    $field->phpType,
                    $declared,
                ));
            }

            return $declared;
        }

        throw new \InvalidArgumentException(sprintf(
            '--public-when=%s names a field this artifact does not declare. Its fields are: %s. '
            . 'A declared visibility that does not exist reads as a boundary and withholds nothing.',
            $declared,
            $fields === [] ? '(none)' : implode(', ', array_map(static fn (FieldSpec $f): string => $f->name . ':' . $f->phpType, $fields)),
        ));
    }

    /** The one authority on who is recognised: `Post` → `PostCaller`. */
    private static function callerClass(string $entity): string
    {
        return $entity . 'Caller';
    }

    /**
     * The `visibleTo()` the controller carries — the ONE place that decides what a caller may read.
     *
     * Generated in both shapes rather than spliced conditionally, so there is exactly one shape of
     * generated controller and the two read actions always ask the same question. Without a
     * declared visibility it answers «everything», and SAYS so along with how to declare one: a
     * seam that looks like a boundary while withholding nothing is worse than no seam.
     */
    private static function visibleToMethod(string $entity, ?string $publicWhen, string $callerFqcn): string
    {
        if ($publicWhen === null) {
            return <<<'PHP'
                /**
                 * The criteria this caller's reads are bounded by — NOTHING, because this artifact
                 * declared no visibility field.
                 *
                 * Every row is public, including any a human would call a draft. To bound it, run
                 * `make` again with `--public-when=<field>` naming the boolean that decides, and
                 * this method will answer `[]` for a recognised caller and `['<field>' => true]`
                 * for a stranger. It is not inferred from a field's NAME: a generator guessing
                 * intent from vocabulary is patched per instance and never closes.
                 *
                 * @return array<string, mixed>
                 */
                private function visibleTo(ServerRequestInterface $request): array
                {
                    return [];
                }

            PHP;
        }

        // FULLY QUALIFIED, and measured: written as the bare class name it resolved inside the
        // controller's OWN namespace (`…\\Controllers\\PostCaller`) and every read answered 500.
        // The unit test had passed because the harness injected the import the generator did not
        // emit — a harness that patches the artifact certifies its own patch.
        $caller = '\\' . ltrim($callerFqcn, '\\');

        return <<<PHP
                /**
                 * The criteria this caller's reads are bounded by: nothing for a caller this app
                 * recognised, and `{$publicWhen} = true` for a stranger.
                 *
                 * Declared with `--public-when={$publicWhen}`, never inferred, and read from the
                 * ENTITY's own `PUBLIC_WHEN` — the declaration a screen bound to it reads too
                 * (greenhouse decisions/0462). Both read actions ask THIS method: a visibility
                 * honoured by the index and forgotten by the detail route is the same leak with less
                 * noise.
                 *
                 * @return array<string, mixed>
                 */
                private function visibleTo(ServerRequestInterface \$request): array
                {
                    return {$caller}::in(\$request) !== null ? [] : [{$entity}::PUBLIC_WHEN => true];
                }

            PHP;
    }

    /** The 5 REST route entries (one per line, trailing commas), fully qualified inline. */
    private function fullyQualifiedRoutesSnippet(string $controllerFqcn, string $table, string $gateFqcn): string
    {
        $behind = "middleware: [\\{$gateFqcn}::class], ";

        return "new \\Milpa\\Http\\Routing\\Route(path: '/{$table}', methods: \\Milpa\\Http\\HttpMethod::GET, "
            . "name: '{$table}_index', handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\{$controllerFqcn}::class, 'index')),\n"
            . "new \\Milpa\\Http\\Routing\\Route(path: '/{$table}/{id}', methods: \\Milpa\\Http\\HttpMethod::GET, "
            . "name: '{$table}_show', handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\{$controllerFqcn}::class, 'show')),\n"
            . "new \\Milpa\\Http\\Routing\\Route(path: '/{$table}', methods: \\Milpa\\Http\\HttpMethod::POST, "
            . "name: '{$table}_create', " . $behind . "handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\{$controllerFqcn}::class, 'create')),\n"
            . "new \\Milpa\\Http\\Routing\\Route(path: '/{$table}/{id}', methods: \\Milpa\\Http\\HttpMethod::PUT, "
            . "name: '{$table}_update', " . $behind . "handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\{$controllerFqcn}::class, 'update')),\n"
            . "new \\Milpa\\Http\\Routing\\Route(path: '/{$table}/{id}', methods: \\Milpa\\Http\\HttpMethod::DELETE, "
            . "name: '{$table}_delete', " . $behind . "handler: new \\Milpa\\Http\\Routing\\HandlerReference(\\{$controllerFqcn}::class, 'delete')),";
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
