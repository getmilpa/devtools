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

namespace Milpa\DevTools\Make\Generators;

use Milpa\DevTools\Make\ConventionDetector;
use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\GenerationResult;
use Milpa\DevTools\Make\GeneratorInterface;
use Milpa\DevTools\Make\MarkerInserter;
use Milpa\DevTools\Make\Markers;
use Milpa\DevTools\Make\PlannedFile;
use Milpa\DevTools\Make\PluginSurgeon;
use Milpa\DevTools\Support\ComposerAutoload;

/**
 * Generates the CLOSED compound: everything a REST resource needs to exist, persist, serve, and be
 * judged — one call, zero manual steps among the required consequences. This is the shape a
 * measured cattle run paid 583k tokens and 60 turns to assemble by hand out of `make entity` +
 * guidance prose; the rule extracted from that run is this generator's contract: a deterministic
 * operation that knows a required postcondition materializes it, or the run is incomplete.
 *
 * Everything is COMPOSED, never reimplemented:
 *
 * - {@see CrudGenerator} (which itself composes {@see EntityGenerator}) contributes the entity from
 *   the `--fields` DSL (enums with declared cases materialized alongside), the 5-method REST
 *   controller, and the combined repository+controller+routes wiring plugin.
 * - {@see ServiceGenerator} contributes a `<Name>Service` domain seam; its registration is spliced
 *   into the SAME wiring plugin (marker anchor or {@see PluginSurgeon} structural insert), because
 *   two generators planning two plugins at one path would be the composition bug
 *   {@see CrudGenerator} already refuses for {@see EntityGenerator}.
 * - {@see TestGenerator} contributes the behavioral judge, red on purpose, so the thing that can
 *   say what the resource must DO exists from birth.
 *
 * One deliberate degradation, NAMED instead of hidden: `<field>:belongsTo:<Target>` cannot exist as
 * a relation in a runtime entity (`milpa/data` has no relation concept — {@see EntityGenerator}'s
 * runtime path refuses it with exactly this advice), so the resource stores the related id as the
 * `<target>_id:int` scalar that advice prescribes, and the postcondition report carries one advisory
 * check per degraded relation so the caller learns it from the verdict, not from a surprise.
 *
 * Only a RUNTIME convention exists — see {@see generate()} for why LEGACY throws.
 */
final class ResourceGenerator implements GeneratorInterface
{
    private const RELATION_TOKEN = '/^(\??)([A-Za-z_]\w*):belongsTo:([A-Za-z_]\w*)$/';

    public function __construct(
        private readonly CrudGenerator $crudGenerator = new CrudGenerator(),
        private readonly ServiceGenerator $serviceGenerator = new ServiceGenerator(),
        private readonly TestGenerator $testGenerator = new TestGenerator(),
        private readonly MarkerInserter $markers = new MarkerInserter(),
        private readonly PluginSurgeon $surgeon = new PluginSurgeon(),
        private readonly ConventionDetector $detector = new ConventionDetector(),
    ) {
    }

    /** The `<what>` token this generator answers to: `'resource'`. */
    public function name(): string
    {
        return 'resource';
    }

    /**
     * The scalar column a `belongsTo:<Target>` field degrades to under the runtime convention —
     * `Author` -> `author_id`, `UserGroup` -> `user_group_id`. Public and static because the
     * {@see \Milpa\DevTools\Make\PostconditionVerifier} must name the SAME column in its relation
     * advisory that the generator actually emitted; one authority, two readers.
     */
    public static function relationColumn(string $target): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $target)) . '_id';
    }

    /**
     * Renders the composed resource per the detected/overridden {@see Flavor}.
     *
     * @throws \RuntimeException When the detected/forced flavor is {@see Flavor::Legacy} — the
     *                           composed resource is a runtime-only concept, same reasoning as
     *                           {@see CrudGenerator::generate()}.
     */
    public function generate(GenerationContext $context): GenerationResult
    {
        $flavor = $this->detector->detect($context->root, $context->option('flavor'));
        if ($flavor === Flavor::Legacy) {
            throw new \RuntimeException(
                'make:resource has no legacy convention to scaffold — the composed entity+service+'
                . 'controller+routes+test resource is a runtime-only concept in this engine (the '
                . 'legacy host has no single resource shape to stub against, same reasoning as '
                . 'make:crud); use --flavor=runtime (the default outside a legacy host).',
            );
        }

        [$appNamespace, $appDir] = ComposerAutoload::primaryNamespace($context->root) ?? ['App', 'src'];
        $appDir = trim($appDir, '/');
        $pluginPath = $context->root . '/' . $appDir . '/Plugins/' . $context->plugin . '/' . $context->plugin . '.php';

        [$degradedFields, $relations] = $this->degradeRelations($context->option('fields') ?? '');

        $crudOptions = $context->options;
        $crudOptions['fields'] = $degradedFields;
        $crudOptions['flavor'] = 'runtime';
        $crud = $this->crudGenerator->generate(
            new GenerationContext($context->plugin, $context->name, $crudOptions, $context->root),
        );

        $serviceClass = $context->name . 'Service';
        $service = $this->serviceGenerator->generate(
            new GenerationContext($context->plugin, $serviceClass, ['flavor' => 'runtime'], $context->root),
        );

        // ServiceGenerator, run in isolation, plans its own wiring plugin at this same path (a fresh
        // one, or a merge of the DISK contents that knows nothing of the crud wiring) — superseded
        // by the crud composition's plugin below, exactly as CrudGenerator supersedes
        // EntityGenerator's own plan; dropped rather than letting two plans target one path.
        $serviceFiles = array_values(array_filter(
            $service->files,
            static fn (PlannedFile $file): bool => $file->path !== $pluginPath,
        ));

        $serviceFqcn = $appNamespace . '\\Plugins\\' . $context->plugin . '\\Services\\' . $serviceClass;
        [$files, $serviceGap] = $this->spliceServiceRegistration(
            $crud->files,
            $pluginPath,
            $serviceClass,
            $serviceFqcn,
            $context->flag('force'),
        );

        $test = $this->testGenerator->generate(
            new GenerationContext($context->plugin, $context->name, [], $context->root),
        );
        foreach ($test->files as $file) {
            // TestGenerator plans a root-relative path; the composed result is written (and its
            // postconditions checked) against the app root, so anchor it there.
            $path = str_starts_with($file->path, '/') ? $file->path : $context->root . '/' . $file->path;
            $files[] = new PlannedFile($path, $file->contents);
        }

        $files = array_merge($files, $serviceFiles);

        $notes = [];
        if ($relations !== []) {
            $notes[] = 'Relation fields degraded to scalars (milpa/data has no relation concept): '
                . implode(', ', array_map(
                    static fn (array $relation): string => "{$relation['field']}:belongsTo:{$relation['target']} -> {$relation['column']}:int",
                    $relations,
                )) . ' — the postcondition report names each one.';
        }
        if ($serviceGap !== null) {
            $notes[] = $serviceGap;
        }
        if ($test->guidance !== null) {
            $notes[] = $test->guidance;
        }

        $guidance = trim(implode("\n\n", array_filter(
            [$crud->guidance, ...$notes],
            static fn (?string $note): bool => $note !== null && trim($note) !== '',
        )));

        return new GenerationResult(
            files: $files,
            // Same single-slot compromise CrudGenerator documents: 'controller' is the most
            // informative single shape check, and the entity went through EntityGenerator's own path.
            verifyKind: 'controller',
            verifyTarget: $appNamespace . '\\Plugins\\' . $context->plugin . '\\Controllers\\' . $context->name . 'Controller',
            flavor: Flavor::Runtime,
            guidance: $guidance === '' ? null : $guidance,
        );
    }

    /**
     * Lands the `<Name>Service` registration in the SAME wiring plugin the crud composition planned:
     * skipped when already present, spliced at the `// {coa:services}` marker when the planned
     * contents carry one (every fresh stub does), structurally into `boot()` otherwise. When the
     * crud composition planned NO plugin file (an existing plugin the surgeon refused — its guidance
     * already names the file and the reason), the service registration shares that fate and is
     * reported, never silently dropped.
     *
     * @param list<PlannedFile> $files the crud composition's planned files
     *
     * @return array{0: list<PlannedFile>, 1: ?string} the files (plugin plan re-spliced) and the
     *                                                 guidance gap when the registration could not land
     */
    private function spliceServiceRegistration(
        array $files,
        string $pluginPath,
        string $serviceClass,
        string $serviceFqcn,
        bool $force,
    ): array {
        $snippet = $this->serviceGenerator->registrationSnippet($serviceFqcn, $serviceFqcn);

        foreach ($files as $i => $file) {
            if ($file->path !== $pluginPath) {
                continue;
            }
            if (str_contains($file->contents, "{$serviceClass}::class")) {
                return [$files, null];
            }
            try {
                $contents = $this->markers->hasMarker($file->contents, Markers::SERVICES)
                    ? $this->markers->insertBefore($file->contents, Markers::SERVICES, $snippet, $force)
                    : $this->surgeon->insertIntoMethod($file->contents, 'boot', $snippet);
            } catch (\RuntimeException $e) {
                return [$files, "The {$serviceClass} registration could not be inserted into "
                    . "{$pluginPath} ({$e->getMessage()}) — add this to its boot():\n\n{$snippet}", ];
            }
            $files[$i] = new PlannedFile($file->path, $contents, $file->merge);

            return [$files, null];
        }

        // No plugin plan among the crud files: either everything crud-side was already wired (the
        // plan was elided as "already wired") or the existing plugin was refused. Ask the DISK.
        if (is_file($pluginPath)) {
            $existing = (string) file_get_contents($pluginPath);
            if (str_contains($existing, "{$serviceClass}::class")) {
                return [$files, null];
            }
            try {
                $contents = $this->markers->hasMarker($existing, Markers::SERVICES)
                    ? $this->markers->insertBefore($existing, Markers::SERVICES, $snippet, $force)
                    : $this->surgeon->insertIntoMethod($existing, 'boot', $snippet);
            } catch (\RuntimeException $e) {
                return [$files, "The {$serviceClass} registration could not be inserted into "
                    . "{$pluginPath} ({$e->getMessage()}) — add this to its boot():\n\n{$snippet}", ];
            }
            $files[] = new PlannedFile($pluginPath, $contents, merge: true);

            return [$files, null];
        }

        return [$files, "The {$serviceClass} registration could not be inserted (no wiring plugin "
            . "exists or was planned) — add this to the plugin's boot():\n\n{$snippet}", ];
    }

    /**
     * Rewrites every `<field>:belongsTo:<Target>` DSL entry to the `<target>_id:int` scalar the
     * runtime convention supports (nullability `?` survives, everything else passes through
     * verbatim), and records each degradation so {@see generate()} can name it. Inner commas —
     * decimal `precision,scale` and enum `Class(a,b,c)` case lists — are protected from the
     * top-level split with the same two encodings {@see \Milpa\DevTools\Make\FieldParser} uses.
     *
     * @return array{0: string, 1: list<array{field: string, target: string, column: string}>}
     */
    private function degradeRelations(string $dsl): array
    {
        if (trim($dsl) === '') {
            return ['', []];
        }

        $encoded = (string) preg_replace_callback(
            '/decimal:(\d+),(\d+)/',
            static fn (array $m): string => "decimal:{$m[1]}|{$m[2]}",
            $dsl,
        );
        $encoded = (string) preg_replace_callback(
            '/\(([^)]*)\)/',
            static fn (array $m): string => '(' . str_replace(',', '|', $m[1]) . ')',
            $encoded,
        );

        $relations = [];
        $entries = [];
        foreach (explode(',', $encoded) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (preg_match(self::RELATION_TOKEN, $entry, $m) === 1) {
                $column = self::relationColumn($m[3]);
                $relations[] = ['field' => $m[2], 'target' => $m[3], 'column' => $column];
                $entry = $m[1] . $column . ':int';
            }
            $entries[] = $entry;
        }

        return [str_replace('|', ',', implode(', ', $entries)), $relations];
    }
}
