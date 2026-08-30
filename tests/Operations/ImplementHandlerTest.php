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

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Operations\ImplementHandler;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/**
 * The atom that lets an agent WRITE the body of what it scaffolded — through a gate, not around it.
 *
 * Measured on three real sessions (long-session-three-arms.tsv): `make` produces the exact structure
 * a task names, and every service lands as an unfilled shell, because no operation fills bodies. The
 * catalogue bounds the achievable, and a task worded beyond the catalogue produces structure that
 * LOOKS like the task. This operation raises that ceiling — with landing as a POSTCONDITION: what
 * does not verify clean does not touch the original. Ever.
 */
final class ImplementHandlerTest extends TestCase
{
    private string $raiz;

    protected function setUp(): void
    {
        $this->raiz = sys_get_temp_dir() . '/milpa-implement-' . bin2hex(random_bytes(4));
        mkdir($this->raiz . '/src/Plugins/Demo/Services', 0o775, true);
        file_put_contents(
            $this->raiz . '/src/Plugins/Demo/Services/GreeterService.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\Demo\\Services;\n\n"
            . "final class GreeterService\n{\n    // Fill me.\n}\n",
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->raiz));
    }

    private function handler(): ImplementHandler
    {
        return new ImplementHandler(new RootResolver($this->raiz));
    }

    /** @return array<string, mixed> */
    private function implement(string $content, string $class = 'GreeterService', string $plugin = 'Demo'): array
    {
        return $this->handler()->handle(['plugin' => $plugin, 'class' => $class, 'content' => $content]);
    }

    private function contenidoValido(): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\Demo\\Services;\n\n"
            . "final class GreeterService\n{\n    public function greet(string \$name): string\n"
            . "    {\n        return 'hola ' . \$name;\n    }\n}\n";
    }

    /** The green path: a body that verifies clean replaces the scaffold, and says where it landed. */
    public function testABodyThatVerifiesCleanLands(): void
    {
        $r = $this->implement($this->contenidoValido());

        self::assertTrue($r['ok'], $r['error'] ?? '');
        self::assertSame('src/Plugins/Demo/Services/GreeterService.php', $r['file']);
        self::assertStringContainsString(
            "return 'hola ' . \$name;",
            (string) file_get_contents($this->raiz . '/' . $r['file']),
        );
    }

    /**
     * THE guarantee this operation exists for: broken syntax is refused WITH the diagnostic — the
     * model corrects from it — and the original scaffold survives byte for byte. A landing gate
     * that half-writes on failure would be worse than no gate: it converts a bad generation into a
     * broken app.
     */
    public function testBrokenSyntaxIsRefusedAndTheOriginalSurvivesByteForByte(): void
    {
        $antes = (string) file_get_contents($this->raiz . '/src/Plugins/Demo/Services/GreeterService.php');

        $r = $this->implement("<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\Demo\\Services;\n\nfinal class GreeterService\n{\n    public function broken(\n}\n");

        self::assertFalse($r['ok']);
        self::assertStringContainsString('syntax', mb_strtolower($r['error']));
        self::assertSame(
            $antes,
            (string) file_get_contents($this->raiz . '/src/Plugins/Demo/Services/GreeterService.php'),
        );
    }

    /** The content must implement the class it names — a rename would leave a file that lies. */
    public function testAContentThatRenamesTheClassIsRefused(): void
    {
        $r = $this->implement(str_replace('GreeterService', 'OtherService', $this->contenidoValido()));

        self::assertFalse($r['ok']);
        self::assertStringContainsString('GreeterService', $r['error']);
    }

    /**
     * The namespace is a FACT of the file's location — the system resolves it, the author does not
     * guess it. A foreign namespace in the content lands CORRECTED to the location-dictated one; it is
     * never refused for it. (An agent should never re-guess what the system already knows.)
     */
    public function testAForeignNamespaceIsResolvedToTheExpectedOne(): void
    {
        $r = $this->implement(str_replace(
            'namespace App\\Plugins\\Demo\\Services;',
            'namespace App\\Elsewhere;',
            $this->contenidoValido(),
        ));

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $landed = (string) file_get_contents($this->raiz . '/src/Plugins/Demo/Services/GreeterService.php');
        self::assertStringContainsString('namespace App\\Plugins\\Demo\\Services;', $landed);
        self::assertStringNotContainsString('App\\Elsewhere', $landed);
    }

    /** No namespace at all is not a lie to refuse — the system injects the one the location dictates. */
    public function testAMissingNamespaceIsInjectedFromTheLocation(): void
    {
        $r = $this->implement(str_replace(
            "namespace App\\Plugins\\Demo\\Services;\n\n",
            '',
            $this->contenidoValido(),
        ));

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $landed = (string) file_get_contents($this->raiz . '/src/Plugins/Demo/Services/GreeterService.php');
        self::assertStringContainsString('namespace App\\Plugins\\Demo\\Services;', $landed);
    }

    /** House rule, enforced at the gate: every PHP file declares strict types. */
    public function testMissingStrictTypesIsRefused(): void
    {
        $r = $this->implement(str_replace("declare(strict_types=1);\n\n", '', $this->contenidoValido()));

        self::assertFalse($r['ok']);
        self::assertStringContainsString('strict_types', $r['error']);
    }

    /** Filling is not creating: a class nobody scaffolded belongs to `make`, and the error says so. */
    public function testAClassThatWasNeverScaffoldedIsRefusedTowardMake(): void
    {
        $r = $this->implement($this->contenidoValido(), 'GhostService');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('make', $r['error']);
    }

    /** The class is a bare identifier — anything path-shaped is refused before touching the disk. */
    public function testAPathShapedClassNameIsRefused(): void
    {
        foreach (['../Kernel', 'Services/GreeterService', 'Greeter.Service', ''] as $malo) {
            $r = $this->implement($this->contenidoValido(), $malo);
            self::assertFalse($r['ok'], "aceptó «{$malo}»");
        }
    }

    /** A plugin that does not exist under this app cannot receive code. */
    public function testAPluginThatDoesNotExistIsRefused(): void
    {
        $r = $this->implement($this->contenidoValido(), 'GreeterService', 'Nope');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('Nope', $r['error']);
    }

    // ── The behavioral judge: the class's own test, run INSIDE the landing gate ──────────────────
    //
    // Measured on a real session: a service landed conformant — syntax, interface, namespace all
    // clean — while its solicitar() SIMULATED persistence in a comment. Linkage has a judge and
    // the app suite has a judge; behavior had none. The judge is not a new operation and never an
    // LLM reading code: it is the test that already declares what the class must DO, executed as
    // one more landing postcondition. These tests run a REAL phpunit — a fake judging a fake would
    // prove the seam, not the judgment.

    /** Prepares the class's behavioral test demanding `greet()` actually greets. */
    private function conJuezConductual(): void
    {
        mkdir($this->raiz . '/tests/Plugins/Demo', 0o775, true);
        file_put_contents(
            $this->raiz . '/tests/bootstrap.php',
            "<?php require __DIR__ . '/../src/Plugins/Demo/Services/GreeterService.php';\n",
        );
        file_put_contents(
            $this->raiz . '/tests/Plugins/Demo/GreeterServiceTest.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse PHPUnit\\Framework\\TestCase;\n\n"
            . "final class GreeterServiceTest extends TestCase\n{\n"
            . "    public function testGreetGreetsForReal(): void\n    {\n"
            . "        \$g = new App\\Plugins\\Demo\\Services\\GreeterService();\n"
            . "        self::assertSame('hola rod', \$g->greet('rod'));\n    }\n}\n",
        );
    }

    /** A handler whose behavior runner is the REAL phpunit over the fixture's bootstrap. */
    private function handlerConJuez(): ImplementHandler
    {
        // The package's OWN vendor first, the monorepo root as fallback: the exported tree has no
        // monorepo above it, and the old single path resolved to /tmp/../vendor there — caught by
        // the release train's exported-suite step, invisible from the monorepo where both exist.
        $phpunit = \dirname(__DIR__, 2) . '/vendor/bin/phpunit';
        if (!is_file($phpunit)) {
            $phpunit = \dirname(__DIR__, 3) . '/../vendor/bin/phpunit';
        }
        $runner = $this->raiz . '/juez.sh';
        file_put_contents($runner, "#!/bin/sh\n" . escapeshellarg($phpunit)
            . ' --bootstrap ' . escapeshellarg($this->raiz . '/tests/bootstrap.php') . " \"\$1\" 2>&1\n");
        chmod($runner, 0o755);

        return new ImplementHandler(new RootResolver($this->raiz), behaviorRunner: $runner);
    }

    /**
     * THE measured case, reconstructed: a body that fakes the behavior lands clean through every
     * prior gate — and the class's own test refuses it, with the original intact and the phpunit
     * verdict travelling back as the diagnostic.
     */
    public function testABodyThatFakesTheBehaviorIsRefusedByTheClassOwnTest(): void
    {
        $this->conJuezConductual();
        $antes = (string) file_get_contents($this->raiz . '/src/Plugins/Demo/Services/GreeterService.php');

        $r = $this->handlerConJuez()->handle(['plugin' => 'Demo', 'class' => 'GreeterService',
            'content' => str_replace("'hola ' . \$name", "'bye ' . \$name", $this->contenidoValido())]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('behavior', $r['error']);
        self::assertStringContainsString('GreeterServiceTest', $r['error']);
        self::assertSame($antes, (string) file_get_contents($this->raiz . '/src/Plugins/Demo/Services/GreeterService.php'));
    }

    /** The honest body passes its judge and the landing SAYS which test went green. */
    public function testABodyThatBehavesLandsNamingItsGreenJudge(): void
    {
        $this->conJuezConductual();

        $r = $this->handlerConJuez()->handle(['plugin' => 'Demo', 'class' => 'GreeterService',
            'content' => $this->contenidoValido()]);

        self::assertTrue($r['ok'], $r['error'] ?? '');
        self::assertStringContainsString('behavior', $r['verified']);
        self::assertStringContainsString('GreeterServiceTest', $r['verified']);
    }

    /**
     * Without a test the landing still lands — Q-P19-R measured that OBLIGATING a criterion made
     * everything worse — but the result SAYS the behavior went unjudged. A silent gap reads as
     * covered, and that is how a simulated persistence ships wearing green.
     */
    public function testWithoutATestTheLandingSaysBehaviorWentUnjudged(): void
    {
        $r = $this->handlerConJuez()->handle(['plugin' => 'Demo', 'class' => 'GreeterService',
            'content' => $this->contenidoValido()]);

        self::assertTrue($r['ok'], $r['error'] ?? '');
        self::assertStringContainsString('behavior unjudged', $r['verified']);
    }

    // ── The judge is a class too: implement reaches tests/, and never judges itself ──────────────

    /**
     * A test class under `tests/Plugins/<plugin>/` is fillable through the SAME gate — with the
     * namespace its location dictates (`App\Tests\…`) — and the behavioral judge does NOT run it
     * against the current shell: a judge that judged itself while landing would make TDD's red
     * unlandable, and the whole flow depends on the test landing BEFORE the body satisfies it.
     */
    public function testAJudgeLandsThroughTheGateWithoutJudgingItself(): void
    {
        mkdir($this->raiz . '/tests/Plugins/Demo', 0o775, true);
        file_put_contents(
            $this->raiz . '/tests/Plugins/Demo/GreeterServiceTest.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests\\Plugins\\Demo;\n\n// Fill me.\n"
        );

        $r = $this->handlerConJuez()->handle(['plugin' => 'Demo', 'class' => 'GreeterServiceTest',
            'content' => $this->juezExigente()]);

        self::assertTrue($r['ok'], $r['error'] ?? '');
        self::assertSame('tests/Plugins/Demo/GreeterServiceTest.php', $r['file']);
        self::assertStringContainsString('IS a judge', $r['verified']);
    }

    /**
     * The whole TDD machine flow, with a real phpunit: the judge lands first (red against the
     * shell, landable because it never judges itself) — then a faking body is REFUSED by it — then
     * the honest body lands green. Order as a property of the gates, not as a suggestion.
     */
    public function testTheFullTddFlowLandsJudgeFirstThenRefusesTheFakeThenLandsTheHonest(): void
    {
        mkdir($this->raiz . '/tests/Plugins/Demo', 0o775, true);
        file_put_contents(
            $this->raiz . '/tests/bootstrap.php',
            "<?php require __DIR__ . '/../src/Plugins/Demo/Services/GreeterService.php';\n"
        );
        file_put_contents(
            $this->raiz . '/tests/Plugins/Demo/GreeterServiceTest.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests\\Plugins\\Demo;\n\n// Fill me.\n"
        );
        $h = $this->handlerConJuez();

        $juez = $h->handle(['plugin' => 'Demo', 'class' => 'GreeterServiceTest', 'content' => $this->juezExigente()]);
        self::assertTrue($juez['ok'], $juez['error'] ?? '');

        $falso = $h->handle(['plugin' => 'Demo', 'class' => 'GreeterService',
            'content' => str_replace("'hola ' . \$name", "'bye ' . \$name", $this->contenidoValido())]);
        self::assertFalse($falso['ok'], 'el cuerpo que finge aterrizó');
        self::assertStringContainsString('behavior', $falso['error']);

        $honesto = $h->handle(['plugin' => 'Demo', 'class' => 'GreeterService', 'content' => $this->contenidoValido()]);
        self::assertTrue($honesto['ok'], $honesto['error'] ?? '');
        self::assertStringContainsString('GreeterServiceTest green', $honesto['verified']);
    }

    /** A complete judge for GreeterService, in the namespace its location dictates. */
    private function juezExigente(): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests\\Plugins\\Demo;\n\n"
            . "use PHPUnit\\Framework\\TestCase;\n\n"
            . "final class GreeterServiceTest extends TestCase\n{\n"
            . "    public function testGreetGreetsForReal(): void\n    {\n"
            . "        \$g = new \\App\\Plugins\\Demo\\Services\\GreeterService();\n"
            . "        self::assertSame('hola rod', \$g->greet('rod'));\n    }\n}\n";
    }

    /** An app without an analyzer still lands — and the result SAYS the analysis did not run. */
    public function testWithoutAnAnalyzerTheLandingSaysSo(): void
    {
        $r = $this->implement($this->contenidoValido());

        self::assertTrue($r['ok']);
        self::assertStringContainsString('static analysis unavailable', $r['verified']);
    }

    /**
     * The analyzer's findings refuse the landing AND restore the original byte for byte — the
     * transactional guarantee holds through the in-place analysis. The analyzer is a seam (a
     * script that always finds a problem): the real PHPStan was measured against the three real
     * failed landings of the first session, ~1s each, both defect classes named surgically.
     */
    public function testAnalyzerFindingsRefuseTheLandingAndRestoreTheOriginal(): void
    {
        $before = (string) file_get_contents($this->raiz . '/src/Plugins/Demo/Services/GreeterService.php');
        $r = $this->conAnalizador('echo "GreeterService.php:7:unknown class Demo\\Missing"; exit 1')
            ->handle(['plugin' => 'Demo', 'class' => 'GreeterService', 'content' => $this->contenidoValido()]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('unknown class', $r['error']);
        self::assertSame(
            $before,
            (string) file_get_contents($this->raiz . '/src/Plugins/Demo/Services/GreeterService.php'),
            'the analyzer refused but the bad content stayed on disk',
        );
    }

    /** A clean analysis lands, and the result claims exactly the checks that ran. */
    public function testACleanAnalysisLandsClaimingStaticConformance(): void
    {
        $r = $this->conAnalizador('exit 0')
            ->handle(['plugin' => 'Demo', 'class' => 'GreeterService', 'content' => $this->contenidoValido()]);

        self::assertTrue($r['ok'], $r['error'] ?? '');
        self::assertStringContainsString('static conformance', $r['verified']);
        self::assertStringContainsString(
            "return 'hola ' . \$name;",
            (string) file_get_contents($this->raiz . '/' . $r['file']),
        );
    }

    /** A handler whose analyzer is a shell seam — `true`/`exit 1` scripts instead of a binary. */
    private function conAnalizador(string $script): ImplementHandler
    {
        $bin = $this->raiz . '/analyzer.sh';
        file_put_contents($bin, "#!/bin/sh\n{$script}\n");
        chmod($bin, 0o755);

        return new ImplementHandler(new RootResolver($this->raiz), $bin);
    }
}
