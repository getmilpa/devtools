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
 * One postcondition a `make` run promised: a named consequence, whether it was actually found on
 * disk, and whether its absence should fail the run.
 *
 * `$required` is the line between a dangling reference and a handed-off decision: a missing
 * `required` consequence (the entity file, the repository registration, a referenced enum) means the
 * scaffold is internally inconsistent and the run is {@see PostconditionReport::ok()} `false`; a
 * missing NON-required one (registering the plugin in `config/plugins.php` — the activation decision
 * `make` deliberately hands to a human) is reported as advisory and never fails the run.
 */
final class PostconditionCheck
{
    /**
     * @param string $name     stable identifier of the promised consequence (e.g. `entity_file`,
     *                         `repository_registered`, `enum:Status`)
     * @param bool   $ok       whether the consequence was actually found
     * @param string $detail   human-readable one-line explanation of what was checked and the outcome
     * @param bool   $required whether a missing consequence fails the run (`true`) or is advisory
     *                         only (`false`)
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $ok,
        public readonly string $detail,
        public readonly bool $required = true,
    ) {
    }
}
