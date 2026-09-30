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

namespace Milpa\DevTools\Make;

/**
 * The rule for a controller with collaborators: what it needs is registered, by type, in its plugin's boot().
 *
 * Rod's first live run (greenhouse evidence/1071) ended in a 500 because nothing told the resident this:
 * its controller asked for `DIContainerInterface`, nothing registers that, and the house could not build
 * the controller the route named. The rule lived only in a comment of the skeleton's HelloPlugin. It is
 * said by `make controller` (its answer and the class it writes) and by `implement`'s refusal — one owner,
 * so the three cannot drift (greenhouse decisions/0541).
 */
final class ControllerDependencies
{
    /** Why a controller with an interface-typed collaborator does not build on its own. */
    public const RULE = 'The container builds a controller on its own only when every constructor parameter is a '
        . 'concrete class it can build, has a default, or accepts null; an interface (a repository, the container '
        . 'itself) resolves only if something registered it.';

    /**
     * The guidance `make controller` gives: where a controller with dependencies is registered.
     *
     * @param string $plugin     the plugin's short class name, e.g. `Blog`
     * @param string $controller the controller's short class name, e.g. `BlogController`
     */
    public static function guidance(string $plugin, string $controller): string
    {
        return "If {$controller} needs a collaborator behind an interface (a repository, a service), first register it "
            . "under its type in {$plugin}::boot(): `\$this->container->registerService(SomeInterface::class, \$instance);` — "
            . "then implement {$controller} with that constructor parameter, and the container fills it. Take what it "
            . 'needs, not the container. ' . self::RULE . ' `implement` refuses a routed controller the house cannot build.';
    }

}
