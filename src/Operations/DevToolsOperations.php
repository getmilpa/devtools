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

use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;

/**
 * El bucle de desarrollo —andamiar y validar— como átomos, para cualquier host.
 *
 * ── POR QUÉ ESTO VIVE EN EL PAQUETE Y NO EN UN HOST ─────────────────────────────────────────────
 *
 * Porque no tienen nada de ese host. `validate` corre los dos validadores de este paquete sobre un
 * manifiesto; `make` corre sus generadores y su verificador; `test` saca la suite del anfitrión en su
 * propio proceso. Ninguno consulta una base, un registry ni un servicio del anfitrión — sólo la raíz
 * del proyecto, que se inyecta.
 *
 * Vivían en `src/app/Operations/` del host que los estrenó, y ahí no molestaban a nadie salvo por
 * una cosa: **el siguiente host tendría que volver a escribirlos**. Un `composer create-project` que
 * arranca sin poder andamiar, validar ni probar nada obliga a copiar tres handlers antes de hacer
 * la primera cosa útil, y esa copia es la que después diverge.
 *
 * Un host los adopta enlistando esta clase; los recibe en TODAS sus superficies, porque un átomo se
 * declara una vez y cada projector lo materializa a su modo.
 *
 * ── LA POLÍTICA DE CONSENTIMIENTO VIAJA CON LA OPERACIÓN ────────────────────────────────────────
 *
 * `validate` lee. `test` ejecuta el código del proyecto y lo declara `mutating` por eso, y se ofrece
 * en la terminal, el TUI y el agente pero NO por HTTP — una petición web que dispara la suite es una
 * superficie que nadie quiso. `make` escribe archivos y lo DECLARA, pero no exige firma: su daño ya está acotado
 * por piezas más finas que una firma —`WriteGuard` se niega a sobrescribir salvo `--force`, un
 * permiso que nombra el archivo, y un verify fallido borra lo recién creado—. Pedir firma para
 * andamiar un controller convertiría la compuerta en trámite, y una compuerta que se pide siempre se
 * aprueba sin leer.
 *
 * Que la política venga DECLARADA en el paquete y no la ponga cada host es el punto: dos hosts que
 * decidieran distinto sobre la misma capacidad serían dos respuestas a una pregunta que sólo tiene
 * una.
 */
final class DevToolsOperations implements CommandProvider
{
    /**
     * Los TRES átomos que este paquete publica: validar un plugin, andamiar un artefacto y correr la
     * suite de pruebas de la app.
     *
     * Los tres son un lazo: se escribe, se revisa que cumpla la convención, y se ejecuta para saber si
     * además HACE lo que debía. Sin el tercero el lazo se cierra en la forma y no en el
     * comportamiento — y quien sólo tenga los dos primeros aprende a confiar en la mitad barata.
     *
     * Se construyen en cada llamada y no se cachean: una `Operation` es un valor inerte y barato, y
     * guardarla obligaría a decidir cuándo invalidarla — una pregunta que no tiene por qué existir.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'validate',
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'Validate a plugin manifest and the providers it declares',
                handler: [ValidateHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'target' => [
                            'type' => 'string',
                            'description' => 'Nombre del plugin, o ruta a un milpa.json',
                        ],
                    ],
                    'required' => ['target'],
                ],
                mutating: false,
            ),
            new Operation(
                name: 'make',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    // Local disk only. It writes files and SUGGESTS the registration; it never runs
                    // the package manager and never touches the boot list itself.
                    Externality::None,
                    // The files can be deleted by hand, and nothing tracks what a half-finished
                    // scaffold left behind.
                    Reversibility::ManualRecovery,
                    // Writing in the caller's own tree, with the caller's own reach. It does not
                    // change what this app is ALLOWED to do — that distinction is what keeps
                    // routine development from demanding a signature per call.
                    Authority::WriteAsUser,
                    escalatesOn: ['plugin'],
                    // New classes in the app's own tree: the set of things this app can execute is
                    // different afterwards, even though nothing boots until someone declares it.
                    subject: Subject::Executable,
                ),
                description: 'Scaffold a framework artifact (plugin, controller, entity, crud, service or tool) and verify it',
                handler: [MakeHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    // El ORDEN importa: un materializador de terminal expone lo obligatorio como
                    // argumentos posicionales en el orden en que se declaran las propiedades, así que
                    // `make entity MiPlugin Cosa` se escribe como cualquiera esperaría.
                    'properties' => [
                        'what' => [
                            'type' => 'string',
                            'enum' => ['plugin', 'controller', 'entity', 'crud', 'service', 'tool', 'test'],
                            'description' => 'Qué artefacto. Con «plugin», los dos nombres siguientes son el mismo',
                        ],
                        'plugin' => ['type' => 'string', 'description' => 'Identificador del plugin destino: UNA palabra `^[A-Za-z_][A-Za-z0-9_]*$`, sin slashes ni ruta (ej. «Tareas», no «Plugins/Tareas»)'],
                        'name' => ['type' => 'string', 'description' => 'Nombre de la clase a crear'],
                        'fields' => ['type' => 'string', 'description' => 'Campos `nombre:tipo` separados por coma; prefija el nombre con `?` para nullable. Ej: «titulo:string, ?fecha_limite:date, hecha:bool». Tipos: string, text, int, bigint, bool, float, decimal, date, datetime, json, «belongsTo:<Entidad>», y «enum:<Clase>(caso1,caso2,…)» que GENERA el enum con esas cases (ej. «prioridad:enum:PrioridadTarea(baja,media,alta)») — declara siempre las cases para no dejar un enum colgando. Mods de escalar: longitud («titulo:string:120») o precisión en decimal («precio:decimal:10,2»). NO existe «default» ni «:nullable» — la nullabilidad es el `?`. Para entity y crud'],
                        'route' => ['type' => 'string', 'description' => 'Ruta base, para controller y crud'],
                        'methods' => ['type' => 'string', 'description' => 'Métodos separados por coma, para controller'],
                        'table' => ['type' => 'string', 'description' => 'Nombre de tabla, para entity y crud'],
                        'provides' => ['type' => 'string', 'description' => 'Capacidades que ofrece, separadas por coma, para plugin'],
                        'requires' => ['type' => 'string', 'description' => 'Capacidades que necesita, separadas por coma, para plugin'],
                        'interface' => ['type' => 'string', 'description' => 'Interfaz que el servicio implementa, para service'],
                        'needs' => ['type' => 'string', 'description' => 'Dependencias que el tool recibe, separadas por coma, para tool'],
                        'tool_name' => ['type' => 'string', 'description' => 'Nombre con el que se registra el tool, si no el derivado'],
                        'description' => ['type' => 'string', 'description' => 'Descripción del tool, la que lee un agente'],
                        'flavor' => ['type' => 'string', 'description' => 'Fuerza la convención: runtime o legacy, si no se detecta'],
                        'dry_run' => ['type' => 'boolean', 'description' => 'Planea sin escribir nada'],
                        'no_verify' => ['type' => 'boolean', 'description' => 'No corras la verificación'],
                        'force' => ['type' => 'boolean', 'description' => 'Sobrescribe archivos existentes'],
                    ],
                    'required' => ['what', 'plugin', 'name'],
                ],
                mutating: true,

                // El objetivo lo nombra el humano (ADR-0044), y lo puso una medición: Q-P20-J midió que
                // una puerta que muta sin contrato se usa 8/8 veces sobre un objeto que nadie nombró.
                namedTarget: 'plugin',
            ),
            new Operation(
                name: 'implement',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    Reversibility::ManualRecovery,
                    Authority::WriteAsUser,
                    escalatesOn: ['class'],
                    subject: Subject::Executable,
                ),
                description: 'Write the body of a class that make scaffolded, verified before it lands',
                handler: [ImplementHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'plugin' => ['type' => 'string', 'description' => 'The plugin directory that owns the class'],
                        'class' => ['type' => 'string', 'description' => 'The class to fill — one bare identifier, no paths'],
                        'content' => ['type' => 'string', 'description' => 'The COMPLETE PHP file: strict_types, the namespace its location dictates, and a class by that name'],
                    ],
                    'required' => ['plugin', 'class', 'content'],
                ],
                mutating: true,
                // The target is named by the human (ADR-0044), and here the target is THE CLASS: a
                // request that does not name it is exactly the one that should not be filling it.
                namedTarget: 'class',
            ),
            new Operation(
                name: 'edit',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    Reversibility::ManualRecovery,
                    Authority::WriteAsUser,
                    escalatesOn: ['class'],
                    subject: Subject::Executable,
                ),
                description: 'Edit a scaffolded class by exact find-replace pairs, verified before it lands',
                handler: [EditHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'plugin' => ['type' => 'string', 'description' => 'The plugin directory that owns the class'],
                        'class' => ['type' => 'string', 'description' => 'The class to edit — one bare identifier, no paths'],
                        'edits' => [
                            'type' => 'array',
                            'description' => 'Find-replace pairs; each `find` must appear VERBATIM and exactly once in the current file',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'find' => ['type' => 'string', 'description' => 'Exact text as it appears in the file today'],
                                    'replace' => ['type' => 'string', 'description' => 'What takes its place'],
                                ],
                                'required' => ['find', 'replace'],
                            ],
                        ],
                    ],
                    'required' => ['plugin', 'class', 'edits'],
                ],
                mutating: true,
                // Same contract as implement: the target is THE CLASS, named by the human.
                namedTarget: 'class',
            ),
            new Operation(
                name: 'test',
                effects: new EffectProfile(
                    // phpunit leaves its cache behind.
                    Mutation::Persistent,
                    // THE CEILING, NOT THE TYPICAL CASE: this runs the app's own suite, which is code
                    // this operation does not control and cannot inspect. At worst those tests reach
                    // the public internet, so that is what the ceiling says.
                    Externality::Public,
                    Reversibility::ManualRecovery,
                    Authority::WriteAsUser,
                    // It RUNS code; it does not change which code runs. The distinction is the whole
                    // point of this dimension.
                    subject: Subject::Data,
                ),
                description: 'Run this app test suite and return the verdict',
                handler: [TestHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'filter' => ['type' => 'string', 'description' => 'Corre sólo las pruebas cuyo nombre casa'],
                        'path' => ['type' => 'string', 'description' => 'Archivo o directorio de pruebas, dentro de la raíz'],
                        'timeout' => ['type' => 'integer', 'description' => 'Segundos antes de detenerla (por defecto 300)'],
                    ],
                    'required' => [],
                ],
                // Correr la suite EJECUTA el código del proyecto: fixtures que escriben, migraciones de
                // prueba, lo que las pruebas hagan. Declararla inocua sería mentir sobre eso. No pide
                // firma por lo mismo que `make`: quien puede llegar hasta aquí ya puede escribir
                // archivos, así que una compuerta en este punto se pediría siempre y se aprobaría sin
                // leer, mientras el permiso que de verdad importa quedó una capa antes.
                mutating: true,
                // Y NO se ofrece por HTTP. Una petición web que dispara la suite de la app es una
                // superficie que nadie quiso: en desarrollo sobra —ahí está la terminal— y en algo
                // desplegado es una forma de tumbar el proceso desde fuera. La terminal, el TUI y el
                // agente son los tres lugares donde alguien está construyendo algo y necesita saber si
                // sirve.
                surfaces: ['cli', 'tui', 'mcp'],
            ),
        ];
    }
}
