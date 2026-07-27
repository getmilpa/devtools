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
 * El estado del tablero después de una corrida: cuatro columnas, todas derivadas.
 *
 * La separación que importa es {@see self::blockedOnHuman()} contra {@see self::blocked()}. La
 * mitad de lo pendiente en esta familia es una llave, un secreto o una decisión — cosas que ninguna
 * cantidad de trabajo mío mueve. Mezclarlas hace que el tablero grite pendientes imposibles junto a
 * los accionables, y a los dos días nadie lo lee.
 */
final readonly class BoardState
{
    /**
     * @param array<string, Reading>      $readings
     * @param array<string, list<string>> $blockers bloqueadores directos de cada pendiente
     */
    public function __construct(
        public array $readings,
        public array $blockers,
    ) {
    }

    /**
     * Hechos ciertos. Nada que hacer.
     *
     * @return list<Reading>
     */
    public function done(): array
    {
        return $this->pick(static fn (Reading $r): bool => $r->outcome->isDone());
    }

    /**
     * Pendientes sin nada que los bloquee: se pueden trabajar ahora mismo.
     *
     * @return list<Reading>
     */
    public function ready(): array
    {
        return $this->pick(fn (Reading $r): bool => !$r->outcome->isDone()
            && !$r->check->human
            && ($this->blockers[$r->check->id] ?? []) === []);
    }

    /**
     * Pendientes esperando a otro pendiente.
     *
     * @return list<Reading>
     */
    public function blocked(): array
    {
        return $this->pick(fn (Reading $r): bool => !$r->outcome->isDone()
            && !$r->check->human
            && ($this->blockers[$r->check->id] ?? []) !== []);
    }

    /**
     * Pendientes que sólo una persona puede destrabar.
     *
     * @return list<Reading>
     */
    public function blockedOnHuman(): array
    {
        return $this->pick(static fn (Reading $r): bool => !$r->outcome->isDone() && $r->check->human);
    }

    /**
     * Lo que no se pudo medir.
     *
     * Se reporta aparte de lo pendiente A PROPÓSITO: no saber no es lo mismo que faltar, y tratarlo
     * como falta manda a alguien a arreglar algo que quizá ya está bien (ADR-0028).
     *
     * @return list<Reading>
     */
    public function unmeasured(): array
    {
        return $this->pick(static fn (Reading $r): bool => $r->outcome === Outcome::Unmeasured);
    }

    /** Milisegundos totales de la corrida. */
    public function milliseconds(): int
    {
        return array_sum(array_map(static fn (Reading $r): int => $r->milliseconds, $this->readings));
    }

    /**
     * @param callable(Reading): bool $filter
     *
     * @return list<Reading>
     */
    private function pick(callable $filter): array
    {
        return array_values(array_filter($this->readings, $filter));
    }
}
