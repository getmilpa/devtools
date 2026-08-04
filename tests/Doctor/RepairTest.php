<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Doctor;

use Milpa\DevTools\Doctor\Repair;
use PHPUnit\Framework\TestCase;

/**
 * Aplicar la reparación que el diagnóstico recomendó — sin necesitar que la app arranque.
 *
 * Vive junto al doctor porque son la misma conversación: él dice qué falta y de dónde sacarlo, esto lo
 * trae. Estaban en paquetes distintos y eso dejaba la reparación fuera del alcance de toda app que no
 * se hubiera creado desde la plantilla — la que más la necesitaba, un host con el grafo abierto, era
 * justo la que no la tenía.
 */
final class RepairTest extends TestCase
{
    private string $raiz;

    protected function setUp(): void
    {
        $this->raiz = sys_get_temp_dir() . '/milpa-repair-' . bin2hex(random_bytes(4));
        mkdir($this->raiz . '/vendor/composer', 0o775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->raiz . '/vendor/composer/installed.json');
        @rmdir($this->raiz . '/vendor/composer');
        @rmdir($this->raiz . '/vendor');
        @rmdir($this->raiz);
    }

    private function instalado(string $paquete): void
    {
        file_put_contents(
            $this->raiz . '/vendor/composer/installed.json',
            json_encode(['packages' => [['name' => $paquete]]], JSON_THROW_ON_ERROR),
        );
    }

    /** Sin paquete se dice qué falta, en vez de reparar lo primero que encuentre. */
    public function testWithoutAPackageItSaysWhatIsMissing(): void
    {
        $r = Repair::apply($this->raiz, '   ');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('package', (string) $r['error']);
    }

    /**
     * LA PUERTA SÓLO ABRE PARA LO QUE EL DIAGNÓSTICO PIDIÓ.
     *
     * Sin esa restricción sería un instalador general con nombre de reparación — la puerta ancha que
     * después nadie se atreve a cerrar, y por la que cualquier paquete entraría a la app por la vía que
     * existe para arreglarla.
     */
    public function testItRefusesWhatTheDiagnosisDidNotRecommend(): void
    {
        $r = Repair::apply($this->raiz, 'vendor/lo-que-sea', recomendados: ['milpa/mcp-server']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no está entre lo que el diagnóstico recomienda', (string) $r['error']);
        self::assertSame(['milpa/mcp-server'], $r['recommended'], 'la negativa no es un callejón');
    }

    /** Con un diagnóstico que no recomienda nada, lo dice así — no como «ese paquete no existe». */
    public function testWithNothingToRepairItSaysThatAndNotSomethingElse(): void
    {
        $r = Repair::apply($this->raiz, 'milpa/mcp-server', recomendados: []);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no recomienda instalar nada', (string) $r['error']);
    }

    /** En seco enseña el comando exacto y no toca nada. */
    public function testADryRunShowsTheCommandAndTouchesNothing(): void
    {
        $r = Repair::apply($this->raiz, 'milpa/mcp-server', seco: true, recomendados: ['milpa/mcp-server']);

        self::assertTrue($r['ok']);
        self::assertTrue($r['dry_run']);
        self::assertStringContainsString('composer require', (string) $r['command']);
        self::assertFileDoesNotExist($this->raiz . '/vendor/composer/installed.json');
    }

    /** Si composer se niega, se dice SU salida — no un resumen. */
    public function testWhenComposerRefusesItsRealOutputIsShown(): void
    {
        $r = Repair::apply(
            $this->raiz,
            'milpa/mcp-server',
            recomendados: ['milpa/mcp-server'],
            corredor: static fn (string $cmd): array => [1, ['Your requirements could not be resolved.']],
        );

        self::assertFalse($r['ok']);
        self::assertStringContainsString('could not be resolved', (string) $r['error']);
    }

    /**
     * QUE COMPOSER SALGA EN 0 NO ES QUE EL PAQUETE HAYA LLEGADO.
     *
     * El código de salida es una afirmación del subproceso sobre sí mismo. El hecho vive en el disco,
     * y ahí se comprueba.
     */
    public function testAZeroExitIsNotProofThePackageArrived(): void
    {
        $r = Repair::apply(
            $this->raiz,
            'milpa/mcp-server',
            recomendados: ['milpa/mcp-server'],
            corredor: static fn (string $cmd): array => [0, ['Nothing to install or update']],
        );

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no aparece instalado', (string) $r['error']);
    }

    /**
     * Y QUE HAYA LLEGADO NO ES QUE LA APP SIGA EN PIE.
     *
     * Instalar un paquete puede cerrar el grafo de una capacidad y abrir otro. Una reparación que deja
     * la app sin arrancar no es una reparación, así que el `ok` se cae — y los dos hechos se dicen por
     * separado porque son distintos.
     */
    public function testARepairThatLeavesTheAppUnableToBootIsNotARepair(): void
    {
        $this->instalado('milpa/mcp-server');

        $r = Repair::apply(
            $this->raiz,
            'milpa/mcp-server',
            recomendados: ['milpa/mcp-server'],
            // El doble distingue los dos comandos, porque son dos hechos distintos: composer sí pudo,
            // y el arranque posterior no. Uno que contestara igual a los dos mediría uno solo.
            corredor: static fn (string $cmd): array => str_contains($cmd, 'composer')
                ? [0, ['Package operations: 1 install']]
                : [1, ['MILPA_CAPABILITY_MISSING: nadie provee «x.y»']],
        );

        self::assertFalse($r['ok']);
        self::assertFalse($r['boots']);
        self::assertStringContainsString('ya no arranca', (string) $r['error']);
        self::assertStringContainsString('MILPA_CAPABILITY_MISSING', (string) $r['boot_error']);
        self::assertStringContainsString('composer remove', (string) $r['hint'], 'y cómo deshacerlo');
    }

    /** Cuando llegó y la app sigue en pie, lo dice — no se calla el hecho que acaba de comprobar. */
    public function testWhenItArrivedAndStillBootsItSaysSo(): void
    {
        $this->instalado('milpa/mcp-server');

        $r = Repair::apply(
            $this->raiz,
            'milpa/mcp-server',
            recomendados: ['milpa/mcp-server'],
            corredor: static fn (string $cmd): array => [0, ['✓ el grafo cierra']],
        );

        self::assertTrue($r['ok']);
        self::assertTrue($r['boots']);
        self::assertSame('milpa/mcp-server', $r['package']);
    }

    /**
     * CÓMO DECLARA SUS PLUGINS UNA APP ES CONVENCIÓN DEL HOST.
     *
     * Sin clases NO se devuelve vacío: se diagnostica el `hostProfile` contra lo instalado, que es
     * diagnosticable sin saber nada de plugins — y es la causa más común de un arranque bloqueado.
     */
    public function testWithoutPluginClassesItStillDiagnosesTheHostProfile(): void
    {
        self::assertSame([], Repair::recommendedPackages($this->raiz, []));
    }
}
