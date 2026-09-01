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

use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\Generators\TestGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The judge scaffold: where it lands, what namespace its location dictates, and — the decision that
 * matters — that it FAILS on purpose until somebody declares real behavior. A scaffold that passed
 * vacuously would green-light every body the moment the file exists: a judge that judges nothing,
 * wearing a verdict.
 */
final class TestGeneratorTest extends TestCase
{
    public function testTheScaffoldLandsWhereTheGateWillLookForIt(): void
    {
        $r = (new TestGenerator())->generate(new GenerationContext('Demo', 'GreeterService', [], '/tmp/x'));

        self::assertCount(1, $r->files);
        self::assertSame('tests/Plugins/Demo/GreeterServiceTest.php', $r->files[0]->path);
        self::assertStringContainsString('namespace App\\Tests\\Plugins\\Demo;', $r->files[0]->contents);
        self::assertStringContainsString('final class GreeterServiceTest extends TestCase', $r->files[0]->contents);
        self::assertStringContainsString('declare(strict_types=1)', $r->files[0]->contents);
    }

    /** The scaffold is RED by construction — a vacuous judge must not green-light anything. */
    public function testTheScaffoldFailsOnPurposeUntilTheJudgeIsReal(): void
    {
        $r = (new TestGenerator())->generate(new GenerationContext('Demo', 'GreeterService', [], '/tmp/x'));

        self::assertStringContainsString('self::fail(', $r->files[0]->contents);
        self::assertStringNotContainsString('markTestIncomplete', $r->files[0]->contents);
        self::assertStringContainsString('implement', (string) $r->guidance);
    }

    /**
     * D-02 fixture (evidence/0451, export:1020): `make what=test name=TareaServiceTest` must not
     * invent `TareaServiceTestTest`. The name already IS the judge; doubling the suffix creates a
     * phantom class the landing gate cannot find, and the house taught that cycle ×44.
     */
    public function testANameThatAlreadyEndsInTestDoesNotDoubleTheSuffix(): void
    {
        $r = (new TestGenerator())->generate(new GenerationContext(
            'TareasPlugin',
            'TareaServiceTest',
            [],
            '/tmp/x',
        ));

        self::assertCount(1, $r->files);
        self::assertSame(
            'tests/Plugins/TareasPlugin/TareaServiceTest.php',
            $r->files[0]->path,
            'the judge file is TareaServiceTest.php — never TareaServiceTestTest.php',
        );
        self::assertStringContainsString(
            'final class TareaServiceTest extends TestCase',
            $r->files[0]->contents,
        );
        self::assertStringNotContainsString('TareaServiceTestTest', $r->files[0]->contents);
        self::assertStringNotContainsString('TareaServiceTestTest', (string) $r->guidance);
        self::assertStringContainsString(
            '{@see \\App\\Plugins\\TareasPlugin\\TareaService}',
            $r->files[0]->contents,
            'the judge names the REAL target, not a doubled-suffix phantom',
        );
        self::assertStringContainsString(
            'declare what TareaService must DO',
            $r->files[0]->contents,
        );
    }

    /**
     * A name that does not end in `Test` still gets the suffix ONCE. Control for the dedup:
     * `Latest` ends in `est`, not `Test` — stripping by a looser suffix would rename the target.
     */
    public function testANameThatDoesNotEndInTestStillGetsTheSuffixOnce(): void
    {
        $r = (new TestGenerator())->generate(new GenerationContext('Demo', 'Latest', [], '/tmp/x'));

        self::assertSame('tests/Plugins/Demo/LatestTest.php', $r->files[0]->path);
        self::assertStringContainsString('final class LatestTest extends TestCase', $r->files[0]->contents);
        self::assertStringContainsString('{@see \\App\\Plugins\\Demo\\Latest}', $r->files[0]->contents);
    }

    /**
     * Bare `Test` is a target named Test, not an empty remainder. Stripping the suffix off this
     * name would collapse the judge into nothing.
     */
    public function testABareTestNameDoesNotCollapseToAnEmptyTarget(): void
    {
        $r = (new TestGenerator())->generate(new GenerationContext('Demo', 'Test', [], '/tmp/x'));

        self::assertSame('tests/Plugins/Demo/TestTest.php', $r->files[0]->path);
        self::assertStringContainsString('final class TestTest extends TestCase', $r->files[0]->contents);
        self::assertStringContainsString('{@see \\App\\Plugins\\Demo\\Test}', $r->files[0]->contents);
    }

    /**
     * When the target does not exist yet, guidance names it and offers two legal next steps —
     * create it first, or implement judge and target together. It never teaches the circular
     * sequence from the fixture: "Fill the judge with implement — then land the body".
     */
    public function testGuidanceDoesNotInstructACircularCycleWhenTheTargetIsMissing(): void
    {
        $r = (new TestGenerator())->generate(new GenerationContext(
            'TareasPlugin',
            'TareaServiceTest',
            [],
            '/tmp/x',
        ));

        $guidance = (string) $r->guidance;
        self::assertStringContainsString('TareaService', $guidance, 'guidance names the REAL target');
        self::assertStringContainsString('TareaServiceTest', $guidance, 'guidance names the judge that was actually produced');
        self::assertStringNotContainsString('TareaServiceTestTest', $guidance);
        self::assertStringNotContainsString('then land', $guidance);
        self::assertStringNotContainsString('Fill the judge with `implement`', $guidance);
        self::assertMatchesRegularExpression(
            '/create the target first|implement .+ together/i',
            $guidance,
            'missing target: create it first, or implement judge and target together',
        );
    }

    /**
     * When the target already exists, guidance still names THAT class (not a doubled-suffix
     * phantom) and still refuses the circular "fill the judge then land the body" sequence —
     * the judge runs when the existing target lands; it does not invent a second body to write.
     */
    public function testGuidanceNamesTheExistingTargetAndNeverInstructsACycle(): void
    {
        $root = sys_get_temp_dir() . '/milpa-devtools-testdedup-' . bin2hex(random_bytes(4));
        mkdir($root . '/src/Plugins/TareasPlugin/Services', 0o775, true);
        file_put_contents(
            $root . '/src/Plugins/TareasPlugin/Services/TareaService.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\TareasPlugin\\Services;\n\nfinal class TareaService\n{\n}\n",
        );

        try {
            $r = (new TestGenerator())->generate(new GenerationContext(
                'TareasPlugin',
                'TareaServiceTest',
                [],
                $root,
            ));

            $guidance = (string) $r->guidance;
            self::assertSame('tests/Plugins/TareasPlugin/TareaServiceTest.php', $r->files[0]->path);
            self::assertStringContainsString('TareaService', $guidance);
            self::assertStringContainsString('TareaServiceTest', $guidance);
            self::assertStringContainsString('implement', $guidance);
            self::assertStringNotContainsString('TareaServiceTestTest', $guidance);
            self::assertStringNotContainsString('then land', $guidance);
            self::assertStringNotContainsString('Fill the judge with `implement`', $guidance);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }
}
