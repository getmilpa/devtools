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
use Milpa\Attributes\PluginMetadata;
use Milpa\DevTools\Support\ComposerAutoload;
use Milpa\DevTools\Validators\MetadataParityValidator;
use Milpa\DevTools\Validators\PluginManifestValidator;
use Milpa\Interfaces\Plugin\PluginInterface;
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

        $root = $this->roots->resolve();

        // LA CONVENCIÓN SE PREGUNTA, no se asume. Este handler hardcodeaba `plugins/<x>/milpa.json`,
        // que es la ruta de un host legacy — así que en una app de runtime, la que sale de un
        // `create-project`, `validate` era un comando que SIEMPRE fallaba: ningún plugin de ahí tiene
        // manifiesto, porque su fuente de verdad es el atributo `#[PluginMetadata]`. Le pasó lo mismo
        // que a `make` antes de que aprendiera a preguntar.
        $manifest = is_file($target) ? $target : $this->manifestDe($root, $target);

        if ($manifest === null) {
            $clase = $this->claseDe($root, $target);
            if ($clase !== null) {
                // Un plugin de runtime SÍ se puede validar: lo que se revisa es el atributo, no un
                // archivo que esa convención no tiene. Contestar «no hay manifiesto» sería exigirle a
                // alguien un archivo que su convención no usa.
                return $this->validarPorAtributo($target, $clase);
            }

            return [
                'ok' => false,
                'target' => $target,
                'manifest' => '',
                'error' => "no encontré el plugin «{$target}»: ni plugins/{$target}/milpa.json ni una "
                    . 'clase con `#[PluginMetadata]` bajo src/Plugins/',
            ];
        }

        $forma = (new PluginManifestValidator())->validate($manifest);
        $proveedores = (new ProviderImplementsValidator())->validate([$manifest]);

        $checks = [
            'manifest' => ['ok' => $forma->ok(), 'findings' => $forma->errors],
            'providers' => ['ok' => $proveedores->ok(), 'findings' => $proveedores->violations],
        ];

        // PARIDAD: que el manifiesto y el atributo digan lo mismo. Estaba implementado y probado desde
        // antes, y no lo alcanzaba nadie — la tercera vez esta semana que este paquete tenía escrita
        // una capacidad sin la línea que la enchufa. Y es la que atrapa el desfase más caro: un
        // `milpa.json` que promete una versión y una clase que declara otra pasan cada uno su propia
        // revisión, y sólo se contradicen entre sí.
        $clase = $this->claseDelManifiesto($manifest);
        if ($clase !== null) {
            $paridad = (new MetadataParityValidator())->validate($manifest, $clase);
            $checks['parity'] = ['ok' => $paridad->ok(), 'findings' => $paridad->divergent];
        }

        $ok = true;
        foreach ($checks as $check) {
            $ok = $ok && $check['ok'];
        }

        return [
            'ok' => $ok,
            'target' => $target,
            'manifest' => $manifest,
            'checks' => $checks,
        ];
    }

    /**
     * El `milpa.json` de un plugin en la convención legacy, o `null` si no lo hay.
     */
    private function manifestDe(string $root, string $target): ?string
    {
        $ruta = $root . '/plugins/' . $target . '/milpa.json';

        return is_file($ruta) ? $ruta : null;
    }

    /**
     * La clase de un plugin en la convención de runtime, o `null`.
     *
     * Se busca por convención de ruta y no autocargando a ciegas: `src/Plugins/<X>/<X>.php` es lo que
     * los generadores de este mismo paquete escriben, así que preguntar por otra cosa sería validar
     * una convención que nadie produce.
     */
    private function claseDe(string $root, string $target): ?string
    {
        [$namespace, $dir] = ComposerAutoload::primaryNamespace($root) ?? ['App', 'src'];
        $archivo = $root . '/' . trim($dir, '/') . '/Plugins/' . $target . '/' . $target . '.php';

        return is_file($archivo) ? $namespace . '\\Plugins\\' . $target . '\\' . $target : null;
    }

    /**
     * La clase que un manifiesto dice ser, para poder confrontarla con él.
     *
     * @return class-string|null
     */
    private function claseDelManifiesto(string $manifest): ?string
    {
        $datos = json_decode((string) file_get_contents($manifest), true);
        if (!\is_array($datos)) {
            return null;
        }

        $namespace = \is_string($datos['namespace'] ?? null) ? trim($datos['namespace'], '\\') : '';
        $entrada = \is_string($datos['entrypoint'] ?? null) ? $datos['entrypoint'] : '';
        if ($namespace === '' || $entrada === '') {
            return null;
        }

        /** @var class-string $clase */
        $clase = $namespace . '\\' . basename($entrada, '.php');

        return class_exists($clase) ? $clase : null;
    }

    /**
     * Valida un plugin de la convención de runtime: su atributo es la fuente de verdad.
     *
     * @return array<string, mixed>
     */
    private function validarPorAtributo(string $target, string $clase): array
    {
        if (!class_exists($clase)) {
            return [
                'ok' => false,
                'target' => $target,
                'manifest' => '',
                'error' => "la clase «{$clase}» existe en disco y no se puede cargar (¿autoload? ¿namespace?)",
            ];
        }

        $atributos = (new \ReflectionClass($clase))->getAttributes(PluginMetadata::class);
        if ($atributos === []) {
            return [
                'ok' => false,
                'target' => $target,
                'manifest' => '',
                'error' => "«{$clase}» no declara `#[PluginMetadata]`, así que el kernel no la puede bootear",
            ];
        }

        $meta = $atributos[0]->newInstance();
        $hallazgos = [];
        if (trim($meta->name) === '') {
            $hallazgos[] = 'el atributo no declara `name`';
        }
        if (preg_match('/^\d+\.\d+\.\d+/', $meta->version) !== 1) {
            $hallazgos[] = "`version` no es semver: «{$meta->version}»";
        }
        if (!is_subclass_of($clase, PluginInterface::class)) {
            $hallazgos[] = "«{$clase}» no implementa PluginInterface";
        }

        return [
            'ok' => $hallazgos === [],
            'target' => $target,
            'manifest' => '',
            'convention' => 'runtime',
            'checks' => [
                'attribute' => ['ok' => $hallazgos === [], 'findings' => $hallazgos],
            ],
        ];
    }
}
