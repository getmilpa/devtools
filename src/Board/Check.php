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
 * Un hecho que se comprueba en vez de recordarse.
 *
 * Es la unidad del tablero. Una tarjeta sin comprobación es una nota, y las notas mienten a los
 * tres días — que es exactamente cómo se murieron los archivos que este tablero viene a sustituir.
 *
 * Tres cosas la definen y ninguna es opcional: qué afirma, **qué artefacto interroga**
 * (ADR-0028), y qué la bloquea. El costo se declara aparte porque decide si el tablero es algo que
 * alguien corre: en el slice mínimo, una sola comprobación se llevó 19.5 de los 20.3 segundos
 * totales, y un tablero que tarda es un tablero que nadie mira.
 */
final readonly class Check
{
    /**
     * @param string              $id       identificador estable; lo que otras comprobaciones nombran al depender
     * @param string              $claim    lo que afirma, en la voz de quien lo lee: "plugin 0.3.0 está publicado"
     * @param Artifact            $artifact qué interroga — obligatorio, es la mitad del ADR-0028
     * @param \Closure(): Outcome $probe    la comprobación misma; devuelve, nunca lanza hacia afuera
     * @param list<string>        $needs    ids que tienen que estar `Passed` para que ésta tenga sentido
     * @param bool                $human    si sólo una persona puede cambiarlo (una llave, un secreto, un juicio)
     * @param Cost                $cost     cuánto cuesta preguntarle
     */
    public function __construct(
        public string $id,
        public string $claim,
        public Artifact $artifact,
        public \Closure $probe,
        public array $needs = [],
        public bool $human = false,
        public Cost $cost = Cost::Fast,
    ) {
    }

    /**
     * Corre la comprobación en aislamiento.
     *
     * Cualquier cosa que escape se convierte en {@see Outcome::Unmeasured}, jamás en `Failed`: una
     * comprobación que revienta no dice nada sobre el sujeto. Y no puede tumbar al corredor — en el
     * slice mínimo un `exit` adentro de una prueba truncó el reporte y las seis comprobaciones que
     * faltaban no salieron, lo cual se lee igualito que un tablero limpio.
     */
    public function run(): Reading
    {
        $started = hrtime(true);

        try {
            $outcome = ($this->probe)();
            $note = null;
        } catch (\Throwable $e) {
            $outcome = Outcome::Unmeasured;
            $note = $e->getMessage();
        }

        return new Reading(
            $this,
            $outcome,
            (int) ((hrtime(true) - $started) / 1_000_000),
            $note,
        );
    }
}
