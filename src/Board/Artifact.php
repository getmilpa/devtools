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
 * Qué cosa interroga una comprobación (ADR-0028).
 *
 * Es obligatorio declararlo porque el mismo hecho aparente tiene respuestas distintas según dónde
 * se pregunte, y la diferencia no se nota hasta que alguien actúa sobre la equivocada. Una
 * comprobación de pins comodín contra el árbol de trabajo reportó once paquetes rotos; contra el
 * índice — lo que de verdad recibe quien instala — no había ninguno. El `*` local es legítimo
 * porque el desarrollo es por ruta, y el export lo pinnea antes de publicar.
 *
 * El orden del enum es el orden de la verdad: {@see self::Published} es lo que un extraño obtiene,
 * y es el default cuando se puede pagar.
 */
enum Artifact: string
{
    /** Lo que recibe quien instala: el índice de paquetes, la release publicada. Cuesta red. */
    case Published = 'published';

    /** Lo que está commiteado y empujado: la rama remota, sus tags. Cuesta red, menos. */
    case Pushed = 'pushed';

    /** El árbol de trabajo local. Barato y el más propenso a mentir sobre lo que otros ven. */
    case Working = 'working';

    /** El resultado de correr una herramienta (un gate, un suite). Caro, y puede no medir. */
    case Tooling = 'tooling';

    /** Si conocer este artefacto necesita red — lo que decide si el tablero corre offline. */
    public function needsNetwork(): bool
    {
        return $this === self::Published || $this === self::Pushed;
    }

    /** Cómo se nombra en un reporte, para que la alarma diga de dónde sacó su verdad. */
    public function label(): string
    {
        return match ($this) {
            self::Published => 'publicado',
            self::Pushed => 'remoto',
            self::Working => 'local',
            self::Tooling => 'herramienta',
        };
    }
}
