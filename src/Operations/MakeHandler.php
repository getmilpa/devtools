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

use Milpa\DevTools\Make\ConventionDetector;
use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\GeneratorInterface;
use Milpa\DevTools\Make\Generators\ControllerGenerator;
use Milpa\DevTools\Make\Generators\CrudGenerator;
use Milpa\DevTools\Make\Generators\EntityGenerator;
use Milpa\DevTools\Make\Generators\PluginGenerator;
use Milpa\DevTools\Make\Generators\ResourceGenerator;
use Milpa\DevTools\Make\Generators\ServiceGenerator;
use Milpa\DevTools\Make\Generators\TestGenerator;
use Milpa\DevTools\Make\Generators\ToolGenerator;
use Milpa\DevTools\Make\PostconditionVerifier;
use Milpa\DevTools\Make\VerifyRunner;
use Milpa\DevTools\Make\WriteGuard;
use Milpa\DevTools\Support\RootResolver;

/**
 * Genera artefactos del framework —un controller, una entidad— siguiendo sus convenciones, y
 * verifica de inmediato lo que escribió.
 *
 * Es el PRIMER átomo que muta, y por eso vale la pena decir qué se decidió con su consentimiento.
 *
 * ── POR QUÉ NO EXIGE FIRMA ──────────────────────────────────────────────────────────────────────
 *
 * Se declara `mutating: true` porque escribe archivos y mentir sobre eso sería lo peor que puede
 * hacer una declaración. Pero NO `requiresConfirmation`, y la razón es que su daño ya está acotado
 * por dos mecanismos más finos que una firma:
 *
 * - `WriteGuard` se niega a sobrescribir salvo que alguien pase `--force`. Ese permiso NOMBRA el
 *   archivo, que es más específico de lo que una firma sobre la llamada puede ser.
 * - Un verify que falla borra lo recién creado. Una generación rota no deja basura en el árbol.
 *
 * Pedir firma para andamiar un controller convertiría la compuerta en trámite, y una compuerta que
 * se pide siempre se aprueba sin leer. Se reserva para lo que no se puede deshacer.
 *
 * ── LO QUE SÍ CUIDA ─────────────────────────────────────────────────────────────────────────────
 *
 * `plugin` y `name` se interpolan directo en rutas del sistema de archivos, así que ambos tienen que
 * casar `^[A-Za-z_][A-Za-z0-9_]*$`. Un `name` como `../../../tmp/x` jamás llega a los generadores.
 * Esa guarda venía del comando y se conserva palabra por palabra: al volverse átomo la entrada deja
 * de venir sólo de una terminal, así que importa más, no menos.
 */
final class MakeHandler
{
    /** @var array<string, GeneratorInterface> */
    private array $generadores = [];

    /**
     * TODOS los que el paquete implementa, no dos.
     *
     * Cableaba `controller` y `entity`. Los otros cuatro estaban completos y probados —`CrudGeneratorTest`
     * y `ToolGeneratorTest` pasan de las quinientas líneas cada una— y no se alcanzaban desde ninguna
     * superficie: ni terminal, ni HTTP, ni TUI, ni el agente. Ningún gate lo notaba, porque no faltaba
     * código sino la línea que lo enchufa; y como nunca estuvieron proyectados, un inventario dorado de
     * lo ofrecido tampoco los habría extrañado. Lo encontró alguien preguntándole al agente por un
     * plugin nuevo y viendo que no tenía a dónde ir. El séptimo (`test`, el juez conductual) llegó
     * luego, enchufado aquí mismo el día uno.
     */
    public function __construct(private readonly RootResolver $roots = new RootResolver())
    {
        $generadores = [
            new ControllerGenerator(),
            new EntityGenerator(),
            new PluginGenerator(),
            new CrudGenerator(),
            new ResourceGenerator(),
            new ServiceGenerator(),
            new ToolGenerator(),
            new TestGenerator(),
        ];
        foreach ($generadores as $generador) {
            $this->generadores[$generador->name()] = $generador;
        }
    }

    /**
     * Los artefactos que este host sabe andamiar.
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        return array_keys($this->generadores);
    }

    /**
     * Andamia el artefacto y corre su verificación.
     *
     * `dry_run` planea sin escribir: devuelve los mismos archivos con acción `would-create`, que es
     * la forma de preguntar «¿qué harías?» sin causarlo — y la que una superficie de agente debería
     * usar antes de la de verdad.
     *
     * When the produced class passes its shape verify but a promised CONSEQUENCE is missing — a
     * referenced enum with no file, a repository/controller/routes registration that landed as
     * guidance instead of code — the run is `incomplete`: `ok` is `false`, an `incomplete` flag is
     * set, and the `postconditions` report names what is missing. The written files are NOT rolled
     * back in that case (they are valid, only unwired); rollback stays reserved for a shape-verify
     * failure. See {@see \Milpa\DevTools\Make\PostconditionVerifier}.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, files: list<array{path: string, action: string}>, verify: array{ok: bool, output: string}|null, guidance: string|null, postconditions?: array{ok: bool, checks: list<array{name: string, ok: bool, required: bool, detail: string}>, missing: list<string>}, incomplete?: bool, error?: string}
     */
    public function handle(array $input): array
    {
        $que = \is_string($input['what'] ?? null) ? $input['what'] : '';
        if (!isset($this->generadores[$que])) {
            return $this->falla("artefacto desconocido «{$que}» — válidos: " . implode(', ', $this->kinds()));
        }

        $plugin = \is_string($input['plugin'] ?? null) ? $input['plugin'] : '';
        $nombre = \is_string($input['name'] ?? null) ? $input['name'] : '';
        $identificador = '/^[A-Za-z_][A-Za-z0-9_]*$/';
        if (preg_match($identificador, $plugin) !== 1 || preg_match($identificador, $nombre) !== 1) {
            return $this->falla('«plugin» y «name» tienen que casar ^[A-Za-z_][A-Za-z0-9_]*$ — sin diagonales ni puntos');
        }

        // `plugin` es el artefacto DESTINO en cinco de los seis; en `plugin` el destino ES el artefacto,
        // así que los dos nombran la misma clase y se exige que coincidan. No es ceremonia gratuita: el
        // esquema no puede decir «obligatorio salvo para este `what`» —lo que la terminal traduce a
        // posicionales sale de `required`, y aflojarlo haría que `make entity MiPlugin Cosa` perdiera un
        // argumento en silencio, que es peor que escribir el nombre dos veces—. Ignorar el que sobra
        // tampoco: una entrada declarada que no se usa es una mentira del esquema.
        if ($que === 'plugin' && $plugin !== $nombre) {
            return $this->falla(
                "para «plugin» los dos argumentos nombran el mismo artefacto — escribe: make plugin {$nombre} {$nombre}",
            );
        }

        $root = $this->roots->resolve();

        // El plugin destino tiene que existir SÓLO en la convención legacy, donde el generador
        // escribe dentro de un `plugins/<Nombre>/` que alguien ya creó. En la convención de runtime
        // el generador crea el plugin de paso —es su trabajo: un controller huérfano no enruta
        // nada—, y exigir el directorio ahí bloqueaba justo el caso que este paquete existe para
        // resolver.
        //
        // La primera versión de este handler hardcodeaba `plugins/` porque lo estrenó un host legacy.
        // Le preguntamos al detector, que es quien decide el sabor y a quien los generadores ya le
        // hacen caso.
        //
        // `plugin` queda fuera de esa exigencia: andamiar el plugin ES crear ese directorio, y pedirlo
        // de antemano sería negarse a hacer lo único que hace. (En legacy el generador se niega solo,
        // con su propio motivo, que es más específico que éste.)
        if (
            $que !== 'plugin'
            && (new ConventionDetector())->detect($root) === Flavor::Legacy
            && !is_dir($root . '/plugins/' . $plugin)
        ) {
            return $this->falla("no existe el directorio del plugin «{$plugin}» en plugins/ — créalo antes de andamiar dentro");
        }

        $contexto = new GenerationContext($plugin, $nombre, [
            'fields' => $input['fields'] ?? null,
            'route' => $input['route'] ?? null,
            // Los generadores de runtime leen `path` donde los de legacy leen `route` — el mismo
            // concepto con dos nombres, de cuando cada sabor se escribió por su lado. Quien pasa
            // `route` en una app de runtime merece que se le haga caso en vez de que se le ignore en
            // silencio, así que uno cae en el otro.
            'path' => $input['path'] ?? $input['route'] ?? null,
            'methods' => $input['methods'] ?? null,
            'table' => $input['table'] ?? null,
            'flavor' => $input['flavor'] ?? null,
            'provides' => $input['provides'] ?? null,
            'requires' => $input['requires'] ?? null,
            'interface' => $input['interface'] ?? null,
            'needs' => $input['needs'] ?? null,
            'tool-name' => $input['tool_name'] ?? null,
            'description' => $input['description'] ?? null,
            // `force` NO viaja, y es a propósito.
            //
            // Los generadores compuestos lo leen para otra cosa: reinsertar en un marcador que ya
            // tiene el fragmento. `MarkerInserter` es idempotente por defecto —compara contra la forma
            // ya indentada y no repite—, y forzarlo sólo sirve para duplicar. Pasar el mismo `--force`
            // de la guarda de escritura los ataba: rehacer una entity con `--force` dejaba el plugin
            // registrando el mismo servicio dos veces, sin que nadie lo pidiera. Medido: cuatro
            // `registerService` donde había dos.
            //
            // Así que este átomo alcanza cinco de las seis opciones de los generadores y deja ésta
            // fuera. No es un olvido de los que este handler acaba de arreglar: es que sobrescribir un
            // archivo y duplicar una inserción son dos intenciones, y la segunda no tiene caso de uso
            // que valga romper la primera.
        ], $root);

        $ensayo = ($input['dry_run'] ?? false) === true;
        $forzar = ($input['force'] ?? false) === true;
        $guarda = new WriteGuard();

        try {
            $resultado = $this->generadores[$que]->generate($contexto);
            foreach ($resultado->files as $archivo) {
                if (!$ensayo) {
                    // `$archivo->merge` dice que ese contenido NO es un reemplazo sino el mismo archivo
                    // con una inserción en su marcador — la promesa del generador de que reaplicarlo no
                    // duplica nada. `WriteGuard` sabe honrarlo desde que existe; este handler lo tiraba,
                    // y con eso `make crud` sobre un plugin que ya existe —el caso normal, porque el
                    // plugin se crea primero— moría diciendo «already exists (use --force to overwrite)».
                    // Contestarle eso a alguien que sólo quiere agregar un CRUD es empujarlo a pasar
                    // `--force` sobre un archivo que no quería sobrescribir.
                    $guarda->assertWritable($archivo->path, $forzar, $archivo->merge);
                }
            }
        } catch (\Throwable $e) {
            return $this->falla($e->getMessage());
        }

        $archivos = [];
        // Los que NO existían antes de esta corrida. Si el verify falla, se borran ÉSOS y sólo ésos:
        // de un archivo sobrescrito con --force nunca hubo copia previa que restaurar, así que
        // «limpiar» sería destruir lo que había.
        $nuevos = [];
        foreach ($resultado->files as $archivo) {
            $existia = is_file($archivo->path);
            // `merged` no es `overwritten`: lo que había sigue ahí y se le agregó el cableado. Llamarle
            // sobrescrito a una inserción asusta por lo que no pasó, y quien lee el reporte para
            // decidir si revisa un diff merece saber cuál de las dos fue.
            $accion = match (true) {
                $ensayo => 'would-create',
                $existia && $archivo->merge => 'merged',
                $existia => 'overwritten',
                default => 'created',
            };
            if (!$ensayo) {
                $guarda->write($archivo->path, $archivo->contents);
                if (!$existia) {
                    $nuevos[] = $archivo->path;
                }
            }
            $archivos[] = ['path' => $archivo->path, 'action' => $accion];
        }

        $verify = null;
        if (!$ensayo && ($input['no_verify'] ?? false) !== true && $resultado->verifyKind !== null && $resultado->verifyTarget !== null) {
            // CON el sabor que produjo el archivo. `VerifyRunner` lo acepta y su docblock dice que lo
            // típico es pasarle el de la misma llamada a `generate()`; este handler lo omitía, así que
            // los verificadores caían a su default —legacy— y en una app de runtime pedían Doctrine
            // para revisar una entity que no lo usa (la de runtime implementa `Milpa\Data\EntityInterface`
            // y no toca un solo atributo de ORM). El verify fallaba SIEMPRE, y como un verify fallido
            // borra lo recién escrito, andamiar no servía de nada sin `--no-verify`: la compuerta que
            // existe para que el agente pruebe lo que escribe era justo la que se lo impedía.
            //
            // Cuarto valor producido y tirado en este archivo, después de `merge`, `guidance` y el
            // flavor mismo. El patrón ya tiene nombre en el tablero.
            $verify = (new VerifyRunner())->run(
                $resultado->verifyKind,
                $resultado->verifyTarget,
                $root,
                $resultado->flavor,
            );
        }

        $verifyOk = $verify === null || $verify['ok'];

        if (!$verifyOk) {
            $borrados = [];
            foreach ($nuevos as $ruta) {
                if (is_file($ruta)) {
                    unlink($ruta);
                    $borrados[$ruta] = true;
                }
            }

            // Y el REPORTE dice que se borraron. Decía `created` sobre archivos que este mismo bloque
            // acababa de deshacer: el veredicto era `ok: false` y la lista contaba otra historia. Un
            // humano cruza las dos y se da cuenta; un agente lee la lista y anuncia que creó archivos
            // que no existen —pasó, contra el Ollama de la LAN, en la primera corrida real.
            foreach ($archivos as $i => $entrada) {
                if (isset($borrados[$entrada['path']])) {
                    $archivos[$i]['action'] = 'rolled-back';
                }
            }
        }

        // POSTCONDICIONES: que `ok:true` SIGNIFIQUE que las consecuencias prometidas existen.
        //
        // El verify de forma dice que la clase producida cumple su convención; no dice nada de lo que
        // esa clase NECESITA para no colgar —el enum que un campo nombró, el registro del repositorio,
        // las rutas—. Un agente real molió ~18 ediciones y 20 negativas por referencias colgadas que
        // `make` reportaba como PASS. Aquí se le pregunta al disco por cada consecuencia; si falta una
        // OBLIGATORIA, el veredicto es `incomplete`, no PASS —y NO se deshace lo escrito: los archivos
        // son válidos, sólo les falta el cableado, así que borrarlos sería castigar al que casi llegó—.
        // Corre bajo la misma compuerta que el verify: `dry_run` y `no_verify` la saltan, porque quien
        // pidió no verificar pidió no verificar.
        $postcondiciones = null;
        $incompleto = false;
        if (
            $verifyOk
            && !$ensayo
            && ($input['no_verify'] ?? false) !== true
            && ($que === 'crud' || $que === 'entity' || $que === 'resource')
        ) {
            $reporte = (new PostconditionVerifier())->verify($que, $contexto, $resultado->flavor ?? Flavor::Runtime);
            $postcondiciones = $reporte->toArray();
            $incompleto = !$reporte->ok();
        }

        $ok = $verifyOk && !$incompleto;

        // La GUÍA sí llega a quien llamó.
        //
        // Los seis generadores la producen —«registra este plugin en config/plugins.php», «agrega el
        // servicio al boot»— y este handler la tiraba: `GenerationResult::$guidance` no lo leía nadie
        // en todo el paquete. Con `plugin` cableado eso pasa de desperdicio a defecto: un plugin recién
        // andamiado que el kernel no bootea hasta que alguien lo declara, y nada que lo diga, es un
        // artefacto que parece terminado y no lo está. Se omite cuando se deshizo lo escrito: decirle a
        // alguien cómo seguir con archivos que ya no existen es mandarlo a un lugar vacío. Un
        // `incomplete` SÍ la conserva: ahí la guía es justo lo que falta por hacer.
        $guia = $verifyOk ? $resultado->guidance : null;

        $salida = ['ok' => $ok, 'files' => $archivos, 'verify' => $verify, 'guidance' => $guia];
        if ($postcondiciones !== null) {
            $salida['postconditions'] = $postcondiciones;
        }
        if ($incompleto) {
            $salida['incomplete'] = true;
        }

        return $salida;
    }

    /**
     * Una falla es un RESULTADO con veredicto, igual que en los átomos de sólo lectura.
     *
     * @return array{ok: bool, files: list<array{path: string, action: string}>, verify: null, guidance: null, error: string}
     */
    private function falla(string $motivo): array
    {
        return ['ok' => false, 'files' => [], 'verify' => null, 'guidance' => null, 'error' => $motivo];
    }
}
