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
 * Generates the AI atom: a plain class carrying ONE `#[Milpa\ToolRuntime\Attributes\Tool]`-attributed
 * method, targeting an EXISTING (or about-to-exist) plugin — the same `<Plugin> <Name>` shape
 * {@see ControllerGenerator}/{@see EntityGenerator}/{@see ServiceGenerator} use.
 *
 * A freestanding tool class registers nothing on its own until a `Milpa\Interfaces\Tooling\
 * ToolProviderInterface::registerTools()` scans it into the tool registry, so — mirroring
 * {@see ServiceGenerator::wireService()} exactly, one concern swapped for another (DI registration ->
 * tool-registry scanning) — this generator ALSO wires the new tool into the target plugin:
 *
 * - No `PluginInterface` plugin exists yet at the target area's conventional path
 *   (`{appDir}/Plugins/{plugin}/{plugin}.php`) -> a minimal one is generated alongside the tool,
 *   already implementing `ToolProviderInterface` with a `registerTools()` that scans the new tool
 *   class via `Milpa\ToolRuntime\ToolScanner` — plus guidance to register the new plugin class in
 *   `config/plugins.php`.
 * - One already exists AND carries the {@see \Milpa\DevTools\Make\Markers::TOOLS}/
 *   {@see \Milpa\DevTools\Make\Markers::TOOL_PROMPTS} anchors (F1) -> the scan call and prompt-section
 *   entry are INSERTED at those markers via {@see \Milpa\DevTools\Make\MarkerInserter} — a
 *   deterministic splice at a known anchor, not a rewrite — see {@see self::wireToolProvider()}.
 * - One already exists but carries NEITHER marker (a hand-written plugin, or one predating F1) -> it
 *   is NOT edited (parsing/rewriting arbitrary host PHP is exactly the fragile AST surgery this
 *   generator's deterministic `PlannedFile`/`WriteGuard` model exists to avoid). The exact
 *   `registerTools()`/`getPromptSections()` snippet to add by hand is returned via
 *   {@see GenerationResult::$guidance} instead.
 *
 * F4: `--needs=Fqcn[,Fqcn]` declares the tool's collaborators — the generated class gains a
 * constructor promoting one `private readonly` property per FQCN (see {@see self::constructorBlock()}),
 * and every wiring path that constructs the tool (the fresh `tool-plugin.runtime.php.stub`, a marker
 * insertion, or the guidance snippet) resolves each one from the container instead of calling a
 * zero-arg `new {Tool}()` — see {@see self::constructorCallArgs()}. Omitting `--needs` keeps the
 * zero-arg form exactly as before.
 *
 * `milpa/tool-runtime` is a runtime-only dependency of the TARGET app being scaffolded, never of
 * `milpa/devtools` itself (this package has no `#[Tool]`/`ToolScanner` coupling of its own, the same
 * way the CONTROLLER path has zero Doctrine coupling — see {@see \Milpa\DevTools\Support\DoctrineAvailability}).
 * When the target app does not appear to have it installed yet, {@see GenerationResult::$guidance}
 * proactively says so instead of emitting a file the host cannot actually load — see
 * {@see self::dependencyGuidance()}.
 *
 * Only a RUNTIME convention exists — see {@see generate()} for why LEGACY throws.
 */
final class ToolGenerator implements GeneratorInterface
{
    private string $stubs;

    public function __construct(
        private readonly StubRenderer $renderer = new StubRenderer(),
        private readonly ConventionDetector $detector = new ConventionDetector(),
        private readonly MarkerInserter $markers = new MarkerInserter(),
    ) {
        $this->stubs = \dirname(__DIR__) . '/stubs';
    }

    /** The `<what>` token this generator answers to: `'tool'`. */
    public function name(): string
    {
        return 'tool';
    }

    /**
     * Renders the tool (+ provider wiring) per the detected/overridden {@see Flavor}.
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
     * The legacy Milpa host convention has no AI-tool scaffold to target in this engine — the
     * `#[Tool]` attribute and its `ToolScanner`/`ToolProviderInterface` auto-discovery are entirely a
     * `milpa/tool-runtime` + `milpa/runtime` concept, with nothing analogous in the legacy host's
     * controller/entity conventions. Throws a clear, actionable message instead of emitting a guess.
     *
     * @throws \RuntimeException Always.
     */
    private function generateLegacy(GenerationContext $context): GenerationResult
    {
        throw new \RuntimeException(
            'make:tool has no legacy convention to scaffold — an AI-callable #[Tool] method is a '
            . 'runtime-only concept in this engine (milpa/tool-runtime has no legacy-host analogue); '
            . 'use --flavor=runtime (the default outside a legacy host).',
        );
    }

    private function generateRuntime(GenerationContext $context): GenerationResult
    {
        [$appNamespace, $appDir] = ComposerAutoload::primaryNamespace($context->root) ?? ['App', 'src'];
        $appDir = trim($appDir, '/');

        $toolNamespace = $appNamespace . '\\Plugins\\' . $context->plugin . '\\Tools';
        $toolPath = $context->root . '/' . $appDir . '/Plugins/' . $context->plugin
            . '/Tools/' . $context->name . '.php';

        $toolName = $this->deriveToolName($context);
        $description = $this->escapeSingleQuoted($this->deriveDescription($context));
        $needs = $this->parseNeeds($context);

        $contents = $this->renderer->render($this->stubs . '/tool.runtime.php.stub', [
            'namespace' => $toolNamespace,
            'class' => $context->name,
            'toolName' => $toolName,
            'description' => $description,
            'constructor' => $this->constructorBlock($needs),
        ]);

        $files = [new PlannedFile($toolPath, $contents)];

        ['file' => $pluginFile, 'guidance' => $wiringGuidance] = $this->wireToolProvider(
            $context,
            $appNamespace,
            $appDir,
            $toolNamespace,
            $toolName,
            $needs,
        );
        if ($pluginFile !== null) {
            $files[] = $pluginFile;
        }

        return new GenerationResult(
            files: $files,
            verifyKind: null,
            verifyTarget: $toolNamespace . '\\' . $context->name,
            flavor: Flavor::Runtime,
            guidance: $this->dependencyGuidance($context, $wiringGuidance),
        );
    }

    /**
     * Decides how the generated tool reaches a booting `ToolProviderInterface::registerTools()` scan
     * — the load-bearing part of this generator, since a freestanding `#[Tool]`-attributed class
     * registers nothing on its own (see the class docblock). Mirrors
     * {@see ServiceGenerator::wireService()} exactly, one concern swapped for another (DI registration
     * -> tool-registry scanning):
     *
     * - No `PluginInterface` plugin exists yet -> a fresh one is generated (as before F1), now ALSO
     *   carrying the {@see Markers::TOOLS}/{@see Markers::TOOL_PROMPTS} anchors for a later run.
     * - One exists AND carries both anchors -> the scan call and prompt-section entry are INSERTED at
     *   the markers (F1) via {@see MarkerInserter} — `$file` becomes the merged plugin, marked
     *   {@see PlannedFile::$merge} so {@see WriteGuard} does not require `--force` to write it.
     * - One exists but carries neither anchor -> unchanged pre-F1 behavior: guidance only, the file
     *   is left untouched.
     *
     * Existence is checked on the FILESYSTEM only (`is_file()`), not via reflection/autoloading —
     * consistent with the rest of this deterministic generate step, and safe to call from a
     * `--dry-run` before anything is installed/autoloadable.
     *
     * @param list<string> $needs FQCNs parsed from `--needs` (F4) — see {@see parseNeeds()}.
     *
     * @return array{file: ?PlannedFile, guidance: string}
     */
    private function wireToolProvider(
        GenerationContext $context,
        string $appNamespace,
        string $appDir,
        string $toolNamespace,
        string $toolName,
        array $needs,
    ): array {
        $pluginNamespace = $appNamespace . '\\Plugins\\' . $context->plugin;
        $pluginPath = $context->root . '/' . $appDir . '/Plugins/' . $context->plugin . '/' . $context->plugin . '.php';
        $pluginFqcn = $pluginNamespace . '\\' . $context->plugin;
        $toolFqcn = $toolNamespace . '\\' . $context->name;

        if (is_file($pluginPath)) {
            $existing = (string) file_get_contents($pluginPath);
            $hasToolsMarker = $this->markers->hasMarker($existing, Markers::TOOLS);
            $hasPromptsMarker = $this->markers->hasMarker($existing, Markers::TOOL_PROMPTS);

            if ($hasToolsMarker && $hasPromptsMarker) {
                $ctorArgs = $this->constructorCallArgs($needs);
                $merged = $this->markers->insertBefore(
                    $existing,
                    Markers::TOOLS,
                    "(new \\Milpa\\ToolRuntime\\ToolScanner(\$registry))->scan(new \\{$toolFqcn}({$ctorArgs}));",
                    $context->flag('force'),
                );
                $merged = $this->markers->insertBefore(
                    $merged,
                    Markers::TOOL_PROMPTS,
                    "'{$context->name} exposes the {$toolName} tool.',",
                    $context->flag('force'),
                );

                $guidance = "Auto-wired into the existing plugin at {$pluginPath} (// {" . Markers::TOOLS
                    . '} and // {' . Markers::TOOL_PROMPTS . '} markers found).';

                return ['file' => new PlannedFile($pluginPath, $merged, merge: true), 'guidance' => $guidance];
            }

            $ctorArgs = $this->constructorCallArgs($needs);
            $registerSnippet = "(new ToolScanner(\$registry))->scan(new {$context->name}({$ctorArgs}));";
            $promptSnippet = "'{$context->name} exposes the {$toolName} tool.'";

            $snippet = "public function registerTools(ToolRegistryInterface \$registry): void\n"
                . "{\n    {$registerSnippet}\n}\n\n"
                . "public function getPromptSections(): array\n"
                . "{\n    return [{$promptSnippet}];\n}";

            $guidance = "A plugin already exists at {$pluginPath} — it is left untouched (editing "
                . "existing host code is outside this generator's deterministic write model). Add "
                . "`use {$toolNamespace}\\{$context->name};`, `use Milpa\\Interfaces\\Tooling\\"
                . "ToolRegistryInterface;` and `use Milpa\\ToolRuntime\\ToolScanner;` imports, make it "
                . 'implement `Milpa\\Interfaces\\Tooling\\ToolProviderInterface` if it does not already, '
                . "and add (or extend) these two methods:\n\n{$snippet}";

            return ['file' => null, 'guidance' => $guidance];
        }

        $pluginContents = $this->renderer->render($this->stubs . '/tool-plugin.runtime.php.stub', [
            'namespace' => $pluginNamespace,
            'class' => $context->plugin,
            'toolNamespace' => $toolNamespace,
            'toolClass' => $context->name,
            'toolName' => $toolName,
            'toolCtorArgs' => $this->constructorCallArgs($needs),
        ]);

        $guidance = "New plugin — register it so the kernel boots it: add {$pluginFqcn}::class to the "
            . 'list returned by config/plugins.php. Its registerTools() scans '
            . "{$context->name} for #[Tool] methods.";

        return ['file' => new PlannedFile($pluginPath, $pluginContents), 'guidance' => $guidance];
    }

    /**
     * Parses `--needs=Fqcn[,Fqcn]` (F4) into a trimmed, non-empty, leading-backslash-stripped FQCN
     * list — each becomes one constructor-promoted collaborator (see {@see constructorBlock()}),
     * resolved from the container by every wiring path (see {@see constructorCallArgs()}). No
     * `--needs` (the default) returns `[]`, keeping the tool's zero-arg constructor exactly as before F4.
     *
     * @return list<string>
     */
    private function parseNeeds(GenerationContext $context): array
    {
        $value = $context->option('needs');
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $entry): string => ltrim(trim($entry), '\\'), explode(',', $value)),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * Renders the tool's constructor for `$needs` (F4): one `private readonly` promoted property per
     * FQCN, inline-fully-qualified so the stub never needs a matching `use` import (mirrors how
     * {@see constructorCallArgs()} resolves each one). `[]` (no `--needs`) renders to `''` — the
     * `{{constructor}}` placeholder then disappears entirely, leaving `tool.runtime.php.stub`'s
     * zero-arg shape byte-for-byte unchanged from before F4.
     *
     * @param list<string> $needs
     */
    private function constructorBlock(array $needs): string
    {
        if ($needs === []) {
            return '';
        }

        $seen = [];
        $params = [];
        foreach ($needs as $fqcn) {
            $param = $this->paramName($fqcn, $seen);
            $params[] = "        private readonly \\{$fqcn} \${$param},";
        }

        return "    public function __construct(\n" . implode("\n", $params) . "\n    ) {\n    }\n\n";
    }

    /**
     * Renders the `new {Tool}(...)` constructor-call arguments for `$needs` (F4): each FQCN resolved
     * as `$this->container->get(Fqcn::class)`, comma-joined; `[]` renders to `''` (the pre-F4 zero-arg
     * `new {Tool}()` call). Every call site renders this INSIDE a `registerTools()` method on a
     * `PluginInterface` implementation — the fresh `tool-plugin.runtime.php.stub`, a marker-merged
     * existing plugin, or this generator's own guidance snippet for hand-adding `registerTools()` to
     * one — so `$this->container` (the promoted property every `PluginInterface` constructor fixes)
     * is always the right accessor, never a bare `$container` local.
     *
     * @param list<string> $needs
     */
    private function constructorCallArgs(array $needs): string
    {
        if ($needs === []) {
            return '';
        }

        return implode(', ', array_map(
            static fn (string $fqcn): string => "\$this->container->get(\\{$fqcn}::class)",
            $needs,
        ));
    }

    /**
     * Derives a constructor parameter name from `$fqcn`'s short class name (`lcfirst`), disambiguating
     * repeats (two `--needs` FQCNs sharing a short name) with a numeric suffix so the rendered
     * constructor never declares the same parameter twice.
     *
     * @param array<string, bool> $seen mutated in place — the short names already claimed so far
     */
    private function paramName(string $fqcn, array &$seen): string
    {
        $pos = strrpos($fqcn, '\\');
        $short = $pos === false ? $fqcn : substr($fqcn, $pos + 1);
        $base = lcfirst($short);

        $name = $base;
        $suffix = 2;
        while (isset($seen[$name])) {
            $name = $base . $suffix;
            $suffix++;
        }
        $seen[$name] = true;

        return $name;
    }

    /**
     * Appends a proactive dependency warning to `$wiringGuidance` when the TARGET app (not
     * `milpa/devtools` itself — see the class docblock) does not appear to have `milpa/tool-runtime`
     * installed, so a scaffolded tool that cannot actually load is caught by the generator's own
     * guidance instead of surfacing as a confusing autoload error later.
     */
    private function dependencyGuidance(GenerationContext $context, string $wiringGuidance): string
    {
        if ($this->toolRuntimeAvailable($context)) {
            return $wiringGuidance;
        }

        return $wiringGuidance . "\n\nmilpa/tool-runtime was not detected in this app (no "
            . 'vendor/milpa/tool-runtime directory, and Milpa\\ToolRuntime\\Attributes\\Tool is not '
            . 'loaded) — run: composer require milpa/tool-runtime to make the #[Tool] attribute, '
            . 'ToolResult and ToolScanner available. To actually expose this tool to an agent over MCP, '
            . 'also consider milpa/mcp-server (or an equivalent MCP-exposing package).';
    }

    /**
     * Whether `milpa/tool-runtime` appears available to the TARGET app rooted at `$context->root` —
     * a `vendor/milpa/tool-runtime` directory check first (works even from a `--dry-run` before
     * anything is autoloadable), falling back to a non-autoloading `class_exists(..., false)` check
     * so calling this never itself triggers the very autoload it is testing for.
     */
    private function toolRuntimeAvailable(GenerationContext $context): bool
    {
        if (is_dir($context->root . '/vendor/milpa/tool-runtime')) {
            return true;
        }

        return class_exists('Milpa\\ToolRuntime\\Attributes\\Tool', false);
    }

    /**
     * Derives the `#[Tool(name: ...)]` wire name: an explicit `--tool-name` override, or the
     * snake_case of `$context->name` with a trailing `Tool` suffix stripped first (`CompleteTaskTool`
     * -> `CompleteTask` -> `complete_task`), mirroring how {@see ServiceGenerator} derives its
     * `--interface` companion name from `$context->name` rather than requiring a second CLI argument.
     */
    private function deriveToolName(GenerationContext $context): string
    {
        $override = $context->option('tool-name');
        if ($override !== null && trim($override) !== '') {
            return trim($override);
        }

        $name = $context->name;
        if ($name !== 'Tool' && str_ends_with($name, 'Tool')) {
            $name = substr($name, 0, -\strlen('Tool'));
        }

        return $this->snakeCase($name);
    }

    /** `CompleteTask` -> `complete_task`; a single-word name lowercases with no separators added. */
    private function snakeCase(string $value): string
    {
        $snake = preg_replace('/(?<!^)[A-Z]/', '_$0', $value) ?? $value;

        return strtolower($snake);
    }

    /** The `--description` option, or a generic fallback derived from `$context->name`. */
    private function deriveDescription(GenerationContext $context): string
    {
        $description = $context->option('description');

        return $description !== null && trim($description) !== ''
            ? $description
            : "{$context->name} tool.";
    }

    /** Escapes `\` and `'` so `$value` is safe to interpolate into a single-quoted PHP string literal. */
    private function escapeSingleQuoted(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
