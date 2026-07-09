<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A conventional RUNTIME-flavor controller — {@see ControllerVerifierTest} expects this to verify
 * clean against {@see \Milpa\DevTools\Make\Flavor::Runtime}. `psr/http-message` (require-dev) is
 * what makes `ServerRequestInterface`/`ResponseInterface` real, loadable interfaces here — this
 * package's own runtime `.stub` targets those same FQCNs without depending on the package at
 * runtime (see `Make/stubs/controller.runtime.php.stub`).
 */
final class GoodRuntimeController
{
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        unset($request);

        throw new \RuntimeException('fixture only — never invoked');
    }
}
