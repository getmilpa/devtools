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

namespace Milpa\DevTools\Support;

/**
 * Corre un proceso hasta que termina o hasta que se acaba el plazo, y devuelve su salida CON su
 * código.
 *
 * ── POR QUÉ NO ES `Board\Shell` ─────────────────────────────────────────────────────────────────
 *
 * Porque {@see \Milpa\DevTools\Board\Shell::run()} devuelve `null` cuando el proceso sale distinto de
 * cero, y para el tablero eso es correcto: un comando que falló no le sirve. Aquí es al revés — un
 * proceso que salió en rojo es justo el caso interesante, y su salida es lo único que le dice a
 * alguien qué arreglar. Dos contratos opuestos sobre la misma llamada al sistema; unificarlos
 * obligaría a uno de los dos a fingir.
 *
 * ── POR QUÉ ES UNA CLASE Y NO UN CALLABLE ───────────────────────────────────────────────────────
 *
 * Para que se pueda sustituir en una prueba sin sacar un proceso de verdad, que es el mismo motivo
 * por el que existe `Board\Shell`. Nació además de un tropiezo: un parámetro `?\Closure` con default
 * hacía fallar la resolución de {@see \Milpa\DevTools\Operations\TestHandler} en la app anfitriona,
 * aunque el default estaba ahí para no necesitarlo nunca. Ese defecto del contenedor ya se arregló
 * —`milpa/container` ya no intenta construir lo que PHP no deja construir— así que hoy esto es una
 * clase por lo primero y no por lo segundo. Se queda igual: la costura por clase es el idioma que
 * este monorepo ya usaba, y un tipo nombrado dice qué se sustituye mientras un `Closure` sólo dice
 * su firma.
 */
class ProcessRunner
{
    /**
     * Corre `$command` en `$cwd` y espera a lo más `$timeoutSeconds`.
     *
     * `stdout` y `stderr` se juntan en el orden en que salieron: separarlos obligaría a quien lee a
     * recomponer la cronología, y el error que importa suele estar en el otro flujo del que se está
     * mirando.
     *
     * Códigos propios cuando el proceso no fue quien contestó: `127` si no arrancó, `124` si se pasó
     * del plazo — los mismos que usa una terminal, para que quien los vea ya sepa qué significan.
     *
     * @param list<string> $command
     *
     * @return array{exit: int, output: string}
     */
    public function run(array $command, string $cwd, int $timeoutSeconds): array
    {
        $descriptores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proceso = @proc_open($command, $descriptores, $tuberias, $cwd);
        if (!\is_resource($proceso)) {
            return ['exit' => 127, 'output' => 'no se pudo arrancar el proceso'];
        }

        stream_set_blocking($tuberias[1], false);
        stream_set_blocking($tuberias[2], false);

        $salida = '';
        $limite = time() + $timeoutSeconds;
        $vencido = false;

        while (true) {
            $salida .= (string) stream_get_contents($tuberias[1]);
            $salida .= (string) stream_get_contents($tuberias[2]);

            $estado = proc_get_status($proceso);
            if (!$estado['running']) {
                break;
            }

            if (time() >= $limite) {
                // Matarlo con el motivo dicho ES una respuesta; esperar para siempre no lo es.
                proc_terminate($proceso, 9);
                $vencido = true;
                break;
            }

            usleep(20000);
        }

        $salida .= (string) stream_get_contents($tuberias[1]);
        $salida .= (string) stream_get_contents($tuberias[2]);
        fclose($tuberias[1]);
        fclose($tuberias[2]);
        $codigo = proc_close($proceso);

        if ($vencido) {
            return [
                'exit' => 124,
                'output' => $salida . "\n\n[el proceso pasó de {$timeoutSeconds}s y se detuvo]",
            ];
        }

        return ['exit' => $codigo, 'output' => $salida];
    }
}
