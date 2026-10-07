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
                effects: EffectProfile::readOnly(),
                description: 'Validate a plugin manifest and the providers it declares',
                handler: [ValidateHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'target' => [
                            'type' => 'string',
                            'description' => 'Local plugin directory, or the path to a milpa.json manifest',
                            'x-milpa-source' => ['tool' => 'artifact:list', 'key' => 'plugin'],
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
                description: 'Scaffold a framework artifact (plugin, page, controller, entity, crud, resource, service, operation, tool or test) and verify it. Something an agent, the terminal and MCP WORK WITH is what=operation: it scaffolds a declared operation, registered in its plugin, whose body you then write with implement. A page a visitor reads is what=page: it scaffolds the public entity and answers with `next`, the screen:declare call that serves it at its route with no HTML of yours',
                handler: [MakeHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    // ORDER matters: a terminal materializer exposes the required properties as
                    // positional arguments in the order they are declared, so `make entity MyPlugin Thing`
                    // is typed the way anyone would expect.
                    'properties' => [
                        'what' => [
                            'type' => 'string',
                            'enum' => ['plugin', 'page', 'controller', 'entity', 'crud', 'resource', 'service', 'operation', 'tool', 'test'],
                            'description' => 'Which artifact. With «plugin», the next two names are the same',
                        ],
                        'plugin' => [
                            'type' => 'string',
                            'description' => 'Target plugin directory: one identifier ^[A-Za-z_][A-Za-z0-9_]*$, no paths. Use an existing directory when adding artifacts. When scaffolding a NEW plugin, use exactly the name the task gives it: no suffix is added. Write scopes are granted per this name (plugins.<plugin>:write).',
                            'x-milpa-source' => ['tool' => 'artifact:list', 'key' => 'plugin'],
                        ],
                        'name' => ['type' => 'string', 'description' => 'The class to create. For what=plugin, the same name as plugin. For what=page, the screen: lowercase letters and digits, starting with a letter'],
                        'fields' => ['type' => 'string', 'description' => 'Comma-separated `name:type` fields, named in English; prefix the name with `?` for nullable. E.g. «<name>:string, ?<name>:date, <name>:bool». Scalar types: string, text, int, bigint, bool, float, decimal, date, datetime, json. «enum:<Class>(case1,case2,…)» GENERATES the enum with those cases (e.g. «<name>:enum:<Class>(<case>,<case>,…)») — always declare the cases so no enum is left dangling. «belongsTo:<Entity>» creates a relation only for entity with --flavor=legacy; runtime resource degrades it to <entity>_id:int and names it in the postconditions; runtime entity and crud must receive the scalar id directly (e.g. «<entity>_id:int»). Scalar modifiers: length («<name>:string:120») or decimal precision («<name>:decimal:10,2»). There is NO «default» and NO «:nullable» — nullability is the `?`'],
                        'route' => ['type' => 'string', 'description' => 'Base route, for page, controller and crud: a literal path such as /<path>, no parameters. Refused where a screen is already mounted'],
                        'returns' => ['type' => 'string', 'enum' => ['page', 'data'], 'description' => 'Required for controller: what its GET route returns. page: a page a visitor reads — no controller is written, the answer is what=page; data: an API'],
                        'entity' => ['type' => 'string', 'description' => 'For page: the entity whose public rows it lists, e.g. <Entity>. With fields it is scaffolded; without, it must exist and declare PUBLIC_WHEN. For operation: the entity of this plugin whose repository its run() receives'],
                        'columns' => ['type' => 'string', 'description' => 'For page: comma-separated fields it shows, in order — the first is the title, the second the body. Default with fields: every field that is not a bool'],
                        'methods' => ['type' => 'string', 'description' => 'Comma-separated methods, for controller'],
                        'table' => ['type' => 'string', 'description' => 'Table name, for entity, crud and resource'],
                        // VISIBILITY IS DECLARED OR IT DOES NOT EXIST (greenhouse decisions/0460). It is
                        // not inferred from a field's NAME: a generator that guesses intent from
                        // vocabulary gets patched per instance and never closes.
                        // `public_when` AND NOT `public-when`: the CLI normalizes a flag's dashes to
                        // underscores before the schema sees it (Application::tokens()), which is why
                        // `tool_name` is spelled that way too. Declared with a dash, the flag arrived
                        // as an undeclared key and the coercer dropped it — the option existed in the
                        // contract, in the handler and in the generator, and did nothing.
                        'public_when' => ['type' => 'string', 'description' => 'For entity, crud, resource and page: a screen can only be bound to an entity that declares it. For crud and resource: the BOOL field that decides a row is public. Declaring it bounds the anonymous reads — a stranger sees only rows where it is true, and a draft answers 404 rather than 403, because a 403 confirms the row exists. A caller this app recognised (the `milpa.auth` request attribute) still sees everything. Omit it and every row is public, drafts included.'],
                        'provides' => ['type' => 'string', 'description' => 'Comma-separated capabilities it provides, for plugin'],
                        'requires' => ['type' => 'string', 'description' => 'Comma-separated capabilities it requires, for plugin'],
                        'interface' => ['type' => 'boolean', 'description' => 'Generate a local <Name>Interface companion for a service. Omit for a plain class; this flag does not select an existing interface.'],
                        'needs' => ['type' => 'string', 'description' => 'Comma-separated classes it receives: the constructor of a tool, the run() of an operation'],
                        'tool_name' => ['type' => 'string', 'description' => 'The name the tool is registered under, instead of the derived one'],
                        'description' => ['type' => 'string', 'description' => 'For tool and operation: what it does, the sentence an agent reads'],
                        'operation' => ['type' => 'string', 'description' => 'For operation: the name it is called by, domain:verb in lower case. Its scope is the domain: <domain>:write, or <domain>:read with reads. For operation, fields are its input (string, int, bool or float)'],
                        'reads' => ['type' => 'boolean', 'description' => 'For operation: it changes nothing. Omit it and the operation is declared as one that writes'],
                        'flavor' => ['type' => 'string', 'description' => 'Force the convention: runtime or legacy, when it is not detected'],
                        'dry_run' => ['type' => 'boolean', 'description' => 'Plan without writing anything'],
                        'no_verify' => ['type' => 'boolean', 'description' => 'Skip the verification'],
                        'force' => ['type' => 'boolean', 'description' => 'Overwrite existing files'],
                    ],
                    'required' => ['what', 'plugin', 'name'],
                ],
                mutating: true,

                // The human names the target (ADR-0044), and a measurement put it here: Q-P20-J measured
                // that a mutating door without a contract is used 8/8 times on an object nobody named.
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
                    new DeclaredCondition(PostconditionVerifier::WRITES_GATED, 'crud, resource: the three mutating routes are declared behind a middleware that exists on disk'),
                    new DeclaredCondition(PostconditionVerifier::READS_BOUNDED, 'crud, resource: both read actions ask one visibility seam, and it honours the declared --public-when field'),
                    new DeclaredCondition(PostconditionVerifier::SERVICE_FILE, 'resource: the service class file exists on disk'),
                    new DeclaredCondition(PostconditionVerifier::SERVICE_REGISTERED, 'resource: the service is registered in the wiring plugin'),
                    new DeclaredCondition(PostconditionVerifier::TEST_FILE, 'resource: the behavioral judge is scaffolded under tests/'),
                    new DeclaredCondition(PostconditionVerifier::OPERATION_FILE, 'operation: the declared operation class exists on disk'),
                    new DeclaredCondition(PostconditionVerifier::OPERATION_REGISTERED, 'operation: its plugin lists it in what operations() returns — unlisted, no catalogue shows it'),
                    new DeclaredCondition(PostconditionVerifier::PLUGIN_REGISTERED, 'advisory — entity, crud, resource: the plugin is listed in config/plugins.php; reported, never failing, because activation is the decision make hands to a human'),
                    new DeclaredCondition(PostconditionVerifier::PREFIX_ENUM, 'dynamic — entity, crud, resource: one check per enum a --fields entry referenced, named enum:<Class>; the enum file must resolve on disk'),
                    new DeclaredCondition(PostconditionVerifier::PREFIX_RELATION, 'dynamic advisory — resource: one check per belongsTo field, named relation:<Entity>; names the scalar id column the relation was degraded to'),
                ],
                artifacts: [
                    'the scaffolded files by kind: plugin wiring, entity, controller, service, operation, tool, test scaffold',
                    'the postcondition report, for entity, crud, resource and operation runs',
                ],
                observableEvidence: 'the files list with per-file actions and, for entity/crud/resource/operation, the postcondition report in the result',
            ),
            // THE STUBS ARE THE APP'S TO OVERRIDE (greenhouse decisions/0216, point 6): `make` reads
            // <root>/stubs/<name> before the package's copy, and this is how a copy gets there.
            new Operation(
                name: 'stubs:publish',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    // Files under stubs/: delete them and make reads the package's again.
                    Reversibility::ManualRecovery,
                    Authority::WriteAsUser,
                    subject: Subject::Executable,
                ),
                description: 'Copy the generators\' stubs into this app\'s stubs/ so make reads your edited copies first; copies already there are kept unless force is given',
                handler: [StubsPublishHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'only' => ['type' => 'string', 'description' => 'Comma-separated stub names to publish (default: every stub the package ships)'],
                        'force' => ['type' => 'boolean', 'description' => 'Overwrite copies already published — your edits to them are lost'],
                    ],
                    'required' => [],
                ],
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'ok' => ['type' => 'boolean'],
                        'dir' => ['type' => 'string'],
                        'published' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'kept' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Already in stubs/ and left untouched'],
                        'hint' => ['type' => 'string'],
                        'error' => ['type' => 'string'],
                    ],
                    'required' => ['ok'],
                ],
                mutating: true,
                surfaces: ['cli', 'tui', 'mcp'],
                observableEvidence: 'stubs/<name> exists after the call, and a make that uses that stub writes what the copy says',
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
                description: 'Write the body of a class that make scaffolded, verified before it lands. '
                    . 'Inline content is capped (MAX_INLINE_BYTES = ' . ImplementHandler::MAX_INLINE_BYTES
                    . ' bytes); over it, write in parts: '
                    . 'mode=start with the first section, mode=append per section, mode=finish to verify and judge. '
                    . 'Repair staging with mode=amend, or restore it from live PHP with mode=reset; '
                    . 'nothing live or verified yet',
                handler: [ImplementHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'plugin' => [
                            'type' => 'string',
                            'description' => 'The plugin directory that owns the class',
                            'x-milpa-source' => ['tool' => 'artifact:list', 'key' => 'plugin'],
                        ],
                        'class' => ['type' => 'string', 'description' => 'The class to fill — one bare identifier, no paths'],
                        'content' => [
                            'type' => 'string',
                            'description' => 'The COMPLETE PHP file (strict_types, the namespace its location dictates, '
                                . 'a class by that name) — or, with mode=start/append, ONE section of it, each at most '
                                . ImplementHandler::MAX_INLINE_BYTES . ' bytes; mode=amend/reset/finish take none',
                        ],
                        'mode' => [
                            'type' => 'string',
                            'enum' => ['start', 'append', 'amend', 'reset', 'finish'],
                            'description' => 'Omit to land the complete file in one call. To land in parts: start '
                                . '(write the header and first section), append (each next section, verbatim), '
                                . 'amend (exact, anchored or line-range edits to current staging by hash; promote before finish), '
                                . 'reset (replace staging with an exact copy of live PHP; promote before editing), '
                                . 'finish (verify and judge the assembled file — only finish claims any green)',
                        ],
                        'expected_sha256' => [
                            'type' => 'string',
                            'pattern' => '^[a-f0-9]{64}$',
                            'description' => 'Required only for mode=amend: current staging SHA-256, returned by start/append/amend. '
                                . 'A stale hash refuses without writing; read the current staging before rebuilding edits',
                        ],
                        'edits' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'description' => 'Required only for mode=amend: ordered staging replacements. Use exact '
                                . '{find, replace}; {before, after, replace} to preserve two unique anchors; or '
                                . '{start_line, end_line, replace} to replace complete 1-based inclusive current lines. '
                                . 'Line-range replacement bytes are verbatim. Total transmitted edit bytes at most '
                                . ImplementHandler::MAX_INLINE_BYTES . '. Never changes live PHP or claims verification',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'find' => ['type' => 'string', 'minLength' => 1],
                                    'before' => ['type' => 'string', 'minLength' => 1],
                                    'after' => ['type' => 'string', 'minLength' => 1],
                                    'start_line' => ['type' => 'integer', 'minimum' => 1],
                                    'end_line' => ['type' => 'integer', 'minimum' => 1],
                                    'replace' => ['type' => 'string'],
                                ],
                                'oneOf' => [
                                    [
                                        'required' => ['find', 'replace'],
                                        'not' => ['anyOf' => [
                                            ['required' => ['before']],
                                            ['required' => ['after']],
                                            ['required' => ['start_line']],
                                            ['required' => ['end_line']],
                                        ]],
                                    ],
                                    [
                                        'required' => ['before', 'after', 'replace'],
                                        'not' => ['anyOf' => [
                                            ['required' => ['find']],
                                            ['required' => ['start_line']],
                                            ['required' => ['end_line']],
                                        ]],
                                    ],
                                    [
                                        'required' => ['start_line', 'end_line', 'replace'],
                                        'not' => ['anyOf' => [
                                            ['required' => ['find']],
                                            ['required' => ['before']],
                                            ['required' => ['after']],
                                        ]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    // `content` is a per-mode obligation the handler enforces with teaching refusals —
                    // finish takes none. plugin and class stay required for every caller.
                    'required' => ['plugin', 'class'],
                ],
                mutating: true,
                // The target is the CLASS. ADR-0044 asked the human to name it; greenhouse
                // decisions/0187 (D-05) refines that: implement MATERIALISES a component the plan
                // implies — WHICH class realises the criterion is the model's interpretive domain, not
                // a human's decision — so it declares `createsNamedTarget`. A reversible WriteAsUser
                // build no longer stops to ask «you did not name TareaService»; a grave op still would
                // (the gate keeps the intent question for the NEVER tier), and selecting an EXISTING
                // target — `edit` below — keeps the flag false and keeps asking.
                namedTarget: 'class',
                createsNamedTarget: true,
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
                description: 'Edit a current scaffolded class — a plugin class or its test — by exact find-replace pairs, verified before it lands. '
                    . 'Total find + replace input is capped at ' . ImplementHandler::MAX_INLINE_BYTES
                    . ' bytes; the current file may be larger. Existing multipart staging is untouched',
                handler: [EditHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'plugin' => [
                            'type' => 'string',
                            'description' => 'The plugin directory that owns the class',
                            'x-milpa-source' => ['tool' => 'artifact:list', 'key' => 'plugin'],
                        ],
                        'class' => ['type' => 'string', 'description' => 'The class to edit — one bare identifier, no paths'],
                        'edits' => [
                            'type' => 'array',
                            'description' => 'Find-replace pairs; each `find` must appear VERBATIM and exactly once in the current file. '
                                . 'Sum of all find and replace bytes must not exceed ' . ImplementHandler::MAX_INLINE_BYTES,
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
                    // IT LEAVES NOTHING THAT LASTS (greenhouse decisions/0523). It used to declare
                    // `persistent` because PHPUnit left its result cache in the house; the run now tells
                    // PHPUnit not to cache, so the operation's own writes die with the process — the same
                    // footing as `serve`, which also runs the app's code. What the app's own tests write
                    // is theirs, as what a served request writes is the request's. This one declaration
                    // is read twice: the terminal runs it unsigned (decisions/0522), and the house's
                    // closure does not count a test run as a change to the house.
                    Mutation::Ephemeral,
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
                        'filter' => ['type' => 'string', 'description' => 'Run only the tests whose name matches'],
                        'path' => ['type' => 'string', 'description' => 'Test file or directory, inside the app root'],
                        'timeout' => ['type' => 'integer', 'description' => 'Seconds before it is stopped (default 300)'],
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
                effects: EffectProfile::readOnly(),
                description: 'Read an artifact\'s contract — an enum\'s cases, a class\'s constructor signature and public methods, what it extends/implements — so you READ a signature instead of provoking an error to learn it',
                handler: [ContractHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'The class or enum to inspect: a bare name (e.g. «<Name>») searches the app plugins; a FQCN with backslashes (e.g. «Milpa\\Data\\RepositoryInterface») resolves through the app autoloader and reaches installed vendor code'],
                        'plugin' => [
                            'type' => 'string',
                            'description' => 'Optional plugin directory to search; omit it to search all plugins',
                            'x-milpa-source' => ['tool' => 'artifact:list', 'key' => 'plugin'],
                        ],
                        'member' => ['type' => 'string', 'description' => 'Narrow the answer: «constructor», «methods», or one method name — a small answer instead of the whole contract'],
                    ],
                    'required' => ['name'],
                ],
                mutating: false,
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'artifact:list',
                effects: EffectProfile::readOnly(),
                description: 'List class, enum, and interface declarations in one or all plugins without loading their bodies',
                handler: [ArtifactListHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'plugin' => [
                            'type' => 'string',
                            'description' => 'Optional plugin directory; omit it to list every plugin',
                            'x-milpa-source' => ['tool' => 'artifact:list', 'key' => 'plugin'],
                        ],
                    ],
                    'required' => [],
                ],
                // List without a filter first: local directories exist before registry enrollment.
                // Greenhouse decisions/0324, evidence/0640.
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'ok' => ['type' => 'boolean'],
                        'artifacts' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'fqcn' => ['type' => 'string'],
                                    'plugin' => ['type' => 'string'],
                                    'kind' => ['type' => 'string'],
                                    'path' => ['type' => 'string'],
                                ],
                                'required' => ['name', 'fqcn', 'plugin', 'kind', 'path'],
                            ],
                        ],
                        'error' => ['type' => 'string'],
                    ],
                    'required' => ['ok', 'artifacts'],
                ],
                mutating: false,
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'test:list',
                effects: EffectProfile::readOnly(),
                description: 'List test classes without running them, optionally filtered by artifact, plugin, or criterion',
                handler: [TestDiscoveryHandler::class, 'handleList'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'artifact' => ['type' => 'string', 'description' => 'Artifact whose conventional <Artifact>Test class should be listed'],
                        'plugin' => [
                            'type' => 'string',
                            'description' => 'Plugin directory whose tests should be listed',
                            'x-milpa-source' => ['tool' => 'artifact:list', 'key' => 'plugin'],
                        ],
                        'criterion' => ['type' => 'string', 'description' => 'Text found in a test method, criterion summary, or assertion name'],
                    ],
                    'required' => [],
                ],
                mutating: false,
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'test:show',
                effects: EffectProfile::readOnly(),
                description: 'Show one test class, its test methods, criteria, and assertion calls without running it',
                handler: [TestDiscoveryHandler::class, 'handleShow'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Test class name or fully qualified class name'],
                        'plugin' => [
                            'type' => 'string',
                            'description' => 'Optional plugin directory used to disambiguate the test class',
                            'x-milpa-source' => ['tool' => 'artifact:list', 'key' => 'plugin'],
                        ],
                    ],
                    'required' => ['name'],
                ],
                mutating: false,
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'test:baseline',
                effects: new EffectProfile(
                    // It runs the suite without a result cache and writes its snapshot OUTSIDE the house,
                    // in the system temp area (greenhouse decisions/0523): nothing the house keeps.
                    Mutation::Ephemeral,
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
                        'snapshot' => ['type' => 'string', 'description' => 'The baseline\'s name — letters, digits, «.», «_», «-» (default «baseline»); it is kept outside the house, in the system temp area'],
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
                    // It reads the baseline and runs the suite without a result cache (decisions/0523).
                    Mutation::Ephemeral,
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
                        'snapshot' => ['type' => 'string', 'description' => 'The name of the baseline to diff against (default «baseline»)'],
                        'timeout' => ['type' => 'integer', 'description' => 'Seconds before it is stopped (default 300)'],
                    ],
                    'required' => [],
                ],
                mutating: true,
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'contract:search',
                effects: EffectProfile::readOnly(),
                description: 'Search class, interface, and enum names across app runtime roots and installed vendor code. Each match includes the scanned declaration’s relative path: pass it unchanged to source_page for documentation (source:page on the CLI). The path identifies a declaration, not the runtime autoloader’s choice.',
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
                effects: EffectProfile::readOnly(),
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
                effects: EffectProfile::readOnly(),
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
                name: 'source:page',
                effects: EffectProfile::readOnly(),
                description: 'Read complete JSON pages of UTF-8 source within the transport result budget; pass next_cursor unchanged to continue, including inside a long line. A cursor grants no permission.',
                handler: [SourcePageHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'Source file inside the app root'],
                        'cursor' => ['type' => 'string', 'description' => 'The previous next_cursor, unchanged; omit to start'],
                        'max_chars' => ['type' => 'integer', 'minimum' => 256, 'description' => 'Explicit JSON result character budget outside a model transport; may only tighten the transport budget'],
                    ],
                    'required' => ['path'],
                ],
                mutating: false,
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'discover',
                effects: EffectProfile::readOnly(),
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
