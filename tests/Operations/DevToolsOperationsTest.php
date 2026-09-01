<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Operations;

use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Mutation;
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

        self::assertSame(
            ['validate', 'make', 'implement', 'edit', 'test', 'artifact:contract', 'artifact:list', 'test:list', 'test:show', 'test:baseline', 'test:delta', 'contract:search', 'package:artifacts', 'source:read'],
            array_keys($porNombre),
        );

        self::assertFalse($porNombre['validate']->mutating, 'validar sólo lee');
        self::assertFalse($porNombre['validate']->requiresConfirmation);

        self::assertFalse($porNombre['artifact:contract']->mutating, 'leer un contrato sólo lee');
        self::assertFalse($porNombre['artifact:contract']->requiresConfirmation);

        foreach (['artifact:list', 'test:list', 'test:show', 'contract:search', 'package:artifacts', 'source:read'] as $readOperation) {
            self::assertFalse($porNombre[$readOperation]->mutating, "{$readOperation} only reads");
            self::assertFalse($porNombre[$readOperation]->requiresConfirmation);
            self::assertSame(Mutation::None, $porNombre[$readOperation]->effects?->mutation);
            self::assertSame(Authority::Read, $porNombre[$readOperation]->effects?->authority);
            self::assertSame(['cli', 'tui', 'mcp'], $porNombre[$readOperation]->surfaces);
        }

        self::assertTrue($porNombre['make']->mutating, 'andamiar escribe archivos y tiene que decirlo');
        self::assertFalse(
            $porNombre['make']->requiresConfirmation,
            'su daño lo acotan WriteGuard y el rollback del verify, más finos que una firma',
        );

        self::assertTrue($porNombre['implement']->mutating, 'landing a body writes a file and says so');
        self::assertFalse(
            $porNombre['implement']->requiresConfirmation,
            'its damage is bounded by the postcondition: what does not verify clean never touches the original',
        );
        self::assertSame(
            'class',
            $porNombre['implement']->namedTarget,
            'the intent contract targets THE CLASS — a request that does not name it should not be filling it',
        );

        self::assertTrue($porNombre['edit']->mutating, 'landing an edit writes a file and says so');
        self::assertFalse(
            $porNombre['edit']->requiresConfirmation,
            'same postcondition as implement — the landing gate is delegated, not duplicated',
        );
        self::assertSame('class', $porNombre['edit']->namedTarget, 'same intent contract as implement');

        self::assertTrue($porNombre['test']->mutating, 'correr la suite ejecuta el código del proyecto');
        self::assertFalse($porNombre['test']->requiresConfirmation);
    }

    /**
     * `test` NO se ofrece por HTTP.
     *
     * Una petición web que dispara la suite de la app es una superficie que nadie quiso: en desarrollo
     * sobra —ahí está la terminal— y en algo desplegado es una forma de tumbar el proceso desde fuera.
     * Los otros dos no declaran superficies, o sea que se ofrecen en las cuatro; declarar ésta es una
     * decisión, y una decisión que se puede borrar sin que nada más se rompa merece su prueba.
     */
    public function testTheSuiteIsNotOfferedOverHttp(): void
    {
        $porNombre = [];
        foreach ((new DevToolsOperations())->operations() as $op) {
            $porNombre[$op->name] = $op;
        }

        self::assertSame(['cli', 'tui', 'mcp'], $porNombre['test']->surfaces);
        self::assertNotContains('http', (array) $porNombre['test']->surfaces);
        self::assertNull($porNombre['make']->surfaces, 'las otras dos se ofrecen en las cuatro');
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

    /** The new read operations declare only the inputs their handlers actually require. */
    public function testReadOperationRequiredInputsMatchTheirContracts(): void
    {
        $byName = [];
        foreach ((new DevToolsOperations())->operations() as $operation) {
            $byName[$operation->name] = $operation;
        }

        self::assertSame([], $byName['artifact:list']->inputSchema['required']);
        self::assertSame([], $byName['test:list']->inputSchema['required']);
        self::assertSame(['name'], $byName['test:show']->inputSchema['required']);
        self::assertSame(['q'], $byName['contract:search']->inputSchema['required']);
        self::assertSame(['package'], $byName['package:artifacts']->inputSchema['required']);
        self::assertSame(['path'], $byName['source:read']->inputSchema['required']);
    }

    /**
     * Los SEIS generadores que el paquete implementa se alcanzan, y el esquema los ofrece.
     *
     * Cableaba dos. `plugin`, `crud`, `service` y `tool` estaban completos y probados, y no llegaban a
     * ninguna superficie — no faltaba código, faltaba la línea que lo enchufa, y por eso ningún gate
     * lo veía. Esta prueba es esa línea puesta a la vista: si alguien agrega un generador y no lo
     * cablea, o lo cablea y no lo declara en el enum, las dos listas dejan de casar.
     */
    public function testEveryGeneratorThePackageImplementsIsReachableAndOffered(): void
    {
        $esperados = ['controller', 'entity', 'plugin', 'crud', 'resource', 'service', 'tool', 'test'];

        $cableados = (new MakeHandler(new RootResolver($this->raiz)))->kinds();
        sort($cableados);
        $ordenados = $esperados;
        sort($ordenados);
        self::assertSame($ordenados, $cableados, 'todos se cablean en el handler');

        $make = (new DevToolsOperations())->operations()[1];
        $ofrecidos = $make->inputSchema['properties']['what']['enum'];
        sort($ofrecidos);
        self::assertSame($ordenados, $ofrecidos, 'y todos se declaran en el esquema');
    }

    /**
     * `resource` is the one-call closed shape: entity + service + controller + routes + judge, all
     * landed as FILES through the handler surface, none as prose. (`no_verify` because this temp
     * root has no autoloader for the shape verify; the postcondition closure itself is proven at the
     * generator layer — see ResourceGeneratorTest.)
     */
    public function testAResourceLandsTheWholeClosedShapeInOneCall(): void
    {
        $handler = new MakeHandler(new RootResolver($this->raiz));
        $r = $handler->handle([
            'what' => 'resource',
            'plugin' => 'Tareas',
            'name' => 'Tarea',
            'fields' => 'titulo:string, hecha:bool',
            'no_verify' => true,
        ]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));

        $porNombre = [];
        foreach ($r['files'] as $archivo) {
            self::assertFileExists($archivo['path']);
            $porNombre[basename($archivo['path'])] = $archivo['action'];
        }
        foreach (['Tarea.php', 'TareaController.php', 'TareaService.php', 'Tareas.php', 'TareaTest.php'] as $pieza) {
            self::assertArrayHasKey($pieza, $porNombre, "la pieza {$pieza} aterriza como archivo, no como prosa");
            self::assertSame('created', $porNombre[$pieza]);
        }

        $plugin = (string) file_get_contents($this->raiz . '/src/Plugins/Tareas/Tareas.php');
        self::assertStringContainsString("Tarea::class . 'Repository'", $plugin);
        self::assertStringContainsString('TareaController::class', $plugin);
        self::assertStringContainsString('TareaService::class', $plugin);
        self::assertStringContainsString("'tareas_index'", $plugin);
    }

    /**
     * Un plugin suelto se andamia, y el resultado DICE cómo terminar de instalarlo.
     *
     * Los seis generadores producen una guía —«registra esto en config/plugins.php»— y este handler la
     * tiraba: nadie en el paquete leía `GenerationResult::$guidance`. Mientras sólo se ofrecían
     * `controller` y `entity` era desperdicio; con `plugin` cableado es un defecto, porque un plugin
     * que el kernel no bootea hasta que alguien lo declara, sin nada que lo diga, parece terminado y
     * no lo está.
     */
    public function testAStandalonePluginIsScaffoldedAndSaysHowToFinishInstallingIt(): void
    {
        $r = (new MakeHandler(new RootResolver($this->raiz)))->handle([
            'what' => 'plugin',
            'plugin' => 'Facturacion',
            'name' => 'Facturacion',
            'provides' => 'facturacion',
        ]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertCount(1, $r['files']);
        self::assertFileExists($r['files'][0]['path']);
        self::assertStringContainsString('config/plugins.php', (string) $r['guidance']);

        $escrito = (string) file_get_contents($r['files'][0]['path']);
        self::assertStringContainsString('class Facturacion', $escrito);
        self::assertStringContainsString("provides: ['facturacion']", $escrito, 'las capacidades declaradas llegan');
    }

    /**
     * Con `plugin`, los dos nombres tienen que ser el mismo — y se dice, no se adivina.
     *
     * El destino y el artefacto coinciden, así que uno de los dos sobra. El esquema no puede decir
     * «obligatorio salvo para este `what`» (lo que la terminal vuelve posicional sale de `required`),
     * así que la alternativa era ignorar el que sobra: una entrada declarada que no se usa es una
     * mentira del esquema, y la mentira sale cara cuando quien la lee es un agente.
     */
    public function testForAPluginBothNamesMustBeTheSame(): void
    {
        $r = (new MakeHandler(new RootResolver($this->raiz)))->handle([
            'what' => 'plugin',
            'plugin' => 'UnaCosa',
            'name' => 'OtraCosa',
        ]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('make plugin OtraCosa OtraCosa', (string) $r['error'], 'la negativa trae la línea que sí funciona');
        self::assertSame([], $r['files'], 'no escribió nada');
    }

    /**
     * Un generador compuesto —`tool`— también se alcanza, con sus opciones propias.
     *
     * `needs`, `tool-name` y `description` las lee sólo este generador; si el handler no las pasara,
     * el átomo las declararía en su esquema y no harían nada, que es la forma más silenciosa de
     * mentir. `tool_name` se escribe con guion bajo en el esquema y con guion en la terminal, igual
     * que `dry_run`.
     */
    public function testACompositeGeneratorIsReachedWithItsOwnOptions(): void
    {
        $r = (new MakeHandler(new RootResolver($this->raiz)))->handle([
            'what' => 'tool',
            'plugin' => 'UnPlugin',
            'name' => 'BuscarFacturas',
            'description' => 'Busca facturas por cliente',
            'no_verify' => true,
        ]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertNotSame([], $r['files']);

        $escrito = '';
        foreach ($r['files'] as $archivo) {
            self::assertFileExists($archivo['path']);
            $escrito .= (string) file_get_contents($archivo['path']);
        }
        self::assertStringContainsString('Busca facturas por cliente', $escrito, 'la descripción que lee un agente llega');
    }

    /**
     * Un generador compuesto se INJERTA en un plugin que ya existe, y el reporte no lo llama sobrescrito.
     *
     * Los cuatro compuestos planean el archivo del plugin con `PlannedFile::$merge` —«esto no reemplaza,
     * inserta en el marcador»— y `WriteGuard` sabe honrarlo desde que la bandera existe. Este handler la
     * tiraba, así que `make crud` sobre el plugin recién creado —el caso normal, porque el plugin va
     * primero— moría con «already exists (use --force to overwrite)». Contestarle eso a quien sólo
     * quiere agregar un CRUD lo empuja a forzar un archivo que no quería sobrescribir.
     */
    public function testACompositeGrafsItselfIntoAnExistingPluginWithoutForce(): void
    {
        $handler = new MakeHandler(new RootResolver($this->raiz));

        $plugin = $handler->handle(['what' => 'plugin', 'plugin' => 'Ventas', 'name' => 'Ventas']);
        self::assertTrue($plugin['ok'], (string) ($plugin['error'] ?? ''));
        $rutaPlugin = $plugin['files'][0]['path'];

        $crud = $handler->handle([
            'what' => 'crud',
            'plugin' => 'Ventas',
            'name' => 'Pedido',
            'fields' => 'folio:string',
            'no_verify' => true,
        ]);

        self::assertTrue($crud['ok'], (string) ($crud['error'] ?? ''));

        $acciones = [];
        foreach ($crud['files'] as $archivo) {
            $acciones[basename($archivo['path'])] = $archivo['action'];
        }
        self::assertSame('merged', $acciones['Ventas.php'], 'injertar no es sobrescribir, y el reporte lo distingue');
        self::assertStringContainsString('registerService', (string) file_get_contents($rutaPlugin), 'el cableado aterrizó');
    }

    /**
     * `--force` sobrescribe el artefacto y NO duplica el cableado.
     *
     * Son dos intenciones que compartían una llave: la guarda de escritura la lee como «reemplaza este
     * archivo» y los generadores compuestos como «reinserta en el marcador aunque ya esté». Pasar la
     * misma bandera a los dos dejaba el plugin registrando el mismo servicio dos veces por rehacer una
     * entity — cuatro `registerService` donde había dos, sin que nadie lo pidiera.
     */
    public function testForceOverwritesTheArtifactWithoutDuplicatingTheWiring(): void
    {
        $handler = new MakeHandler(new RootResolver($this->raiz));
        $handler->handle(['what' => 'plugin', 'plugin' => 'Almacen', 'name' => 'Almacen']);

        $entrada = [
            'what' => 'crud',
            'plugin' => 'Almacen',
            'name' => 'Caja',
            'fields' => 'folio:string',
            'no_verify' => true,
        ];
        $primera = $handler->handle($entrada);
        self::assertTrue($primera['ok'], (string) ($primera['error'] ?? ''));

        $rutaPlugin = $this->raiz . '/src/Plugins/Almacen/Almacen.php';
        $antes = substr_count((string) file_get_contents($rutaPlugin), 'registerService');
        self::assertGreaterThan(0, $antes);

        $segunda = $handler->handle($entrada + ['force' => true]);
        self::assertTrue($segunda['ok'], (string) ($segunda['error'] ?? ''));

        self::assertSame(
            $antes,
            substr_count((string) file_get_contents($rutaPlugin), 'registerService'),
            'forzar la escritura no puede duplicar el registro del servicio',
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

    /**
     * Cuando el verify falla y se deshace lo escrito, el REPORTE lo dice.
     *
     * Decía `created` sobre archivos que el propio handler acababa de borrar. El veredicto venía en
     * `ok: false`, así que quien cruzara las dos llaves se daba cuenta —y quien leyera la lista, no.
     * Un agente contra el Ollama de la LAN leyó la lista y anunció dos archivos creados que no
     * existían en disco. Una lista que sobrevive al hecho que describe es peor que no traerla.
     *
     * El verify corre en un subproceso que resuelve la raíz de ESTE directorio temporal, donde no hay
     * autoloader: falla siempre, que es justo la rama que se quiere medir.
     */
    public function testWhenTheVerifyFailsTheReportSaysWhatWasUndone(): void
    {
        $r = (new MakeHandler(new RootResolver($this->raiz)))->handle([
            'what' => 'entity',
            'plugin' => 'UnPlugin',
            'name' => 'Deshecha',
            'fields' => 'titulo:string',
        ]);

        self::assertFalse($r['ok'], 'sin autoloader en la raíz, el verify no puede pasar');
        self::assertNotSame([], $r['files']);

        foreach ($r['files'] as $archivo) {
            self::assertSame('rolled-back', $archivo['action']);
            self::assertFileDoesNotExist($archivo['path'], 'lo reportado como deshecho tiene que no estar');
        }

        // Y la guía se calla: decirle a alguien cómo seguir con archivos que ya no existen es
        // mandarlo a un lugar vacío.
        self::assertNull($r['guidance']);
    }

    /**
     * `validate` funciona en una app de RUNTIME — antes era un comando que siempre fallaba ahí.
     *
     * Hardcodeaba `plugins/<x>/milpa.json`, que es la ruta de un host legacy. En la app que sale de un
     * `create-project` ningún plugin tiene manifiesto —su fuente de verdad es `#[PluginMetadata]`— así
     * que `coa validate HelloPlugin` contestaba «no hay manifiesto» sobre el plugin de ejemplo que ese
     * mismo `create-project` acababa de escribir. Le pasó lo mismo que a `make` antes de aprender a
     * preguntarle al detector.
     */
    public function testValidateWorksOnARuntimePluginWithNoManifest(): void
    {
        mkdir($this->raiz . '/src/Plugins/Runtimero', 0777, true);
        file_put_contents($this->raiz . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['Fixture\\' => 'src/']],
        ]));
        file_put_contents(
            $this->raiz . '/src/Plugins/Runtimero/Runtimero.php',
            "<?php\ndeclare(strict_types=1);\nnamespace Fixture\\Plugins\\Runtimero;\nfinal class Runtimero {}\n",
        );

        $r = (new ValidateHandler(new RootResolver($this->raiz)))->handle(['target' => 'Runtimero']);

        // No pasa —la clase del fixture no está en el autoload— pero LLEGA a intentar cargarla, que
        // es lo que antes no ocurría, y el motivo nombra el fallo más común de todos: una clase que se
        // declara y no se puede cargar. Antes contestaba «no hay manifiesto» sobre una convención que
        // no usa manifiestos, mandando a alguien a crear un archivo equivocado.
        self::assertFalse($r['ok']);
        self::assertStringContainsString('no se puede cargar', (string) $r['error']);
        self::assertStringNotContainsString('no hay manifiesto', (string) $r['error']);
    }

    /**
     * Un target que no existe en NINGUNA convención lo dice nombrando las dos.
     *
     * Antes decía sólo dónde buscó bajo `plugins/`, que en una app de runtime es un lugar donde nunca
     * iba a haber nada — mandaba a alguien a crear un archivo que su convención no usa.
     */
    public function testAnUnknownTargetNamesBothConventions(): void
    {
        $r = (new ValidateHandler(new RootResolver($this->raiz)))->handle(['target' => 'NoExisteEnNinguna']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('milpa.json', (string) $r['error']);
        self::assertStringContainsString('PluginMetadata', (string) $r['error']);
    }

    /**
     * `ok:true` de `make` SIGNIFICA que las consecuencias prometidas existen — el reporte de
     * postcondiciones acompaña un PASS y todas pasan.
     *
     * El verify de forma corre de verdad (registramos un autoloader que mapea el árbol temporal), así
     * que este PASS es el de una entidad autocargable Y cableada: su archivo, el enum que un campo
     * declaró con cases, y el registro del repositorio en el plugin recién andamiado.
     */
    public function testAMakeThatPassesCarriesAPostconditionReportWhereEverythingHolds(): void
    {
        file_put_contents(
            $this->raiz . '/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]]),
        );
        $autoloader = $this->registerAppAutoloader();

        try {
            $r = (new MakeHandler(new RootResolver($this->raiz)))->handle([
                'what' => 'entity',
                'plugin' => 'PostcondOk',
                'name' => 'Widget',
                'fields' => 'title:string, priority:enum:Kind(a,b)',
                'flavor' => 'runtime',
            ]);
        } finally {
            spl_autoload_unregister($autoloader);
        }

        self::assertTrue($r['ok'], (string) ($r['error'] ?? '') . ' / ' . json_encode($r['verify'] ?? null));
        self::assertArrayNotHasKey('incomplete', $r);
        self::assertArrayHasKey('postconditions', $r);
        self::assertTrue($r['postconditions']['ok']);
        self::assertSame([], $r['postconditions']['missing']);
    }

    /**
     * Una consecuencia REFERENCIADA que cuelga vuelve el veredicto `incomplete`, no PASS — y NO
     * deshace lo escrito.
     *
     * `enum:Ghost` sin cases referencia un enum que `make` no crea. La clase de la entidad es válida y
     * autocargable —el verify de forma pasa—, pero apunta a un enum que no está en disco: exactamente
     * la referencia colgada por la que un agente molió 20 negativas creyendo que tenía un PASS. El
     * reporte nombra lo que falta y el archivo de la entidad sigue ahí para terminarlo.
     */
    public function testADanglingConsequenceMakesTheRunIncompleteWithoutRollingBack(): void
    {
        file_put_contents(
            $this->raiz . '/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]]),
        );
        $autoloader = $this->registerAppAutoloader();

        try {
            $r = (new MakeHandler(new RootResolver($this->raiz)))->handle([
                'what' => 'entity',
                'plugin' => 'PostcondDangling',
                'name' => 'Widget',
                'fields' => 'title:string, priority:enum:Ghost',
                'flavor' => 'runtime',
            ]);
        } finally {
            spl_autoload_unregister($autoloader);
        }

        self::assertFalse($r['ok'], 'una referencia colgada no puede ser PASS');
        self::assertTrue($r['incomplete']);
        self::assertContains('enum:Ghost', $r['postconditions']['missing']);

        // No se deshizo: los archivos son válidos, sólo les falta el enum — borrarlos castigaría al
        // que casi llegó. Ninguno quedó como 'rolled-back' y el de la entidad está en disco.
        foreach ($r['files'] as $archivo) {
            self::assertNotSame('rolled-back', $archivo['action']);
        }
        self::assertFileExists($this->raiz . '/src/Plugins/PostcondDangling/Entities/Widget.php');
    }

    /**
     * Registers a PSR-4 autoloader for `App\` -> the temp root's `src/`, so the real shape verifier
     * can autoload a freshly scaffolded runtime class instead of failing "class not found" (the reason
     * every other verifying test skips verify). Returns the closure to unregister after the test.
     */
    private function registerAppAutoloader(): callable
    {
        $root = $this->raiz;
        $loader = static function (string $class) use ($root): void {
            if (!str_starts_with($class, 'App\\')) {
                return;
            }
            $relative = str_replace('\\', '/', substr($class, \strlen('App\\')));
            $file = $root . '/src/' . $relative . '.php';
            if (is_file($file)) {
                require $file;
            }
        };
        spl_autoload_register($loader);

        return $loader;
    }
}
