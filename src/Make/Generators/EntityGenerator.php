<?php

declare(strict_types=1);

namespace Milpa\DevTools\Make\Generators;

use Milpa\DevTools\Make\FieldParser;
use Milpa\DevTools\Make\FieldSpec;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\GenerationResult;
use Milpa\DevTools\Make\GeneratorInterface;
use Milpa\DevTools\Make\PlannedFile;
use Milpa\DevTools\Make\StubRenderer;
use Milpa\DevTools\Support\DoctrineAvailability;

/**
 * Generates a Doctrine entity from the `--fields` DSL following the framework's conventions
 * (strict types, id + uuid via {@see \Milpa\Support\UuidGenerator}, typed accessors,
 * enum-as-string columns, ManyToOne relations). Emits one file under the plugin's `Entities/`.
 *
 * `doctrine/orm` is an optional dependency of `milpa/devtools` (see `composer.json`'s `suggest`) —
 * this is the ONLY generator that needs it, and {@see generate()} fails fast with
 * {@see DoctrineAvailability::MESSAGE} when it is not installed, instead of emitting a file the host
 * cannot actually use.
 */
final class EntityGenerator implements GeneratorInterface
{
    private string $stubs;
    private bool $doctrineAvailable;

    /**
     * @param bool|null $doctrineAvailable override for testing; `null` (the default) auto-detects via
     *                                     {@see DoctrineAvailability::isAvailable()}
     */
    public function __construct(
        private readonly FieldParser $parser = new FieldParser(),
        private readonly StubRenderer $renderer = new StubRenderer(),
        ?bool $doctrineAvailable = null,
    ) {
        $this->stubs = \dirname(__DIR__) . '/stubs';
        $this->doctrineAvailable = $doctrineAvailable ?? DoctrineAvailability::isAvailable();
    }

    /** The `<what>` token this generator answers to: `'entity'`. */
    public function name(): string
    {
        return 'entity';
    }

    /**
     * Parses `--fields`, renders the entity class, and returns it paired with its `entity` verify
     * target.
     *
     * @throws \RuntimeException When `doctrine/orm` is not installed (see {@see DoctrineAvailability}).
     */
    public function generate(GenerationContext $context): GenerationResult
    {
        if (!$this->doctrineAvailable) {
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
        );
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
