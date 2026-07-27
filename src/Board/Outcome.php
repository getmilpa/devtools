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
 * Cómo salió una comprobación. Tres resultados, nunca dos (ADR-0028).
 *
 * `Unmeasured` es el que existe por una razón cara: colapsarlo contra `Failed` hace que la
 * herramienta acuse al código de lo que hizo el instrumento. Un `vendor/` viejo dando una cobertura
 * falsa, un OOM de PHPStan leído como "el análisis encontró errores", un cs-fixer que abortó por
 * invocación leído como "hay violaciones de estilo" — las tres fueron el mismo día, y las tres
 * mandaron a alguien a arreglar algo que no estaba roto.
 */
enum Outcome: string
{
    /** La comprobación corrió y el hecho es cierto. */
    case Passed = 'passed';

    /** La comprobación corrió y el hecho es falso. Esto sí es trabajo pendiente. */
    case Failed = 'failed';

    /**
     * La comprobación NO pudo correr: faltó red, faltó una herramienta, reventó.
     * No dice nada sobre el sujeto — y sobre todo, no dice que esté bien.
     */
    case Unmeasured = 'unmeasured';

    /** Si este resultado significa que hay algo por hacer. Lo no medido NO cuenta como hecho. */
    public function isDone(): bool
    {
        return $this === self::Passed;
    }

    /** El símbolo con el que se lee de un vistazo en una terminal. */
    public function symbol(): string
    {
        return match ($this) {
            self::Passed => '✓',
            self::Failed => '·',
            self::Unmeasured => '?',
        };
    }
}
