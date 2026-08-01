<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Operations\TestHandler;
use Milpa\DevTools\Support\ProcessRunner;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/**
 * El átomo que deja al agente EJECUTAR lo que escribió.
 *
 * El verificador que corre después de `make` comprueba la forma: que la clase carga, que implementa
 * lo que dice, que no imprime a la salida. Es real y no toca el comportamiento — una entidad puede
 * satisfacer `EntityInterface` a la perfección y devolver el campo equivocado en `toArray()`. Sin
 * este átomo, todo lo que un agente podía saber de su propio trabajo era la mitad barata.
 *
 * El proceso se sustituye por una costura: correr PHPUnit de verdad desde dentro de PHPUnit es una
 * recursión que no se quiere depurar, y lo que estas pruebas miden —cómo se arma el comando, cómo se
 * lee el resumen, qué se contesta cuando no se pudo correr— no necesita el proceso real.
 */
final class TestHandlerTest extends TestCase
{
    private string $raiz;

    protected function setUp(): void
    {
        $this->raiz = sys_get_temp_dir() . '/milpa-devtools-test-' . bin2hex(random_bytes(4));
        mkdir($this->raiz . '/vendor/bin', 0777, true);
        mkdir($this->raiz . '/tests', 0777, true);
        file_put_contents($this->raiz . '/vendor/bin/phpunit', "#!/bin/sh\n");
        file_put_contents($this->raiz . '/tests/UnaPrueba.php', '<?php');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->raiz, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->raiz);
    }

    /**
     * Un doble que APUNTA lo que le pidieron y contesta lo que la prueba dijo.
     *
     * Guarda en propiedades públicas en vez de por referencia: `readonly` y promoción no se llevan con
     * `&$x`, y una prueba que pelea con el lenguaje para observar una llamada suele estar observando
     * de más.
     *
     * @param array{exit: int, output: string} $respuesta
     */
    private function doble(array $respuesta): ProcessRunner
    {
        return new class ($respuesta) extends ProcessRunner {
            /** @var list<string>|null */
            public ?array $comando = null;
            public ?string $cwd = null;
            public ?int $plazo = null;

            /** @param array{exit: int, output: string} $respuesta */
            public function __construct(private readonly array $respuesta)
            {
            }

            public function run(array $command, string $cwd, int $timeoutSeconds): array
            {
                $this->comando = $command;
                $this->cwd = $cwd;
                $this->plazo = $timeoutSeconds;

                return $this->respuesta;
            }
        };
    }

    /** @param array{exit: int, output: string} $respuesta */
    private function handler(array $respuesta): TestHandler
    {
        return new TestHandler($this->roots(), $this->doble($respuesta));
    }

    private function roots(): RootResolver
    {
        return new RootResolver($this->raiz);
    }

    /** Una suite verde: el veredicto es el código de salida, y los conteos salen del resumen. */
    public function testAGreenSuiteReportsItsCounts(): void
    {
        $r = $this->handler(['exit' => 0, 'output' => "....\n\nOK (12 tests, 34 assertions)\n"])->handle([]);

        self::assertTrue($r['ok']);
        self::assertTrue($r['ran']);
        self::assertSame(12, $r['tests']);
        self::assertSame(34, $r['assertions']);
        self::assertSame(0, $r['failures']);
        self::assertSame(0, $r['errors']);
    }

    /**
     * Una suite roja: el veredicto lo da el código de salida, NO el texto.
     *
     * Es lo que lee un CI sobre la misma corrida. Leer otra cosa aquí produciría un átomo que
     * contesta distinto que el pipeline, y entonces uno de los dos sobra.
     */
    public function testARedSuiteIsRedAndItsCountsAreRead(): void
    {
        $salida = "FAILURES!\nTests: 12, Assertions: 30, Failures: 2, Errors: 1.\n";
        $r = $this->handler(['exit' => 1, 'output' => $salida])->handle([]);

        self::assertFalse($r['ok']);
        self::assertTrue($r['ran'], 'corrió: falló, que no es lo mismo que no haber podido');
        self::assertSame(12, $r['tests']);
        self::assertSame(2, $r['failures']);
        self::assertSame(1, $r['errors']);
        self::assertStringContainsString('FAILURES!', $r['output'], 'el texto del fallo llega a quien tiene que arreglarlo');
    }

    /**
     * Conteos que no se pueden leer van en `null`, no en cero.
     *
     * Cero pruebas y no-pude-contar son respuestas distintas; decir `0` a la segunda sería inventar un
     * dato, y quien lo lea concluirá que la suite está vacía cuando lo que pasó es que ni arrancó.
     */
    public function testUnreadableCountsAreNullAndNotZero(): void
    {
        $r = $this->handler(['exit' => 2, 'output' => "PHP Fatal error: algo\n"])->handle([]);

        self::assertFalse($r['ok']);
        self::assertNull($r['tests']);
        self::assertNull($r['assertions']);
        self::assertNull($r['failures']);
    }

    /** Sin phpunit instalado se contesta con el motivo y la línea que lo arregla — y `ran: false`. */
    public function testWithoutPhpunitItSaysSoAndDistinguishesItFromAFailingSuite(): void
    {
        unlink($this->raiz . '/vendor/bin/phpunit');

        $r = (new TestHandler($this->roots()))->handle([]);

        self::assertFalse($r['ok']);
        self::assertFalse($r['ran'], 'no poder correr las pruebas y que fallen son dos noticias distintas');
        self::assertStringContainsString('composer require --dev phpunit/phpunit', (string) $r['error']);
    }

    /** `filter` y `path` llegan al comando, y la suite corre desde la raíz del proyecto. */
    public function testTheFilterAndPathReachTheCommandFromTheProjectRoot(): void
    {
        $doble = $this->doble(['exit' => 0, 'output' => 'OK (1 test, 1 assertion)']);
        $r = (new TestHandler($this->roots(), $doble))
            ->handle(['filter' => 'testAlgo', 'path' => 'tests/UnaPrueba.php']);

        self::assertTrue($r['ok']);
        self::assertContains('--filter', (array) $doble->comando);
        self::assertContains('testAlgo', (array) $doble->comando);
        self::assertContains($this->raiz . '/tests/UnaPrueba.php', (array) $doble->comando);
        self::assertSame($this->raiz, $doble->cwd, 'phpunit se resuelve contra la raíz, no contra donde se llamó');
        self::assertStringContainsString('--filter', $r['command'], 'el comando corrido se reporta tal cual');
    }

    /**
     * Un `path` que se sale de la raíz se rechaza ANTES de armar el comando.
     *
     * La entrada ya no viene sólo de una terminal: este átomo se proyecta también a MCP, así que
     * quien lo llama puede ser un agente al que alguien le dictó la ruta.
     */
    public function testAPathThatEscapesTheRootIsRefused(): void
    {
        $doble = $this->doble(['exit' => 0, 'output' => 'OK (1 test, 1 assertion)']);
        $r = (new TestHandler($this->roots(), $doble))->handle(['path' => '../../../etc']);

        self::assertFalse($r['ok']);
        self::assertFalse($r['ran']);
        self::assertNull($doble->comando, 'nunca se corrió ningún comando');
        self::assertStringContainsString('dentro de', (string) $r['error']);
    }

    /** Una ruta que no existe se rechaza igual: apuntar a la nada no es apuntar dentro. */
    public function testAPathThatDoesNotExistIsRefused(): void
    {
        $r = $this->handler(['exit' => 0, 'output' => 'OK (1 test, 1 assertion)'])
            ->handle(['path' => 'tests/NoExiste.php']);

        self::assertFalse($r['ok']);
        self::assertFalse($r['ran']);
    }

    /**
     * La salida se recorta conservando el FINAL, y se dice que se recortó.
     *
     * El resumen y los fallos viven al final. Y un reporte truncado en silencio es peor que uno
     * corto: quien lo lee no sabe que le falta algo.
     */
    public function testLongOutputIsTruncatedFromTheStartAndSaysSo(): void
    {
        $ruido = str_repeat('x', 20000);
        $r = $this->handler(['exit' => 0, 'output' => $ruido . "\nOK (1 test, 1 assertion)"])->handle([]);

        self::assertStringContainsString('recortada', $r['output']);
        self::assertStringContainsString('OK (1 test, 1 assertion)', $r['output'], 'el resumen sobrevive al recorte');
        self::assertLessThan(20000, \strlen($r['output']));
    }

    /** El plazo se acota a un rango con sentido: cero segundos no es un plazo y un día no es un plazo. */
    public function testTheTimeoutIsClampedToSomethingMeaningful(): void
    {
        $doble = $this->doble(['exit' => 0, 'output' => 'OK (1 test, 1 assertion)']);
        $handler = new TestHandler($this->roots(), $doble);

        $handler->handle(['timeout' => 0]);
        self::assertSame(1, $doble->plazo);

        $handler->handle(['timeout' => 99999]);
        self::assertSame(3600, $doble->plazo);

        $handler->handle([]);
        self::assertSame(300, $doble->plazo, 'sin decir nada, cinco minutos');
    }
}
