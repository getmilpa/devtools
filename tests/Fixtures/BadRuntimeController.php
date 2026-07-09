<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Fixtures;

/**
 * Deliberately violates several RUNTIME `ControllerVerifier` conventions at once —
 * {@see ControllerVerifierTest} asserts each one is caught: missing an `index()` method entirely,
 * and (via {@see BadRuntimeControllerWithWrongTypes}) wrong parameter/return types.
 */
final class BadRuntimeController
{
    public function show(mixed $request): mixed
    {
        return $request;
    }
}
