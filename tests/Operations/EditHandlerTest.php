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

use Milpa\DevTools\Operations\EditHandler;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/**
 * Editing by exact find→replace pairs — the shape born from a measurement: six whole-file landings
 * were refused in one real session because the model re-generates from its priors, and the sixth
 * attempt regressed to the first attempt's defect. A pair that must match EXACTLY ONCE cannot
 * smuggle a prior in: it touches the line it names or it refuses.
 *
 * The landing authority stays single: `edit` produces the candidate content and DELEGATES the whole
 * gate — syntax, strict types, class, namespace, static conformance, restore-on-failure — to the
 * same code `implement` runs. Two landing gates would diverge on the case nobody tested.
 */
final class EditHandlerTest extends TestCase
{
    private string $raiz;

    protected function setUp(): void
    {
        $this->raiz = sys_get_temp_dir() . '/milpa-edit-' . bin2hex(random_bytes(4));
        mkdir($this->raiz . '/src/Plugins/Demo/Services', 0o775, true);
        file_put_contents(
            $this->raiz . '/src/Plugins/Demo/Services/GreeterService.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\Demo\\Services;\n\n"
            . "final class GreeterService\n{\n    public function greet(string \$name): string\n"
            . "    {\n        return 'hola ' . \$name;\n    }\n}\n",
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->raiz));
    }

    /** @param list<array{find: string, replace: string}> $edits
     * @return array<string, mixed> */
    private function edit(array $edits, string $class = 'GreeterService', string $plugin = 'Demo'): array
    {
        return (new EditHandler(new RootResolver($this->raiz)))
            ->handle(['plugin' => $plugin, 'class' => $class, 'edits' => $edits]);
    }

    private function original(): string
    {
        return (string) file_get_contents($this->raiz . '/src/Plugins/Demo/Services/GreeterService.php');
    }

    /** One exact pair lands through the whole inherited gate, and says how many edits applied. */
    public function testAnExactPairLandsThroughTheWholeGate(): void
    {
        $r = $this->edit([['find' => "return 'hola ' . \$name;", 'replace' => "return 'bye ' . \$name;"]]);

        self::assertTrue($r['ok'], $r['error'] ?? '');
        self::assertSame(1, $r['edits_applied']);
        self::assertStringContainsString("return 'bye ' . \$name;", $this->original());
    }

    /** A pair whose `find` is absent refuses NAMING the miss — and nothing on disk moves. */
    public function testAFindThatDoesNotMatchIsRefusedAndNothingMoves(): void
    {
        $antes = $this->original();
        $r = $this->edit([['find' => 'no existo en el archivo', 'replace' => 'x']]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no existo en el archivo', $r['error']);
        self::assertSame($antes, $this->original());
    }

    /**
     * A `find` that matches twice is refused: applying it anyway would edit a place the request
     * never named — the exact prior-smuggling this operation exists to make impossible.
     */
    public function testAnAmbiguousFindIsRefused(): void
    {
        $antes = $this->original();
        $r = $this->edit([['find' => "\$name", 'replace' => "\$who"]]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('once', $r['error']);
        self::assertSame($antes, $this->original());
    }

    /** An edit that breaks the file is refused by the INHERITED gate, and the original survives. */
    public function testABreakingEditIsRefusedByTheInheritedGate(): void
    {
        $antes = $this->original();
        $r = $this->edit([['find' => 'public function greet(string $name): string', 'replace' => 'public function greet(']]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('syntax', mb_strtolower($r['error']));
        self::assertSame($antes, $this->original());
    }

    /** Empty edits are nothing to land — refused before touching anything. */
    public function testEmptyEditsAreRefused(): void
    {
        $r = $this->edit([]);

        self::assertFalse($r['ok']);
    }

    /**
     * The behavioral judge travels through the delegation: an edit that breaks what the class's
     * own test demands is refused by the INHERITED gate, original intact — one landing authority
     * means one judge, never two.
     */
    public function testABehaviorBreakingEditIsRefusedByTheInheritedJudge(): void
    {
        mkdir($this->raiz . '/tests/Plugins/Demo', 0o775, true);
        file_put_contents(
            $this->raiz . '/tests/bootstrap.php',
            "<?php require __DIR__ . '/../src/Plugins/Demo/Services/GreeterService.php';\n"
        );
        file_put_contents(
            $this->raiz . '/tests/Plugins/Demo/GreeterServiceTest.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse PHPUnit\\Framework\\TestCase;\n\n"
            . "final class GreeterServiceTest extends TestCase\n{\n"
            . "    public function testGreets(): void\n    {\n"
            . "        self::assertSame('hola x', (new App\\Plugins\\Demo\\Services\\GreeterService())->greet('x'));\n    }\n}\n"
        );
        $phpunit = \dirname(__DIR__, 3) . '/../vendor/bin/phpunit';
        $runner = $this->raiz . '/juez.sh';
        file_put_contents($runner, "#!/bin/sh\n" . escapeshellarg($phpunit)
            . ' --bootstrap ' . escapeshellarg($this->raiz . '/tests/bootstrap.php') . " \"\$1\" 2>&1\n");
        chmod($runner, 0o755);

        $antes = $this->original();
        $lander = new \Milpa\DevTools\Operations\ImplementHandler(new RootResolver($this->raiz), behaviorRunner: $runner);
        $r = (new EditHandler(new RootResolver($this->raiz), $lander))
            ->handle(['plugin' => 'Demo', 'class' => 'GreeterService',
                'edits' => [['find' => "return 'hola ' . \$name;", 'replace' => "return 'bye ' . \$name;"]]]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('behavior', $r['error']);
        self::assertSame($antes, $this->original());
    }

    /** Editing is not creating: an unscaffolded class is refused toward `make`, same as implement. */
    public function testAnUnscaffoldedClassIsRefusedTowardMake(): void
    {
        $r = $this->edit([['find' => 'x', 'replace' => 'y']], 'GhostService');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('make', $r['error']);
    }
}
