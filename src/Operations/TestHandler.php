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

namespace Milpa\DevTools\Operations;

use Milpa\DevTools\Support\ProcessRunner;
use Milpa\DevTools\Support\RootResolver;

/**
 * Corre la suite de pruebas de la app anfitriona y devuelve el veredicto como un valor.
 *
 * ── POR QUÉ EXISTE ──────────────────────────────────────────────────────────────────────────────
 *
 * Porque «probar» no puede significar sólo «revisar convenciones». El verificador que corre después
 * de `make` reflexiona la clase recién escrita y comprueba que cumple las reglas del framework: que
 * carga, que implementa lo que dice, que no imprime a la salida. Eso es real y atrapa mucho — y no
 * atrapa nada de lo que el código HACE. Una entidad que satisface `EntityInterface` a la perfección
 * puede devolver el campo equivocado en `toArray()`, y el verificador la aprueba.
 *
 * Quien escribe código tiene que poder ejecutarlo. Para una persona eso es teclear `phpunit`; para
 * un agente, sin este átomo, no era nada: escribía, veía «PASS» de una verificación estructural, y
 * ahí se acababa lo que podía saber de su propio trabajo. Un lazo que se cierra en la forma y no en
 * el comportamiento enseña a confiar en la mitad barata.
 *
 * ── POR QUÉ ESTE SÍ SACA UN SUBPROCESO ──────────────────────────────────────────────────────────
 *
 * El resto del paquete presume de no hacerlo: {@see \Milpa\DevTools\Make\VerifyRunner} dejó de
 * llamar a `php scripts/verify-*.php` justamente para correr en el mismo proceso que acaba de
 * escribir el archivo. Aquí es al revés, y por la misma clase de razón: una suite necesita su propio
 * proceso. Cargar las pruebas dentro de éste heredaría el autoloader ya poblado, las clases ya
 * cargadas y el estado global de quien llamó; un fatal en una prueba se llevaría al agente entero, y
 * el código de salida —que ES el veredicto de PHPUnit— no existiría. Aislar no es un lujo aquí, es
 * lo que hace que el resultado signifique algo.
 *
 * ── LO QUE CUIDA ────────────────────────────────────────────────────────────────────────────────
 *
 * Un `path` que apunte fuera de la raíz se rechaza antes de armar el comando: la entrada ya no viene
 * sólo de una terminal. Hay un plazo (`timeout`) porque una suite colgada colgaría a quien la llamó,
 * y {@see ProcessRunner} la mata diciendo por qué — esperar para siempre no es una respuesta. Y la
 * salida se recorta con una marca que dice que se recortó: un reporte truncado en silencio es peor
 * que uno corto.
 */
final class TestHandler
{
    /** Cuánta salida se devuelve. Lo que importa —el resumen y los fallos— vive al final. */
    private const MAX_OUTPUT = 12000;

    /**
     * @param ProcessRunner $runner costura para las pruebas de ESTE paquete: correr PHPUnit de verdad
     *                              desde dentro de PHPUnit es una recursión que no se quiere depurar.
     *                              Es una CLASE y no un `Closure` porque un contenedor que autoresuelve
     *                              constructores sabe qué hacer con la primera y no con el segundo.
     */
    public function __construct(
        private readonly RootResolver $roots = new RootResolver(),
        private readonly ProcessRunner $runner = new ProcessRunner(),
    ) {
    }

    /**
     * Corre la suite y contesta cómo le fue.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, ran: bool, tests: int|null, assertions: int|null, failures: int|null, errors: int|null, output: string, command: string, error?: string}
     */
    public function handle(array $input): array
    {
        $root = $this->roots->resolve();

        $binario = $root . '/vendor/bin/phpunit';
        if (!is_file($binario)) {
            return $this->falla(
                "phpunit no está instalado en {$root} — corre: composer require --dev phpunit/phpunit",
            );
        }

        $comando = [\PHP_BINARY, $binario, '--colors=never'];

        $filtro = \is_string($input['filter'] ?? null) ? trim($input['filter']) : '';
        if ($filtro !== '') {
            $comando[] = '--filter';
            $comando[] = $filtro;
        }

        $ruta = \is_string($input['path'] ?? null) ? trim($input['path']) : '';
        if ($ruta !== '') {
            $absoluta = $this->dentroDe($root, $ruta);
            if ($absoluta === null) {
                return $this->falla("«path» tiene que existir y quedar dentro de {$root} — recibí: {$ruta}");
            }
            $comando[] = $absoluta;
        }

        $plazo = \is_int($input['timeout'] ?? null) ? $input['timeout'] : 300;
        $plazo = max(1, min(3600, $plazo));

        $resultado = $this->runner->run($comando, $root, $plazo);

        $salida = $this->recortar($resultado['output']);
        $conteos = $this->conteos($resultado['output']);

        return [
            // El veredicto es el CÓDIGO DE SALIDA de PHPUnit, no lo que diga su texto. Es lo que un CI
            // lee, y leer otra cosa aquí produciría un átomo que contesta distinto que el pipeline
            // sobre exactamente la misma corrida.
            'ok' => $resultado['exit'] === 0,
            'ran' => true,
            'tests' => $conteos['tests'],
            'assertions' => $conteos['assertions'],
            'failures' => $conteos['failures'],
            'errors' => $conteos['errors'],
            'output' => $salida,
            'command' => implode(' ', $comando),
        ];
    }

    /**
     * Lee los conteos del resumen de PHPUnit, sin exigir que existan.
     *
     * Una corrida verde dice `OK (12 tests, 34 assertions)` y una roja `Tests: 12, Assertions: 34,
     * Failures: 2.` — dos formas del mismo dato. Cuando ninguna casa (una suite que ni arrancó, un
     * formato distinto) los conteos van en `null`: cero pruebas y no-pude-contar son respuestas
     * distintas, y decir `0` a la segunda sería inventar.
     *
     * @return array{tests: int|null, assertions: int|null, failures: int|null, errors: int|null}
     */
    private function conteos(string $salida): array
    {
        $leer = static function (string $patron) use ($salida): ?int {
            return preg_match($patron, $salida, $m) === 1 ? (int) $m[1] : null;
        };

        if (preg_match('/OK \((\d+) tests?, (\d+) assertions?\)/', $salida, $m) === 1) {
            return ['tests' => (int) $m[1], 'assertions' => (int) $m[2], 'failures' => 0, 'errors' => 0];
        }

        $pruebas = $leer('/\bTests: (\d+)/');

        return [
            'tests' => $pruebas,
            'assertions' => $leer('/\bAssertions: (\d+)/'),
            'failures' => $pruebas === null ? null : ($leer('/\bFailures: (\d+)/') ?? 0),
            'errors' => $pruebas === null ? null : ($leer('/\bErrors: (\d+)/') ?? 0),
        ];
    }

    /** Se conserva el FINAL: ahí están el resumen y los fallos, que es lo que se fue a buscar. */
    private function recortar(string $salida): string
    {
        if (\strlen($salida) <= self::MAX_OUTPUT) {
            return $salida;
        }

        return "[…salida recortada, se conservan los últimos " . self::MAX_OUTPUT . " caracteres…]\n"
            . substr($salida, -self::MAX_OUTPUT);
    }

    /** La ruta absoluta si existe y cae dentro de la raíz; `null` si no. */
    private function dentroDe(string $root, string $ruta): ?string
    {
        $candidata = str_starts_with($ruta, '/') ? $ruta : $root . '/' . $ruta;
        $real = realpath($candidata);
        $raizReal = realpath($root);

        if ($real === false || $raizReal === false) {
            return null;
        }

        return str_starts_with($real, $raizReal . '/') || $real === $raizReal ? $real : null;
    }

    /**
     * Una falla es un RESULTADO con veredicto, igual que en los demás átomos de este paquete.
     *
     * `ran: false` la distingue de una suite que corrió y salió en rojo: no poder correr las pruebas y
     * que las pruebas fallen son dos noticias distintas, y quien las confunda arreglará lo que no es.
     *
     * @return array{ok: bool, ran: bool, tests: null, assertions: null, failures: null, errors: null, output: string, command: string, error: string}
     */
    private function falla(string $motivo): array
    {
        return [
            'ok' => false,
            'ran' => false,
            'tests' => null,
            'assertions' => null,
            'failures' => null,
            'errors' => null,
            'output' => '',
            'command' => '',
            'error' => $motivo,
        ];
    }
}
