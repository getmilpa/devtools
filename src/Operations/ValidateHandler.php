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

use Milpa\DevTools\Support\RootResolver;
use Milpa\DevTools\Validators\PluginManifestValidator;
use Milpa\DevTools\Validators\ProviderImplementsValidator;

/**
 * Valida un plugin de punta a punta: la forma de su manifiesto y que los servicios que declara
 * realmente implementen sus interfaces.
 *
 * Es el mismo trabajo que hacía `coa:validate`, sin la mitad que pintaba. El comando anterior tenía
 * dos renderers adentro —una versión humana con marcas de color y una `--json` con su propia
 * forma— y decidía entre ellos con un `if` sobre una bandera. Eso es un comando que conoce a sus
 * consumidores: para agregar una superficie había que volver a este archivo.
 *
 * Aquí no hay formato. Devuelve estructura y quien materialice decide cómo se ve, que es la razón
 * por la que la bandera `--json` desapareció en vez de mudarse.
 */
final class ValidateHandler
{
    public function __construct(private readonly RootResolver $roots = new RootResolver())
    {
    }

    /**
     * Corre los dos validadores sobre un manifiesto y reporta qué encontró cada uno.
     *
     * El manifiesto no encontrado NO es una excepción: es un resultado con `ok: false` y el motivo,
     * porque «miré y no está» es una observación válida sobre el objetivo que se pidió. Lanzar
     * obligaría a cada superficie a traducir la excepción a su forma, y sería la superficie
     * decidiendo qué significa un archivo ausente.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, target: string, manifest: string, checks?: array<string, array{ok: bool, findings: list<string>}>, error?: string}
     */
    public function handle(array $input): array
    {
        $target = \is_string($input['target'] ?? null) ? $input['target'] : '';
        if ($target === '') {
            return ['ok' => false, 'target' => '', 'manifest' => '', 'error' => 'falta `target`: el nombre de un plugin o la ruta de un milpa.json'];
        }

        $manifest = is_file($target)
            ? $target
            : $this->roots->resolve() . '/plugins/' . $target . '/milpa.json';

        if (!is_file($manifest)) {
            return [
                'ok' => false,
                'target' => $target,
                'manifest' => $manifest,
                'error' => "no hay manifiesto para '{$target}' (se buscó en {$manifest})",
            ];
        }

        $forma = (new PluginManifestValidator())->validate($manifest);
        $proveedores = (new ProviderImplementsValidator())->validate([$manifest]);

        return [
            'ok' => $forma->ok() && $proveedores->ok(),
            'target' => $target,
            'manifest' => $manifest,
            'checks' => [
                'manifest' => ['ok' => $forma->ok(), 'findings' => $forma->errors],
                'providers' => ['ok' => $proveedores->ok(), 'findings' => $proveedores->violations],
            ],
        ];
    }
}
