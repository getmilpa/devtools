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

/*
 * Boots the house at argv[1] the way its front controller does and asks its container for the class in
 * argv[2] — but only when a route of that house names the class as its controller, because that is the
 * only way the house ever builds it (`ContainerHandlerResolver` → `$container->get()`). One JSON object on
 * STDOUT is the whole answer; whatever the house prints while booting is held and discarded.
 *
 * Run as a child of {@see \Milpa\DevTools\Operations\ConstructionProbe}: a boot that fatals must kill this
 * process, never the operation that asked (greenhouse decisions/0541).
 */

$root = rtrim((string) ($argv[1] ?? ''), '/');
$class = (string) ($argv[2] ?? '');

/** @param array<string, mixed> $answer */
$say = static function (array $answer): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(\STDOUT, (string) json_encode($answer, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    exit(0);
};
$relative = static fn (string $text): string => str_replace($root . '/', '', $text);

register_shutdown_function(static function () use ($say, $relative): void {
    $error = error_get_last();
    if ($error !== null && \in_array($error['type'], [\E_ERROR, \E_PARSE, \E_CORE_ERROR, \E_COMPILE_ERROR], true)) {
        $say(['booted' => false, 'reason' => $relative($error['message'])]);
    }
});

ini_set('display_errors', 'stderr');
ob_start();

try {
    require $root . '/vendor/autoload.php';

    if (!class_exists(\Milpa\Runtime\Kernel::class) || !is_file($root . '/config/boot.php')) {
        $say(['booted' => false, 'reason' => 'this app is not a milpa/runtime house (no Kernel or no config/boot.php)']);
    }

    // The same layering `public/index.php` and `bin/coa` apply: what the human wrote, then what the
    // machine wrote, then the machine's secrets. A boot() that reads config sees what the request sees.
    /** @var array<string, mixed> $config */
    $config = is_file($root . '/config/app.php') ? require $root . '/config/app.php' : [];
    if (class_exists(\Milpa\AppRuntime\Config\MachineOverlay::class)) {
        $config = \Milpa\AppRuntime\Config\MachineOverlay::sobre($config, $root);
    }
    if (class_exists(\Milpa\AppRuntime\Config\SecretOverlay::class)) {
        $config = \Milpa\AppRuntime\Config\SecretOverlay::sobre($config, $root);
    }

    /** @var array{container: \Milpa\Interfaces\Di\DIContainerInterface, plugins: list<class-string>} $boot */
    $boot = require $root . '/config/boot.php';
    $kernel = \Milpa\Runtime\Kernel::boot([
        'root' => $root,
        'plugins' => $boot['plugins'],
        'config' => $config,
        'container' => $boot['container'],
    ]);
    $boot['container']->registerService(\Milpa\Runtime\Kernel::class, $kernel);
} catch (\Throwable $e) {
    $say(['booted' => false, 'reason' => $relative($e::class . ': ' . $e->getMessage())]);
}

// WITH NO CLASS TO BUILD, THE HOUSE IS ASKED FOR ITS WHOLE ROUTE TABLE (greenhouse decisions/0567, slice BV-2):
// what `make` needs to know before scaffolding a route — whether something already answers there.
if ($class === '') {
    $served = [];
    foreach ($kernel->router()->routes() as $route) {
        $served[] = [
            'method' => implode('|', array_map(static fn (\Milpa\Http\HttpMethod $m): string => $m->value, $route->methods)),
            'path' => $route->path,
            'name' => (string) ($route->name ?? ''),
        ];
    }
    $say(['booted' => true, 'served' => $served]);
}

// WHAT AN OPERATION'S run() WORKS THROUGH IS HANDED BY THE ENTRY THAT LISTS IT (greenhouse evidence/1154). The house
// resolves each collaborator of `run()` with the resolver `DeclaredOperation::from()` was given in the plugin's
// `operations()` — at the call, and nowhere before it: an operation written against something that entry cannot
// hand landed green and failed at its first call. So the same act is done here, without running `run()`: the
// resolver is asked for each type it would be asked for. It is closed over by the handler of the operation, and
// that is where it is read.
$operation = null;
foreach ($kernel->commands() as $command) {
    $handler = $command->handler ?? null;
    if (!$handler instanceof \Closure) {
        continue;
    }
    $closed = (new \ReflectionFunction($handler))->getStaticVariables();
    if (!\is_string($closed['class'] ?? null) || ltrim($closed['class'], '\\') !== ltrim($class, '\\') || !\is_array($closed['collaborators'] ?? null)) {
        continue;
    }
    $resolve = $closed['resolve'] ?? null;
    $unhanded = [];
    foreach ($closed['collaborators'] as $type) {
        if (!\is_string($type)) {
            continue;
        }
        try {
            if (!$resolve instanceof \Closure || !\is_object($resolve($type))) {
                $unhanded[] = ['type' => $type, 'error' => 'the entry that lists it gave nothing back'];
            }
        } catch (\Throwable $e) {
            $unhanded[] = ['type' => $type, 'error' => $relative($e->getMessage())];
        }
    }
    $operation = ['name' => (string) $command->name, 'handed' => $unhanded === [], 'unhanded' => $unhanded];
    break;
}
$asked = $operation === null ? [] : ['operation' => $operation];

$routes = [];
foreach ($kernel->router()->routes() as $route) {
    if ($route->handler !== null && ltrim($route->handler->controller, '\\') === ltrim($class, '\\')) {
        $routes[] = implode('|', array_map(static fn (\Milpa\Http\HttpMethod $m): string => $m->value, $route->methods)) . ' ' . $route->path;
    }
}
if ($routes === []) {
    $say(['booted' => true, 'routes' => []] + $asked);
}

$container = $kernel->container();
try {
    $built = $container->get($class);
    $say(['booted' => true, 'routes' => $routes, 'built' => \is_object($built)] + $asked);
} catch (\Throwable $e) {
    // What the container could not fill, named from the constructor: a parameter whose class-typed hint
    // nothing registered and PHP cannot `new`, with no default and no null to fall back on.
    $unresolvable = [];
    $constructor = class_exists($class) ? (new \ReflectionClass($class))->getConstructor() : null;
    foreach ($constructor?->getParameters() ?? [] as $parameter) {
        $type = $parameter->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()
            || $parameter->isDefaultValueAvailable() || $parameter->allowsNull()) {
            continue;
        }
        $name = $type->getName();
        $buildable = class_exists($name) && (new \ReflectionClass($name))->isInstantiable();
        if (!$container->has($name) && !$buildable) {
            $unresolvable[] = ['parameter' => '$' . $parameter->getName(), 'type' => $name];
        }
    }
    $messages = [];
    for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
        $messages[] = $relative($cause->getMessage());
    }
    $say([
        'booted' => true,
        'routes' => $routes,
        'built' => false,
        'error' => (new \ReflectionClass($e))->getShortName() . ': ' . implode(' ← ', $messages),
        'unresolvable' => $unresolvable,
    ] + $asked);
}
