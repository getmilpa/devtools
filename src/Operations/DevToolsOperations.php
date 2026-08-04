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
                            'enum' => ['plugin', 'controller', 'entity', 'crud', 'service', 'tool'],
                            'description' => 'Qué artefacto. Con «plugin», los dos nombres siguientes son el mismo',
                        ],
                        'plugin' => ['type' => 'string', 'description' => 'Directorio del plugin destino'],
                        'name' => ['type' => 'string', 'description' => 'Nombre de la clase a crear'],
                        'fields' => ['type' => 'string', 'description' => 'DSL de campos, para entity y crud'],
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
                name: 'test',
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
