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

use Milpa\DevTools\Support\ComposerAutoload;

/**
 * Checks that the CONSEQUENCES a `make:entity` / `make:crud` run promised actually exist on disk,
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
    public function __construct(private readonly FieldParser $parser = new FieldParser())
    {
    }

    /**
     * Builds the {@see PostconditionReport} for a completed `$kind` generation, checking each
     * consequence that `$kind` promised against the tree under `$context->root`.
     *
     * Kinds other than `entity`/`crud` have no filesystem consequences beyond the class the shape
     * verifier already covers, so they get an empty (always-ok) report.
     */
    public function verify(string $kind, GenerationContext $context, Flavor $flavor): PostconditionReport
    {
        if ($kind !== 'entity' && $kind !== 'crud') {
            return new PostconditionReport([]);
        }

        if ($flavor === Flavor::Legacy) {
            return new PostconditionReport([$this->legacyEntityFile($context)]);
        }

        return $kind === 'crud' ? $this->verifyCrud($context) : $this->verifyEntity($context);
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
        $checks[] = $this->pluginRegistered($context, $appNamespace, $appDir);

        return new PostconditionReport($checks);
    }

    /** The entity class file the run promised, checked on disk. */
    private function entityFile(GenerationContext $context, string $appDir): PostconditionCheck
    {
        $path = $this->pluginDir($context, $appDir) . '/Entities/' . $context->name . '.php';

        return new PostconditionCheck(
            'entity_file',
            is_file($path),
            is_file($path) ? "entity written at {$path}" : "entity file missing: {$path}",
        );
    }

    /** The REST controller file the CRUD run promised, checked on disk. */
    private function controllerFile(GenerationContext $context, string $appDir): PostconditionCheck
    {
        $path = $this->pluginDir($context, $appDir) . '/Controllers/' . $context->name . 'Controller.php';

        return new PostconditionCheck(
            'controller_file',
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

            $checks[] = new PostconditionCheck('enum:' . $enum, is_file($path), $detail);
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
            'repository_registered',
            $ok,
            $ok
                ? "{$context->name} repository registered in {$pluginPath}"
                : "{$context->name} repository is NOT registered — the wiring landed as guidance, not code; "
                    . "register it in the plugin's boot() (or add a // {coa:services} marker so make can wire it)",
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
            'controller_registered',
            $ok,
            $ok
                ? "{$context->name}Controller registered in {$pluginPath}"
                : "{$context->name}Controller is NOT registered — register it in the plugin's boot() "
                    . '(or add a // {coa:services} marker so make can wire it)',
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
            'routes_declared',
            $missing === [],
            $missing === []
                ? "all 5 REST routes declared in {$pluginPath}"
                : 'missing route(s) ' . implode(', ', $missing) . " — the routes landed as guidance, not code; "
                    . "declare them in the plugin's routes() (or add a // {coa:routes} marker so make can wire them)",
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
            'plugin_registered',
            $ok,
            $ok
                ? "plugin listed in {$configPath}"
                : "plugin not yet listed in config/plugins.php — add {$fqcn}::class to boot it (make leaves "
                    . 'this activation to you)',
            required: false,
        );
    }

    /** The source of the wiring plugin, or `null` when no plugin file exists yet. */
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
            'entity_file',
            is_file($path),
            is_file($path) ? "entity written at {$path}" : "entity file missing: {$path}",
        );
    }
}
