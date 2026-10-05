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

namespace Milpa\DevTools\Operations;

/**
 * What the booted house serves, asked of the house itself (greenhouse decisions/0567 §3, slice BV-2).
 *
 * `make` scaffolds a route; whether something already answers there is a fact of the running house — its
 * router — not of any file this package could read. A declared screen in particular is mounted by the app
 * runtime from its own store, which this package does not know. So this asks the way the construction judge
 * asks ({@see ConstructionProbe}): the same child process boots the house at its root and prints its route
 * table. A house that does not boot, or is not a `milpa/runtime` house, answers nothing — never a guess.
 */
final class ServedRoutes
{
    /** The prefix the app runtime gives the route of a mounted screen: `live.screen.<screen>`. */
    private const SCREEN = 'live.screen.';

    public function __construct(private readonly ?string $php = null)
    {
    }

    /**
     * The house's route table, or `[]` when it cannot be asked.
     *
     * @return list<array{method: string, path: string, name: string}>
     */
    public function table(string $root): array
    {
        exec('timeout 60 ' . escapeshellarg($this->php ?? \PHP_BINARY) . ' ' . escapeshellarg(ConstructionProbe::SCRIPT) . ' '
            . escapeshellarg($root) . " '' 2>/dev/null", $lines);
        $answer = json_decode(implode("\n", $lines), true);
        $rows = [];
        foreach (\is_array($answer) && \is_array($answer['served'] ?? null) ? $answer['served'] : [] as $row) {
            if (\is_array($row) && \is_string($row['method'] ?? null) && \is_string($row['path'] ?? null)) {
                $rows[] = ['method' => $row['method'], 'path' => $row['path'], 'name' => \is_string($row['name'] ?? null) ? $row['name'] : ''];
            }
        }

        return $rows;
    }

    /**
     * The routes declared screens are mounted at, each with its screen.
     *
     * @return array<string, string> route → screen name
     */
    public function mountedScreens(string $root): array
    {
        $mounted = [];
        foreach ($this->table($root) as $row) {
            if (str_starts_with($row['name'], self::SCREEN) && \in_array('GET', explode('|', $row['method']), true)) {
                $mounted[$row['path']] = substr($row['name'], \strlen(self::SCREEN));
            }
        }

        return $mounted;
    }
}
