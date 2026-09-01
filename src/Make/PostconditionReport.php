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
 * The outcome of checking every consequence a `make` run promised — the report that makes `ok:true`
 * MEAN the promised artifacts and wiring actually exist.
 *
 * A run is {@see self::ok()} only when no REQUIRED consequence is missing; a missing required
 * consequence is a dangling reference (an entity with no repository, a field naming an enum that was
 * never made) and puts the run in the `incomplete` state {@see \Milpa\DevTools\Operations\MakeHandler}
 * reports instead of PASS. Advisory (non-required) checks — the plugin's activation in
 * `config/plugins.php` — are carried for the caller to see but never fail the run.
 */
final class PostconditionReport
{
    /**
     * @param list<PostconditionCheck> $checks every consequence checked, required and advisory alike
     */
    public function __construct(public readonly array $checks)
    {
    }

    /** True when no REQUIRED consequence is missing — advisory checks never fail the run. */
    public function ok(): bool
    {
        return $this->missing() === [];
    }

    /**
     * The names of the required consequences that are missing — empty when the run is complete.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $missing = [];
        foreach ($this->checks as $check) {
            if ($check->required && !$check->ok) {
                $missing[] = $check->name;
            }
        }

        return $missing;
    }

    /**
     * The report as the plain array the {@see \Milpa\DevTools\Operations\MakeHandler} attaches to its
     * result, so every surface (CLI, TUI, agent) reads the same shape.
     *
     * @return array{ok: bool, checks: list<array{name: string, ok: bool, required: bool, detail: string}>, missing: list<string>}
     */
    public function toArray(): array
    {
        $checks = [];
        foreach ($this->checks as $check) {
            $checks[] = [
                'name' => $check->name,
                'ok' => $check->ok,
                'required' => $check->required,
                'detail' => $check->detail,
            ];
        }

        return ['ok' => $this->ok(), 'checks' => $checks, 'missing' => $this->missing()];
    }
}
