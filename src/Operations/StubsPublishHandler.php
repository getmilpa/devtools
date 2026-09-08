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

namespace Milpa\DevTools\Operations;

use Milpa\DevTools\Make\StubLocator;
use Milpa\DevTools\Support\RootResolver;

/**
 * `stubs:publish` — copy the package's stubs into the app's `stubs/`, where `make` reads them first.
 *
 * A copy already in the app is KEPT unless `force` says otherwise: the whole point of publishing is to
 * edit the copy, and a second publish must not undo the edit. `only` narrows the copy to named stubs; a
 * name the package does not ship is refused, not silently skipped.
 */
final class StubsPublishHandler
{
    public function __construct(
        private readonly RootResolver $roots = new RootResolver(),
        private readonly StubLocator $locator = new StubLocator(),
    ) {
    }

    /**
     * Copies the named (or every) stub into `<root>/stubs/`, keeping copies already there unless `force`.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function handle(array $input): array
    {
        $root = $this->roots->resolve();
        $force = ($input['force'] ?? false) === true;
        $shipped = $this->locator->names();

        $only = $input['only'] ?? [];
        if (\is_string($only)) {
            $only = array_values(array_filter(array_map('trim', explode(',', $only)), static fn (string $n): bool => $n !== ''));
        }
        if (!\is_array($only)) {
            return ['ok' => false, 'error' => '`only` names stubs: a list, or one comma-separated string'];
        }
        $unknown = array_values(array_diff($only, $shipped));
        if ($unknown !== []) {
            return ['ok' => false, 'error' => sprintf('no stub named «%s»; the package ships: %s', implode('», «', $unknown), implode(', ', $shipped))];
        }
        $names = $only === [] ? $shipped : array_values(array_intersect($shipped, $only));

        $dir = rtrim($root, '/') . '/stubs';
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'could not create ' . $dir];
        }

        $published = [];
        $kept = [];
        foreach ($names as $name) {
            $target = $dir . '/' . $name;
            if (is_file($target) && !$force) {
                $kept[] = $name;
                continue;
            }
            if (@copy($this->locator->package() . '/' . $name, $target) === false) {
                return ['ok' => false, 'error' => 'could not write ' . $target, 'published' => $published];
            }
            $published[] = $name;
        }

        return [
            'ok' => true,
            'dir' => $dir,
            'published' => $published,
            'kept' => $kept,
            'hint' => $published === [] && $kept !== []
                ? 'every stub was already published; edit the copies under stubs/, or pass `force` to start over from the package'
                : 'edit the copies under stubs/ — make reads them before the package\'s own',
        ];
    }
}
