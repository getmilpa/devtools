<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Doctor;

use Milpa\Attributes\PluginMetadata;
use Milpa\DevTools\Doctor\AppDoctor;
use PHPUnit\Framework\TestCase;

/**
 * La diagnosis que sobrevive a una app rota.
 *
 * Cuando el grafo de capacidades no cierra, el kernel no arranca; `coa` no despacha; ninguna operación
 * corre. Medido en la app de ejemplo: `plugins:list`, `validate` y `test` caídas —las quince
 * herramientas del agente— y una línea de error como todo el dato. La herramienta que explica por qué
 * algo no arranca no puede necesitar que arranque.
 */
final class AppDoctorTest extends TestCase
{
    /** Un grafo que cierra se reporta sano, con lo que cada plugin provee y pide. */
    public function testAGraphThatClosesIsReportedHealthy(): void
    {
        $reporte = (new AppDoctor())->diagnose([DoctorBuscador::class, DoctorBlog::class]);

        self::assertTrue($reporte->ok());
        self::assertTrue($reporte->graphCloses);
        self::assertCount(2, $reporte->plugins);
        self::assertSame(['search'], $reporte->plugins[0]['provides']);
        self::assertSame(['search'], $reporte->plugins[1]['requires']);
        self::assertSame([], $reporte->errors);
    }

    /**
     * Una capacidad sin proveedor se reporta CON su forma aprendible.
     *
     * El código, el porqué, los arreglos y las acciones que un agente puede aplicar sin interpretar
     * nada — todo eso ya lo produce el resolver, y el arranque lo reducía a una línea.
     */
    public function testAMissingCapabilityComesBackWithItsLearnableShape(): void
    {
        $reporte = (new AppDoctor())->diagnose([DoctorBlog::class]);

        self::assertFalse($reporte->ok());
        self::assertFalse($reporte->graphCloses);
        self::assertNotSame([], $reporte->missing);
        self::assertNotSame([], $reporte->errors);

        $error = $reporte->errors[0];
        self::assertSame('MILPA_CAPABILITY_MISSING', $error['code']);
        self::assertStringContainsString('search', (string) $error['message']);
        self::assertNotSame('', (string) $error['why'], 'el porqué, que la excepción no conservaba');
        self::assertNotSame([], (array) $error['fixes']);
        self::assertArrayHasKey('recommendedActions', $error, 'lo aplicable sin interpretar');
        self::assertArrayHasKey('learn', $error, 'y a dónde se aprende');
    }

    /**
     * UNA CLASE DECLARADA QUE NO CARGA es el fallo más común, y el que un mensaje de arranque nunca
     * alcanza a nombrar — porque el arranque truena antes, en otro lado.
     */
    public function testADeclaredClassThatCannotBeLoadedIsNamed(): void
    {
        $reporte = (new AppDoctor())->diagnose(['App\\Plugins\\NoExiste\\NoExiste']);

        self::assertFalse($reporte->ok());
        self::assertCount(1, $reporte->unreadable);
        self::assertStringContainsString('no se puede cargar', $reporte->unreadable[0]);
    }

    /** Una clase sin `#[PluginMetadata]` también: el kernel no la puede bootear y hay que decirlo. */
    public function testAClassWithoutTheAttributeIsNamedToo(): void
    {
        $reporte = (new AppDoctor())->diagnose([DoctorSinAtributo::class]);

        self::assertFalse($reporte->ok());
        self::assertStringContainsString('PluginMetadata', $reporte->unreadable[0]);
    }

    /**
     * Un plugin ILEGIBLE hace que la app no esté sana AUNQUE el grafo de los legibles cierre.
     *
     * El kernel lo va a intentar cargar igual. Contestar que todo está bien porque los demás cierran
     * sería el peor de los diagnósticos: tranquiliza sobre lo que sí falla.
     */
    public function testAnUnreadablePluginMakesTheAppUnhealthyEvenIfTheRestCloses(): void
    {
        $reporte = (new AppDoctor())->diagnose([DoctorBuscador::class, 'App\\NoExiste']);

        self::assertTrue($reporte->graphCloses, 'los legibles sí cierran');
        self::assertFalse($reporte->ok(), 'y aun así la app no arranca');
    }

    /** Una app sin plugins está sana: es el estado de toda app recién creada. */
    public function testAnAppWithNoPluginsIsHealthy(): void
    {
        $reporte = (new AppDoctor())->diagnose([]);

        self::assertTrue($reporte->ok());
        self::assertSame([], $reporte->plugins);
    }
}

#[PluginMetadata(version: '1.0.0', author: 'x', site: 'https://x.com', name: 'Buscador', type: 'Service', provides: ['search'])]
final class DoctorBuscador
{
}

#[PluginMetadata(version: '1.0.0', author: 'x', site: 'https://x.com', name: 'Blog', type: 'Web', requires: ['search'])]
final class DoctorBlog
{
}

final class DoctorSinAtributo
{
}
