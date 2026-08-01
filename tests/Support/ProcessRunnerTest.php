<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Support;

use Milpa\DevTools\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * El único subproceso que este paquete saca — probado contra procesos de verdad.
 *
 * Todo lo demás corre en el mismo proceso a propósito, así que ésta es la clase donde un error se
 * paga en cuelgues y en veredictos inventados: un proceso que no arrancó reportado como suite en
 * rojo, o una suite colgada colgando a quien la llamó. Sustituirla por un doble aquí sería probar el
 * doble.
 */
final class ProcessRunnerTest extends TestCase
{
    /** La salida vuelve con su código, no en lugar de él. */
    public function testItReturnsOutputAndExitCodeTogether(): void
    {
        $r = (new ProcessRunner())->run(
            [\PHP_BINARY, '-r', 'echo "hola"; exit(0);'],
            sys_get_temp_dir(),
            10,
        );

        self::assertSame(0, $r['exit']);
        self::assertStringContainsString('hola', $r['output']);
    }

    /**
     * Un proceso que sale en rojo CONSERVA su salida.
     *
     * Es la diferencia con `Board\Shell`, que devuelve `null` cuando el código no es cero. Para el
     * tablero eso está bien; aquí la corrida roja es justo la interesante, y su texto es lo único que
     * le dice a alguien qué arreglar.
     */
    public function testAFailingProcessKeepsItsOutput(): void
    {
        $r = (new ProcessRunner())->run(
            [\PHP_BINARY, '-r', 'echo "se rompió esto"; exit(3);'],
            sys_get_temp_dir(),
            10,
        );

        self::assertSame(3, $r['exit']);
        self::assertStringContainsString('se rompió esto', $r['output']);
    }

    /** stderr y stdout llegan juntos: el error que importa suele estar en el otro flujo. */
    public function testBothStreamsArrive(): void
    {
        $r = (new ProcessRunner())->run(
            [\PHP_BINARY, '-r', 'fwrite(STDOUT, "por-salida"); fwrite(STDERR, "por-error");'],
            sys_get_temp_dir(),
            10,
        );

        self::assertStringContainsString('por-salida', $r['output']);
        self::assertStringContainsString('por-error', $r['output']);
    }

    /** Corre DONDE se le dijo: una suite resuelta contra el directorio equivocado no prueba esta app. */
    public function testItRunsInTheGivenDirectory(): void
    {
        $cwd = realpath(sys_get_temp_dir());
        self::assertIsString($cwd);

        $r = (new ProcessRunner())->run([\PHP_BINARY, '-r', 'echo getcwd();'], $cwd, 10);

        self::assertSame($cwd, trim($r['output']));
    }

    /**
     * Un proceso que se pasa del plazo se MATA, y la respuesta dice por qué.
     *
     * `124` es el código que usa una terminal para esto, así que quien lo vea ya sabe qué significa
     * sin leer esta clase. Esperar para siempre no es una respuesta: colgaría al agente que preguntó.
     */
    public function testAProcessThatOverrunsIsKilledAndSaysSo(): void
    {
        $inicio = microtime(true);
        $r = (new ProcessRunner())->run([\PHP_BINARY, '-r', 'sleep(30);'], sys_get_temp_dir(), 1);
        $tardo = microtime(true) - $inicio;

        self::assertSame(124, $r['exit']);
        self::assertStringContainsString('se detuvo', $r['output']);
        self::assertLessThan(10, $tardo, 'lo mató de verdad, no esperó los 30 segundos');
    }

    /** Un binario que no existe da `127` y no una excepción: no-pude-arrancar también es un resultado. */
    public function testABinaryThatDoesNotExistIsAResultAndNotAnException(): void
    {
        $r = (new ProcessRunner())->run(['/no/existe/este/binario'], sys_get_temp_dir(), 10);

        self::assertSame(127, $r['exit']);
        self::assertNotSame('', $r['output']);
    }
}
