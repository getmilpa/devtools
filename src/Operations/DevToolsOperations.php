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
use Milpa\Command\DeclaredCondition;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\DevTools\Make\PostconditionVerifier;

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
     * The package's generate, verify, execute, and read-only introspection operations.
     *
     * They are rebuilt on each call rather than cached: an `Operation` is an inert, inexpensive value,
     * while caching it would introduce an invalidation decision this provider does not need.
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
                description: 'Scaffold a framework artifact (plugin, controller, entity, crud, resource, service, tool or test) and verify it',
                handler: [MakeHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    // El ORDEN importa: un materializador de terminal expone lo obligatorio como
                    // argumentos posicionales en el orden en que se declaran las propiedades, así que
                    // `make entity MiPlugin Cosa` se escribe como cualquiera esperaría.
                    'properties' => [
                        'what' => [
                            'type' => 'string',
                            'enum' => ['plugin', 'controller', 'entity', 'crud', 'resource', 'service', 'tool', 'test'],
                            'description' => 'Qué artefacto. Con «plugin», los dos nombres siguientes son el mismo',
                        ],
                        'plugin' => ['type' => 'string', 'description' => 'Identificador del plugin destino: UNA palabra `^[A-Za-z_][A-Za-z0-9_]*$`, sin slashes ni ruta (ej. «Tareas», no «Plugins/Tareas»)'],
                        'name' => ['type' => 'string', 'description' => 'Nombre de la clase a crear'],
                        'fields' => ['type' => 'string', 'description' => 'Campos `nombre:tipo` separados por coma; prefija el nombre con `?` para nullable. Ej: «titulo:string, ?fecha_limite:date, hecha:bool». Tipos: string, text, int, bigint, bool, float, decimal, date, datetime, json, «belongsTo:<Entidad>», y «enum:<Clase>(caso1,caso2,…)» que GENERA el enum con esas cases (ej. «prioridad:enum:PrioridadTarea(baja,media,alta)») — declara siempre las cases para no dejar un enum colgando. Mods de escalar: longitud («titulo:string:120») o precisión en decimal («precio:decimal:10,2»). NO existe «default» ni «:nullable» — la nullabilidad es el `?`. Para entity, crud y resource (donde belongsTo:<Entidad> se degrada a <entidad>_id:int y se nombra en las postcondiciones)'],
                        'route' => ['type' => 'string', 'description' => 'Ruta base, para controller y crud'],
                        'methods' => ['type' => 'string', 'description' => 'Métodos separados por coma, para controller'],
                        'table' => ['type' => 'string', 'description' => 'Nombre de tabla, para entity, crud y resource'],
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

                // THE DECLARED CONTRACT (greenhouse decisions/0183): what must hold before the run,
                // what a completed run proves, and what it leaves behind — declared as data so a
                // caller reads the answer instead of asking a model to invent it.
                //
                // Every precondition here is backed by the handler's refusal, and the contract test
                // violates each one asserting the refusal — declaring what is not enforced is red.
                preconditions: [
                    new DeclaredCondition(
                        'identifier-shaped-names',
                        '`plugin` and `name` match ^[A-Za-z_][A-Za-z0-9_]*$ — no slashes, no dots; '
                            . 'a violating name is refused before any generator runs',
                    ),
                    new DeclaredCondition(
                        'plugin-directory-exists',
                        'legacy flavor only: the target plugin directory exists under plugins/ '
                            . 'before scaffolding inside it — scaffolding the plugin itself is exempt, '
                            . 'and the runtime flavor creates the plugin as part of the run',
                    ),
                ],
                // ONE AUTHORITY: these names ARE PostconditionVerifier's constants — the same source
                // the report emits from — so declaration and report cannot drift. The report stays
                // the per-run truth; the descriptions say which kinds emit which.
                postconditions: [
                    new DeclaredCondition(PostconditionVerifier::ENTITY_FILE, 'entity, crud, resource: the entity class file exists on disk'),
                    new DeclaredCondition(PostconditionVerifier::CONTROLLER_FILE, 'crud, resource: the REST controller file exists on disk'),
                    new DeclaredCondition(PostconditionVerifier::CONTROLLER_REGISTERED, 'crud, resource: the controller is registered in the wiring plugin'),
                    new DeclaredCondition(PostconditionVerifier::REPOSITORY_REGISTERED, 'entity, crud, resource: the entity repository is registered in the wiring plugin'),
                    new DeclaredCondition(PostconditionVerifier::ROUTES_DECLARED, 'crud, resource: all five REST routes are declared in the wiring plugin'),
                    new DeclaredCondition(PostconditionVerifier::SERVICE_FILE, 'resource: the service class file exists on disk'),
                    new DeclaredCondition(PostconditionVerifier::SERVICE_REGISTERED, 'resource: the service is registered in the wiring plugin'),
                    new DeclaredCondition(PostconditionVerifier::TEST_FILE, 'resource: the behavioral judge is scaffolded under tests/'),
                    new DeclaredCondition(PostconditionVerifier::PLUGIN_REGISTERED, 'advisory — entity, crud, resource: the plugin is listed in config/plugins.php; reported, never failing, because activation is the decision make hands to a human'),
                    new DeclaredCondition(PostconditionVerifier::PREFIX_ENUM, 'dynamic — entity, crud, resource: one check per enum a --fields entry referenced, named enum:<Class>; the enum file must resolve on disk'),
                    new DeclaredCondition(PostconditionVerifier::PREFIX_RELATION, 'dynamic advisory — resource: one check per belongsTo field, named relation:<Entity>; names the scalar id column the relation was degraded to'),
                ],
                artifacts: [
                    'the scaffolded files by kind: plugin wiring, entity, controller, service, tool, test scaffold',
                    'the postcondition report, for entity, crud and resource runs',
                ],
                observableEvidence: 'the files list with per-file actions and, for entity/crud/resource, the postcondition report in the result',
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

                // THE DECLARED CONTRACT: both preconditions are enforced by the handler with a
                // refusal, and the contract test violates each one asserting it.
                preconditions: [
                    new DeclaredCondition(
                        'phpunit-installed',
                        'vendor/bin/phpunit exists under the app root — without it the handler '
                            . 'refuses with the composer line that installs it, and `ran` stays false',
                    ),
                    new DeclaredCondition(
                        'path-inside-root',
                        'a `path`, when given, must exist and resolve inside the app root — one '
                            . 'that escapes is refused before any command is built',
                    ),
                ],
                observableEvidence: 'the verdict in the result: `ok` is the PHPUnit exit code — what a CI reads — with the parsed counts and the tail of the output',
            ),
            new Operation(
                name: 'artifact:contract',
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'Read an artifact\'s contract — an enum\'s cases, a class\'s constructor signature and public methods, what it extends/implements — so you READ a signature instead of provoking an error to learn it',
                handler: [ContractHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'The class or enum to inspect: a bare name (e.g. «Tarea») searches the app plugins; a FQCN with backslashes (e.g. «Milpa\\Data\\RepositoryInterface») resolves through the app autoloader and reaches installed vendor code'],
                        'plugin' => ['type' => 'string', 'description' => 'El plugin donde buscar — opcional; si se omite, busca en todos'],
                        'member' => ['type' => 'string', 'description' => 'Narrow the answer: «constructor», «methods», or one method name — a small answer instead of the whole contract'],
                    ],
                    'required' => ['name'],
                ],
                mutating: false,
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'artifact:list',
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'List class, enum, and interface declarations in one or all plugins without loading their bodies',
                handler: [ArtifactListHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'plugin' => ['type' => 'string', 'description' => 'Optional plugin identifier; omit it to list every plugin'],
                    ],
                    'required' => [],
                ],
                mutating: false,
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'test:list',
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'List test classes without running them, optionally filtered by artifact, plugin, or criterion',
                handler: [TestDiscoveryHandler::class, 'handleList'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'artifact' => ['type' => 'string', 'description' => 'Artifact whose conventional <Artifact>Test class should be listed'],
                        'plugin' => ['type' => 'string', 'description' => 'Plugin whose tests should be listed'],
                        'criterion' => ['type' => 'string', 'description' => 'Text found in a test method, criterion summary, or assertion name'],
                    ],
                    'required' => [],
                ],
                mutating: false,
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'test:show',
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'Show one test class, its test methods, criteria, and assertion calls without running it',
                handler: [TestDiscoveryHandler::class, 'handleShow'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Test class name or fully qualified class name'],
                        'plugin' => ['type' => 'string', 'description' => 'Optional plugin used to disambiguate the test class'],
                    ],
                    'required' => ['name'],
                ],
                mutating: false,
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'test:baseline',
                effects: new EffectProfile(
                    // It runs the suite (phpunit leaves its cache) AND writes a snapshot file.
                    Mutation::Persistent,
                    // Same ceiling as `test`: it runs the app's own suite, code this operation does not
                    // control; at worst those tests reach the public internet.
                    Externality::Public,
                    Reversibility::ManualRecovery,
                    Authority::WriteAsUser,
                    // It runs code and records what it observed; it does not change which code runs.
                    subject: Subject::Data,
                ),
                description: 'Run the suite and record which tests pass and fail as a baseline to diff against later',
                handler: [TestBaselineHandler::class, 'handleBaseline'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'filter' => ['type' => 'string', 'description' => 'Run only the tests whose name matches'],
                        'snapshot' => ['type' => 'string', 'description' => 'Where to write the baseline, inside the app root (default .milpa/test-baseline.json)'],
                        'timeout' => ['type' => 'integer', 'description' => 'Seconds before it is stopped (default 300)'],
                    ],
                    'required' => [],
                ],
                mutating: true,
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'test:delta',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::Public,
                    Reversibility::ManualRecovery,
                    Authority::WriteAsUser,
                    subject: Subject::Data,
                ),
                description: 'Run the suite again and report new, resolved, and unchanged failures against the recorded baseline',
                handler: [TestBaselineHandler::class, 'handleDelta'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'filter' => ['type' => 'string', 'description' => 'Run only the tests whose name matches'],
                        'snapshot' => ['type' => 'string', 'description' => 'The baseline to diff against, inside the app root (default .milpa/test-baseline.json)'],
                        'timeout' => ['type' => 'integer', 'description' => 'Seconds before it is stopped (default 300)'],
                    ],
                    'required' => [],
                ],
                mutating: true,
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'contract:search',
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'Search class, interface, and enum names across the app plugins AND installed vendor code — find the right name to ask for before guessing an API',
                handler: [ContractSearchHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'q' => ['type' => 'string', 'description' => 'Name fragment to match, case-insensitive (e.g. «Repository»); end with a backslash to match whole namespaces (e.g. «Milpa\\Data\\»)'],
                        'package' => ['type' => 'string', 'description' => 'Optional «vendor/name» package that narrows the search to its code (e.g. «milpa/data»)'],
                    ],
                    'required' => ['q'],
                ],
                mutating: false,
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'package:artifacts',
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'List the classes, interfaces, and enums an installed package declares through its autoload roots',
                handler: [PackageArtifactsHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'package' => ['type' => 'string', 'description' => 'The installed package to enumerate, as «vendor/name» (e.g. «milpa/data»)'],
                    ],
                    'required' => ['package'],
                ],
                mutating: false,
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'source:read',
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'Read a slice of one source file inside the app root — read first, so an edit can find-replace verbatim text instead of reconstructing the file from memory',
                handler: [SourceReadHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'The file to read, relative to the app root (or absolute inside it)'],
                        'from' => ['type' => 'integer', 'description' => '1-based line to start from (default 1)'],
                        'lines' => ['type' => 'integer', 'description' => 'How many lines to return (default 120, max 400)'],
                    ],
                    'required' => ['path'],
                ],
                mutating: false,
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'discover',
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'Find anything by one query — artifacts, contracts, tests, packages — through the existing finders, answered as ONE row shape where each row names the exact operation call that answers in full',
                handler: [DiscoverHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'What to find: a name fragment (matched like contract:search matches), or «vendor/name» to reach an installed package'],
                        'kinds' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'enum' => DiscoverHandler::KINDS],
                            'description' => 'Narrow the search to a subset of kinds; omit it to search them all',
                        ],
                    ],
                    'required' => ['query'],
                ],
                mutating: false,
                surfaces: ['cli', 'tui', 'mcp'],

                // THE DECLARED CONTRACT (greenhouse decisions/0183): each precondition below is one
                // the handler enforces with a refusal, tied by the discover falsifiers that violate
                // each and assert it.
                preconditions: [
                    new DeclaredCondition(
                        'query-named',
                        'a non-empty `query` names what to find — an empty one is refused asking for one',
                    ),
                    new DeclaredCondition(
                        'kinds-valid',
                        '`kinds`, when given, is a non-empty subset of artifact, contract, test, package '
                            . '— an unknown kind is refused naming that valid set',
                    ),
                ],
                observableEvidence: 'the found rows in the result: each row is {kind, identity, path?, detail} where detail names a declared operation call that answers in full; an empty found still names the queried kinds',
            ),
        ];
    }
}
