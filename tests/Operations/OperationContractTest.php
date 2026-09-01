<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Operations;

use Milpa\Command\DeclaredCondition;
use Milpa\Command\Operation;
use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\Generators\ResourceGenerator;
use Milpa\DevTools\Make\PostconditionVerifier;
use Milpa\DevTools\Operations\DevToolsOperations;
use Milpa\DevTools\Operations\MakeHandler;
use Milpa\DevTools\Operations\TestHandler;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/**
 * The declared OperationContract cannot lie (greenhouse decisions/0183).
 *
 * Two laws, each encoded as a falsifier:
 *
 * - F-PRE — every DECLARED precondition is backed by the handler's refusal: for each one, this test
 *   violates it and asserts the handler refuses. The tie is an explicit declaration↔violation map,
 *   asserted complete against the declaration itself, so a condition declared without enforcement
 *   has no row in the map and turns the test red — it cannot land as prose.
 * - F-POST — the `make` declaration and the {@see PostconditionVerifier} emit from ONE authority:
 *   the verifier's public constants. The declared names are asserted identical to that constant
 *   set, and a real widest-kind run's emitted names are asserted inside the declared union. Drift
 *   is structurally impossible because both sides read the same constants; this test is the
 *   tripwire for the one move left open, adding a name on one side only.
 */
final class OperationContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-contract-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    /** F-PRE: every precondition `make` declares is enforced by a refusal this test provokes. */
    public function testEveryDeclaredMakePreconditionIsBackedByARefusal(): void
    {
        // declaration name → [violating input, fragment the refusal must carry, extra setup]
        $violations = [
            'identifier-shaped-names' => [
                ['what' => 'entity', 'plugin' => '../evil', 'name' => 'Task'],
                '^[A-Za-z_][A-Za-z0-9_]*$',
                null,
            ],
            'plugin-directory-exists' => [
                ['what' => 'entity', 'plugin' => 'Missing', 'name' => 'Task'],
                'no existe el directorio del plugin',
                function (): void {
                    // `milpa.json` is the self-declaring legacy signal ConventionDetector honours.
                    file_put_contents($this->root . '/milpa.json', '{}');
                },
            ],
        ];

        $declared = array_map(
            static fn (DeclaredCondition $c): string => $c->name,
            $this->operation('make')->preconditions,
        );
        self::assertSame(
            array_keys($violations),
            $declared,
            'the map covers EXACTLY what make declares — a declared precondition with no violation row here is an unenforced declaration, and it is red',
        );

        foreach ($violations as $condition => [$input, $fragment, $setup]) {
            if ($setup !== null) {
                $setup();
            }
            $result = (new MakeHandler(new RootResolver($this->root)))->handle($input);

            self::assertFalse($result['ok'], "violating «{$condition}» must be refused");
            self::assertSame([], $result['files'], "a refusal over «{$condition}» writes nothing");
            self::assertStringContainsString($fragment, (string) $result['error'], "the refusal backs «{$condition}»");
        }
    }

    /** F-PRE: every precondition `test` declares is enforced by a refusal this test provokes. */
    public function testEveryDeclaredTestPreconditionIsBackedByARefusal(): void
    {
        $violations = [
            'phpunit-installed' => [
                [],
                'composer require --dev phpunit/phpunit',
                null,
            ],
            'path-inside-root' => [
                ['path' => '../../../etc'],
                'dentro de',
                function (): void {
                    mkdir($this->root . '/vendor/bin', 0o775, true);
                    file_put_contents($this->root . '/vendor/bin/phpunit', "#!/bin/sh\n");
                },
            ],
        ];

        $declared = array_map(
            static fn (DeclaredCondition $c): string => $c->name,
            $this->operation('test')->preconditions,
        );
        self::assertSame(
            array_keys($violations),
            $declared,
            'the map covers EXACTLY what test declares — an unenforced declaration has no row here and is red',
        );

        foreach ($violations as $condition => [$input, $fragment, $setup]) {
            if ($setup !== null) {
                $setup();
            }
            $result = (new TestHandler(new RootResolver($this->root)))->handle($input);

            self::assertFalse($result['ok'], "violating «{$condition}» must be refused");
            self::assertFalse($result['ran'], "a violated «{$condition}» refuses BEFORE running — not a red suite");
            self::assertStringContainsString($fragment, (string) $result['error'], "the refusal backs «{$condition}»");
        }
    }

    /** F-POST: the declared postcondition names ARE the verifier's constants — one authority, cited twice. */
    public function testTheMakePostconditionDeclarationSharesTheVerifierAuthority(): void
    {
        $declared = array_map(
            static fn (DeclaredCondition $c): string => $c->name,
            $this->operation('make')->postconditions,
        );

        self::assertSame(
            [
                ...PostconditionVerifier::STATIC_NAMES,
                PostconditionVerifier::PREFIX_ENUM,
                PostconditionVerifier::PREFIX_RELATION,
            ],
            $declared,
            'the declaration cites the exact constants the verifier emits from — a name on one side only is red',
        );
    }

    /** F-POST, run side: everything a real widest-kind run emits falls inside the declared union. */
    public function testEveryEmittedPostconditionNameFallsInsideTheDeclaredUnion(): void
    {
        file_put_contents(
            $this->root . '/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]]),
        );
        $ctx = new GenerationContext(
            plugin: 'Board',
            name: 'Task',
            options: ['flavor' => 'runtime', 'fields' => 'title:string, priority:enum:Priority(low,high), owner:belongsTo:User'],
            root: $this->root,
        );
        foreach ((new ResourceGenerator())->generate($ctx)->files as $file) {
            if (!is_dir(\dirname($file->path))) {
                mkdir(\dirname($file->path), 0o775, true);
            }
            file_put_contents($file->path, $file->contents);
        }

        $report = (new PostconditionVerifier())->verify('resource', $ctx, Flavor::Runtime);

        self::assertNotSame([], $report->checks, 'the widest kind emits checks — an empty report would prove nothing');
        foreach ($report->checks as $check) {
            $static = \in_array($check->name, PostconditionVerifier::STATIC_NAMES, true);
            $dynamic = str_starts_with($check->name, PostconditionVerifier::PREFIX_ENUM)
                || str_starts_with($check->name, PostconditionVerifier::PREFIX_RELATION);
            self::assertTrue(
                $static || $dynamic,
                "emitted «{$check->name}» is outside the declared union — declare it or stop emitting it",
            );
        }
    }

    /** Additive: operations that declare no contract keep exactly the empty shape they always had. */
    public function testOperationsWithoutADeclaredContractKeepTheEmptyShape(): void
    {
        foreach (['validate', 'implement', 'edit', 'artifact:contract', 'source:read'] as $name) {
            $op = $this->operation($name);
            self::assertSame([], $op->preconditions, $name);
            self::assertSame([], $op->postconditions, $name);
            self::assertSame([], $op->artifacts, $name);
            self::assertNull($op->observableEvidence, $name);
        }
    }

    /** make declares what it produces and both declare what proves a run; test leaves no artifact. */
    public function testArtifactsAndObservableEvidenceAreDeclaredWhereTrue(): void
    {
        $make = $this->operation('make');
        self::assertNotSame([], $make->artifacts, 'make produces files and says so');
        self::assertStringContainsString('postcondition report', (string) $make->observableEvidence);

        $test = $this->operation('test');
        self::assertSame([], $test->artifacts, 'the suite leaves a verdict, not an artifact');
        self::assertStringContainsString('exit code', (string) $test->observableEvidence);
    }

    private function operation(string $name): Operation
    {
        foreach ((new DevToolsOperations())->operations() as $op) {
            if ($op->name === $name) {
                return $op;
            }
        }
        self::fail("no operation named '{$name}'");
    }
}
