<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\GenerationResult;
use Milpa\DevTools\Make\Generators\CrudGenerator;
use Milpa\DevTools\Make\Generators\EntityGenerator;
use Milpa\DevTools\Make\PostconditionVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see PostconditionVerifier}: that a runtime `make:entity`/`make:crud` is reported COMPLETE
 * only when every promised consequence — the entity/controller files, each referenced enum, the
 * repository/controller registrations, the declared routes — is actually on disk, and `incomplete`
 * (naming what is missing) the moment one dangles. The generators write into a real temp tree
 * (mirroring {@see CrudGeneratorTest}), then only the files a scenario keeps are materialised, so an
 * absent enum or an unwired plugin is exactly what the caller of a half-finished scaffold would face.
 */
final class PostconditionVerifierTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-postcond-' . uniqid();
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

    public function testACompleteEntityRunPassesEveryRequiredConsequence(): void
    {
        $ctx = $this->context('BoardPlugin', 'Task', 'title:string, priority:enum:Priority(low,high)');
        $this->writeFiles((new EntityGenerator())->generate($ctx));

        $report = (new PostconditionVerifier())->verify('entity', $ctx, Flavor::Runtime);

        self::assertTrue($report->ok(), 'a fully scaffolded entity has no dangling consequence: ' . implode(', ', $report->missing()));
        self::assertSame([], $report->missing());
        self::assertTrue($this->check($report, 'entity_file'));
        self::assertTrue($this->check($report, 'enum:Priority'), 'a declared-cases enum is materialised, so it resolves');
        self::assertTrue($this->check($report, 'repository_registered'));
    }

    public function testADanglingReferencedEnumMakesTheRunIncomplete(): void
    {
        // `enum:Priority` (no cases) REFERENCES an enum made elsewhere — make does not create it, so
        // if it is not already on disk the entity carries a dangling reference. This is the exact
        // defect the layer exists to catch.
        $ctx = $this->context('BoardPlugin', 'Task', 'title:string, priority:enum:Priority');
        $this->writeFiles((new EntityGenerator())->generate($ctx));

        $report = (new PostconditionVerifier())->verify('entity', $ctx, Flavor::Runtime);

        self::assertFalse($report->ok());
        self::assertContains('enum:Priority', $report->missing());
        self::assertFalse($this->check($report, 'enum:Priority'));
        self::assertTrue($this->check($report, 'entity_file'), 'the entity itself was still written');
    }

    /**
     * The closure control, positive side: a hand-written plugin with NO markers but a parseable
     * boot() is no longer a guidance case — EntityGenerator splices the registration structurally,
     * so the postcondition layer finds the consequence and the run is COMPLETE.
     */
    public function testAnUnmarkedButParseablePluginIsAutoWiredAndTheRunIsComplete(): void
    {
        $this->preexistingPluginWithoutMarkers('BoardPlugin');
        $ctx = $this->context('BoardPlugin', 'Task', 'title:string');
        $this->writeFiles((new EntityGenerator())->generate($ctx));

        $report = (new PostconditionVerifier())->verify('entity', $ctx, Flavor::Runtime);

        self::assertTrue($report->ok(), 'structural wiring closed the gap: ' . implode(', ', $report->missing()));
        self::assertTrue($this->check($report, 'repository_registered'));
        self::assertTrue($this->check($report, 'entity_file'));
    }

    /**
     * The fail-closed control (P0.2): ONLY a plugin file the surgeon refuses — here, hand-mangled to
     * carry no class declaration — may still leave the wiring unlanded, and then the report NAMES
     * the file and the reason instead of a generic "landed as guidance".
     */
    public function testAnUnwirablePluginReportsTheMissingRepositoryNamingFileAndReason(): void
    {
        $this->preexistingUnwirablePlugin('BoardPlugin');
        $ctx = $this->context('BoardPlugin', 'Task', 'title:string');
        $this->writeFiles((new EntityGenerator())->generate($ctx));

        $report = (new PostconditionVerifier())->verify('entity', $ctx, Flavor::Runtime);

        self::assertFalse($report->ok());
        self::assertContains('repository_registered', $report->missing());
        self::assertTrue($this->check($report, 'entity_file'));

        $detail = $this->detail($report, 'repository_registered');
        self::assertStringContainsString('could not be auto-wired', $detail);
        self::assertStringContainsString('no class declaration found', $detail, 'the REASON is named');
        self::assertStringContainsString($this->root . '/src/Plugins/BoardPlugin/BoardPlugin.php', $detail, 'the FILE is named');
    }

    public function testACompleteCrudRunPassesEveryRequiredConsequence(): void
    {
        $ctx = $this->context('BoardPlugin', 'Task', 'title:string');
        $this->writeFiles((new CrudGenerator())->generate($ctx));

        $report = (new PostconditionVerifier())->verify('crud', $ctx, Flavor::Runtime);

        self::assertTrue($report->ok(), 'complete crud missing: ' . implode(', ', $report->missing()));
        self::assertTrue($this->check($report, 'entity_file'));
        self::assertTrue($this->check($report, 'controller_file'));
        self::assertTrue($this->check($report, 'repository_registered'));
        self::assertTrue($this->check($report, 'controller_registered'));
        self::assertTrue($this->check($report, 'routes_declared'));
    }

    public function testAnUnwirableCrudPluginReportsEveryUnwiredConsequenceWithTheReason(): void
    {
        $this->preexistingUnwirablePlugin('BoardPlugin');
        $ctx = $this->context('BoardPlugin', 'Task', 'title:string');
        $this->writeFiles((new CrudGenerator())->generate($ctx));

        $report = (new PostconditionVerifier())->verify('crud', $ctx, Flavor::Runtime);

        self::assertFalse($report->ok());
        self::assertContains('repository_registered', $report->missing());
        self::assertContains('controller_registered', $report->missing());
        self::assertContains('routes_declared', $report->missing());
        // The files themselves were still produced — only the wiring dangles, with the reason named.
        self::assertTrue($this->check($report, 'entity_file'));
        self::assertTrue($this->check($report, 'controller_file'));
        self::assertStringContainsString('no class declaration found', $this->detail($report, 'routes_declared'));
    }

    /**
     * And the same crud on an unmarked-but-parseable plugin is COMPLETE — the structural splice
     * closes all three wiring consequences at once.
     */
    public function testACrudOnAnUnmarkedButParseablePluginIsComplete(): void
    {
        $this->preexistingPluginWithoutMarkers('BoardPlugin');
        $ctx = $this->context('BoardPlugin', 'Task', 'title:string');
        $this->writeFiles((new CrudGenerator())->generate($ctx));

        $report = (new PostconditionVerifier())->verify('crud', $ctx, Flavor::Runtime);

        self::assertTrue($report->ok(), 'structural wiring closed the gaps: ' . implode(', ', $report->missing()));
        self::assertTrue($this->check($report, 'repository_registered'));
        self::assertTrue($this->check($report, 'controller_registered'));
        self::assertTrue($this->check($report, 'routes_declared'));
    }

    public function testPluginRegistrationIsAdvisoryAndNeverFailsTheRun(): void
    {
        $ctx = $this->context('BoardPlugin', 'Task', 'title:string');
        $this->writeFiles((new EntityGenerator())->generate($ctx));

        $report = (new PostconditionVerifier())->verify('entity', $ctx, Flavor::Runtime);

        // No config/plugins.php exists, so the plugin is not activated — but that is a handoff, not a
        // dangling reference: the check is present and failing, yet the run is still complete.
        self::assertFalse($this->check($report, 'plugin_registered'));
        self::assertNotContains('plugin_registered', $report->missing());
        self::assertTrue($report->ok());

        // Once it IS listed, the advisory check flips.
        mkdir($this->root . '/config', 0o775, true);
        file_put_contents(
            $this->root . '/config/plugins.php',
            "<?php\n\nreturn [\n    App\\Plugins\\BoardPlugin\\BoardPlugin::class,\n];\n",
        );
        $after = (new PostconditionVerifier())->verify('entity', $ctx, Flavor::Runtime);
        self::assertTrue($this->check($after, 'plugin_registered'));
    }

    public function testAKindWithNoFilesystemConsequencesGetsAnEmptyReport(): void
    {
        $ctx = $this->context('BoardPlugin', 'Whatever', '');
        $report = (new PostconditionVerifier())->verify('controller', $ctx, Flavor::Runtime);

        self::assertSame([], $report->toArray()['checks']);
        self::assertTrue($report->ok());
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

    private function preexistingPluginWithoutMarkers(string $plugin): void
    {
        $dir = $this->root . '/src/Plugins/' . $plugin;
        mkdir($dir, 0o775, true);
        file_put_contents(
            $dir . '/' . $plugin . '.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\{$plugin};\n\n"
                . "final class {$plugin}\n{\n    public function boot(): void\n    {\n    }\n}\n",
        );
    }

    /** A hand-mangled plugin file (no class declaration) — the one shape the structural inserter refuses. */
    private function preexistingUnwirablePlugin(string $plugin): void
    {
        $dir = $this->root . '/src/Plugins/' . $plugin;
        mkdir($dir, 0o775, true);
        file_put_contents(
            $dir . '/' . $plugin . '.php',
            "<?php\n// hand-mangled: no class declaration in here\n",
        );
    }

    /** The detail text of the named check — the report's own explanation of what it found. */
    private function detail(\Milpa\DevTools\Make\PostconditionReport $report, string $name): string
    {
        foreach ($report->checks as $c) {
            if ($c->name === $name) {
                return $c->detail;
            }
        }
        self::fail("no postcondition check named '{$name}'");
    }

    private function check(\Milpa\DevTools\Make\PostconditionReport $report, string $name): bool
    {
        foreach ($report->checks as $c) {
            if ($c->name === $name) {
                return $c->ok;
            }
        }
        self::fail("no postcondition check named '{$name}'");
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
