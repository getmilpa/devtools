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

namespace Milpa\DevTools\Doctor;

use Milpa\Attributes\PluginMetadata;
use Milpa\Plugin\Runtime\MetadataGraphResolver;
use Milpa\Resolver\Report\ResolutionStatus;

/**
 * Explica el estado arquitectónico de una app SIN arrancarla.
 *
 * ── POR QUÉ SIN ARRANCARLA ──────────────────────────────────────────────────────────────────────
 *
 * Porque el caso que más falta hace es justo aquel en el que la app no arranca. Medido en una app de
 * ejemplo con una capacidad sin proveedor: `plugins:list`, `validate` y `test` caídas —las quince
 * herramientas del agente— y una sola línea de error como todo el dato disponible. La herramienta que
 * explica por qué algo no arranca no puede necesitar que arranque; si la necesita, la diagnosis muere
 * con el paciente.
 *
 * ── NO CALCULA NADA NUEVO ───────────────────────────────────────────────────────────────────────
 *
 * El resolver ya produce un reporte completo —qué falta, qué choca, qué se degrada, a qué lección
 * lleva cada error, y qué acciones lo arreglarían— y el arranque conserva de todo eso la primera
 * línea, como mensaje de una excepción. Esto es el mismo cálculo con el reporte entero puesto en las
 * manos de quien pregunta. La mayor parte de lo que a un agente le falta para operar un framework no
 * es capacidad nueva: es que lo que el sistema ya sabe le llegue.
 */
final readonly class AppDoctor
{
    /**
     * Diagnostica la app formada por estas clases de plugin.
     *
     * Recibe las clases y no la ruta de un `config/plugins.php` porque de dónde salen es convención
     * del host: este paquete no puede decidir cómo se declara una app sin volverse su dueño.
     *
     * @param list<string> $pluginClasses tal como el host las declara
     */
    public function diagnose(array $pluginClasses): DoctorReport
    {
        $registros = [];
        $ilegibles = [];

        foreach ($pluginClasses as $clase) {
            if (!class_exists($clase)) {
                // El fallo más común de todos, y el que un mensaje de arranque nunca alcanza a
                // nombrar: la clase está declarada y no se puede cargar.
                $ilegibles[] = "{$clase} — declarado y no se puede cargar (¿autoload? ¿namespace?)";

                continue;
            }

            $atributos = (new \ReflectionClass($clase))->getAttributes(PluginMetadata::class);
            if ($atributos === []) {
                $ilegibles[] = "{$clase} — sin #[PluginMetadata], así que el kernel no lo puede bootear";

                continue;
            }

            $meta = $atributos[0]->newInstance();
            $registros[] = [
                'name' => $meta->name,
                'version' => $meta->version,
                'type' => $meta->type,
                'provides' => array_values($meta->provides),
                'requires' => array_values($meta->requires),
                'suggests' => array_values($meta->suggests),
            ];
        }

        try {
            $reporte = (new MetadataGraphResolver())->diagnose($registros);
        } catch (\InvalidArgumentException $e) {
            // Un registro que el resolver no puede ingerir NO es un grafo que no cierra: es una
            // entrada ilegible. Confundirlos mandaría a alguien a buscar un proveedor que no era el
            // problema.
            return new DoctorReport(
                plugins: $this->resumen($registros),
                unreadable: [...$ilegibles, 'un manifiesto está malformado: ' . $e->getMessage()],
                graphCloses: false,
            );
        }

        $errores = [];
        foreach ($reporte->errors as $error) {
            $errores[] = $error->toArray();
        }

        return new DoctorReport(
            plugins: $this->resumen($registros),
            unreadable: $ilegibles,
            missing: $reporte->missing,
            errors: $errores,
            graphCloses: $reporte->status !== ResolutionStatus::Blocked,
        );
    }

    /**
     * @param list<array<string, mixed>> $registros
     *
     * @return list<array{name: string, version: string, provides: list<string>, requires: list<string>}>
     */
    private function resumen(array $registros): array
    {
        $filas = [];
        foreach ($registros as $registro) {
            /** @var list<string> $provee */
            $provee = \is_array($registro['provides'] ?? null) ? array_values($registro['provides']) : [];
            /** @var list<string> $pide */
            $pide = \is_array($registro['requires'] ?? null) ? array_values($registro['requires']) : [];

            $filas[] = [
                'name' => \is_string($registro['name'] ?? null) ? $registro['name'] : '',
                'version' => \is_string($registro['version'] ?? null) ? $registro['version'] : '',
                'provides' => $provee,
                'requires' => $pide,
            ];
        }

        return $filas;
    }
}
