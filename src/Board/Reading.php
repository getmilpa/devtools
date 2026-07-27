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

namespace Milpa\DevTools\Board;

/**
 * Lo que una comprobación contestó, con todo lo necesario para poder discutirle: el resultado, de
 * qué artefacto salió, cuánto tardó, y —si no pudo medir— por qué.
 */
final readonly class Reading
{
    public function __construct(
        public Check $check,
        public Outcome $outcome,
        public int $milliseconds,
        public ?string $note = null,
    ) {
    }

    /** Una línea que dice de dónde sacó su verdad, para que la alarma se pueda refutar sin adivinar. */
    public function line(): string
    {
        $suffix = $this->note !== null ? ' — ' . $this->note : '';

        return sprintf(
            '%s %-34s [%s] %5dms%s',
            $this->outcome->symbol(),
            $this->check->id,
            $this->check->artifact->label(),
            $this->milliseconds,
            $suffix,
        );
    }
}
