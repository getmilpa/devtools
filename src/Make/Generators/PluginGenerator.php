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
use Milpa\DevTools\Make\PlannedFile;
use Milpa\DevTools\Make\StubRenderer;
use Milpa\DevTools\Support\ComposerAutoload;

/**
 * Generates a STANDALONE plugin: the composition unit `make:controller`/`make:entity` already
 * generate "by rebound" when no plugin exists yet at their target area (see
 * {@see ControllerGenerator::wireRoute()} / {@see EntityGenerator::wireRepository()}), now available
 * explicitly and on its own — a `#[PluginMetadata]` + `Milpa\Interfaces\Plugin\PluginInterface`,
 * `Milpa\Interfaces\Tooling\ToolProviderInterface` class, ready for a follow-up
 * `make:service`/`make:tool`/`make:controller`/`make:entity`/`make:crud` call (targeting this same
 * plugin name) to wire something into it.
 *
 * F1: the rendered plugin carries {@see \Milpa\DevTools\Make\Markers}' `// {coa:services}` (inside
 * `boot()`) and `// {coa:tools}`/`// {coa:tool-prompts}` (inside
 * `registerTools()`/`getPromptSections()`) anchors from the start — a follow-up composite generator
 * that finds THIS plugin on disk auto-wires its registration at the matching marker instead of only
 * emitting guidance (see {@see ServiceGenerator::wireService()}, {@see ToolGenerator::wireToolProvider()},
 * and {@see \Milpa\DevTools\Make\MarkerInserter}). It carries `Milpa\Runtime\Http\RouteProviderInterface`
 * and a `// {coa:routes}` anchor too — see below for why that took a second pass.
 *
 * The routes anchor was ORIGINALLY left out on a defensible-sounding argument: `milpa/runtime` is not
 * a `milpa/devtools` dependency (unlike `milpa/core`, where `ToolProviderInterface` lives), so a stub
 * that `implements RouteProviderInterface` cannot be `require`d in THIS package's own test process,
 * only lint-checked. What that reasoning optimized for was the convenience of these tests, and what
 * it cost was paid by every user: a plugin made with `make:plugin` was NOT a valid auto-wire target,
 * so the very next `make:crud` against it found no anchor and printed five `Route` literals for
 * someone to paste by hand. The two most natural commands in a row, and the second one degraded
 * because of a constraint internal to this package. `crud-plugin.runtime.php.stub` had implemented
 * the interface all along and is lint-checked exactly the same way, which is the proof that the
 * constraint was never really binding.
 *
 * Unlike {@see ControllerGenerator}/{@see EntityGenerator}, this generator has no separate "target
 * plugin" — the plugin IS the artifact being generated, so only `GenerationContext::$name` is read;
 * `GenerationContext::$plugin` is not applicable, and a caller wiring this generator into a CLI
 * command should pass the same value for both (`coa make:plugin <Name>` has a single positional
 * argument).
 *
 * Only a RUNTIME convention exists — see {@see generate()} for why LEGACY throws.
 */
final class PluginGenerator implements GeneratorInterface
{
    private string $stubs;

    public function __construct(
        private readonly StubRenderer $renderer = new StubRenderer(),
        private readonly ConventionDetector $detector = new ConventionDetector(),
    ) {
        $this->stubs = \dirname(__DIR__) . '/stubs';
    }

    /** The `<what>` token this generator answers to: `'plugin'`. */
    public function name(): string
    {
        return 'plugin';
    }

    /**
     * Renders the standalone plugin per the detected/overridden {@see Flavor}.
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
     * The legacy Milpa host convention (`Milpa\app\Providers\BaseController`-derived controllers,
     * Doctrine entities) has no standalone-plugin scaffold to target in this engine — a legacy host's
     * plugin shape (`extends PluginBase`, `milpa.json` manifest) is a hand-authored convention this
     * package ships zero stub/verifier coupling to, unlike `controller.php.stub`/`entity.php.stub`.
     * Throws a clear, actionable message instead of emitting a guess.
     *
     * @throws \RuntimeException Always.
     */
    private function generateLegacy(GenerationContext $context): GenerationResult
    {
        throw new \RuntimeException(
            'make:plugin has no legacy convention to scaffold — a standalone Milpa\\Interfaces\\Plugin\\'
            . 'PluginInterface plugin is a runtime-only concept in this engine (the legacy host plugin '
            . 'shape is hand-authored, not stubbed here); use --flavor=runtime (the default outside a '
            . 'legacy host).',
        );
    }

    private function generateRuntime(GenerationContext $context): GenerationResult
    {
        [$appNamespace, $appDir] = ComposerAutoload::primaryNamespace($context->root) ?? ['App', 'src'];
        $appDir = trim($appDir, '/');

        $pluginNamespace = $appNamespace . '\\Plugins\\' . $context->name;
        $pluginPath = $context->root . '/' . $appDir . '/Plugins/' . $context->name . '/' . $context->name . '.php';
        $pluginFqcn = $pluginNamespace . '\\' . $context->name;

        $contents = $this->renderer->render($this->stubs . '/plugin.standalone.runtime.php.stub', [
            'namespace' => $pluginNamespace,
            'class' => $context->name,
            'metadataArgs' => $this->metadataArgs($context),
        ]);

        $guidance = "New plugin — register it so the kernel boots it: add {$pluginFqcn}::class to the "
            . 'list returned by config/plugins.php.';

        // Declared `requires:` are named in the guidance, because registering a plugin whose required
        // capability has no provider does not degrade — the resolver refuses to boot the whole host
        // (MILPA_CAPABILITY_MISSING). So the two steps this guidance describes are "add the line" and
        // "make sure something provides these", and omitting the second turns the first into a
        // landmine: an agent scaffolded a plugin with `requires: database`, followed the guidance to
        // the letter, and bricked the app it was building in.
        $requires = $this->parseList($context->option('requires'));
        if ($requires !== []) {
            $guidance .= ' It declares requires: ' . implode(', ', $requires)
                . ' — the host must already have an active plugin or package providing each one, or the '
                . 'kernel refuses to boot (MILPA_CAPABILITY_MISSING) once you register it.';
        }

        return new GenerationResult(
            files: [new PlannedFile($pluginPath, $contents)],
            verifyKind: null,
            verifyTarget: $pluginFqcn,
            flavor: Flavor::Runtime,
            guidance: $guidance,
        );
    }

    /**
     * Renders the optional `provides:`/`requires:` capability arguments for the `#[PluginMetadata]`
     * attribute from `--provides=cap1,cap2 --requires=cap3`, each becoming its own array-literal line
     * only when non-empty — mirrors how {@see EntityGenerator::generateRuntime()} composes its
     * optional `{{uses}}` block, so an unreplaced-placeholder-free stub still renders cleanly when
     * neither flag is given.
     */
    private function metadataArgs(GenerationContext $context): string
    {
        $provides = $this->parseList($context->option('provides'));
        $requires = $this->parseList($context->option('requires'));

        $args = '';
        if ($provides !== []) {
            $args .= '    provides: [' . $this->quoted($provides) . "],\n";
        }
        if ($requires !== []) {
            $args .= '    requires: [' . $this->quoted($requires) . "],\n";
        }

        return $args;
    }

    /**
     * Splits a `--provides`/`--requires` CSV option into trimmed, non-empty entries.
     *
     * @return list<string>
     */
    private function parseList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    /** @param list<string> $values */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(
            static fn (string $value): string => "'" . addslashes($value) . "'",
            $values,
        ));
    }
}
