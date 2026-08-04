<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Doctor;

use Milpa\DevTools\Doctor\Update;
use PHPUnit\Framework\TestCase;

/**
 * Actualizar las distribuciones de una app, y comprobar después que sigue en pie.
 *
 * El mismo arco que `Repair` con otro verbo: reparar trae lo que falta, actualizar mueve lo que ya
 * está, y las dos terminan igual — un cero de composer es una afirmación del subproceso sobre sí
 * mismo, y que las versiones cambien no es que la app arranque.
 */
final class UpdateTest extends TestCase
{
    private string $raiz;

    protected function setUp(): void
    {
        $this->raiz = sys_get_temp_dir() . '/milpa-update-' . bin2hex(random_bytes(4));
        mkdir($this->raiz . '/vendor/composer', 0o775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->raiz . '/vendor/composer/installed.json');
        @rmdir($this->raiz . '/vendor/composer');
        @rmdir($this->raiz . '/vendor');
        @rmdir($this->raiz);
    }

    /** @param array<string, string> $versiones */
    private function instalado(array $versiones): void
    {
        file_put_contents(
            $this->raiz . '/vendor/composer/installed.json',
            json_encode(
                ['packages' => array_map(
                    static fn (string $n, string $v): array => ['name' => $n, 'version' => $v],
                    array_keys($versiones),
                    array_values($versiones),
                )],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    /**
     * EN SECO NO TOCA NADA, y dice qué haría con las palabras de composer.
     *
     * Actualizar es la operación menos reversible que alguien hace a la ligera: cambia el grafo entero
     * de una vez. Decir qué cambiaría antes la vuelve una decisión en vez de una apuesta.
     */
    public function testADryRunSaysWhatWouldChangeAndTouchesNothing(): void
    {
        $r = Update::apply(
            $this->raiz,
            seco: true,
            corredor: static fn (string $cmd): array => [0, ['Lock file operations: 0 installs, 7 updates']],
        );

        self::assertTrue($r['ok']);
        self::assertTrue($r['dry_run']);
        self::assertStringContainsString('--dry-run', 'composer update --dry-run', 'el seco pasa por composer');
        self::assertStringContainsString('7 updates', implode("\n", $r['would']));
    }

    /** Por defecto la FAMILIA, no todo: mover las dependencias de terceros es otra decisión. */
    public function testByDefaultItOnlyMovesTheFamily(): void
    {
        $visto = null;
        Update::apply($this->raiz, seco: true, corredor: static function (string $cmd) use (&$visto): array {
            $visto = $cmd;

            return [0, []];
        });

        self::assertStringContainsString("composer update 'milpa/*'", (string) $visto);
    }

    /** Y si se nombran paquetes, son ésos y nada más. */
    public function testNamedPackagesAreTheOnesThatMove(): void
    {
        $visto = null;
        Update::apply($this->raiz, seco: true, paquetes: ['milpa/agent'], corredor: static function (string $cmd) use (&$visto): array {
            $visto = $cmd;

            return [0, []];
        });

        self::assertStringContainsString("composer update 'milpa/agent'", (string) $visto);
        self::assertStringNotContainsString('milpa/*', (string) $visto);
    }

    /** Si composer se niega, se dice SU salida — no un resumen. */
    public function testWhenComposerRefusesItsRealOutputIsShown(): void
    {
        $r = Update::apply(
            $this->raiz,
            corredor: static fn (string $cmd): array => [1, ['Your requirements could not be resolved.']],
        );

        self::assertFalse($r['ok']);
        self::assertStringContainsString('could not be resolved', (string) $r['error']);
    }

    /**
     * LO QUE SE MOVIÓ SE LEE DEL DISCO, no de lo que composer contó.
     *
     * Es el mismo criterio que en `Repair`: el hecho vive en `installed.json`.
     */
    public function testWhatMovedIsReadFromDiskAndNotFromComposersStory(): void
    {
        $this->instalado(['milpa/agent' => 'v0.5.1']);

        $prueba = $this;
        $r = Update::apply(
            $this->raiz,
            corredor: function (string $cmd) use ($prueba): array {
                if (str_contains($cmd, 'composer')) {
                    // El «update» de verdad: cambia el disco.
                    $prueba->instalado(['milpa/agent' => 'v0.5.2']);

                    return [0, ['done']];
                }

                return [0, ['✓ el grafo cierra']];
            },
        );

        self::assertTrue($r['ok']);
        self::assertTrue($r['boots']);
        self::assertSame(['milpa/agent' => ['from' => 'v0.5.1', 'to' => 'v0.5.2']], $r['changed']);
    }

    /**
     * UNA ACTUALIZACIÓN QUE DEJA LA APP SIN ARRANCAR NO ESTÁ TERMINADA.
     *
     * Los dos hechos se dicen por separado: las versiones SÍ se movieron, y aun así el grafo dejó de
     * cerrar. Y no se revierte solo — deshacer lo que el humano pidió es otra decisión— pero se dice
     * dónde está el punto de retorno.
     */
    public function testAnUpdateThatBreaksTheBootIsNotDone(): void
    {
        $this->instalado(['milpa/agent' => 'v0.5.1']);

        $r = Update::apply(
            $this->raiz,
            corredor: static fn (string $cmd): array => str_contains($cmd, 'composer')
                ? [0, ['done']]
                : [1, ['MILPA_CAPABILITY_MISSING: nadie provee «x.y»']],
        );

        self::assertFalse($r['ok']);
        self::assertFalse($r['boots']);
        self::assertStringContainsString('ya no arranca', (string) $r['error']);
        self::assertStringContainsString('MILPA_CAPABILITY_MISSING', (string) $r['boot_error']);
        self::assertStringContainsString('composer.lock', (string) $r['hint'], 'dice dónde está el punto de retorno');
    }
}
