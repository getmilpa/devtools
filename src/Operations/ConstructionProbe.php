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
     * @return array{unjudged?: string, routes?: list<string>, built?: bool, error?: string, unresolvable?: list<array{parameter: string, type: string}>}
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

        $routes = array_values(array_filter((array) ($answer['routes'] ?? []), 'is_string'));
        if ($routes === []) {
            return ['routes' => []];
        }
        if (($answer['built'] ?? false) === true) {
            return ['routes' => $routes, 'built' => true];
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
        ];
    }

    /**
     * The refusal a model corrects from: which route asks, what could not be filled, and the rule — with
     * the line that registers the built controller in its plugin's boot().
     *
     * @param list<string>                                 $routes
     * @param list<array{parameter: string, type: string}> $unresolvable
     */
    public static function refusal(string $plugin, string $class, array $routes, string $error, array $unresolvable): string
    {
        $missing = $unresolvable === [] ? '' : ' It could not fill '
            . implode(', ', array_map(static fn (array $u): string => $u['parameter'] . ' (' . $u['type'] . ')', $unresolvable)) . '.';
        // A placeholder per parameter the container could not fill — except the container itself, which
        // is replaced by what the controller would have pulled out of it.
        $arguments = implode(', ', array_map(
            static fn (array $u): string => \in_array($u['type'], self::CONTAINERS, true)
                ? '/* what it would pull from the container */'
                : '/* ' . substr((string) strrchr('\\' . $u['type'], '\\'), 1) . ' ' . $u['parameter'] . ' */',
            $unresolvable,
        ));

        return "refused: the house cannot build «{$class}» — " . implode(' and ', $routes)
            . " asks the container for it, and the container said: {$error}.{$missing}\n"
            . ControllerDependencies::RULE . " Register the built controller in {$plugin}::boot():\n"
            . "    \$this->container->registerService({$class}::class, new {$class}({$arguments}));\n"
            . 'and pass it what it needs, not the container — a controller that pulls its collaborators from '
            . 'the container fails at request time, where no trial sees it.';
    }
}
