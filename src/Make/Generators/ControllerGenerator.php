<?php

declare(strict_types=1);

namespace Milpa\DevTools\Make\Generators;

use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\GenerationResult;
use Milpa\DevTools\Make\GeneratorInterface;
use Milpa\DevTools\Make\PlannedFile;
use Milpa\DevTools\Make\StubRenderer;

/**
 * Generates a controller extending {@see \Milpa\app\Providers\BaseController} with one
 * routed method per `--methods` entry (`#[Route]`, `array $params = []`, `HttpResponse`). Follows
 * the framework's controller convention exactly so `verify-controller.php` passes on the output.
 */
final class ControllerGenerator implements GeneratorInterface
{
    /**
     * RESTful (verb, path-suffix) per conventional method name, chosen so every (path, verb) pair
     * generated for a given base route is unique — `index`/`store` share the collection path with
     * different verbs, `show`/`update`/`destroy` share the member path with different verbs. Any
     * other method name gets its own GET sub-path so it can never collide either.
     *
     * @var array<string, array{verb: string, suffix: string}>
     */
    private const ROUTES = [
        'index' => ['verb' => 'GET', 'suffix' => ''],
        'store' => ['verb' => 'POST', 'suffix' => ''],
        'show' => ['verb' => 'GET', 'suffix' => '/{id}'],
        'update' => ['verb' => 'PUT', 'suffix' => '/{id}'],
        'destroy' => ['verb' => 'DELETE', 'suffix' => '/{id}'],
    ];

    private string $stubs;

    public function __construct(private readonly StubRenderer $renderer = new StubRenderer())
    {
        $this->stubs = \dirname(__DIR__) . '/stubs';
    }

    /** The `<what>` token this generator answers to: `'controller'`. */
    public function name(): string
    {
        return 'controller';
    }

    /** Renders the controller class + one `#[Route]`-attributed method per `--methods` entry. */
    public function generate(GenerationContext $context): GenerationResult
    {
        $namespace = 'Milpa\\Plugins\\' . $context->plugin . '\\Controllers';
        $base = $context->option('route') ?? '/' . strtolower(str_replace('Controller', '', $context->name));
        $slug = trim(str_replace('/', '_', $base), '_') ?: 'index';

        $methodNames = array_filter(array_map('trim', explode(',', $context->option('methods') ?? 'index')));
        $methods = [];
        foreach ($methodNames as $method) {
            $route = self::ROUTES[$method] ?? ['verb' => 'GET', 'suffix' => '/' . $method];
            $methods[] = $this->renderer->render($this->stubs . '/controller-method.stub', [
                'path' => $base . $route['suffix'],
                'verb' => $route['verb'],
                'routeName' => $slug . '_' . $method,
                'method' => $method,
            ]);
        }

        $contents = $this->renderer->render($this->stubs . '/controller.php.stub', [
            'namespace' => $namespace,
            'class' => $context->name,
            'methods' => implode('', $methods),
        ]);

        $path = $context->root . '/plugins/' . $context->plugin . '/Controllers/' . $context->name . '.php';

        return new GenerationResult(
            files: [new PlannedFile($path, $contents)],
            verifyKind: 'controller',
            verifyTarget: $namespace . '\\' . $context->name,
        );
    }
}
