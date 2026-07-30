<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Operations\DevToolsOperations;
use Milpa\DevTools\Operations\MakeHandler;
use Milpa\DevTools\Operations\ValidateHandler;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/**
 * Los dos átomos que este paquete publica, probados AQUÍ.
 *
 * Vivían en un host y sus pruebas se quedaron allá cuando el código se mudó. El CI lo dijo de
 * inmediato por el piso de cobertura, y tenía razón por una razón que va más allá del número: un
 * paquete que publica capacidades para hosts que no controla no puede apoyarse en que alguno de
 * ellos las pruebe. El día que ese host cambie de opinión, lo publicado se queda sin red.
 */
final class DevToolsOperationsTest extends TestCase
{
    private string $raiz;

    protected function setUp(): void
    {
        $this->raiz = sys_get_temp_dir() . '/milpa-devtools-ops-' . bin2hex(random_bytes(4));
        mkdir($this->raiz . '/plugins/UnPlugin', 0777, true);
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
     * La política de consentimiento viaja con la operación, no con el host.
     *
     * Es la razón entera de declararla aquí: dos hosts que decidieran distinto sobre la misma
     * capacidad serían dos respuestas a una pregunta que sólo tiene una.
     */
    public function testTheConsentPolicyTravelsWithTheOperation(): void
    {
        $porNombre = [];
        foreach ((new DevToolsOperations())->operations() as $op) {
            $porNombre[$op->name] = $op;
        }

        self::assertSame(['validate', 'make'], array_keys($porNombre));

        self::assertFalse($porNombre['validate']->mutating, 'validar sólo lee');
        self::assertFalse($porNombre['validate']->requiresConfirmation);

        self::assertTrue($porNombre['make']->mutating, 'andamiar escribe archivos y tiene que decirlo');
        self::assertFalse(
            $porNombre['make']->requiresConfirmation,
            'su daño lo acotan WriteGuard y el rollback del verify, más finos que una firma',
        );
    }

    /**
     * Lo obligatorio se declara EN ORDEN.
     *
     * Un materializador de terminal expone lo obligatorio como argumentos posicionales siguiendo el
     * orden de las propiedades, así que reordenarlas cambiaría cómo se teclea `make` en toda app que
     * adopte este paquete — sin tocar ni una línea de esa app.
     */
    public function testTheRequiredInputsAreDeclaredInTheOrderTheyAreTyped(): void
    {
        $make = (new DevToolsOperations())->operations()[1];

        self::assertSame(['what', 'plugin', 'name'], $make->inputSchema['required']);
        self::assertSame(
            ['what', 'plugin', 'name'],
            \array_slice(array_keys($make->inputSchema['properties']), 0, 3),
        );
    }

    /** Un manifiesto ausente es un RESULTADO con veredicto, no una excepción. */
    public function testAMissingManifestIsAResult(): void
    {
        $r = (new ValidateHandler(new RootResolver($this->raiz)))->handle(['target' => 'NoExiste']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('NoExiste', (string) $r['error']);
    }

    /** Sin `target` tampoco lanza: contesta qué falta. */
    public function testAMissingTargetIsAnswered(): void
    {
        $r = (new ValidateHandler(new RootResolver($this->raiz)))->handle([]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('target', (string) $r['error']);
    }

    /** Un manifiesto real pasa por los DOS validadores, y el veredicto es su conjunción. */
    public function testARealManifestGoesThroughBothCheckers(): void
    {
        file_put_contents($this->raiz . '/plugins/UnPlugin/milpa.json', (string) json_encode([
            'name' => 'acme/unplugin',
            'displayName' => 'UnPlugin',
            'version' => '1.0.0',
            'type' => 'Service',
            'authors' => [['name' => 'Acme', 'email' => '']],
        ]));

        $r = (new ValidateHandler(new RootResolver($this->raiz)))->handle(['target' => 'UnPlugin']);

        self::assertArrayHasKey('checks', $r);
        self::assertArrayHasKey('manifest', $r['checks']);
        self::assertArrayHasKey('providers', $r['checks']);
        self::assertSame(
            $r['checks']['manifest']['ok'] && $r['checks']['providers']['ok'],
            $r['ok'],
            'el veredicto es la conjunción de los dos, no una tercera opinión',
        );
    }

    /**
     * Una travesía de ruta jamás llega a los generadores.
     *
     * `plugin` y `name` se interpolan directo en rutas del sistema de archivos. Al publicarse como
     * operación, la entrada deja de venir sólo de alguien que teclea en su propia máquina — así que
     * esta guarda importa más aquí que donde nació.
     */
    public function testPathTraversalNeverReachesTheGenerators(): void
    {
        $r = (new MakeHandler(new RootResolver($this->raiz)))->handle([
            'what' => 'entity',
            'plugin' => 'UnPlugin',
            'name' => '../../../tmp/x',
        ]);

        self::assertFalse($r['ok']);
        self::assertSame([], $r['files']);
        self::assertStringContainsString('sin diagonales ni puntos', (string) $r['error']);
    }

    /** Un artefacto desconocido contesta con los que sí existen. */
    public function testAnUnknownArtifactAnswersWithTheValidOnes(): void
    {
        $r = (new MakeHandler(new RootResolver($this->raiz)))->handle([
            'what' => 'nave-espacial',
            'plugin' => 'UnPlugin',
            'name' => 'X',
        ]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('controller', (string) $r['error']);
        self::assertStringContainsString('entity', (string) $r['error']);
    }

    /**
     * `dry_run` planea y NO escribe — comprobado contra el disco.
     *
     * Preguntarle al resultado si escribió sería creerle justo en lo que se está midiendo.
     */
    public function testDryRunPlansWithoutTouchingTheDisk(): void
    {
        $r = (new MakeHandler(new RootResolver($this->raiz)))->handle([
            'what' => 'entity',
            'plugin' => 'UnPlugin',
            'name' => 'Cosa',
            'fields' => 'titulo:string',
            'dry_run' => true,
        ]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertNotSame([], $r['files']);

        foreach ($r['files'] as $archivo) {
            self::assertSame('would-create', $archivo['action']);
            self::assertFileDoesNotExist($archivo['path']);
        }
    }

    /**
     * Y escribiendo de verdad: el archivo aparece y se reporta como creado.
     *
     * `no_verify` porque verificar saca un subproceso que resuelve la raíz REAL del proyecto, no
     * este directorio temporal — probar el andamiaje y probar el verificador son dos cosas, y
     * mezclarlas haría que esta prueba fallara por algo que no mide.
     */
    public function testItActuallyWritesWhatItPlanned(): void
    {
        $r = (new MakeHandler(new RootResolver($this->raiz)))->handle([
            'what' => 'entity',
            'plugin' => 'UnPlugin',
            'name' => 'Cosa',
            'fields' => 'titulo:string',
            'no_verify' => true,
        ]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertNotSame([], $r['files']);

        foreach ($r['files'] as $archivo) {
            self::assertSame('created', $archivo['action']);
            self::assertFileExists($archivo['path']);
        }
    }

    /**
     * En la convención LEGACY el directorio del plugin sí tiene que existir; en la de runtime no.
     *
     * Es la rama que este handler cambió al mudarse aquí. La primera versión exigía `plugins/<X>/`
     * siempre, porque la escribió un host legacy — y con eso bloqueaba justo el caso que este
     * paquete existe para resolver: en una app de runtime el generador CREA el plugin de paso,
     * porque un controller huérfano no enruta nada.
     *
     * Un `$root` con `milpa.json` es la señal afirmativa de legacy que el detector busca.
     */
    public function testTheDirectoryIsOnlyRequiredUnderTheLegacyConvention(): void
    {
        file_put_contents($this->raiz . '/milpa.json', '{"name":"acme/host"}');

        $r = (new MakeHandler(new RootResolver($this->raiz)))->handle([
            'what' => 'entity',
            'plugin' => 'NoExisteEsteDirectorio',
            'name' => 'Cosa',
            'fields' => 'titulo:string',
        ]);

        self::assertFalse($r['ok'], 'en legacy, sin directorio no se andamia');
        self::assertStringContainsString('plugins/', (string) $r['error']);

        // Y sin esa señal —el mismo árbol sin `milpa.json`— el generador sigue adelante.
        unlink($this->raiz . '/milpa.json');
        $enRuntime = (new MakeHandler(new RootResolver($this->raiz)))->handle([
            'what' => 'entity',
            'plugin' => 'NoExisteEsteDirectorio',
            'name' => 'Cosa',
            'fields' => 'titulo:string',
            'dry_run' => true,
        ]);

        self::assertTrue($enRuntime['ok'], 'en runtime el generador crea el plugin de paso');
    }

    /**
     * Escribir dos veces sin `--force` se NIEGA, y lo dice.
     *
     * Es la guarda más fina que hace innecesaria una firma en esta operación, así que si dejara de
     * valer, la decisión de no pedir firma dejaría de estar justificada.
     */
    public function testItRefusesToOverwriteWithoutForce(): void
    {
        $entrada = [
            'what' => 'entity',
            'plugin' => 'UnPlugin',
            'name' => 'Cosa',
            'fields' => 'titulo:string',
            'no_verify' => true,
        ];

        self::assertTrue((new MakeHandler(new RootResolver($this->raiz)))->handle($entrada)['ok']);

        $segunda = (new MakeHandler(new RootResolver($this->raiz)))->handle($entrada);
        self::assertFalse($segunda['ok']);
        self::assertSame([], $segunda['files'], 'no escribió nada al negarse');
    }
}
