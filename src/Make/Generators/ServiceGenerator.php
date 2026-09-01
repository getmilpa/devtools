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
use Milpa\DevTools\Make\PluginSurgeon;
use Milpa\DevTools\Make\Markers;
use Milpa\DevTools\Make\PlannedFile;
use Milpa\DevTools\Make\StubRenderer;
use Milpa\DevTools\Support\ComposerAutoload;

/**
 * Generates a domain service — a plain, dependency-free class (optionally paired with a companion
 * interface it implements, via `--interface`) targeting an EXISTING (or about-to-exist) plugin, the
 * same `<Plugin> <Name>` shape {@see ControllerGenerator}/{@see EntityGenerator} use.
 *
 * A freestanding service class does nothing on its own until something resolves it from the DI
 * container, so — mirroring {@see ControllerGenerator::wireRoute()} /
 * {@see EntityGenerator::wireRepository()} exactly, one concern swapped for another (route/repository
 * registration -> service registration) — this generator ALSO wires the new service into the target
 * plugin's `boot()`:
 *
 * - No `PluginInterface` plugin exists yet at the target area's conventional path
 *   (`{appDir}/Plugins/{plugin}/{plugin}.php`) -> a minimal one is generated alongside the service,
 *   its `boot()` already registering `new Service()` under the service's own class (or its interface,
 *   when `--interface` was used) — plus guidance to register the new plugin class in
 *   `config/plugins.php`.
 * - One already exists AND carries the {@see \Milpa\DevTools\Make\Markers::SERVICES} anchor (F1) ->
 *   the registration is INSERTED at that marker via {@see \Milpa\DevTools\Make\MarkerInserter} — a
 *   deterministic splice at a known anchor, not a rewrite — see {@see self::wireService()}.
 * - One already exists but carries NO marker -> the registration is spliced STRUCTURALLY into its
 *   `boot()` via {@see \Milpa\DevTools\Make\PluginSurgeon} (same ladder as `make:crud`); only a
 *   file the surgeon refuses (naming the reason) falls back to a guidance snippet.
 *
 * Only a RUNTIME convention exists — see {@see generate()} for why LEGACY throws.
 */
final class ServiceGenerator implements GeneratorInterface
{
    private string $stubs;

    public function __construct(
        private readonly StubRenderer $renderer = new StubRenderer(),
        private readonly ConventionDetector $detector = new ConventionDetector(),
        private readonly MarkerInserter $markers = new MarkerInserter(),
        private readonly PluginSurgeon $surgeon = new PluginSurgeon(),
    ) {
        $this->stubs = \dirname(__DIR__) . '/stubs';
    }

    /** The `<what>` token this generator answers to: `'service'`. */
    public function name(): string
    {
        return 'service';
    }

    /**
     * Renders the service (+ interface, + plugin wiring) per the detected/overridden {@see Flavor}.
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
     * The legacy Milpa host convention has no plain, DI-registered service scaffold to target in this
     * engine — every legacy artifact this package stubs (controller, entity) has a fixed, framework-
     * mandated base class/attribute shape to conform to; a "service" in the legacy host is just
     * whatever class a plugin's `boot()` happens to `registerService()`, with no single shape to
     * generate against. Throws a clear, actionable message instead of emitting a guess.
     *
     * @throws \RuntimeException Always.
     */
    private function generateLegacy(GenerationContext $context): GenerationResult
    {
        throw new \RuntimeException(
            'make:service has no legacy convention to scaffold — a plain, DI-registered service class '
            . 'is a runtime-only concept in this engine (the legacy host has no fixed service shape to '
            . 'stub against, unlike its controller/entity conventions); use --flavor=runtime (the '
            . 'default outside a legacy host).',
        );
    }

    private function generateRuntime(GenerationContext $context): GenerationResult
    {
        [$appNamespace, $appDir] = ComposerAutoload::primaryNamespace($context->root) ?? ['App', 'src'];
        $appDir = trim($appDir, '/');

        $serviceNamespace = $appNamespace . '\\Plugins\\' . $context->plugin . '\\Services';
        $servicePath = $context->root . '/' . $appDir . '/Plugins/' . $context->plugin
            . '/Services/' . $context->name . '.php';

        $withInterface = $context->flag('interface');
        $interfaceClass = $withInterface ? $context->name . 'Interface' : null;

        $serviceContents = $this->renderer->render($this->stubs . '/service.runtime.php.stub', [
            'namespace' => $serviceNamespace,
            'class' => $context->name,
            'implementsClause' => $interfaceClass !== null ? ' implements ' . $interfaceClass : '',
        ]);

        $files = [new PlannedFile($servicePath, $serviceContents)];

        if ($interfaceClass !== null) {
            $interfacePath = $context->root . '/' . $appDir . '/Plugins/' . $context->plugin
                . '/Services/' . $interfaceClass . '.php';
            $interfaceContents = $this->renderer->render($this->stubs . '/service-interface.runtime.php.stub', [
                'namespace' => $serviceNamespace,
                'class' => $interfaceClass,
            ]);
            $files[] = new PlannedFile($interfacePath, $interfaceContents);
        }

        ['file' => $pluginFile, 'guidance' => $guidance] = $this->wireService(
            $context,
            $appNamespace,
            $appDir,
            $serviceNamespace,
            $interfaceClass,
        );
        if ($pluginFile !== null) {
            $files[] = $pluginFile;
        }

        return new GenerationResult(
            files: $files,
            verifyKind: null,
            verifyTarget: $serviceNamespace . '\\' . $context->name,
            flavor: Flavor::Runtime,
            guidance: $guidance,
        );
    }

    /**
     * Decides how the generated service reaches a booting DI registration — the load-bearing part of
     * this generator, since a freestanding service class does nothing on its own (see the class
     * docblock). Mirrors {@see ControllerGenerator::wireRoute()} / {@see EntityGenerator::wireRepository()}
     * exactly:
     *
     * - No `PluginInterface` plugin exists yet -> a fresh one is generated (as before F1), now ALSO
     *   carrying the {@see Markers::SERVICES} anchor for a later run.
     * - One exists AND carries the anchor -> the registration is INSERTED at the marker (F1) via
     *   {@see MarkerInserter} — `$file` becomes the merged plugin, marked {@see PlannedFile::$merge}
     *   so {@see WriteGuard} does not require `--force` to write it.
     * - One exists but carries no anchor -> the semantic needle is checked first (already wired ->
     *   nothing to add), then the registration is spliced structurally into `boot()` via
     *   {@see PluginSurgeon}; guidance remains ONLY for a file the surgeon refuses, naming the reason.
     *
     * Existence is checked on the FILESYSTEM only (`is_file()`), not via reflection/autoloading —
     * consistent with the rest of this deterministic generate step, and safe to call from a
     * `--dry-run` before anything is installed/autoloadable.
     *
     * @return array{file: ?PlannedFile, guidance: string}
     */
    private function wireService(
        GenerationContext $context,
        string $appNamespace,
        string $appDir,
        string $serviceNamespace,
        ?string $interfaceClass,
    ): array {
        $registrationClass = $interfaceClass ?? $context->name;

        $pluginNamespace = $appNamespace . '\\Plugins\\' . $context->plugin;
        $pluginPath = $context->root . '/' . $appDir . '/Plugins/' . $context->plugin . '/' . $context->plugin . '.php';
        $pluginFqcn = $pluginNamespace . '\\' . $context->plugin;

        if (is_file($pluginPath)) {
            $existing = (string) file_get_contents($pluginPath);
            if ($this->markers->hasMarker($existing, Markers::SERVICES)) {
                $registrationFqcn = $serviceNamespace . '\\' . $registrationClass;
                $serviceFqcn = $serviceNamespace . '\\' . $context->name;

                $merged = $this->markers->insertBefore(
                    $existing,
                    Markers::SERVICES,
                    $this->registrationSnippet($registrationFqcn, $serviceFqcn),
                    $context->flag('force'),
                );

                $guidance = "Auto-wired into the existing plugin at {$pluginPath} (// {" . Markers::SERVICES
                    . '} marker found). Resolve it later via $container->get(' . $registrationClass . '::class).';

                return ['file' => new PlannedFile($pluginPath, $merged, merge: true), 'guidance' => $guidance];
            }

            // No anchor — the pre-F1 contract returned the snippet as prose here, narrating a
            // consequence this generator fully knows. It now follows the same resolution ladder as
            // {@see CrudGenerator::wireExistingPlugin()}: semantic needle (already wired) ->
            // structural splice via the surgeon -> guidance ONLY on refusal, naming the reason.
            $registrationFqcn = $serviceNamespace . '\\' . $registrationClass;
            $serviceFqcn = $serviceNamespace . '\\' . $context->name;
            $snippet = $this->registrationSnippet($registrationFqcn, $serviceFqcn);

            if (str_contains($existing, $registrationClass . '::class')) {
                $guidance = "Already wired: {$pluginPath} already registers {$registrationClass} — "
                    . 'nothing to add. Resolve it later via $container->get('
                    . $registrationClass . '::class).';

                return ['file' => null, 'guidance' => $guidance];
            }

            $reason = $this->surgeon->diagnose($existing);
            if ($reason === null) {
                try {
                    $merged = $this->surgeon->hasMethod($existing, 'boot')
                        ? $this->surgeon->insertIntoMethod($existing, 'boot', $snippet)
                        : $this->surgeon->appendMethod(
                            $existing,
                            $this->surgeon->wrapMethod('public function boot(): void', $snippet),
                        );

                    $guidance = "Auto-wired into the existing plugin at {$pluginPath} (boot(), "
                        . 'structurally). Resolve it later via $container->get('
                        . $registrationClass . '::class).';

                    return ['file' => new PlannedFile($pluginPath, $merged, merge: true), 'guidance' => $guidance];
                } catch (\RuntimeException $e) {
                    $reason = $e->getMessage();
                }
            }

            $guidance = "A plugin already exists at {$pluginPath} but could not be auto-wired "
                . "({$reason}) — the file is left untouched. Add this to its boot() (fully "
                . "qualified, no imports needed):\n\n{$snippet}\n\n"
                . "Resolve it later via \$container->get({$registrationClass}::class).";

            return ['file' => null, 'guidance' => $guidance];
        }

        $uses = "use {$serviceNamespace}\\{$context->name};\n";
        if ($interfaceClass !== null) {
            $uses .= "use {$serviceNamespace}\\{$interfaceClass};\n";
        }

        $pluginContents = $this->renderer->render($this->stubs . '/service-plugin.runtime.php.stub', [
            'namespace' => $pluginNamespace,
            'class' => $context->plugin,
            'uses' => $uses,
            'serviceClass' => $context->name,
            'registrationClass' => $registrationClass,
        ]);

        $guidance = "New plugin — register it so the kernel boots it: add {$pluginFqcn}::class to the "
            . 'list returned by config/plugins.php. Its boot() registers ' . $context->name
            . "; resolve it later via \$container->get({$registrationClass}::class).";

        return ['file' => new PlannedFile($pluginPath, $pluginContents), 'guidance' => $guidance];
    }

    /**
     * The `registerService()` statement that registers `$serviceFqcn` under `$registrationFqcn`,
     * with both class references fully qualified inline so splicing it into any plugin file — at a
     * marker, structurally, or by hand — never has to touch the target's import block.
     * {@see wireService()} and {@see ResourceGenerator} both land exactly this shape: one authority
     * for what "the service is registered" looks like.
     */
    public function registrationSnippet(string $registrationFqcn, string $serviceFqcn): string
    {
        return "\$this->container->registerService(\n    \\{$registrationFqcn}::class,\n    new \\{$serviceFqcn}(),\n);";
    }
}
