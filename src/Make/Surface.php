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
 * What a route serves, said in fields instead of prose (greenhouse decisions/0567 §2, slice BV-2).
 *
 * Measured (greenhouse evidence/1101): a resident read the contract of `make`, found no word for a page, and
 * chose «a controller that concatenates HTML» — the cheap path never asked what the route returns. `make` now
 * asks, and answers with this block: `kind` is `visual` (a page a visitor reads), `data` (an API) or
 * `undeclared` (the author has not said, and nothing is scaffolded until they do).
 *
 * A VISUAL SURFACE IS A SCREEN, AND `make` CANNOT DECLARE ONE. The screen store lives in the app runtime, which
 * this package does not know and must not write around (a second writer of `config/screens.json` would be a
 * second authority over what a declaration is). So a page is answered with {@see next()}: the exact
 * `screen:declare` call to run, the way a trial answers with `to_apply`.
 */
final class Surface
{
    /** What `returns` may say of a controller's GET route. */
    public const RETURNS = ['page', 'data'];

    /**
     * A literal route as HTTP writes it — `/blog` — from what a caller typed (`blog`, `/blog/`).
     *
     * @throws \InvalidArgumentException naming what is wrong with it
     */
    public static function route(string $typed): string
    {
        $route = trim($typed);
        if (str_contains($route, ':')) {
            throw new \InvalidArgumentException("«{$typed}» is not a route: a route is a path that starts with /, not a URL");
        }
        if (str_contains($route, '{') || str_contains($route, '}')) {
            throw new \InvalidArgumentException("«{$typed}» is not a base route: it takes no parameters — the generator adds /{id} where one belongs");
        }
        $route = '/' . trim($route, '/');
        if (preg_match('~^/[A-Za-z0-9._\~/-]*$~D', $route) !== 1 || str_contains($route, '//')) {
            throw new \InvalidArgumentException("«{$typed}» is not a route: write it with letters, digits, and . _ ~ - between its slashes");
        }

        return $route;
    }

    /** What two spellings of one route share: no slash at either end, no case. */
    public static function key(string $route): string
    {
        return strtolower(trim($route, '/'));
    }

    /**
     * The block for a page: a visual surface whose governed authoring is a screen.
     *
     * @return array{kind: string, route: string, preferred_authoring: string, screen: string, declared: bool}
     */
    public static function visual(string $route, string $screen, bool $declared): array
    {
        return ['kind' => 'visual', 'route' => 'GET ' . $route, 'preferred_authoring' => 'screen', 'screen' => $screen, 'declared' => $declared];
    }

    /**
     * The block for a controller whose author has not said what its GET route returns.
     *
     * @return array{kind: string, route: string, ask: string, options: list<string>}
     */
    public static function undeclared(string $route): array
    {
        return ['kind' => 'undeclared', 'route' => 'GET ' . $route, 'ask' => 'returns', 'options' => self::RETURNS];
    }

    /**
     * The block for a controller that answers data.
     *
     * @return array{kind: string, route: string}
     */
    public static function data(string $route): array
    {
        return ['kind' => 'data', 'route' => 'GET ' . $route];
    }

    /**
     * The call that declares the page: `screen:declare`, with every argument filled.
     *
     * Two columns or more read as entries — the first is the title, the second the body, the rest are named
     * beside them (`content`). One column is a list (`data-table`): a readable entry needs a title AND a body.
     *
     * @param list<string> $columns the entity fields the page shows, in order
     *
     * @return array{operation: string, arguments: array<string, mixed>}
     */
    public static function next(string $screen, string $route, string $plugin, string $entity, array $columns): array
    {
        $arguments = ['name' => $screen, 'route' => $route, 'source' => ['entity' => $plugin . '/' . $entity, 'columns' => $columns]];
        if (\count($columns) < 2) {
            return ['operation' => 'screen:declare', 'arguments' => ['name' => $screen, 'type' => 'data-table'] + $arguments];
        }
        $roles = ['title' => $columns[0], 'body' => $columns[1]] + (\count($columns) > 2 ? ['meta' => \array_slice($columns, 2)] : []);

        return ['operation' => 'screen:declare', 'arguments' => [
            'name' => $screen,
            'type' => 'content',
            'route' => $route,
            'props' => ['heading' => ucfirst($screen), 'roles' => $roles],
            'source' => $arguments['source'],
        ]];
    }
}
