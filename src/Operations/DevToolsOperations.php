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
 * Porque no tiene nada de ese host. `validate` corre los dos validadores de este paquete sobre un
 * manifiesto; `make` corre sus generadores y su verificador. Ninguno de los dos consulta una base,
 * un registry ni un servicio del anfitrión — sólo la raíz del proyecto, que se inyecta.
 *
 * Vivían en `src/app/Operations/` del host que los estrenó, y ahí no molestaban a nadie salvo por
 * una cosa: **el siguiente host tendría que volver a escribirlos**. Un `composer create-project` que
 * arranca sin poder andamiar ni validar nada obliga a copiar dos handlers antes de hacer la primera
 * cosa útil, y esa copia es la que después diverge.
 *
 * Un host los adopta enlistando esta clase; los recibe en TODAS sus superficies, porque un átomo se
 * declara una vez y cada projector lo materializa a su modo.
 *
 * ── LA POLÍTICA DE CONSENTIMIENTO VIAJA CON LA OPERACIÓN ────────────────────────────────────────
 *
 * `validate` lee. `make` escribe archivos y lo DECLARA, pero no exige firma: su daño ya está acotado
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
     * Los dos átomos que este paquete publica: validar un plugin y andamiar un artefacto.
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
                description: 'Valida el manifiesto de un plugin y los proveedores que declara',
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
                description: 'Andamia un artefacto del framework (controller o entity) y lo verifica',
                handler: [MakeHandler::class, 'handle'],
                inputSchema: [
                    'type' => 'object',
                    // El ORDEN importa: un materializador de terminal expone lo obligatorio como
                    // argumentos posicionales en el orden en que se declaran las propiedades, así que
                    // `make entity MiPlugin Cosa` se escribe como cualquiera esperaría.
                    'properties' => [
                        'what' => ['type' => 'string', 'enum' => ['controller', 'entity'], 'description' => 'Qué artefacto'],
                        'plugin' => ['type' => 'string', 'description' => 'Directorio del plugin destino'],
                        'name' => ['type' => 'string', 'description' => 'Nombre de la clase a crear'],
                        'fields' => ['type' => 'string', 'description' => 'DSL de campos, para entity'],
                        'route' => ['type' => 'string', 'description' => 'Ruta base, para controller'],
                        'methods' => ['type' => 'string', 'description' => 'Métodos separados por coma, para controller'],
                        'table' => ['type' => 'string', 'description' => 'Nombre de tabla, para entity'],
                        'dry_run' => ['type' => 'boolean', 'description' => 'Planea sin escribir nada'],
                        'no_verify' => ['type' => 'boolean', 'description' => 'No corras la verificación'],
                        'force' => ['type' => 'boolean', 'description' => 'Sobrescribe archivos existentes'],
                    ],
                    'required' => ['what', 'plugin', 'name'],
                ],
                mutating: true,
            ),
        ];
    }
}
