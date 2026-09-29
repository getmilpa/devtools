<?php

namespace Milpa\DevTools\Tests\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A runtime controller whose only `declare(strict_types=1)` is this sentence — the statement is missing, and
 * {@see \Milpa\DevTools\Tests\Verify\ControllerVerifierTest} expects the verifier to say so (greenhouse evidence/1061).
 */
final class RuntimeControllerStrictInWordsOnly
{
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        unset($request);

        throw new \RuntimeException('fixture only — never invoked');
    }
}
