<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Fixtures;

/**
 * Has an `index()` method (unlike {@see BadRuntimeController}) but with the wrong parameter/return
 * types and a stray `#[Route]` attribute — {@see ControllerVerifierTest} asserts each is caught (or,
 * for `#[Route]`, warned about) independently of the "missing index()" case.
 */
final class BadRuntimeControllerWithWrongTypes
{
    #[\Milpa\Attributes\Route(path: '/widgets', method: 'GET', name: 'widgets_index')]
    public function index(mixed $request): mixed
    {
        return $request;
    }
}
