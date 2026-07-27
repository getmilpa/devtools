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
 * Cuánto cuesta preguntar. Existe porque un tablero lento es un tablero que nadie corre, y un
 * tablero que nadie corre es un ROADMAP.md con otro traje.
 *
 * En el slice mínimo la proporción fue brutal: catorce comprobaciones, y una sola —la que delegaba
 * al gate completo del host— se llevó el 96% del tiempo. Separarlas permite que la corrida de
 * todos los días sea instantánea y que la cara se pida a propósito.
 */
enum Cost: string
{
    /** Milisegundos: git local, existencia de archivos, grep. */
    case Fast = 'fast';

    /** Décimas: una consulta de red al índice de paquetes. */
    case Network = 'network';

    /** Segundos o más: correr un gate, una suite, un export completo. */
    case Slow = 'slow';
}
