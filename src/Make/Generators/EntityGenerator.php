<?php

declare(strict_types=1);

namespace Milpa\DevTools\Make\Generators;

use Milpa\DevTools\Make\ConventionDetector;
use Milpa\DevTools\Make\FieldParser;
use Milpa\DevTools\Make\FieldSpec;
use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\GenerationResult;
use Milpa\DevTools\Make\GeneratorInterface;
use Milpa\DevTools\Make\PlannedFile;
use Milpa\DevTools\Make\StubRenderer;
use Milpa\DevTools\Support\ComposerAutoload;
use Milpa\DevTools\Support\DoctrineAvailability;

/**
 * Generates an entity in one of two conventions — see {@see Flavor} — auto-detected per app root by
 * {@see ConventionDetector} (override with `GenerationContext`'s `flavor` option, e.g.
 * `--flavor=runtime`), mirroring exactly how {@see ControllerGenerator} picks its convention:
 *
 * - **Legacy**: a Doctrine entity from the `--fields` DSL following the framework's conventions
 *   (strict types, id + uuid via {@see \Milpa\Support\UuidGenerator}, typed accessors,
 *   enum-as-string columns, ManyToOne relations). `doctrine/orm` is an optional dependency of
 *   `milpa/devtools` (see `composer.json`'s `suggest`) — this is the only path that needs it, and
 *   {@see generate()} fails fast with {@see DoctrineAvailability::MESSAGE} when it is not installed,
 *   instead of emitting a file the host cannot actually use.
 * - **Runtime**: a plain `final readonly class` implementing `milpa/data`'s
 *   `Milpa\Data\EntityInterface` (`id()`/`toArray()`/`fromArray()`, no Doctrine attributes) — the
 *   `milpa/runtime` + skeleton convention. An orphaned entity class has nothing to persist it, so
 *   this path ALSO wires a booting plugin whose `boot()` registers a `Milpa\Data\FileRepository` for
 *   the entity into the DI container — a minimal `PluginInterface` implementation is generated
 *   alongside the entity when the target plugin area doesn't exist yet, or the exact registration
 *   snippet to add by hand is returned via {@see GenerationResult::$guidance} when it does — see
 *   {@see self::wireRepository()}, which mirrors {@see ControllerGenerator::wireRoute()}.
 */
final class EntityGenerator implements GeneratorInterface
{
    private string $stubs;

    /**
     * @param bool|null $doctrineAvailable override for testing; `null` (the default) auto-detects via
     *                                     {@see DoctrineAvailability::isAvailable()} — checked LAZILY,
     *                                     only inside {@see generateLegacy()}, so a bare
     *                                     `new EntityGenerator()` used for the RUNTIME path never
     *                                     triggers Doctrine autoloading (mirrors the guarantee
     *                                     {@see ControllerGenerator}'s runtime path already has).
     */
    public function __construct(
        private readonly FieldParser $parser = new FieldParser(),
        private readonly StubRenderer $renderer = new StubRenderer(),
        private readonly ConventionDetector $detector = new ConventionDetector(),
        private readonly ?bool $doctrineAvailable = null,
    ) {
        $this->stubs = \dirname(__DIR__) . '/stubs';
    }

    /** The `<what>` token this generator answers to: `'entity'`. */
    public function name(): string
    {
        return 'entity';
    }

    /** Renders the entity (+ repository wiring for runtime) per the detected/overridden {@see Flavor}. */
    public function generate(GenerationContext $context): GenerationResult
    {
        $flavor = $this->detector->detect($context->root, $context->option('flavor'));

        return $flavor === Flavor::Runtime
            ? $this->generateRuntime($context)
            : $this->generateLegacy($context);
    }

    /**
     * Parses `--fields`, renders the Doctrine entity class, and returns it paired with its `entity`
     * verify target.
     *
     * @throws \RuntimeException When `doctrine/orm` is not installed (see {@see DoctrineAvailability}).
     */
    private function generateLegacy(GenerationContext $context): GenerationResult
    {
        if (!($this->doctrineAvailable ?? DoctrineAvailability::isAvailable())) {
            throw new \RuntimeException(DoctrineAvailability::MESSAGE);
        }

        $fields = $this->parser->parse($context->option('fields') ?? '');
        $namespace = 'Milpa\\Plugins\\' . $context->plugin . '\\Entities';
        $table = $context->option('table') ?? strtolower($context->name) . 's';

        $props = [];
        $methods = [];
        $uses = [];
        foreach ($fields as $field) {
            if (\in_array(strtolower($field->name), ['id', 'uuid'], true)) {
                throw new \InvalidArgumentException(
                    "field '{$field->name}' collides with the entity stub's built-in \${$field->name} — choose a different name",
                );
            }
            $props[] = $this->property($field);
            $methods[] = $this->accessors($field);
            $useLine = $this->useLine($field, $context->plugin);
            if ($useLine !== null) {
                $uses[$useLine] = true;
            }
        }

        $contents = $this->renderer->render($this->stubs . '/entity.php.stub', [
            'namespace' => $namespace,
            'class' => $context->name,
            'table' => $table,
            'uses' => $uses === [] ? '' : implode("\n", array_keys($uses)) . "\n",
            // each block already carries a trailing blank-line separator; collapse the final one so
            // the template's own blank line (before __construct / the class closing brace) is the
            // only one left — otherwise PSR-12 "no double blank line" gets violated.
            'properties' => rtrim(implode('', $props), "\n") . "\n",
            'methods' => rtrim(implode('', $methods), "\n") . "\n",
        ]);

        $path = $context->root . '/plugins/' . $context->plugin . '/Entities/' . $context->name . '.php';

        return new GenerationResult(
            files: [new PlannedFile($path, $contents)],
            verifyKind: 'entity',
            verifyTarget: $namespace . '\\' . $context->name,
            flavor: Flavor::Legacy,
        );
    }

    /**
     * Parses `--fields`, renders the plain `Milpa\Data\EntityInterface` entity class (+ repository
     * wiring), and returns it paired with its `entity` verify target.
     *
     * @throws \InvalidArgumentException When a field is named `id` (collides with the stub's
     *                                   built-in `$id`), or is a `belongsTo` relation — `milpa/data`
     *                                   has no relation concept yet, so a runtime entity cannot
     *                                   express one; store the related id as a plain scalar field
     *                                   instead, or use `--flavor=legacy`.
     */
    private function generateRuntime(GenerationContext $context): GenerationResult
    {
        $fields = $this->parser->parse($context->option('fields') ?? '');
        [$appNamespace, $appDir] = ComposerAutoload::primaryNamespace($context->root) ?? ['App', 'src'];
        $appDir = trim($appDir, '/');

        $entityNamespace = $appNamespace . '\\Plugins\\' . $context->plugin . '\\Entities';
        $entityPath = $context->root . '/' . $appDir . '/Plugins/' . $context->plugin
            . '/Entities/' . $context->name . '.php';

        // `$id` is always the first constructor param, mirroring milpa/data's own `Post`-shaped
        // fixture (`Milpa\Data\Tests\Fixtures\TestEntity`) — every array is seeded with the `id`
        // line so an empty `--fields` DSL still renders a valid (if minimal) entity, with no
        // dangling blank line to reconcile (see StubRenderer's plain str_replace semantics).
        $ctorLines = ['        public int|string|null $id,'];
        $toArrayLines = ["            'id' => \$this->id,"];
        $fromArrayLines = ["            \$row['id'] ?? null,"];
        $uses = [];

        foreach ($fields as $field) {
            if (strtolower($field->name) === 'id') {
                throw new \InvalidArgumentException(
                    "field '{$field->name}' collides with the entity stub's built-in \${$field->name} — choose a different name",
                );
            }

            if ($field->kind === 'belongsTo') {
                throw new \InvalidArgumentException(
                    "field '{$field->name}': belongsTo relations aren't supported by runtime entities "
                    . "yet (milpa/data has no relation concept) — store the related id as a plain "
                    . "scalar field (e.g. '{$field->name}:int'), or use --flavor=legacy",
                );
            }

            if ($field->kind === 'enum') {
                $enum = (string) $field->target;
                $uses[] = 'use ' . $appNamespace . '\\Plugins\\' . $context->plugin . '\\Enums\\' . $enum . ';';
                $type = ($field->nullable ? '?' : '') . $enum;

                $ctorLines[] = "        public {$type} \${$field->name},";
                $toArrayLines[] = $field->nullable
                    ? "            '{$field->name}' => \$this->{$field->name}?->value,"
                    : "            '{$field->name}' => \$this->{$field->name}->value,";
                $fromArrayLines[] = $field->nullable
                    ? "            \$row['{$field->name}'] !== null ? {$enum}::from(\$row['{$field->name}']) : null,"
                    : "            {$enum}::from(\$row['{$field->name}']),";

                continue;
            }

            $phpType = $this->phpType($field);
            $type = ($field->nullable ? '?' : '') . $phpType;
            $ctorLines[] = "        public {$type} \${$field->name},";

            if ($phpType === 'DateTime') {
                $uses[] = 'use DateTime;';
                $toArrayLines[] = $field->nullable
                    ? "            '{$field->name}' => \$this->{$field->name}?->format(DATE_ATOM),"
                    : "            '{$field->name}' => \$this->{$field->name}->format(DATE_ATOM),";
                $fromArrayLines[] = $field->nullable
                    ? "            \$row['{$field->name}'] !== null ? new DateTime(\$row['{$field->name}']) : null,"
                    : "            new DateTime(\$row['{$field->name}']),";

                continue;
            }

            $toArrayLines[] = "            '{$field->name}' => \$this->{$field->name},";
            $fromArrayLines[] = "            \$row['{$field->name}'],";
        }

        $contents = $this->renderer->render($this->stubs . '/entity.runtime.php.stub', [
            'namespace' => $entityNamespace,
            'class' => $context->name,
            'uses' => $uses === [] ? '' : implode("\n", array_unique($uses)) . "\n",
            'ctorParams' => implode("\n", $ctorLines),
            'toArrayEntries' => implode("\n", $toArrayLines),
            'fromArrayArgs' => implode("\n", $fromArrayLines),
        ]);

        $files = [new PlannedFile($entityPath, $contents)];

        ['file' => $pluginFile, 'guidance' => $guidance] = $this->wireRepository(
            $context,
            $appNamespace,
            $appDir,
            $entityNamespace,
        );
        if ($pluginFile !== null) {
            $files[] = $pluginFile;
        }

        return new GenerationResult(
            files: $files,
            verifyKind: 'entity',
            verifyTarget: $entityNamespace . '\\' . $context->name,
            flavor: Flavor::Runtime,
            guidance: $guidance,
        );
    }

    /**
     * Decides how the generated entity reaches a booting `Milpa\Data\FileRepository` — the
     * load-bearing part of the runtime path, since an orphaned entity class has nothing to persist
     * it (see the class docblock). Mirrors {@see ControllerGenerator::wireRoute()} exactly, one
     * concern swapped for the other (route registration -> repository registration):
     *
     * - No `PluginInterface` plugin exists yet at the target area's conventional path
     *   (`{appDir}/Plugins/{plugin}/{plugin}.php`) -> generate a minimal one whose `boot()`
     *   registers a `FileRepository($root . '/var/<table>.json', Entity::class)` into the DI
     *   container under the id `Entity::class . 'Repository'`, plus guidance to register the new
     *   plugin class in `config/plugins.php`.
     * - One already exists -> it is NOT edited (same deterministic-write rationale as the
     *   controller path). The exact `boot()` registration snippet to add by hand is returned
     *   instead.
     *
     * Existence is checked on the FILESYSTEM only (`is_file()`), not via reflection/autoloading —
     * safe to call from a `--dry-run` before anything is installed/autoloadable, and consistent
     * with {@see ControllerGenerator::wireRoute()}.
     *
     * @return array{file: ?PlannedFile, guidance: string}
     */
    private function wireRepository(
        GenerationContext $context,
        string $appNamespace,
        string $appDir,
        string $entityNamespace,
    ): array {
        $table = $context->option('table') ?? strtolower($context->name) . 's';

        $pluginNamespace = $appNamespace . '\\Plugins\\' . $context->plugin;
        $pluginPath = $context->root . '/' . $appDir . '/Plugins/' . $context->plugin . '/' . $context->plugin . '.php';
        $pluginFqcn = $pluginNamespace . '\\' . $context->plugin;
        $repositoryId = "{$context->name}::class . 'Repository'";

        if (is_file($pluginPath)) {
            $snippet = "\$this->container->registerService(\n"
                . "    {$repositoryId},\n"
                . "    new FileRepository((new RootResolver())->resolve() . '/var/{$table}.json', {$context->name}::class),\n"
                . ');';

            $guidance = "A plugin already exists at {$pluginPath} — it is left untouched (editing "
                . "existing host code is outside this generator's deterministic write model). Add "
                . "`use {$entityNamespace}\\{$context->name};`, `use Milpa\\Data\\FileRepository;` and "
                . "`use Milpa\\Runtime\\Support\\RootResolver;` imports and this to its boot():\n\n{$snippet}\n\n"
                . "Resolve it later via \$container->get({$repositoryId}).";

            return ['file' => null, 'guidance' => $guidance];
        }

        $pluginContents = $this->renderer->render($this->stubs . '/entity-plugin.runtime.php.stub', [
            'namespace' => $pluginNamespace,
            'class' => $context->plugin,
            'entityNamespace' => $entityNamespace,
            'entityClass' => $context->name,
            'table' => $table,
        ]);

        $guidance = "New plugin — register it so the kernel boots it: add {$pluginFqcn}::class to the "
            . 'list returned by config/plugins.php. Its boot() wires a FileRepository for '
            . "{$context->name}; resolve it later via \$container->get({$repositoryId}).";

        return ['file' => new PlannedFile($pluginPath, $pluginContents), 'guidance' => $guidance];
    }

    private function property(FieldSpec $field): string
    {
        $default = $field->nullable ? ' = null' : '';
        $phpType = ($field->nullable ? '?' : '') . $this->phpType($field);

        if ($field->kind === 'belongsTo') {
            return $this->renderer->render($this->stubs . '/entity-manytoone.stub', [
                'target' => (string) $field->target,
                'name' => $field->name,
                'nullableBool' => $field->nullable ? 'true' : 'false',
                'phpType' => $phpType,
                'default' => $default,
            ]);
        }

        if ($field->kind === 'enum') {
            // The column/property always stores the enum's scalar backing value (string), not the
            // enum type itself — $field->phpType holds the *enum class name* here (see FieldParser),
            // so the property type is built explicitly rather than reusing the generic $phpType above.
            $lengthArg = isset($field->modifiers['length']) ? ', length: ' . $field->modifiers['length'] : '';
            $nullableArg = $field->nullable ? ', nullable: true' : '';
            $enumPhpType = ($field->nullable ? '?' : '') . 'string';

            return $this->renderer->render($this->stubs . '/entity-enum.stub', [
                'lengthArg' => $lengthArg,
                'nullableArg' => $nullableArg,
                'phpType' => $enumPhpType,
                'name' => $field->name,
                'default' => $default,
            ]);
        }

        return $this->renderer->render($this->stubs . '/entity-scalar.stub', [
            'column' => $this->columnArgs($field),
            'phpType' => $phpType,
            'name' => $field->name,
            'default' => $default,
            'varDoc' => $this->varDoc($field),
        ]);
    }

    private function columnArgs(FieldSpec $field): string
    {
        $args = ["type: '{$field->columnType}'"];
        if (isset($field->modifiers['length'])) {
            $args[] = 'length: ' . $field->modifiers['length'];
        }
        if (isset($field->modifiers['precision'])) {
            $args[] = 'precision: ' . $field->modifiers['precision'];
            $args[] = 'scale: ' . $field->modifiers['scale'];
        }
        if ($field->nullable) {
            $args[] = 'nullable: true';
        }

        return implode(', ', $args);
    }

    private function accessors(FieldSpec $field): string
    {
        $studly = ucfirst($field->name);
        $var = '$this->' . $field->name;

        if ($field->kind === 'enum') {
            $enum = (string) $field->target;
            $getterType = ($field->nullable ? '?' : '') . $enum;
            $get = $field->nullable
                ? "{$var} === null ? null : {$enum}::from({$var})"
                : "{$enum}::from({$var})";
            $setValue = $field->nullable ? '$value?->value' : '$value->value';

            return "    public function get{$studly}(): {$getterType}\n"
                . "    {\n"
                . "        return {$get};\n"
                . "    }\n"
                . "\n"
                . "    public function set{$studly}({$getterType} \$value): self\n"
                . "    {\n"
                . "        {$var} = {$setValue};\n"
                . "\n"
                . "        return \$this;\n"
                . "    }\n"
                . "\n";
        }

        $type = ($field->nullable ? '?' : '') . $this->phpType($field);

        // Array-typed accessors need the same `@return`/`@param` docblocks as the property's `@var`
        // (F3): PHPStan L6 flags `missingType.iterableValue` on the getter/setter independently of
        // the property, since the native `array`/`?array` type alone carries no value-type info.
        $getDoc = '';
        $setDoc = '';
        if ($this->phpType($field) === 'array') {
            $arrayType = 'array<string, mixed>' . ($field->nullable ? '|null' : '');
            $getDoc = "    /** @return {$arrayType} */\n";
            $setDoc = "    /** @param {$arrayType} \$value */\n";
        }

        return $getDoc
            . "    public function get{$studly}(): {$type}\n"
            . "    {\n"
            . "        return {$var};\n"
            . "    }\n"
            . "\n"
            . $setDoc
            . "    public function set{$studly}({$type} \$value): self\n"
            . "    {\n"
            . "        {$var} = \$value;\n"
            . "\n"
            . "        return \$this;\n"
            . "    }\n"
            . "\n";
    }

    /** Strips FieldParser's leading `\` (used for `\DateTime`) so it matches the bare `use DateTime;` import. */
    private function phpType(FieldSpec $field): string
    {
        return ltrim($field->phpType, '\\');
    }

    /**
     * Scalar `array` properties (DSL `json`) need an explicit `@var` docblock — plain `private array`
     * has no value-type info, which trips PHPStan level 6's `missingType.iterableValue`. Every other
     * scalar type is unambiguous from its native PHP type, so no docblock is emitted for those.
     */
    private function varDoc(FieldSpec $field): string
    {
        if ($this->phpType($field) !== 'array') {
            return '';
        }

        $nullSuffix = $field->nullable ? '|null' : '';

        return "    /** @var array<string, mixed>{$nullSuffix} */\n";
    }

    private function useLine(FieldSpec $field, string $plugin): ?string
    {
        // belongsTo targets always live in the same plugin's Entities/ namespace as the generated
        // entity itself, so an explicit `use` would be a same-namespace import — cs-fixer's
        // no_unused_imports flags those as dead, and rightly so: PHP resolves them automatically.
        if ($field->kind === 'enum') {
            return 'use Milpa\\Plugins\\' . $plugin . '\\Enums\\' . (string) $field->target . ';';
        }
        if ($field->kind === 'scalar' && ($field->columnType === 'datetime' || $field->columnType === 'date')) {
            return 'use DateTime;';
        }

        return null;
    }
}
