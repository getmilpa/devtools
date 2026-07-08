<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Fixtures;

/**
 * Deliberately violates several `ControllerVerifier` conventions at once — {@see ControllerVerifierTest}
 * asserts each one is caught: does not extend BaseController, constructor does not call
 * parent::__construct(), the routed method's HTTP verb is lowercase, and its signature is missing the
 * `array $params = []` second parameter.
 */
final class BadController
{
    public function __construct(mixed $container)
    {
        // intentionally does not chain up to a parent constructor — there is no parent to chain to
        // anyway, since this class does not extend BaseController at all.
        unset($container);
    }

    #[\Milpa\Attributes\Route(path: '/widgets', method: 'get', name: 'widgets_index')]
    public function index(mixed $request): mixed
    {
        return null;
    }
}
