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

use Milpa\DevTools\Support\RootResolver;

/**
 * Edit a scaffolded class by exact find→replace pairs — the shape a measurement asked for.
 *
 * ── WHY PAIRS, AND WHY EXACTLY-ONCE ──────────────────────────────────────────────────────────────
 *
 * The first real session against the conformance gate (implement-first-landing.tsv, corrida 2)
 * showed WHERE the model's priors leak: re-generating the whole file. Six landings were refused and
 * the sixth regressed to the first one's defect — every full re-generation is a fresh chance to
 * reintroduce everything the diagnostics had already killed. A find→replace pair that must match
 * EXACTLY ONCE has no such surface: it touches the line it names, or it refuses. Ambiguity is
 * refused too — applying a pair that matches twice would edit a place the request never named.
 *
 * Not unified diffs, deliberately: line numbers and context hunks are where models miss; exact
 * substrings are mechanically verifiable and are the edit shape code models know best.
 *
 * ── ONE LANDING AUTHORITY ────────────────────────────────────────────────────────────────────────
 *
 * This adapter delegates bounded current-file pairs and the entire landing gate — syntax, strict
 * types, class, namespace, static conformance, restore-on-failure — to {@see ImplementHandler}. A
 * second gate here would be a second translation of «what may land», and two of those diverge on
 * the case nobody tested.
 */
final class EditHandler
{
    private readonly ImplementHandler $lander;

    public function __construct(
        RootResolver $roots = new RootResolver(),
        ?ImplementHandler $lander = null,
    ) {
        // The lander shares THIS handler's root by default — two resolvers pointing at different
        // trees would apply the pairs in one app and land the result in another.
        $this->lander = $lander ?? new ImplementHandler($roots);
    }

    /**
     * Apply exactly-once find→replace pairs to one scaffolded class, then land through implement's gate.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function handle(array $input): array
    {
        return $this->lander->edit($input);
    }
}
