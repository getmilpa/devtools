<?php

/**
 * This file is part of Milpa DevTools — the generate-verify-inspect developer loop of the Milpa PHP framework.
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
 * The two controller conventions `coa:make controller` can target — see {@see ConventionDetector}
 * for how one is chosen.
 *
 * - `Runtime`: a plain, dependency-free PSR-7 class (`index(ServerRequestInterface): ResponseInterface`,
 *   no base class, no `#[Route]`) wired through a `RouteProviderInterface` plugin — the
 *   `milpa/runtime` + skeleton convention.
 * - `Legacy`: extends the host's `Milpa\app\Providers\BaseController` and routes via `#[Route]`
 *   attributes — the existing Milpa host convention (`src/Make/stubs/controller.php.stub`).
 */
enum Flavor: string
{
    case Runtime = 'runtime';
    case Legacy = 'legacy';

    /**
     * Parses a `--flavor` CLI value into a case.
     *
     * @throws \InvalidArgumentException When `$value` is neither 'runtime' nor 'legacy'.
     */
    public static function fromOption(string $value): self
    {
        return match ($value) {
            'runtime' => self::Runtime,
            'legacy' => self::Legacy,
            default => throw new \InvalidArgumentException(
                "invalid --flavor '{$value}' (expected 'runtime' or 'legacy')",
            ),
        };
    }
}
