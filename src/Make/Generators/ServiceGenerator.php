<?php

declare(strict_types=1);

namespace Milpa\DevTools\Make\Generators;

use Milpa\DevTools\Make\ConventionDetector;
use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\GenerationResult;
use Milpa\DevTools\Make\GeneratorInterface;
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
 * - One already exists -> it is NOT edited (parsing/rewriting arbitrary host PHP is exactly the
 *   fragile AST surgery this generator's deterministic `PlannedFile`/`WriteGuard` model exists to
 *   avoid — see {@see self::wireService()}). The exact `registerService()` snippet to add by hand is
 *   returned via {@see GenerationResult::$guidance} instead.
 *
 * Only a RUNTIME convention exists — see {@see generate()} for why LEGACY throws.
 */
final class ServiceGenerator implements GeneratorInterface
{
    private string $stubs;

    public function __construct(
        private readonly StubRenderer $renderer = new StubRenderer(),
        private readonly ConventionDetector $detector = new ConventionDetector(),
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

        $withInterface = $this->wantsInterface($context);
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
     * exactly.
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
            $snippet = "\$this->container->registerService(\n"
                . "    {$registrationClass}::class,\n"
                . "    new {$context->name}(),\n"
                . ');';

            $importLines = ["`use {$serviceNamespace}\\{$context->name};`"];
            if ($interfaceClass !== null) {
                $importLines[] = "`use {$serviceNamespace}\\{$interfaceClass};`";
            }
            $importsText = \count($importLines) > 1
                ? implode(', ', \array_slice($importLines, 0, -1)) . ' and ' . end($importLines)
                : $importLines[0];

            $guidance = "A plugin already exists at {$pluginPath} — it is left untouched (editing "
                . "existing host code is outside this generator's deterministic write model). Add "
                . "{$importsText} import" . (\count($importLines) > 1 ? 's' : '') . " and this to its boot():\n\n{$snippet}\n\n"
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

    /** Reads the `--interface` flag: truthy for `true`/`'1'`/`'true'`/any non-empty string but `'0'`/`'false'`. */
    private function wantsInterface(GenerationContext $context): bool
    {
        $value = $context->options['interface'] ?? false;

        if (\is_bool($value)) {
            return $value;
        }
        if (\is_string($value)) {
            return !\in_array(strtolower($value), ['', '0', 'false'], true);
        }

        return (bool) $value;
    }
}
