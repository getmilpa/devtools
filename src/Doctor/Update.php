<?php

/**
 * This file is part of milpa/devtools — scaffolding and diagnosis for the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Doctor;

/**
 * Actualizar las distribuciones de una app, y comprobar después que sigue en pie.
 *
 * ── EL MISMO ARCO QUE {@see Repair}, CON OTRO VERBO ─────────────────────────────────────────────
 *
 * Reparar trae lo que falta; actualizar mueve lo que ya está. Las dos cosas terminan igual y por la
 * misma razón: que composer salga en 0 es una afirmación del subproceso **sobre sí mismo**, y que las
 * versiones hayan cambiado no es que la app siga arrancando. Los dos hechos se comprueban donde
 * existen — el disco y un arranque de verdad.
 *
 * ── EN SECO PRIMERO, Y ESO NO ES UNA CORTESÍA ───────────────────────────────────────────────────
 *
 * Actualizar es la operación menos reversible que un humano hace a la ligera: cambia todo el grafo de
 * una vez. Decir QUÉ cambiaría antes de tocar nada es lo que la vuelve una decisión en vez de una
 * apuesta — y `composer update --dry-run` ya lo sabe; lo único que faltaba era pedírselo.
 *
 * ── LO QUE NO HACE, Y ES DELIBERADO ─────────────────────────────────────────────────────────────
 *
 * **No decide que una actualización «es segura» por su número de versión.** Semver es una promesa del
 * autor, no una medición: un patch puede romper y un minor puede no tocar nada. Lo único que esta app
 * puede afirmar de verdad es que **después de actualizar seguía arrancando**, y eso es lo que dice.
 * Poner un semáforo verde sobre un rango de versiones sería el certificado sustituto de siempre.
 *
 * **Y no revierte solo.** Deshacer lo que el humano pidió es otra decisión; lo que sí hace es decir
 * dónde está el punto de retorno —`composer.lock`— en vez de dejarlo adivinando.
 */
final class Update
{
    /**
     * Actualiza las distribuciones `milpa/*` de esta app, o las que se le nombren.
     *
     * @param null|list<string>                                     $paquetes qué actualizar; `null` es
     *                                                                        todo lo de la familia
     * @param null|callable(string): array{0: int, 1: list<string>} $corredor costura de prueba: lo que
     *                                                                        una prueba no puede
     *                                                                        arreglar, lo inyecta
     *
     * @return array<string, mixed>
     */
    public static function apply(
        string $raiz,
        bool $seco = false,
        ?array $paquetes = null,
        ?callable $corredor = null,
    ): array {
        // POR DEFECTO LA FAMILIA, NO TODO. `composer update` a secas mueve también las dependencias
        // de terceros, y eso es una decisión distinta que nadie pidió al escribir `coa update`.
        $objetivo = $paquetes === null || $paquetes === []
            ? "'milpa/*'"
            : implode(' ', array_map(static fn (string $p): string => escapeshellarg($p), $paquetes));

        $comando = 'composer update ' . $objetivo;

        $correr = $corredor ?? static function (string $cmd) use ($raiz): array {
            $salida = [];
            $codigo = 1;
            exec('cd ' . escapeshellarg($raiz) . ' && ' . $cmd . ' 2>&1', $salida, $codigo);

            return [$codigo, $salida];
        };

        $antes = self::versiones($raiz);

        if ($seco) {
            [$codigo, $salida] = $correr($comando . ' --dry-run --no-interaction');

            return [
                'ok' => $codigo === 0,
                'dry_run' => true,
                'command' => $comando,
                // LA SALIDA DE COMPOSER TAL CUAL: es quien sabe qué resolvería, y resumirla sería
                // contar de segunda mano lo que se puede leer de primera.
                'would' => $salida,
            ];
        }

        [$codigo, $salida] = $correr($comando . ' --no-interaction');

        if ($codigo !== 0) {
            return [
                'ok' => false,
                'command' => $comando,
                'error' => implode("\n", \array_slice($salida, -12)),
            ];
        }

        // QUÉ SE MOVIÓ DE VERDAD, leído del disco y no de lo que composer contó. Es el mismo criterio
        // que en `Repair`: el hecho vive en `installed.json`.
        $despues = self::versiones($raiz);
        $cambios = [];
        foreach ($despues as $nombre => $version) {
            $previa = $antes[$nombre] ?? null;
            if ($previa !== $version) {
                $cambios[$nombre] = ['from' => $previa, 'to' => $version];
            }
        }

        [$codigoArranque, $salidaArranque] = $correr(escapeshellarg(PHP_BINARY) . ' bin/coa doctor');

        if ($codigoArranque === 0) {
            return ['ok' => true, 'command' => $comando, 'changed' => $cambios, 'boots' => true];
        }

        // UNA ACTUALIZACIÓN QUE DEJA LA APP SIN ARRANCAR NO ESTÁ TERMINADA, y por eso el `ok` se cae.
        // Los dos hechos se dicen por separado porque son distintos y quien lea esto necesita los dos:
        // las versiones SÍ se movieron, y aun así el grafo dejó de cerrar.
        return [
            'ok' => false,
            'command' => $comando,
            'changed' => $cambios,
            'boots' => false,
            'error' => 'las versiones se actualizaron y esta app ya no arranca',
            'boot_error' => implode("\n", \array_slice($salidaArranque, -12)),
            'hint' => 'el punto de retorno es composer.lock: `git checkout composer.lock && composer install`',
        ];
    }

    /**
     * Qué versión tiene instalada cada paquete, según el disco.
     *
     * @return array<string, string>
     */
    private static function versiones(string $raiz): array
    {
        $archivo = $raiz . '/vendor/composer/installed.json';
        if (!is_file($archivo)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($archivo), true);
        if (!\is_array($json)) {
            return [];
        }

        $paquetes = \is_array($json['packages'] ?? null) ? $json['packages'] : $json;

        $versiones = [];
        foreach ($paquetes as $paquete) {
            if (\is_array($paquete) && \is_string($paquete['name'] ?? null) && \is_string($paquete['version'] ?? null)) {
                $versiones[$paquete['name']] = $paquete['version'];
            }
        }

        return $versiones;
    }
}
