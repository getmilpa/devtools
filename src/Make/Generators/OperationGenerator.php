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
use Milpa\DevTools\Make\PluginRegistration;
use Milpa\DevTools\Make\PluginSurgeon;
use Milpa\DevTools\Make\StubLocator;
use Milpa\DevTools\Make\StubRenderer;
use Milpa\DevTools\Support\ComposerAutoload;

/**
 * Generates an OPERATION: something an agent, the terminal and MCP work with (greenhouse decisions/0591).
 *
 * Nothing else `make` scaffolds enters the agent's catalogue as something to work with: a controller serves a
 * route, an entity stores, and a `#[Tool]` shows up and is refused as unjudgeable, because no producer states its
 * effect. A real resident decided on its own to write `#[Operation]` classes, and the house had no door for it:
 * `implement` fills a class, it does not create one (greenhouse evidence/1127).
 *
 * WHAT IT LEAVES is the declared form of `milpa/command` (greenhouse decisions/0212), one class per operation:
 *
 * - its NAME, `domain:verb` — given (`--operation`), or derived from the plugin and the class and said back;
 * - WHAT IT DOES AT WORST — `#[Mutates]` with the write profile of a domain operation (persistent, no third party,
 *   manual recovery, as the caller, over data), or `#[Reads]` when asked (`--reads`). Said nothing, it mutates:
 *   silence never lowers a control;
 * - THE AUTHORITY IT SPENDS — `#[Needs(scopes: ['<domain>:write'])]`, or `:read`. The domain is what stands before
 *   the separator of its name, so nobody invents a word of authority. It is what a person grants to admit it;
 * - ITS INPUT — the `--fields`, as the constructor;
 * - WHERE ITS STATE LIVES — `run()` receives the repository of an entity of its plugin, the one `make entity`
 *   registered: the one `--entity` names, or the plugin's ONLY entity when none is named. Nothing is invented: it
 *   is the same store, reached by the same key. With several entities and none named, none is guessed — and the
 *   result says so, because a `run()` written against a repository nobody hands it lands and fails at its first
 *   call (greenhouse evidence/1154);
 * - what else `run()` WORKS THROUGH — the `--needs`, resolved by whoever registers it.
 *
 * AND IT IS REGISTERED, never left as prose to paste: `operations()` of the plugin lists it through
 * `DeclaredOperation::from()`, at the anchor when the plugin carries one, structurally through
 * {@see PluginSurgeon} when it does not — the same ladder {@see ServiceGenerator} and {@see ControllerGenerator}
 * follow. A plugin `make plugin` or `make entity` scaffolded becomes a `CommandProvider` this way.
 *
 * THE BODY IS NOT WRITTEN, AND IT SAYS SO: `run()` answers `ok: false` naming itself a scaffold. A scaffold that
 * answered `ok: true` would be a write that did not happen (greenhouse decisions/0587).
 *
 * `milpa/command` is a dependency of the TARGET app, as every runtime stub's framework classes are.
 */
final class OperationGenerator implements GeneratorInterface
{
    /** What a domain operation is named like: a domain, a separator, a verb. */
    private const NAME = '/^[a-z][a-z0-9_-]*[:.][a-z][a-z0-9_.:-]*$/';

    /** The inputs a scaffold declares, and the PHP type each one is. */
    private const INPUTS = ['string' => 'string', 'text' => 'string', 'int' => 'int', 'bigint' => 'int', 'bool' => 'bool', 'float' => 'float', 'decimal' => 'float'];

    /** How the sentence an unfilled scaffold answers with ends, after its class name: what «unfilled» is read by. */
    public const UNFILLED = '::run() is a scaffold — fill it with implement';

    private const COMMAND_PROVIDER = 'Milpa\\Command\\CommandProvider';

    private StubLocator $stubs;

    public function __construct(
        private readonly StubRenderer $renderer = new StubRenderer(),
        private readonly ConventionDetector $detector = new ConventionDetector(),
        private readonly MarkerInserter $markers = new MarkerInserter(),
        private readonly StubLocator $locator = new StubLocator(),
        private readonly PluginSurgeon $surgeon = new PluginSurgeon(),
    ) {
        $this->stubs = $this->locator;
    }

    /** The `<what>` token this generator answers to: `'operation'`. */
    public function name(): string
    {
        return 'operation';
    }

    /**
     * Renders the operation and its registration.
     *
     * @throws \RuntimeException         when the flavor is {@see Flavor::Legacy}: a declared operation is a runtime concept
     * @throws \InvalidArgumentException when a name or a field is not one this scaffold can declare without guessing
     */
    public function generate(GenerationContext $context): GenerationResult
    {
        $this->stubs = $this->locator->at($context->root);
        if ($this->detector->detect($context->root, $context->option('flavor')) !== Flavor::Runtime) {
            throw new \RuntimeException(
                'make:operation has no legacy convention to scaffold — a declared operation (milpa/command) is a '
                . 'runtime-only concept in this engine; use --flavor=runtime (the default outside a legacy host).',
            );
        }

        [$appNamespace, $appDir] = ComposerAutoload::primaryNamespace($context->root) ?? ['App', 'src'];
        $appDir = trim($appDir, '/');
        $namespace = $appNamespace . '\\Plugins\\' . $context->plugin . '\\Operations';
        $path = $context->root . '/' . $appDir . '/Plugins/' . $context->plugin . '/Operations/' . $context->name . '.php';

        $reads = $context->flag('reads');
        $named = self::entityOf($context, $appDir);
        // WHERE ITS STATE LIVES IS NOT SOMETHING TO REMEMBER TO SAY (greenhouse evidence/1154): a plugin's only
        // entity is the one its operations work over. Which of several is a decision, and nobody made it.
        $entities = $named === null ? self::entitiesOf($context, $appDir) : [];
        $entity = $named ?? (\count($entities) === 1 ? $entities[0] : null);
        $entityFqcn = $entity === null ? null : $appNamespace . '\\Plugins\\' . $context->plugin . '\\Entities\\' . $entity;
        $name = self::operationName($context);
        $scope = self::domainOf($name) . ($reads ? ':read' : ':write');
        $description = self::describe($context);

        $contents = $this->renderer->render($this->stubs->path('operation.runtime.php.stub'), [
            'namespace' => $namespace,
            'class' => $context->name,
            'uses' => self::uses($reads, $entityFqcn),
            'docDescription' => str_replace('*/', '* /', $description),
            'description' => self::singleQuoted($description),
            'operationName' => $name,
            'effect' => $reads
                ? '#[Reads]'
                : '#[Mutates(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::WriteAsUser, subject: Subject::Data)]',
            'scope' => $scope,
            'constructor' => self::constructor($context->option('fields')),
            'runDoc' => $entity === null
                ? '    /** @return array<string, mixed> */'
                : "    /**\n     * @param RepositoryInterface<{$entity}> \$" . self::repositoryParameter($entity) . "\n     *\n     * @return array<string, mixed>\n     */",
            'collaborators' => implode(', ', array_filter([
                $entity === null ? '' : 'RepositoryInterface $' . self::repositoryParameter($entity),
                self::collaborators($context->option('needs')),
            ])),
        ]);

        $files = [new PlannedFile($path, $contents)];
        ['file' => $plugin, 'guidance' => $wiring] = $this->register($context, $appNamespace, $appDir, $namespace, $entityFqcn);
        if ($plugin !== null) {
            $files[] = $plugin;
        }

        return new GenerationResult(
            files: $files,
            verifyKind: null,
            verifyTarget: $namespace . '\\' . $context->name,
            flavor: Flavor::Runtime,
            guidance: \sprintf(
                'Operation «%s» is scaffolded: an agent calls it as %s, and it spends the scope «%s». %s%s '
                . 'Its body is NOT written: run() answers ok: false until it is filled. Once this scaffold has landed '
                . 'in the house, write it with: implement plugin=%s class=%s',
                $name,
                self::toolName($name),
                $scope,
                match (true) {
                    $named !== null => "Its run() receives the repository of {$entity}. ",
                    $entity !== null => "Its run() receives the repository of {$entity} — the only entity of this plugin. ",
                    $entities !== [] => 'Its run() receives NO repository: this plugin has several entities (' . implode(', ', $entities)
                        . ') and none was named — if it stores or reads rows, scaffold it with entity=<Entity>. ',
                    default => '',
                },
                $wiring,
                $context->plugin,
                $context->name,
            ),
        );
    }

    /**
     * The name the operation declares: the one given, or `<plugin>:<class>` in kebab case.
     *
     * @throws \InvalidArgumentException when the given name is not `domain:verb`
     */
    public static function operationName(GenerationContext $context): string
    {
        $given = $context->option('operation');
        if ($given === null || trim($given) === '') {
            return self::kebab($context->plugin) . ':' . self::kebab($context->name);
        }
        $given = trim($given);
        if (preg_match(self::NAME, $given) !== 1) {
            throw new \InvalidArgumentException(
                "«{$given}» is not an operation name — it is a domain, a separator and a verb, in lower case: domain:verb",
            );
        }

        return $given;
    }

    /** The domain of an operation name: what stands before its first separator. */
    public static function domainOf(string $name): string
    {
        return (string) preg_split('/[:.]/', $name, 2)[0];
    }

    /** The name an agent's catalogue shows for an operation — the rule of `Milpa\Console\McpProjector::toolName()`. */
    public static function toolName(string $name): string
    {
        return mb_substr((string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $name), 0, 64);
    }

    /**
     * The registration entry `operations()` lists, fully qualified so it splices into any plugin file.
     *
     * The closure is how `run()` finds what it works through: every collaborator by its class, from the plugin's
     * container — and, for an operation over an entity, that entity's repository by the id `make entity` registered
     * it under (`Entity::class . 'Repository'`). A repository has no class of its own to be found by.
     */
    public static function entry(string $operationFqcn, ?string $entityFqcn = null): string
    {
        $resolve = $entityFqcn === null
            ? '$this->container->get($type)'
            : '$type === \\Milpa\\Data\\RepositoryInterface::class ? $this->container->get(\\' . ltrim($entityFqcn, '\\')
                . "::class . 'Repository') : \$this->container->get(\$type)";

        return '\\Milpa\\Command\\Declaration\\DeclaredOperation::from(\\' . ltrim($operationFqcn, '\\')
            . '::class, fn (string $type): object => ' . $resolve . '),';
    }

    /**
     * The entity `--entity` names, when the plugin has it — or null when none was named.
     *
     * @throws \InvalidArgumentException when the name is not an entity file of this plugin
     */
    private static function entityOf(GenerationContext $context, string $appDir): ?string
    {
        $entity = $context->option('entity');
        if ($entity === null || trim($entity) === '') {
            return null;
        }
        $entity = trim($entity);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $entity) !== 1
            || !is_file($context->root . '/' . $appDir . '/Plugins/' . $context->plugin . '/Entities/' . $entity . '.php')) {
            throw new \InvalidArgumentException(
                "«{$entity}» is not an entity of plugin «{$context->plugin}» — scaffold it first: make entity {$context->plugin} {$entity}",
            );
        }

        return $entity;
    }

    /**
     * The entities a plugin has, by name and in order: the classes under its `Entities/` that are entities.
     *
     * @return list<string>
     */
    private static function entitiesOf(GenerationContext $context, string $appDir): array
    {
        return self::entitiesIn($context->root . '/' . $appDir . '/Plugins/' . $context->plugin);
    }

    /**
     * The entities under a plugin's source directory, by name and in order.
     *
     * @return list<string>
     */
    public static function entitiesIn(string $pluginDir): array
    {
        $found = [];
        foreach (glob(rtrim($pluginDir, '/') . '/Entities/*.php') ?: [] as $file) {
            // An entity says it is one; an enum or a value object that lives beside them is not counted.
            if (str_contains((string) file_get_contents($file), 'EntityInterface')) {
                $found[] = basename($file, '.php');
            }
        }
        sort($found);

        return $found;
    }

    /** How `run()` names the repository it receives: `Widget` → `widgets`. */
    private static function repositoryParameter(string $entity): string
    {
        return lcfirst($entity) . 's';
    }

    /**
     * The operations of a plugin whose body is still the scaffold's, by class name.
     *
     * Read from the sentence the scaffold itself answers with: the house wrote it, so the house can tell an unfilled
     * operation from a filled one without calling either (greenhouse evidence/1156).
     *
     * @return list<string>
     */
    public static function unfilledIn(string $pluginDir): array
    {
        $unfilled = [];
        foreach (glob($pluginDir . '/Operations/*.php') ?: [] as $file) {
            $class = basename($file, '.php');
            if (str_contains((string) file_get_contents($file), $class . self::UNFILLED)) {
                $unfilled[] = $class;
            }
        }
        sort($unfilled);

        return $unfilled;
    }

    /**
     * The entity whose repository a plugin hands the operation's `run()`, read back from the entry {@see entry()}
     * wrote — or `null` when the entry finds everything by its class.
     */
    public static function entityHandedTo(string $pluginSource, string $class): ?string
    {
        $entry = '/(?<![A-Za-z0-9_])' . preg_quote($class, '/') . '::class,[^\n]*?\\\\?((?:[A-Za-z_][A-Za-z0-9_]*\\\\)+[A-Za-z_][A-Za-z0-9_]*)'
            . "::class \\. 'Repository'/";

        return preg_match($entry, $pluginSource, $found) === 1 ? $found[1] : null;
    }

    /**
     * Whether a plugin's source already lists the operation class, by its short or its qualified name.
     */
    public static function lists(string $pluginSource, string $class): bool
    {
        return preg_match('/(?<![A-Za-z0-9_])' . preg_quote($class, '/') . '::class\b/', $pluginSource) === 1;
    }

    /**
     * How the operation reaches the plugin's `operations()` — the load-bearing part: a declared class nobody lists
     * is in no catalogue.
     *
     * - no plugin yet: a fresh one, a `CommandProvider` that lists it and carries the anchor for the next;
     * - a plugin that already lists the class: nothing to add;
     * - a plugin with the anchor: inserted there;
     * - a plugin without it: spliced structurally — into the literal list `operations()` returns, or as a new
     *   `operations()` with the anchor in it — and the class is made a `CommandProvider`;
     * - a plugin the surgeon refuses: left untouched, the reason named and the entry handed over. The run is then
     *   incomplete, and the postcondition report says so.
     *
     * @return array{file: ?PlannedFile, guidance: string}
     */
    private function register(GenerationContext $context, string $appNamespace, string $appDir, string $operationNamespace, ?string $entityFqcn): array
    {
        $pluginNamespace = $appNamespace . '\\Plugins\\' . $context->plugin;
        $pluginPath = $context->root . '/' . $appDir . '/Plugins/' . $context->plugin . '/' . $context->plugin . '.php';
        $entry = self::entry($operationNamespace . '\\' . $context->name, $entityFqcn);

        if (!is_file($pluginPath)) {
            $contents = $this->renderer->render($this->stubs->path('operation-plugin.runtime.php.stub'), [
                'namespace' => $pluginNamespace,
                'class' => $context->plugin,
                'operationNamespace' => $operationNamespace,
                'operationClass' => $context->name,
            ]);

            return [
                'file' => new PlannedFile($pluginPath, $contents),
                'guidance' => PluginRegistration::guidance($context->plugin) . ' Its operations() lists ' . $context->name . '.',
            ];
        }

        $existing = (string) file_get_contents($pluginPath);
        if (self::lists($existing, $context->name)) {
            return ['file' => null, 'guidance' => "Already registered: {$pluginPath} already lists {$context->name} — nothing to add."];
        }

        if ($this->markers->hasMarker($existing, Markers::OPERATIONS)) {
            return [
                'file' => new PlannedFile($pluginPath, $this->markers->insertBefore($existing, Markers::OPERATIONS, $entry), merge: true),
                'guidance' => "Registered in the existing plugin at {$pluginPath} (// {" . Markers::OPERATIONS . '} anchor found).',
            ];
        }

        $reason = $this->surgeon->diagnose($existing);
        if ($reason === null) {
            try {
                $provider = $this->surgeon->ensureImplements($existing, self::COMMAND_PROVIDER);
                $merged = $this->surgeon->hasMethod($provider, 'operations')
                    ? $this->surgeon->insertIntoReturnArray($provider, 'operations', $entry)
                    : $this->surgeon->appendMethod($provider, $this->surgeon->wrapMethod(
                        "/**\n * The operations this plugin contributes — what an agent, the terminal and MCP can call.\n *\n"
                        . " * @return list<\\Milpa\\Command\\Operation>\n */\npublic function operations(): array",
                        "return [\n    {$entry}\n    // {" . Markers::OPERATIONS . "}\n];",
                    ));

                return [
                    'file' => new PlannedFile($pluginPath, $merged, merge: true),
                    'guidance' => "Registered in the existing plugin at {$pluginPath} (operations(), structurally): it is a CommandProvider now.",
                ];
            } catch (\RuntimeException $e) {
                $reason = $e->getMessage();
            }
        }

        return [
            'file' => null,
            'guidance' => "The operation could not be registered in {$pluginPath} ({$reason}) — the file is left untouched, and "
                . 'until it lists the operation no catalogue shows it. Make the plugin implement \\' . self::COMMAND_PROVIDER
                . " and list this in the array its operations() returns:\n\n{$entry}\n",
        ];
    }

    /** The imports of the operation class: for a read or for a mutation, and for the entity whose repository it receives. */
    private static function uses(bool $reads, ?string $entityFqcn): string
    {
        $uses = $reads
            ? ['Milpa\\Command\\Declaration\\Needs', 'Milpa\\Command\\Declaration\\Operation', 'Milpa\\Command\\Declaration\\Reads']
            : [
                'Milpa\\Command\\Declaration\\Mutates', 'Milpa\\Command\\Declaration\\Needs', 'Milpa\\Command\\Declaration\\Operation',
                'Milpa\\Command\\Effect\\Authority', 'Milpa\\Command\\Effect\\Externality', 'Milpa\\Command\\Effect\\Mutation',
                'Milpa\\Command\\Effect\\Reversibility', 'Milpa\\Command\\Effect\\Subject',
            ];

        if ($entityFqcn !== null) {
            $uses = [ltrim($entityFqcn, '\\'), ...$uses, 'Milpa\\Data\\RepositoryInterface'];
            sort($uses);
        }

        return implode("\n", array_map(static fn (string $use): string => "use {$use};", $uses)) . "\n";
    }

    /**
     * The constructor the `--fields` declare — the operation's input contract — or nothing.
     *
     * A required input first, a nullable one after it with `null` as its default: PHP wants that order, and an
     * optional input is exactly a nullable one here.
     *
     * @throws \InvalidArgumentException for a field that is not `name:type` of a type an input can be
     */
    private static function constructor(?string $fields): string
    {
        if ($fields === null || trim($fields) === '') {
            return '';
        }
        $required = [];
        $optional = [];
        foreach (explode(',', $fields) as $field) {
            $field = trim($field);
            if ($field === '') {
                continue;
            }
            if (preg_match('/^(\??)([A-Za-z_][A-Za-z0-9_]*):([a-z]+)$/', $field, $parts) !== 1 || !isset(self::INPUTS[$parts[3]])) {
                throw new \InvalidArgumentException(
                    "«{$field}» is not an input an operation scaffold declares — it is name:type, with type one of "
                    . implode(', ', array_keys(self::INPUTS)) . '; prefix the name with ? when it is optional',
                );
            }
            $type = self::INPUTS[$parts[3]];
            if ($parts[1] === '?') {
                $optional[] = "        public readonly ?{$type} \${$parts[2]} = null,";
            } else {
                $required[] = "        public readonly {$type} \${$parts[2]},";
            }
        }
        if ($required === [] && $optional === []) {
            return '';
        }

        return "    public function __construct(\n" . implode("\n", [...$required, ...$optional]) . "\n    ) {\n    }\n\n";
    }

    /** The parameters of `run()`: one collaborator per `--needs` class, fully qualified. */
    private static function collaborators(?string $needs): string
    {
        if ($needs === null || trim($needs) === '') {
            return '';
        }
        $seen = [];
        $parameters = [];
        foreach (explode(',', $needs) as $fqcn) {
            $fqcn = ltrim(trim($fqcn), '\\');
            if ($fqcn === '') {
                continue;
            }
            $position = strrpos($fqcn, '\\');
            $base = lcfirst($position === false ? $fqcn : substr($fqcn, $position + 1));
            $name = $base;
            for ($suffix = 2; isset($seen[$name]); $suffix++) {
                $name = $base . $suffix;
            }
            $seen[$name] = true;
            $parameters[] = "\\{$fqcn} \${$name}";
        }

        return implode(', ', $parameters);
    }

    private static function describe(GenerationContext $context): string
    {
        $description = $context->option('description');

        return $description !== null && trim($description) !== '' ? trim($description) : "{$context->name} operation.";
    }

    /** `ShipWidget` → `ship-widget`. */
    private static function kebab(string $value): string
    {
        return strtolower(trim((string) preg_replace('/(?<!^)[A-Z]/', '-$0', str_replace('_', '-', $value)), '-'));
    }

    /** Escapes `\` and `'` so `$value` is safe inside a single-quoted PHP string literal. */
    private static function singleQuoted(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
