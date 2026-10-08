<?php

/**
 * This file is part of Milpa DevTools — the developer toolbox of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Operations;

use Milpa\DevTools\Make\ControllerDependencies;

/**
 * Asks a booted house to build a routed controller, the way serving its route does.
 *
 * ── WHY EXECUTION AND NOT A READING OF THE CONSTRUCTOR (greenhouse decisions/0541) ──────────────
 *
 * Rod's first live run (evidence/1071) landed GREEN a controller whose constructor asked for
 * `DIContainerInterface`; nothing registers it, and `GET /blog` went from 200 to 500. A reading would
 * have to guess: an interface may be registered in some plugin's boot(), a concrete class may still
 * refuse to build. The house's own act does not guess — `ContainerHandlerResolver` asks the container
 * for the class a route names — so this runs that act in a child process ({@see self::SCRIPT}), on the
 * root it is given, and reports what the container said.
 *
 * Only a class some route names is built: the house builds nothing else by name, and a judge that
 * constructs what nothing constructs invents the use. A house that does not boot is left UNJUDGED and
 * says so — the boot witness refuses a broken boot at the promotion (0512/0515); a second judge of the
 * same fact would be a second authority.
 */
final class ConstructionProbe
{
    /** The child that boots the house and asks its container; one JSON object on its STDOUT. */
    public const SCRIPT = __DIR__ . '/../../resources/construction-probe.php';

    /** What an operation over stored rows takes: registered by nobody, handed by the entry that names its entity. */
    private const REPOSITORY = 'Milpa\\Data\\RepositoryInterface';

    /** The container contracts a controller should not ask for (greenhouse decisions/0541, point 3). */
    private const CONTAINERS = ['Milpa\\Interfaces\\Di\\DIContainerInterface', 'Psr\\Container\\ContainerInterface'];

    /**
     * @param string|null $php the PHP binary for the child, or `null` for this process's own
     */
    public function __construct(private readonly ?string $php = null)
    {
    }

    /**
     * What the house said about building `$class`.
     *
     * `unjudged` carries the reason nothing was built (no autoloader, a boot that failed, a child that
     * answered nothing); otherwise `routes` lists the routes that name the class — empty when none does —
     * and, when some does, `built` says whether the container gave it back, with `error` and
     * `unresolvable` (parameter → type) when it did not.
     *
     * `operation` is there when the class is an operation the booted house offers (greenhouse evidence/1154): its
     * name, whether the entry that lists it handed `run()` everything it works through, and — when it did not —
     * each type it could not hand with what the house answered.
     *
     * @return array{unjudged?: string, routes?: list<string>, built?: bool, error?: string, unresolvable?: list<array{parameter: string, type: string}>, operation?: array{name: string, handed: bool, unhanded: list<array{type: string, error: string}>}}
     */
    public function probe(string $root, string $class): array
    {
        if (!is_file($root . '/vendor/autoload.php')) {
            return ['unjudged' => 'this app has no vendor/autoload.php'];
        }

        // The same binary `test` runs under; one that cannot start answers nothing, and that is said.
        $php = $this->php ?? \PHP_BINARY;
        exec('timeout 60 ' . escapeshellarg($php) . ' ' . escapeshellarg(self::SCRIPT) . ' '
            . escapeshellarg($root) . ' ' . escapeshellarg($class) . ' 2>/dev/null', $lines, $exit);
        $answer = json_decode(implode("\n", $lines), true);
        if (!\is_array($answer)) {
            return ['unjudged' => 'the house gave no answer when booted (exit ' . $exit . ')'];
        }
        if (($answer['booted'] ?? false) !== true) {
            return ['unjudged' => 'the house did not boot: ' . (\is_string($answer['reason'] ?? null) ? $answer['reason'] : 'no reason given')];
        }

        $asked = self::operationIn($answer);
        $routes = array_values(array_filter((array) ($answer['routes'] ?? []), 'is_string'));
        if ($routes === []) {
            return ['routes' => []] + $asked;
        }
        if (($answer['built'] ?? false) === true) {
            return ['routes' => $routes, 'built' => true] + $asked;
        }

        $unresolvable = [];
        foreach ((array) ($answer['unresolvable'] ?? []) as $entry) {
            if (\is_array($entry) && \is_string($entry['parameter'] ?? null) && \is_string($entry['type'] ?? null)) {
                $unresolvable[] = ['parameter' => $entry['parameter'], 'type' => $entry['type']];
            }
        }

        return [
            'routes' => $routes,
            'built' => false,
            'error' => \is_string($answer['error'] ?? null) ? $answer['error'] : 'the container gave nothing back',
            'unresolvable' => $unresolvable,
        ] + $asked;
    }

    /**
     * What the child said of the operation this class is, read strictly — or nothing: it is none.
     *
     * @param array<mixed> $answer
     *
     * @return array{}|array{operation: array{name: string, handed: bool, unhanded: list<array{type: string, error: string}>}}
     */
    private static function operationIn(array $answer): array
    {
        $said = $answer['operation'] ?? null;
        if (!\is_array($said) || !\is_string($said['name'] ?? null) || !\is_bool($said['handed'] ?? null)) {
            return [];
        }
        $unhanded = [];
        foreach ((array) ($said['unhanded'] ?? []) as $entry) {
            if (\is_array($entry) && \is_string($entry['type'] ?? null)) {
                $unhanded[] = ['type' => $entry['type'], 'error' => \is_string($entry['error'] ?? null) ? $entry['error'] : ''];
            }
        }

        return ['operation' => ['name' => $said['name'], 'handed' => $said['handed'], 'unhanded' => $unhanded]];
    }

    /**
     * The refusal a model corrects from when the house cannot hand an operation what its `run()` works through:
     * what it asked for, what the house answered, why, and THE WAY with the order in which it lands.
     *
     * ── A REPOSITORY IS REACHED BY ITS ENTITY (greenhouse evidence/1154) ──────────────────────────────────────────
     *
     * `Milpa\Data\RepositoryInterface` is registered by nobody, and should not be: there is one repository per
     * entity, under the id `make entity` gave it. What hands it to `run()` is the entry that lists the operation in
     * its plugin — written with the entity when the operation was scaffolded with one, and by class when it was
     * not. A real resident scaffolded with none, wrote `run()` against the repository, and nothing said a word
     * until the first call. The fix is one exact edit of that entry, and it is written out here: with one entity
     * in the plugin it names it; with several it leaves the name to whoever knows which.
     *
     * Anything else nobody registered is the defect a controller's constructor has, and takes the same fix: its
     * type registered in the plugin's `boot()`.
     *
     * @param string                                   $pluginDir the plugin's source directory
     * @param string                                   $fqcn      the operation's class
     * @param list<array{type: string, error: string}> $unhanded
     */
    public static function unhanded(string $pluginDir, string $plugin, string $class, string $fqcn, string $name, array $unhanded): string
    {
        $asked = implode('; ', array_map(static fn (array $u): string => $u['type'] . ', and the house answered: ' . rtrim($u['error'], '. '), $unhanded));
        $text = "refused: the house cannot hand «{$class}» what its run() works through — «{$name}» asked for {$asked}.\n"
            . "What run() takes is found by the entry that lists this operation in operations() of its plugin, and by nothing else.\n";
        $again = "Then send this same implement plugin={$plugin} class={$class} again.";

        $types = array_column($unhanded, 'type');
        $others = array_values(array_filter($types, static fn (string $type): bool => $type !== self::REPOSITORY));
        $steps = [];
        if (\in_array(self::REPOSITORY, $types, true)) {
            $entities = \Milpa\DevTools\Make\Generators\OperationGenerator::entitiesIn($pluginDir);
            $text .= 'A repository has no class to be found by: it is reached by its entity, under the id `make entity` registered it with. '
                . ($entities === [] ? "This plugin has no entity yet.\n" : "This plugin's entities: " . implode(', ', $entities) . ".\n");
            $byClass = \Milpa\DevTools\Make\Generators\OperationGenerator::entry($fqcn);
            $source = (string) @file_get_contents($pluginDir . '/' . $plugin . '.php');
            $namespace = substr($fqcn, 0, (int) strrpos($fqcn, '\\Operations\\'));
            $entity = \count($entities) === 1 ? $entities[0] : '<Entity>';
            $byEntity = \Milpa\DevTools\Make\Generators\OperationGenerator::entry($fqcn, $namespace . '\\Entities\\' . $entity);
            if ($entities === []) {
                $steps[] = "Scaffold the entity it stores — make what=entity plugin={$plugin} name=<Entity> fields=… — and promote it.";
            }
            $steps[] = str_contains($source, $byClass)
                ? "Make its entry say which entity — edit plugin={$plugin} class={$plugin} with one {find, replace} pair, and promote it:\n   find: {$byClass}\n   replace: {$byEntity}"
                : "Make the entry that lists it in operations() of {$plugin} resolve " . self::REPOSITORY . " to its entity's repository — the resolver `make` writes is:\n   {$byEntity}";
        }
        foreach ($others as $type) {
            $steps[] = "Register «{$type}» in boot() of {$plugin} — \$this->container->registerService(\\" . ltrim($type, '\\')
                . '::class, <how it is built>); — or take a concrete class the container can build; and promote it.';
        }
        $steps[] = $again;

        return $text . "In this order:\n" . implode("\n", array_map(static fn (int $n, string $step): string => ($n + 1) . '. ' . $step, array_keys($steps), $steps));
    }

    /**
     * The refusal a model corrects from: which route asks, what could not be filled, the rule, and the ORDER
     * in which the fix lands.
     *
     * ── WHY THE PARAMETER'S TYPE AND NOT THE BUILT CONTROLLER (greenhouse evidence/1075) ──────────────
     *
     * The first version said «register the built controller in boot()». A real resident followed it and hit a
     * wall: `new BlogController($repository)` in the plugin is refused by static analysis while the live
     * controller has no such constructor, and the controller is refused by THIS judge until something is
     * registered. Registering the parameter's TYPE names no controller, so it lands on its own — and then the
     * container fills the parameter and the controller lands.
     *
     * @param list<string>                                 $routes
     * @param list<array{parameter: string, type: string}> $unresolvable
     */
    public static function refusal(string $plugin, string $class, array $routes, string $error, array $unresolvable): string
    {
        $missing = $unresolvable === [] ? '' : ' It could not fill '
            . implode(', ', array_map(static fn (array $u): string => $u['parameter'] . ' (' . $u['type'] . ')', $unresolvable)) . '.';
        $container = array_values(array_filter($unresolvable, static fn (array $u): bool => \in_array($u['type'], self::CONTAINERS, true)));
        $types = array_values(array_filter($unresolvable, static fn (array $u): bool => !\in_array($u['type'], self::CONTAINERS, true)));

        $text = "refused: the house cannot build «{$class}» — " . implode(' and ', $routes)
            . " asks the container for it, and the container said: {$error}.{$missing}\n" . ControllerDependencies::RULE . "\n";
        if ($container !== []) {
            $text .= "Do not take the container: take what {$class} would pull from it as constructor parameters, "
                . "not the container — a controller that pulls its collaborators from the container fails at request "
                . "time, where no trial sees it. Then make those parameters buildable as below.\n";
        }
        if ($types === [] && $container === []) {
            return $text . 'The constructor itself failed: fix what it does, or move that work out of the constructor.';
        }

        $lines = array_map(
            static fn (array $u): string => "       \$this->container->registerService(\\{$u['type']}::class, /* the "
                . substr((string) strrchr('\\' . $u['type'], '\\'), 1) . ' instance */);',
            $types,
        );

        return $text . "Make it buildable in this order — each step lands on its own:\n"
            . "  1. In {$plugin}::boot(), register what the parameter asks for under its type:\n"
            . ($lines === [] ? "       \$this->container->registerService(SomeInterface::class, \$instance);\n" : implode("\n", $lines) . "\n")
            . "  2. implement {$class} again: the container then fills "
            . ($types === [] ? 'those parameters' : implode(', ', array_map(static fn (array $u): string => $u['parameter'], $types))) . ".\n"
            . "(Registering the built controller instead — new {$class}(...) in boot() — only lands once {$class} "
            . 'already has that constructor.)';
    }
}
