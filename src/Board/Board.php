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
 * El tablero: corre las comprobaciones y deriva en qué columna cae cada una.
 *
 * Nadie mueve nada. Las columnas salen de los resultados y del grafo, así que el tablero no puede
 * estar desactualizado — o compila verde, o dice exactamente qué falló y de qué artefacto lo supo.
 *
 * Lo único que se declara a mano son las aristas (`needs`) y qué es cosa de humanos. En el slice
 * mínimo eso fueron cuatro líneas para diecisiete pendientes, y crecen por acoplamiento, no por
 * repo — que es lo que hace que esto escale donde la prosa no escaló.
 */
final class Board
{
    /** @var array<string, Check> */
    private array $checks = [];

    /** @param list<Check> $checks */
    public function __construct(array $checks = [])
    {
        foreach ($checks as $check) {
            $this->add($check);
        }
    }

    /**
     * @throws \InvalidArgumentException si el id ya existe — dos hechos con el mismo nombre hacen
     *                                   que las dependencias apunten a cualquiera de los dos
     */
    /**
     * Registers a check, returning the board so declarations read as one expression.
     */
    public function add(Check $check): self
    {
        if (isset($this->checks[$check->id])) {
            throw new \InvalidArgumentException("Ya hay una comprobación con el id '{$check->id}'.");
        }

        $this->checks[$check->id] = $check;

        return $this;
    }

    /**
     * Corre todo lo que quepa en el presupuesto de costo y deriva el estado.
     *
     * Lo que se salta por costo NO se omite: entra como {@see Outcome::Unmeasured}, porque un
     * resultado ausente se lee como un resultado bueno y ése es justo el error que ADR-0028
     * prohíbe.
     *
     * @param list<Cost> $affordable qué costos se pagan en esta corrida
     */
    public function run(array $affordable = [Cost::Fast, Cost::Network, Cost::Slow]): BoardState
    {
        $this->assertEveryDependencyExists();

        $readings = [];
        foreach ($this->checks as $id => $check) {
            $readings[$id] = \in_array($check->cost, $affordable, true)
                ? $check->run()
                : new Reading($check, Outcome::Unmeasured, 0, 'no se corrió: costo ' . $check->cost->value);
        }

        return new BoardState($readings, $this->blockers($readings));
    }

    /**
     * Para cada comprobación pendiente, de qué depende que todavía no está hecho.
     *
     * Se reportan sólo los bloqueadores DIRECTOS: la cadena completa se recorre preguntando otra
     * vez, y una lista transitiva entierra al culpable inmediato entre sus abuelos.
     *
     * @param array<string, Reading> $readings
     *
     * @return array<string, list<string>>
     */
    private function blockers(array $readings): array
    {
        $blockers = [];
        foreach ($this->checks as $id => $check) {
            if ($readings[$id]->outcome->isDone()) {
                continue;
            }

            $pending = [];
            foreach ($check->needs as $need) {
                if (!$readings[$need]->outcome->isDone()) {
                    $pending[] = $need;
                }
            }

            $blockers[$id] = $pending;
        }

        return $blockers;
    }

    /**
     * Una dependencia hacia un id inexistente es un error de quien declaró, y se dice al armar y no
     * al leer: si se ignorara, la comprobación aparecería como lista para trabajar cuando en
     * realidad nadie sabe qué la bloquea.
     *
     * @throws \InvalidArgumentException
     */
    private function assertEveryDependencyExists(): void
    {
        foreach ($this->checks as $check) {
            foreach ($check->needs as $need) {
                if (!isset($this->checks[$need])) {
                    throw new \InvalidArgumentException(
                        "'{$check->id}' depende de '{$need}', que no existe en el tablero.",
                    );
                }
            }
        }
    }
}
