<?php

/**
 * This file is part of milpa/devtools — the development tools a Milpa app installs, not copies.
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
 * Where a stub is read from: the app's `stubs/` first, the package's own second.
 *
 * The generators ship their templates under this package; an app that wants `make` to write its own
 * conventions (a header, a base class, a docblock style) copies a stub to `<root>/stubs/<name>` and edits
 * it — `stubs:publish` does the copying — and from then on that file wins for that ONE stub while every
 * other keeps coming from the package (greenhouse decisions/0216, point 6). A name that exists in neither
 * place is refused by name, not read as an empty template.
 */
final class StubLocator
{
    public function __construct(
        private readonly string $package = __DIR__ . '/stubs',
        private readonly ?string $app = null,
    ) {
    }
}
