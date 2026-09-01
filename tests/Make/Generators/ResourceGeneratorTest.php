<?php

/**
 * This file is part of Milpa DevTools — the developer toolbox of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make\Generators;

use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\GenerationResult;
use Milpa\DevTools\Make\Generators\PluginGenerator;
use Milpa\DevTools\Make\Generators\ResourceGenerator;
use Milpa\DevTools\Make\PlannedFile;
use Milpa\DevTools\Make\PostconditionVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see ResourceGenerator}: that ONE call composes the CLOSED resource — entity (+ enums),
 * 5-method controller, service, combined wiring plugin, behavioral judge — and that the composed
 * result passes its own {@see PostconditionVerifier} report on a fresh cattle-like root: every
 * promised consequence on disk, zero of the wiring landing as prose. The `belongsTo` degradation is
 * asserted BOTH ways: the scalar column exists in the entity, and the relation is NAMED in the
 * postcondition report. Real-temp-directory setup, mirroring {@see \Milpa\DevTools\Tests\Make\CrudGeneratorTest}.
 */
final class ResourceGeneratorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-resource-' . uniqid();
        mkdir($this->root, 0o775, true);
        file_put_contents(
            $this->root . '/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], JSON_PRETTY_PRINT),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testOneCallComposesEntityEnumControllerServicePluginAndJudge(): void
    {
        $ctx = $this->context('Todos', 'Task', 'title:string, done:bool, priority:enum:Priority(low,high)');

        $result = (new ResourceGenerator())->generate($ctx);

        $names = array_map(static fn (PlannedFile $f): string => basename($f->path), $result->files);
        sort($names);
        $this->assertSame(
            ['Priority.php', 'Task.php', 'TaskController.php', 'TaskService.php', 'TaskTest.php', 'Todos.php'],
            $names,
            'the whole closed shape, planned by one call',
        );

        $plugin = $this->fileNamed($result->files, 'Todos.php');
        $this->assertStringContainsString("Task::class . 'Repository'", $plugin->contents);
        $this->assertStringContainsString('TaskController::class', $plugin->contents);
        $this->assertStringContainsString('TaskService::class', $plugin->contents);
        foreach (['index', 'show', 'create', 'update', 'delete'] as $verb) {
            $this->assertStringContainsString("'tasks_{$verb}'", $plugin->contents);
        }

        $test = $this->fileNamed($result->files, 'TaskTest.php');
        $this->assertStringEndsWith('/tests/Plugins/Todos/TaskTest.php', $test->path);
        $this->assertStringStartsWith($this->root . '/', $test->path, 'the judge is anchored to the app root');
        $this->assertStringContainsString('self::fail(', $test->contents, 'the judge is red on purpose');

        foreach ($result->files as $file) {
            $this->assertPhpLints($file->contents);
        }

        $this->assertSame(Flavor::Runtime, $result->flavor);
        $this->assertSame('controller', $result->verifyKind);
        $this->assertSame('App\\Plugins\\Todos\\Controllers\\TaskController', $result->verifyTarget);

        $guidance = (string) $result->guidance;
        $this->assertStringContainsString('config/plugins.php', $guidance, 'activation stays the one named human step');
        $this->assertStringNotContainsString('add this to its boot()', $guidance, 'zero wiring prose on the closed path');
    }

    /**
     * THE closure proof (P0.4's acceptance): after one `make resource` on a fresh root, the
     * postcondition report finds EVERY promised consequence — nothing dangles, nothing landed as
     * guidance. This is the run that previously took a 583k-token session to almost-close by hand.
     */
    public function testTheComposedResultPassesItsOwnPostconditionsOnAFreshRoot(): void
    {
        $ctx = $this->context('Todos', 'Task', 'title:string, priority:enum:Priority(low,high)');
        $this->writeFiles((new ResourceGenerator())->generate($ctx));

        $report = (new PostconditionVerifier())->verify('resource', $ctx, Flavor::Runtime);

        $this->assertTrue($report->ok(), 'closed resource missing: ' . implode(', ', $report->missing()));
        $this->assertSame([], $report->missing());

        $byName = [];
        foreach ($report->checks as $check) {
            $byName[$check->name] = $check->ok;
        }
        foreach ([
            'entity_file', 'controller_file', 'service_file', 'test_file', 'enum:Priority',
            'repository_registered', 'controller_registered', 'service_registered', 'routes_declared',
        ] as $required) {
            $this->assertArrayHasKey($required, $byName, "consequence {$required} must be checked");
            $this->assertTrue($byName[$required], "consequence {$required} must be found on disk");
        }
    }

    /**
     * `belongsTo:<Target>` cannot exist as a relation in a runtime entity — instead of the refusal
     * `make entity` gives, the resource DEGRADES it to the `<target>_id:int` scalar that refusal
     * prescribes, and the postcondition report NAMES the relation as an advisory check.
     */
    public function testBelongsToDegradesToTheScalarColumnAndIsNamedInTheReport(): void
    {
        $ctx = $this->context('Todos', 'Task', 'title:string, ?owner:belongsTo:UserGroup');

        $result = (new ResourceGenerator())->generate($ctx);

        $entity = $this->fileNamed($result->files, 'Task.php');
        $this->assertStringContainsString('public ?int $user_group_id,', $entity->contents, 'nullability survives the degradation');
        $this->assertStringNotContainsString('belongsTo', $entity->contents);

        $this->assertStringContainsString('owner:belongsTo:UserGroup -> user_group_id:int', (string) $result->guidance);

        $this->writeFiles($result);
        $report = (new PostconditionVerifier())->verify('resource', $ctx, Flavor::Runtime);

        $this->assertTrue($report->ok(), 'a degraded relation is advisory, never a dangling consequence: ' . implode(', ', $report->missing()));
        $relation = null;
        foreach ($report->checks as $check) {
            if ($check->name === 'relation:UserGroup') {
                $relation = $check;
            }
        }
        $this->assertNotNull($relation, 'the degraded relation must be NAMED in the report');
        $this->assertFalse($relation->required, 'named, not failing — the degradation is deliberate');
        $this->assertStringContainsString('user_group_id', $relation->detail);
    }

    /**
     * The make:plugin-first path: a standalone plugin scaffolded earlier (markers from birth) takes
     * the whole resource as marker splices — and a SECOND identical run adds nothing, because every
     * half reports already-wired instead of duplicating.
     */
    public function testComposesIntoAnExistingMakePluginScaffoldIdempotently(): void
    {
        $pluginResult = (new PluginGenerator())->generate(
            new GenerationContext('Todos', 'Todos', ['flavor' => 'runtime'], $this->root),
        );
        $this->writeFiles($pluginResult);

        $ctx = $this->context('Todos', 'Task', 'title:string');
        $result = (new ResourceGenerator())->generate($ctx);

        $plugin = $this->fileNamed($result->files, 'Todos.php');
        $this->assertTrue($plugin->merge, 'grafting into an existing plugin is a merge, not an overwrite');
        $this->assertStringContainsString("Task::class . 'Repository'", $plugin->contents);
        $this->assertStringContainsString('TaskService::class', $plugin->contents);
        $this->assertStringContainsString("'tasks_index'", $plugin->contents);
        $this->assertSame(1, preg_match_all('/^\s*\/\/ \{coa:services\}$/m', $plugin->contents), 'the services anchor line survives for a later run');
        $this->assertSame(1, preg_match_all('/^\s*\/\/ \{coa:routes\}$/m', $plugin->contents), 'the routes anchor line survives for a later run');
        $this->assertSame(1, substr_count($plugin->contents, "'tasks_index'"), 'the routes went in once, not once per anchor mention');
        $this->assertPhpLints($plugin->contents);

        // Land everything, run the same command again: no plugin plan (already wired), no duplicates.
        $this->writeFiles($result);
        $again = (new ResourceGenerator())->generate($ctx);

        foreach ($again->files as $file) {
            $this->assertNotSame('Todos.php', basename($file->path), 're-running must not re-plan the plugin');
        }
        $this->assertStringContainsString('Already wired', (string) $again->guidance);
        $onDisk = (string) file_get_contents($this->root . '/src/Plugins/Todos/Todos.php');
        $this->assertSame(1, substr_count($onDisk, "new \\App\\Plugins\\Todos\\Services\\TaskService()"), 'one service registration, not two');
    }

    /**
     * The fail-closed control: a plugin file the surgeon refuses (no class declaration) leaves the
     * wiring unlanded — and BOTH the guidance and the postcondition report NAME the file and the
     * reason, so `ok:false` explains itself instead of narrating a fix.
     */
    public function testAnUnwirablePluginFailsClosedNamingTheFileAndReason(): void
    {
        $pluginDir = $this->root . '/src/Plugins/Todos';
        mkdir($pluginDir, 0o775, true);
        file_put_contents($pluginDir . '/Todos.php', "<?php\n// hand-mangled: no class declaration in here\n");

        $ctx = $this->context('Todos', 'Task', 'title:string');
        $result = (new ResourceGenerator())->generate($ctx);

        foreach ($result->files as $file) {
            $this->assertNotSame('Todos.php', basename($file->path), 'an unwirable plugin file must not be (re)planned');
        }
        $guidance = (string) $result->guidance;
        $this->assertStringContainsString('could not be auto-wired', $guidance);
        $this->assertStringContainsString('no class declaration found', $guidance);
        $this->assertStringContainsString($pluginDir . '/Todos.php', $guidance);

        $this->writeFiles($result);
        $report = (new PostconditionVerifier())->verify('resource', $ctx, Flavor::Runtime);

        $this->assertFalse($report->ok());
        foreach (['repository_registered', 'controller_registered', 'service_registered', 'routes_declared'] as $gap) {
            $this->assertContains($gap, $report->missing());
        }
        foreach ($report->checks as $check) {
            if ($check->name === 'repository_registered') {
                $this->assertStringContainsString('could not be auto-wired: no class declaration found', $check->detail);
                $this->assertStringContainsString($pluginDir . '/Todos.php', $check->detail);
            }
        }
    }

    public function testLegacyFlavorThrowsAClearError(): void
    {
        $ctx = new GenerationContext(
            plugin: 'Todos',
            name: 'Task',
            options: ['flavor' => 'legacy'],
            root: $this->root,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--flavor=runtime');
        (new ResourceGenerator())->generate($ctx);
    }

    private function context(string $plugin, string $name, string $fields): GenerationContext
    {
        return new GenerationContext(
            plugin: $plugin,
            name: $name,
            options: ['flavor' => 'runtime', 'fields' => $fields],
            root: $this->root,
        );
    }

    private function writeFiles(GenerationResult $result): void
    {
        foreach ($result->files as $file) {
            $dir = \dirname($file->path);
            if (!is_dir($dir)) {
                mkdir($dir, 0o775, true);
            }
            file_put_contents($file->path, $file->contents);
        }
    }

    /** @param list<PlannedFile> $files */
    private function fileNamed(array $files, string $basename): PlannedFile
    {
        foreach ($files as $file) {
            if (basename($file->path) === $basename) {
                return $file;
            }
        }

        $this->fail("no planned file named {$basename} among: " . implode(', ', array_map(
            static fn (PlannedFile $f): string => basename($f->path),
            $files,
        )));
    }

    private function assertPhpLints(string $code): void
    {
        $tmp = $this->root . '/lint-' . uniqid() . '.php';
        file_put_contents($tmp, $code);
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $exitCode);
        unlink($tmp);

        $this->assertSame(0, $exitCode, "php -l failed:\n" . implode("\n", $output));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
