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
 * Aplicar la reparación que {@see AppDoctor} recomendó — sin necesitar que la app arranque.
 *
 * ── POR QUÉ VIVE JUNTO AL DOCTOR ────────────────────────────────────────────────────────────────
 *
 * Porque son la misma conversación. El doctor dice qué falta y de dónde sacarlo; esto lo trae. Vivían
 * en paquetes distintos —el diagnóstico aquí, la reparación en `milpa/framework`— y eso dejaba la
 * reparación fuera del alcance de toda app que no se hubiera creado desde esa plantilla: **la que más
 * la necesitaba, un host viejo con el grafo abierto, era justo la que no la tenía.**
 *
 * ── Y POR QUÉ NO PUEDE SER UNA OPERACIÓN ────────────────────────────────────────────────────────
 *
 * Una operación se despacha con el kernel arriba, y esto existe para el caso en que el kernel NO
 * levanta. Medido el 2026-08-04 en un host con una capacidad requerida sin proveedor:
 *
 *     coa coa:doctor   → [Initialization Error] MILPA_CAPABILITY_MISSING
 *     coa repair       → [Initialization Error] MILPA_CAPABILITY_MISSING
 *     coa <cualquiera> → [Initialization Error] MILPA_CAPABILITY_MISSING
 *
 * Es el argumento que el doctor ya había ganado —«la herramienta que explica por qué algo no arranca
 * no puede necesitar que arranque»— un paso más adelante: la que lo arregla, tampoco.
 *
 * ── LO QUE DECIDE, Y ES LO QUE LA DEFINE ────────────────────────────────────────────────────────
 *
 * **La puerta sólo abre para lo que el diagnóstico de HOY pidió**, recalculado en cada llamada: una
 * acción de hace diez minutos puede describir un problema que ya no existe, y aplicarla sería reparar
 * una foto. Sin esa restricción sería un instalador general con nombre de reparación — la puerta ancha
 * que después nadie se atreve a cerrar.
 *
 * El paquete NO sale de un catálogo escrito a mano: sale del diagnóstico, que lo derivó del grafo. Un
 * catálogo aquí sería una tercera lista que puede envejecer aparte de las otras dos.
 *
 * ── LAS DOS COSAS QUE NO SE DAN POR HECHAS ──────────────────────────────────────────────────────
 *
 * Que composer salga en 0 no es que el paquete haya llegado: se relee `installed.json`, que es donde
 * el hecho existe. Y que haya llegado no es que la app siga en pie: se corre el diagnóstico **en un
 * proceso nuevo**, porque el autoloader de éste se armó antes de que composer escribiera nada y
 * contestaría sobre el mundo de hace un minuto con cara de actual.
 */
final class Repair
{
    /**
     * Aplica una de las reparaciones que el diagnóstico recomienda, y verifica que la app siga en pie.
     *
     * @param null|list<string>                                     $recomendados costura de prueba
     * @param null|callable(string): array{0: int, 1: list<string>} $corredor     costura de prueba: lo
     *                                                                            que una prueba no
     *                                                                            puede arreglar, lo
     *                                                                            inyecta — y nombrado
     *
     * @return array<string, mixed>
     */
    public static function apply(
        string $raiz,
        string $paquete,
        bool $seco = false,
        ?array $recomendados = null,
        ?callable $corredor = null,
    ): array {
        $paquete = trim($paquete);
        if ($paquete === '') {
            return ['ok' => false, 'error' => 'falta `package`: cuál de las reparaciones que el doctor recomienda'];
        }

        $recomendados ??= self::recommendedPackages($raiz);

        if (!\in_array($paquete, $recomendados, true)) {
            return [
                'ok' => false,
                'error' => $recomendados === []
                    ? "el diagnóstico no recomienda instalar nada, así que «{$paquete}» no es una reparación"
                    : "«{$paquete}» no está entre lo que el diagnóstico recomienda",
                // LO QUE SÍ, para que la negativa no sea un callejón.
                'recommended' => $recomendados,
                'hint' => 'corre `coa doctor` y usa el paquete que nombra su `action`',
            ];
        }

        $comando = 'composer require ' . escapeshellarg($paquete);

        if ($seco) {
            return ['ok' => true, 'package' => $paquete, 'command' => $comando, 'dry_run' => true];
        }

        $correr = $corredor ?? static function (string $cmd) use ($raiz): array {
            $salida = [];
            $codigo = 1;
            exec('cd ' . escapeshellarg($raiz) . ' && ' . $cmd . ' 2>&1', $salida, $codigo);

            return [$codigo, $salida];
        };

        [$codigo, $salida] = $correr($comando . ' --no-interaction');

        if ($codigo !== 0) {
            return [
                'ok' => false,
                'package' => $paquete,
                'command' => $comando,
                // LA SALIDA REAL, no un resumen: composer se niega por razones que sólo él conoce —un
                // conflicto de versiones, sin red, una plataforma bloqueada— y esconderlas convierte un
                // problema arreglable en un «no funcionó».
                'error' => implode("\n", \array_slice($salida, -12)),
            ];
        }

        if (!self::llego($raiz, $paquete)) {
            return [
                'ok' => false,
                'package' => $paquete,
                'command' => $comando,
                'error' => "composer terminó en 0 y «{$paquete}» no aparece instalado",
                'hint' => 'el código de salida es una afirmación del subproceso sobre sí mismo, no sobre esta app',
            ];
        }

        [$codigoArranque, $salidaArranque] = $correr(escapeshellarg(PHP_BINARY) . ' bin/coa doctor');

        if ($codigoArranque === 0) {
            return ['ok' => true, 'package' => $paquete, 'command' => $comando, 'boots' => true];
        }

        // UNA REPARACIÓN QUE DEJA LA APP SIN ARRANCAR NO ES UNA REPARACIÓN. Los dos hechos se dicen
        // por separado porque son distintos y quien lea esto necesita los dos: el paquete SÍ llegó, y
        // aun así el grafo dejó de cerrar.
        return [
            'ok' => false,
            'package' => $paquete,
            'command' => $comando,
            'boots' => false,
            'error' => "el paquete llegó y esta app ya no arranca — «{$paquete}» quedó instalado",
            'boot_error' => implode("\n", \array_slice($salidaArranque, -12)),
            'hint' => 'corre `coa doctor` para el detalle, o `composer remove ' . $paquete . '` para deshacerlo',
        ];
    }

    /**
     * Los paquetes que el diagnóstico de HOY recomienda instalar.
     *
     * @param null|list<string> $clases las clases de plugin, si el host sabe cuáles son
     *
     * @return list<string>
     */
    public static function recommendedPackages(string $raiz, ?array $clases = null): array
    {
        // CÓMO DECLARA SUS PLUGINS UNA APP ES CONVENCIÓN DEL HOST, y este paquete no puede decidirlo
        // sin volverse su dueño — es la regla que {@see AppDoctor} ya tenía escrita y que la primera
        // versión de esto rompió, asumiendo `config/plugins.php`. En este monorepo ese archivo no
        // existe —los plugins se descubren escaneando un directorio— así que la reparación contestaba
        // «nada que recomendar» sobre una app tumbada, por no encontrar un archivo de otra convención.
        //
        // Sin clases NO se devuelve vacío: se diagnostica el `hostProfile` contra lo que los paquetes
        // instalados proveen, que es diagnosticable sin saber nada de plugins — y es justo la causa
        // más común de un arranque bloqueado. Quien conozca su convención pasa sus clases y suma el
        // grafo de plugins encima.
        if ($clases === null) {
            $declarados = $raiz . '/config/plugins.php';
            /** @var list<string> $clases */
            $clases = is_file($declarados) ? (array) require $declarados : [];
        }

        $paquetes = [];
        foreach ((new AppDoctor())->diagnose($clases, $raiz)->errors as $error) {
            foreach ((array) ($error['recommendedActions'] ?? []) as $accion) {
                if (\is_array($accion) && ($accion['type'] ?? null) === 'install-package' && \is_string($accion['package'] ?? null)) {
                    $paquetes[] = $accion['package'];
                }
            }
        }

        return array_values(array_unique($paquetes));
    }

    /** ¿El paquete existe en el disco después de instalarlo? Es donde el hecho vive. */
    private static function llego(string $raiz, string $paquete): bool
    {
        $archivo = $raiz . '/vendor/composer/installed.json';
        if (!is_file($archivo)) {
            return false;
        }

        $json = json_decode((string) file_get_contents($archivo), true);
        if (!\is_array($json)) {
            return false;
        }

        $paquetes = \is_array($json['packages'] ?? null) ? $json['packages'] : $json;

        foreach ($paquetes as $instalado) {
            if (\is_array($instalado) && ($instalado['name'] ?? null) === $paquete) {
                return true;
            }
        }

        return false;
    }
}
