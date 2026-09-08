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

    /** The locator bound to an app root: `<root>/stubs/` is consulted before the package. */
    public function at(string $root): self
    {
        return new self($this->package, rtrim($root, '/') . '/stubs');
    }

    /** The file to render for `$name` — the app's copy when it has one, the package's otherwise. */
    public function path(string $name): string
    {
        if ($this->app !== null && is_file($this->app . '/' . $name)) {
            return $this->app . '/' . $name;
        }
        $shipped = $this->package . '/' . $name;
        if (is_file($shipped)) {
            return $shipped;
        }

        throw new \InvalidArgumentException(sprintf(
            'no stub named «%s»: not in %s%s',
            $name,
            $this->package,
            $this->app === null ? '' : ' nor in ' . $this->app,
        ));
    }

    /** Whether `$name` is currently read from the app's copy rather than the package's. */
    public function isOverridden(string $name): bool
    {
        return $this->app !== null && is_file($this->app . '/' . $name);
    }

    /**
     * Every stub the package ships, by name, sorted.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $files = glob($this->package . '/*.stub') ?: [];
        $names = array_map(static fn (string $f): string => basename($f), $files);
        sort($names);

        return $names;
    }

    /** The package's stub directory. */
    public function package(): string
    {
        return $this->package;
    }

    /** The app's stub directory once bound, `null` before. */
    public function app(): ?string
    {
        return $this->app;
    }
}
