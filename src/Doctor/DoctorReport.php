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

namespace Milpa\DevTools\Doctor;

/**
 * En qué estado arquitectónico está una app, como VALOR.
 *
 * Un valor y no una impresión, por lo mismo que el resto de esta familia (ADR-0035): así el mismo
 * diagnóstico sirve para una terminal, para un TUI y para un agente sin que ninguno tenga que parsear
 * lo que otro imprimió. Y sobre todo: así se puede probar sin capturar salida.
 */
final readonly class DoctorReport
{
    /**
     * @param list<array{name: string, version: string, provides: list<string>, requires: list<string>}> $plugins
     *                                                                                                               los que sí se pudieron leer
     * @param list<string>                                                                               $unreadable
     *                                                                                                               los declarados que no se pudieron leer, con su motivo — el fallo más común de todos
     * @param list<array<string, mixed>>                                                                 $missing
     *                                                                                                               capacidades que nadie provee
     * @param list<array<string, mixed>>                                                                 $errors
     *                                                                                                               la forma aprendible del resolver: código, por qué, arreglos y acciones aplicables
     */
    public function __construct(
        public array $plugins = [],
        public array $unreadable = [],
        public array $missing = [],
        public array $errors = [],
        public bool $graphCloses = true,
    ) {
    }

    /**
     * Si esta app puede arrancar así.
     *
     * Un plugin ilegible cuenta como que NO: el kernel lo va a intentar cargar igual, y contestar que
     * todo está bien porque el grafo de los legibles cierra sería el peor de los diagnósticos —
     * tranquiliza sobre lo que sí falla.
     */
    public function ok(): bool
    {
        return $this->unreadable === [] && $this->graphCloses;
    }
}
