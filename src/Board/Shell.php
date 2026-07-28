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
 * Las dos cosas del mundo exterior que el tablero necesita: correr un proceso y traer una URL.
 *
 * Vive detrás de una clase para que los tests puedan sustituirla — un tablero cuyos tests salen a
 * la red no se puede correr en CI y, peor, empieza a fallar por razones que no son suyas.
 *
 * Las dos devuelven `null` para decir "no pude", nunca una cadena vacía: vacío es una respuesta y
 * no-pude no lo es, y confundirlas es exactamente lo que ADR-0028 prohíbe.
 */
class Shell
{
    /**
     * @param list<string> $command
     *
     * @return string|null null si el proceso no pudo correr o salió distinto de cero
     */
    /**
     * Runs a command and returns its output, or null when it could not run.
     *
     * Null rather than an empty string on failure: a command that produced nothing and a command
     * that never ran are different answers, and a check reading the second as the first would
     * conclude something from an absence it caused.
     *
     * @param list<string> $command
     */
    public function run(array $command): ?string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes);
        if (!\is_resource($process)) {
            return null;
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? $stdout : null;
    }

    /** @return string|null null cuando no hubo respuesta utilizable (sin red, 404, timeout) */
    /**
     * Fetches a URL, or null when the network did not answer.
     *
     * Same distinction as {@see run()}: no answer is not an empty answer, and a board that treated
     * an unreachable index as an empty one would report a package missing when it is merely
     * offline.
     */
    public function fetch(string $url): ?string
    {
        $context = stream_context_create(['http' => [
            'timeout' => 5,
            'ignore_errors' => false,
            'header' => "User-Agent: milpa-devtools-board\r\n",
        ]]);

        $body = @file_get_contents($url, false, $context);

        return $body === false ? null : $body;
    }
}
