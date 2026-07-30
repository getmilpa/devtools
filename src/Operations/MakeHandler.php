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
use Milpa\DevTools\Make\Generators\EntityGenerator;
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

    public function __construct(private readonly RootResolver $roots = new RootResolver())
    {
        foreach ([new ControllerGenerator(), new EntityGenerator()] as $generador) {
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
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, files: list<array{path: string, action: string}>, verify: array{ok: bool, output: string}|null, error?: string}
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
        if ((new ConventionDetector())->detect($root) === Flavor::Legacy && !is_dir($root . '/plugins/' . $plugin)) {
            return $this->falla("no existe el directorio del plugin «{$plugin}» en plugins/ — créalo antes de andamiar dentro");
        }

        $contexto = new GenerationContext($plugin, $nombre, [
            'fields' => $input['fields'] ?? null,
            'route' => $input['route'] ?? null,
            'methods' => $input['methods'] ?? null,
            'table' => $input['table'] ?? null,
        ], $root);

        $ensayo = ($input['dry_run'] ?? false) === true;
        $forzar = ($input['force'] ?? false) === true;
        $guarda = new WriteGuard();

        try {
            $resultado = $this->generadores[$que]->generate($contexto);
            foreach ($resultado->files as $archivo) {
                if (!$ensayo) {
                    $guarda->assertWritable($archivo->path, $forzar);
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
            $accion = $ensayo ? 'would-create' : ($existia ? 'overwritten' : 'created');
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
            $verify = (new VerifyRunner())->run($resultado->verifyKind, $resultado->verifyTarget, $root);
        }

        $ok = $verify === null || $verify['ok'];

        if (!$ok) {
            foreach ($nuevos as $ruta) {
                if (is_file($ruta)) {
                    unlink($ruta);
                }
            }
        }

        return ['ok' => $ok, 'files' => $archivos, 'verify' => $verify];
    }

    /**
     * Una falla es un RESULTADO con veredicto, igual que en los átomos de sólo lectura.
     *
     * @return array{ok: bool, files: list<array{path: string, action: string}>, verify: null, error: string}
     */
    private function falla(string $motivo): array
    {
        return ['ok' => false, 'files' => [], 'verify' => null, 'error' => $motivo];
    }
}
