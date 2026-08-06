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
     * The namespace must be the one the file's location dictates, or the class lands unloadable —
     * `php -l` cannot see that, so the gate must.
     */
    public function testAForeignNamespaceIsRefusedNamingTheExpectedOne(): void
    {
        $r = $this->implement(str_replace(
            'namespace App\\Plugins\\Demo\\Services;',
            'namespace App\\Elsewhere;',
            $this->contenidoValido(),
        ));

        self::assertFalse($r['ok']);
        self::assertStringContainsString('App\\Plugins\\Demo\\Services', $r['error']);
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
