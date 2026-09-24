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

namespace Milpa\DevTools\Make;

use Milpa\DevTools\Make\Generators\ResourceGenerator;
use Milpa\DevTools\Support\ComposerAutoload;

/**
 * Checks that the CONSEQUENCES a `make:entity` / `make:crud` / `make:resource` run promised
 * actually exist on disk,
 * so `ok:true` cannot mean "the class is shaped right" while a referenced enum, the repository
 * registration, the controller or the declared routes dangle unwired.
 *
 * This is the strong-postcondition layer over the shape-only {@see \Milpa\DevTools\Verify\VerifyRunner}:
 * that one reflects the produced class against its convention; this one asks the filesystem whether
 * every OTHER artifact the run said it would leave behind is really there. Where the shape verifier
 * needs the class autoloaded, this verifier reads files and source text only — a freshly scaffolded
 * tree is checkable before anything is installed or autoloadable.
 *
 * A REQUIRED consequence that is missing is a dangling reference and makes the run `incomplete`
 * (see {@see \Milpa\DevTools\Operations\MakeHandler}); the one ADVISORY consequence — activating the
 * plugin in `config/plugins.php` — is reported but never fails the run, because booting a plugin is
 * an authority decision `make` deliberately hands to a human (see the `make` effect profile in
 * {@see \Milpa\DevTools\Operations\DevToolsOperations}).
 *
 * Only the RUNTIME {@see Flavor} carries the repository/route/enum wiring these checks describe; a
 * LEGACY entity (a Doctrine class with no wiring plugin of its own) is checked for its file alone.
 */
final class PostconditionVerifier
{
    /** The entity class file the run promised exists on disk (entity, crud, resource). */
    public const ENTITY_FILE = 'entity_file';

    /** The REST controller file the run promised exists on disk (crud, resource). */
    public const CONTROLLER_FILE = 'controller_file';

    /** The controller reached a registration in the wiring plugin (crud, resource). */
    public const CONTROLLER_REGISTERED = 'controller_registered';

    /** The entity's repository reached a registration in the wiring plugin (entity, crud, resource). */
    public const REPOSITORY_REGISTERED = 'repository_registered';

    /** All five REST routes the run promised were declared in the wiring plugin (crud, resource). */
    public const ROUTES_DECLARED = 'routes_declared';

    /** The service class file the resource run promised exists on disk (resource). */
    public const SERVICE_FILE = 'service_file';

    /** The service reached a registration in the wiring plugin (resource). */
    public const SERVICE_REGISTERED = 'service_registered';

    /** The behavioral judge the resource run promised was scaffolded under tests/ (resource). */
    public const TEST_FILE = 'test_file';

    /** The 3 mutating routes are declared behind a middleware that exists (crud). */
    public const WRITES_GATED = 'writes_gated';

    /** Both read actions ask the one visibility seam, and it honours what was declared (crud). */
    public const READS_BOUNDED = 'reads_bounded';

    /** ADVISORY: the plugin is listed in config/plugins.php — reported, never failing the run. */
    public const PLUGIN_REGISTERED = 'plugin_registered';

    /** Dynamic prefix: one check per enum a --fields entry referenced, named `enum:<Class>`. */
    public const PREFIX_ENUM = 'enum:';

    /** Dynamic ADVISORY prefix: one check per belongsTo relation, named `relation:<Entity>`. */
    public const PREFIX_RELATION = 'relation:';

    /**
     * Every STATIC name this verifier can emit — THE one authority on postcondition names.
     *
     * The `make` operation's DECLARED postconditions ({@see \Milpa\DevTools\Operations\DevToolsOperations})
     * are built from these same constants, so the declaration and the report cannot drift apart:
     * renaming an emission here renames the declaration in the same keystroke, and a name added
     * here without being declared (or declared without existing here) turns the contract test red.
     * Dynamic names are covered by the two documented prefixes above.
     */
    public const STATIC_NAMES = [
        self::ENTITY_FILE,
        self::CONTROLLER_FILE,
        self::CONTROLLER_REGISTERED,
        self::REPOSITORY_REGISTERED,
        self::ROUTES_DECLARED,
        self::WRITES_GATED,
        self::READS_BOUNDED,
        self::SERVICE_FILE,
        self::SERVICE_REGISTERED,
        self::TEST_FILE,
        self::PLUGIN_REGISTERED,
    ];
    public function __construct(
        private readonly FieldParser $parser = new FieldParser(),
        private readonly PluginSurgeon $surgeon = new PluginSurgeon(),
    ) {
    }

    /**
     * Builds the {@see PostconditionReport} for a completed `$kind` generation, checking each
     * consequence that `$kind` promised against the tree under `$context->root`.
     *
     * Kinds other than `entity`/`crud`/`resource` have no filesystem consequences beyond the class
     * the shape verifier already covers, so they get an empty (always-ok) report.
     */
    public function verify(string $kind, GenerationContext $context, Flavor $flavor): PostconditionReport
    {
        if ($kind !== 'entity' && $kind !== 'crud' && $kind !== 'resource') {
            return new PostconditionReport([]);
        }

        if ($flavor === Flavor::Legacy) {
            return new PostconditionReport([$this->legacyEntityFile($context)]);
        }

        return match ($kind) {
            'crud' => $this->verifyCrud($context),
            'resource' => $this->verifyResource($context),
            default => $this->verifyEntity($context),
        };
    }

    /**
     * Checks a runtime `make:entity`: the entity file, every enum a field referenced, and that the
     * entity actually reached a booting repository registration in its plugin.
     */
    private function verifyEntity(GenerationContext $context): PostconditionReport
    {
        [$appNamespace, $appDir] = $this->appLayout($context->root);

        $checks = [$this->entityFile($context, $appDir)];
        foreach ($this->enumChecks($context, $appDir) as $check) {
            $checks[] = $check;
        }
        $checks[] = $this->repositoryRegistered($context, $appDir);
        $checks[] = $this->pluginRegistered($context, $appNamespace, $appDir);

        return new PostconditionReport($checks);
    }

    /**
     * Checks a runtime `make:crud`: the entity and controller files, every referenced enum, the
     * repository AND controller registrations, and that all five REST routes were declared.
     */
    private function verifyCrud(GenerationContext $context): PostconditionReport
    {
        [$appNamespace, $appDir] = $this->appLayout($context->root);

        $checks = [
            $this->entityFile($context, $appDir),
            $this->controllerFile($context, $appDir),
        ];
        foreach ($this->enumChecks($context, $appDir) as $check) {
            $checks[] = $check;
        }
        $checks[] = $this->repositoryRegistered($context, $appDir);
        $checks[] = $this->controllerRegistered($context, $appDir);
        $checks[] = $this->routesDeclared($context, $appDir);
        $checks[] = $this->writesAreGated($context, $appDir);
        $checks[] = $this->readsAreBounded($context, $appDir);
        $checks[] = $this->pluginRegistered($context, $appNamespace, $appDir);

        return new PostconditionReport($checks);
    }

    /**
     * Checks a runtime `make:resource`: every `make:crud` consequence PLUS the service class and its
     * registration, the behavioral judge's file, and one ADVISORY check per `belongsTo` relation the
     * `--fields` DSL declared — the degradation to a scalar id column is deliberate (the runtime
     * convention has no relation concept) but it must be NAMED in the verdict, never silent.
     */
    private function verifyResource(GenerationContext $context): PostconditionReport
    {
        [$appNamespace, $appDir] = $this->appLayout($context->root);

        $checks = [
            $this->entityFile($context, $appDir),
            $this->controllerFile($context, $appDir),
            $this->serviceFile($context, $appDir),
            $this->testFile($context),
        ];
        foreach ($this->enumChecks($context, $appDir) as $check) {
            $checks[] = $check;
        }
        foreach ($this->relationChecks($context) as $check) {
            $checks[] = $check;
        }
        $checks[] = $this->repositoryRegistered($context, $appDir);
        $checks[] = $this->controllerRegistered($context, $appDir);
        $checks[] = $this->serviceRegistered($context, $appDir);
        $checks[] = $this->routesDeclared($context, $appDir);
        $checks[] = $this->writesAreGated($context, $appDir);
        $checks[] = $this->readsAreBounded($context, $appDir);
        $checks[] = $this->pluginRegistered($context, $appNamespace, $appDir);

        return new PostconditionReport($checks);
    }

    /** The `<Name>Service` class file the resource run promised, checked on disk. */
    private function serviceFile(GenerationContext $context, string $appDir): PostconditionCheck
    {
        $path = $this->pluginDir($context, $appDir) . '/Services/' . $context->name . 'Service.php';

        return new PostconditionCheck(
            self::SERVICE_FILE,
            is_file($path),
            is_file($path) ? "service written at {$path}" : "service file missing: {$path}",
        );
    }

    /** The behavioral judge the resource run promised — `tests/Plugins/<Plugin>/<Name>Test.php` under the app root. */
    private function testFile(GenerationContext $context): PostconditionCheck
    {
        $path = $context->root . '/tests/Plugins/' . $context->plugin . '/' . $context->name . 'Test.php';

        return new PostconditionCheck(
            self::TEST_FILE,
            is_file($path),
            is_file($path)
                ? "behavioral judge scaffolded at {$path} (red on purpose until it judges something)"
                : "test scaffold missing: {$path}",
        );
    }

    /** Whether the `<Name>Service` was registered into the container in the wiring plugin. */
    private function serviceRegistered(GenerationContext $context, string $appDir): PostconditionCheck
    {
        $source = $this->pluginSource($context, $appDir);
        $needle = $context->name . 'Service::class';
        $ok = $source !== null && str_contains($source, $needle);
        $pluginPath = $this->pluginPath($context, $appDir);

        return new PostconditionCheck(
            self::SERVICE_REGISTERED,
            $ok,
            $ok
                ? "{$context->name}Service registered in {$pluginPath}"
                : "{$context->name}Service is NOT registered — register it in the plugin's boot() "
                    . '(or add a // {coa:services} marker so make can wire it)'
                    . $this->autoWireObstacle($context, $appDir),
        );
    }

    /**
     * One ADVISORY (never-failing) check per `belongsTo` field the `--fields` DSL declared, naming
     * the scalar column the relation was degraded to — the column name comes from the same authority
     * the generator used ({@see ResourceGenerator::relationColumn()}), so report and emission cannot
     * drift apart.
     *
     * @return list<PostconditionCheck>
     */
    private function relationChecks(GenerationContext $context): array
    {
        try {
            $fields = $this->parser->parse($context->option('fields') ?? '');
        } catch (\InvalidArgumentException) {
            return [];
        }

        $checks = [];
        foreach ($fields as $field) {
            if ($field->kind !== 'belongsTo') {
                continue;
            }
            $target = (string) $field->target;
            $column = ResourceGenerator::relationColumn($target);
            $checks[] = new PostconditionCheck(
                self::PREFIX_RELATION . $target,
                true,
                "field '{$field->name}' belongsTo {$target} was degraded to scalar {$column}:int — "
                    . 'milpa/data has no relation concept, so the related id is stored as a plain int',
                required: false,
            );
        }

        return $checks;
    }

    /**
     * Names WHY a wiring consequence is missing when the plugin file itself is the obstacle — one
     * the structural inserter refuses (see {@see PluginSurgeon::diagnose()}). Empty when there is no
     * plugin file or it is parseable: then the absence is a plain unwired registration, not a
     * refusal, and the check's own message already says what to do.
     */
    private function autoWireObstacle(GenerationContext $context, string $appDir): string
    {
        $source = $this->pluginSource($context, $appDir);
        if ($source === null) {
            return '';
        }
        $reason = $this->surgeon->diagnose($source);

        return $reason === null
            ? ''
            : '; ' . $this->pluginPath($context, $appDir) . ' could not be auto-wired: ' . $reason;
    }

    /** The entity class file the run promised, checked on disk. */
    private function entityFile(GenerationContext $context, string $appDir): PostconditionCheck
    {
        $path = $this->pluginDir($context, $appDir) . '/Entities/' . $context->name . '.php';

        return new PostconditionCheck(
            self::ENTITY_FILE,
            is_file($path),
            is_file($path) ? "entity written at {$path}" : "entity file missing: {$path}",
        );
    }

    /** The REST controller file the CRUD run promised, checked on disk. */
    private function controllerFile(GenerationContext $context, string $appDir): PostconditionCheck
    {
        $path = $this->pluginDir($context, $appDir) . '/Controllers/' . $context->name . 'Controller.php';

        return new PostconditionCheck(
            self::CONTROLLER_FILE,
            is_file($path),
            is_file($path) ? "controller written at {$path}" : "controller file missing: {$path}",
        );
    }

    /**
     * A check per enum a `--fields` entry referenced: the enum class must resolve to a file on disk,
     * whether `make` materialised it from declared cases (`enum:Status(a,b,c)`) or it references one
     * made elsewhere (`enum:Status`). A referenced enum with no file is the dangling reference this
     * whole layer exists to catch.
     *
     * @return list<PostconditionCheck>
     */
    private function enumChecks(GenerationContext $context, string $appDir): array
    {
        try {
            $fields = $this->parser->parse($context->option('fields') ?? '');
        } catch (\InvalidArgumentException) {
            // Generation already parsed these successfully to get here; an unparseable DSL is not a
            // postcondition to report, so surface no enum checks rather than a confusing failure.
            return [];
        }

        $checks = [];
        foreach ($fields as $field) {
            if ($field->kind !== 'enum') {
                continue;
            }
            $enum = (string) $field->target;
            $path = $this->pluginDir($context, $appDir) . '/Enums/' . $enum . '.php';
            $detail = is_file($path)
                ? "enum {$enum} resolves ({$path})"
                : "field '{$field->name}' references enum {$enum} but no file exists at {$path} — "
                    . "declare its cases (enum:{$enum}(case1,case2,…)) so make creates it, or add the enum";

            $checks[] = new PostconditionCheck(self::PREFIX_ENUM . $enum, is_file($path), $detail);
        }

        return $checks;
    }

    /**
     * Whether the entity's repository was actually registered — either in the plugin `make` generated
     * or auto-wired, or (the guidance-only case) not at all, which leaves the entity with nothing to
     * persist it.
     */
    private function repositoryRegistered(GenerationContext $context, string $appDir): PostconditionCheck
    {
        $source = $this->pluginSource($context, $appDir);
        $needle = $context->name . "::class . 'Repository'";
        $ok = $source !== null && str_contains($source, $needle);
        $pluginPath = $this->pluginPath($context, $appDir);

        return new PostconditionCheck(
            self::REPOSITORY_REGISTERED,
            $ok,
            $ok
                ? "{$context->name} repository registered in {$pluginPath}"
                : "{$context->name} repository is NOT registered — the wiring landed as guidance, not code; "
                    . "register it in the plugin's boot() (or add a // {coa:services} marker so make can wire it)"
                    . $this->autoWireObstacle($context, $appDir),
        );
    }

    /** Whether the CRUD controller was registered into the container in the wiring plugin. */
    private function controllerRegistered(GenerationContext $context, string $appDir): PostconditionCheck
    {
        $source = $this->pluginSource($context, $appDir);
        $needle = $context->name . 'Controller::class';
        $ok = $source !== null && str_contains($source, $needle);
        $pluginPath = $this->pluginPath($context, $appDir);

        return new PostconditionCheck(
            self::CONTROLLER_REGISTERED,
            $ok,
            $ok
                ? "{$context->name}Controller registered in {$pluginPath}"
                : "{$context->name}Controller is NOT registered — register it in the plugin's boot() "
                    . '(or add a // {coa:services} marker so make can wire it)'
                    . $this->autoWireObstacle($context, $appDir),
        );
    }

    /** Whether all five REST routes the CRUD promised were declared in the wiring plugin. */
    private function routesDeclared(GenerationContext $context, string $appDir): PostconditionCheck
    {
        $source = $this->pluginSource($context, $appDir);
        $table = $context->option('table') ?? strtolower($context->name) . 's';
        $expected = ['index', 'show', 'create', 'update', 'delete'];

        $missing = [];
        foreach ($expected as $verb) {
            if ($source === null || !str_contains($source, "{$table}_{$verb}")) {
                $missing[] = "{$table}_{$verb}";
            }
        }
        $pluginPath = $this->pluginPath($context, $appDir);

        return new PostconditionCheck(
            self::ROUTES_DECLARED,
            $missing === [],
            $missing === []
                ? "all 5 REST routes declared in {$pluginPath}"
                : 'missing route(s) ' . implode(', ', $missing) . " — the routes landed as guidance, not code; "
                    . "declare them in the plugin's routes() (or add a // {coa:routes} marker so make can wire them)"
                    . $this->autoWireObstacle($context, $appDir),
        );
    }

    /**
     * The three mutating routes are declared behind a middleware, and the gate they name exists.
     *
     * REQUIRED, and it is the guard on the defect of greenhouse `decisions/0459`: this scaffold used
     * to publish `POST`, `PUT` and `DELETE` with `middleware: []`, and on a served app an anonymous
     * `POST` answered **201** with the row persisted. Checked on the DECLARATION rather than by
     * calling the route, because that is what this verifier can see — and a declared middleware the
     * container cannot produce already fails the dispatch closed, so a named-but-absent gate is the
     * one remaining way to end up open. Both halves, therefore: named, and on disk.
     */
    private function writesAreGated(GenerationContext $context, string $appDir): PostconditionCheck
    {
        $source = $this->pluginSource($context, $appDir);
        $table = $context->option('table') ?? strtolower($context->name) . 's';
        $gate = $context->name . 'WritesGate';

        $ungated = [];
        foreach (['create', 'update', 'delete'] as $verb) {
            $name = "{$table}_{$verb}";
            if ($source === null) {
                $ungated[] = $name;

                continue;
            }
            // The route's own entry, from its name to the end of that declaration: the middleware
            // has to be on THAT route and not merely somewhere in the file.
            $at = strpos($source, "'{$name}'");
            if ($at === false) {
                $ungated[] = $name;

                continue;
            }
            $entry = substr($source, $at, 400);
            if (! str_contains($entry, 'middleware')) {
                $ungated[] = $name;
            }
        }

        $gateFile = $context->root . '/' . $appDir . '/Plugins/' . $context->plugin . '/Http/' . $gate . '.php';
        $gateOnDisk = is_file($gateFile);

        $ok = $ungated === [] && $gateOnDisk;
        $detail = $ok
            ? "the 3 write routes are declared behind {$gate}, which exists at {$gateFile}"
            : ($ungated !== []
                ? 'write route(s) ' . implode(', ', $ungated) . ' declare no middleware, so anyone can '
                    . "call them — declare {$gate}::class on each (greenhouse decisions/0459)"
                : "the write routes name {$gate} and it is not at {$gateFile}: a middleware the "
                    . 'container cannot produce fails the dispatch closed, so those routes would 500');

        return new PostconditionCheck(self::WRITES_GATED, $ok, $detail);
    }

    /**
     * Both read actions ask the ONE visibility seam, and when a visibility was declared the seam
     * honours it.
     *
     * The acta promises this guard, so it exists: a visibility honoured by the index and forgotten
     * by the detail route is the same leak with less noise, and a declared field the seam does not
     * name is a boundary that is only a word (greenhouse decisions/0460). Checked on the generated
     * controller, which is where both halves are written.
     */
    private function readsAreBounded(GenerationContext $context, string $appDir): PostconditionCheck
    {
        $source = $this->controllerSource($context, $appDir);
        $declared = $context->option('public-when');
        $declared = $declared === null || trim($declared) === '' ? null : trim($declared);

        if ($source === null) {
            return new PostconditionCheck(self::READS_BOUNDED, false, 'the controller could not be read');
        }

        $missing = [];
        // Both actions, by the seam they must ask — not by their own bodies, which is what let the
        // two drift in the first place.
        if (substr_count($source, '$this->visibleTo($request)') < 2) {
            $missing[] = 'one of the two read actions does not ask visibleTo()';
        }
        if ($declared !== null && ! str_contains($source, "'{$declared}' => true")) {
            $missing[] = "the seam does not name the declared field «{$declared}»";
        }

        return new PostconditionCheck(
            self::READS_BOUNDED,
            $missing === [],
            $missing === []
                ? ($declared === null
                    ? 'both reads ask one visibility seam; nothing was declared, so nothing is withheld'
                    : "both reads ask one visibility seam, bounded by «{$declared}» for a stranger")
                : implode('; ', $missing) . ' (greenhouse decisions/0460)',
        );
    }

    /**
     * ADVISORY: whether the plugin is listed in `config/plugins.php` so the kernel boots it. Never
     * fails the run — activating a plugin is the authority decision `make` hands to a human — but it
     * is reported so the caller sees the one step that is genuinely theirs, not left to guess at.
     */
    private function pluginRegistered(GenerationContext $context, string $appNamespace, string $appDir): PostconditionCheck
    {
        $configPath = $context->root . '/config/plugins.php';
        $fqcn = $appNamespace . '\\Plugins\\' . $context->plugin . '\\' . $context->plugin;
        $source = is_file($configPath) ? file_get_contents($configPath) : false;
        $ok = $source !== false
            && (str_contains($source, $fqcn) || str_contains($source, $context->plugin . '::class'));

        return new PostconditionCheck(
            self::PLUGIN_REGISTERED,
            $ok,
            $ok
                ? "plugin listed in {$configPath}"
                : "plugin not yet listed in config/plugins.php — add {$fqcn}::class to boot it (make leaves "
                    . 'this activation to you)',
            required: false,
        );
    }

    /** The source of the wiring plugin, or `null` when no plugin file exists yet. */
    /** The generated controller's source, or null when it is not on disk. */
    private function controllerSource(GenerationContext $context, string $appDir): ?string
    {
        $path = $this->pluginDir($context, $appDir) . '/Controllers/' . $context->name . 'Controller.php';
        if (!is_file($path)) {
            return null;
        }
        $source = file_get_contents($path);

        return $source === false ? null : $source;
    }

    private function pluginSource(GenerationContext $context, string $appDir): ?string
    {
        $path = $this->pluginPath($context, $appDir);
        if (!is_file($path)) {
            return null;
        }
        $source = file_get_contents($path);

        return $source === false ? null : $source;
    }

    /** The conventional path of the plugin file for this context. */
    private function pluginPath(GenerationContext $context, string $appDir): string
    {
        return $this->pluginDir($context, $appDir) . '/' . $context->plugin . '.php';
    }

    /** The conventional directory of the target plugin under the app's source tree. */
    private function pluginDir(GenerationContext $context, string $appDir): string
    {
        return $context->root . '/' . $appDir . '/Plugins/' . $context->plugin;
    }

    /**
     * The app's primary namespace and source directory, defaulting to `['App', 'src']` exactly as the
     * runtime generators do, so the paths checked here match the paths written.
     *
     * @return array{0: string, 1: string}
     */
    private function appLayout(string $root): array
    {
        [$appNamespace, $appDir] = ComposerAutoload::primaryNamespace($root) ?? ['App', 'src'];

        return [$appNamespace, trim($appDir, '/')];
    }

    /** The entity file check for the LEGACY convention, whose entities live under `plugins/`. */
    private function legacyEntityFile(GenerationContext $context): PostconditionCheck
    {
        $path = $context->root . '/plugins/' . $context->plugin . '/Entities/' . $context->name . '.php';

        return new PostconditionCheck(
            self::ENTITY_FILE,
            is_file($path),
            is_file($path) ? "entity written at {$path}" : "entity file missing: {$path}",
        );
    }
}
