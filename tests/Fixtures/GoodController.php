<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Fixtures;

use Milpa\app\Providers\BaseController;
use Milpa\app\Providers\HttpResponse;

/** A conventional controller — {@see ControllerVerifierTest} expects this to verify clean. */
final class GoodController extends BaseController
{
    public function __construct(mixed $container)
    {
        parent::__construct($container);
    }

    /**
     * @param array<string, string> $params
     */
    #[\Milpa\Attributes\Route(path: '/widgets', method: 'GET', name: 'widgets_index')]
    public function index(mixed $request, array $params = []): HttpResponse
    {
        return new HttpResponse();
    }
}
