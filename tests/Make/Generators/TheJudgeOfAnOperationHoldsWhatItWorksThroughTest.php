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
use Milpa\DevTools\Make\GenerationResult;
use Milpa\DevTools\Make\Generators\EntityGenerator;
use Milpa\DevTools\Make\Generators\OperationGenerator;
use Milpa\DevTools\Make\Generators\PluginGenerator;
use Milpa\DevTools\Make\Generators\TestGenerator;
use PHPUnit\Framework\TestCase;

/**
 * WHAT THE HOUSE TELLS A BUILDER TO WRITE FIRST, IT LEAVES READY TO BE WRITTEN (greenhouse evidence/1156).
 *
 * The house says the judge comes before the body. Three real residents out of three, having scaffolded their
 * operations, spent their next fourteen to seventeen calls to the model finding out one thing the house knew all
 * along: how a test gets the repository an operation's `run()` receives. One of them never found it, and filled no
 * body at all. The judge's scaffold was a bare `TestCase` that fails on purpose and holds nothing.
 *
 * So the judge of an operation is scaffolded holding what that operation works through, and showing the call the
 * house makes. Everything here is scaffolded by the real generators, and the judge is RUN by a real phpunit: a
 * scaffold that only looked right would not pass.
 */
final class TheJudgeOfAnOperationHoldsWhatItWorksThroughTest extends TestCase
{
    private static int $n = 0;

    private string $root;
    private string $plugin;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-judge-' . uniqid();
        mkdir($this->root, 0o775, true);
        file_put_contents(
            $this->root . '/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], JSON_PRETTY_PRINT),
        );
        $this->plugin = 'Bodega' . (++self::$n) . 'x' . getmypid();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testTheJudgeOfAnOperationBuildsTheRepositoryItsRunReceives(): void
    {
        $this->house();

        $judge = $this->judge('GuardarCaja')->files[0]->contents;

        self::assertStringContainsString("use App\\Plugins\\{$this->plugin}\\Entities\\Caja;", $judge);
        self::assertStringContainsString('use Milpa\\Data\\InMemoryRepository;', $judge);
        self::assertStringContainsString('private RepositoryInterface $cajas;', $judge, 'named as run() names it');
        self::assertStringContainsString('$this->cajas = new InMemoryRepository(Caja::class);', $judge);
    }

    public function testItShowsTheCallTheHouseMakes(): void
    {
        $this->house();

        $judge = $this->judge('GuardarCaja')->files[0]->contents;

        self::assertStringContainsString("use App\\Plugins\\{$this->plugin}\\Operations\\GuardarCaja;", $judge);
        self::assertStringContainsString("\$answer = (new GuardarCaja(etiqueta: '…'))->run(\$this->cajas);", $judge);
    }

    /** The red is still TDD's starting position — and it is the ONLY thing wrong with the scaffold. */
    public function testTheScaffoldStillFailsOnPurposeAndForNoOtherReason(): void
    {
        $this->house();

        [$exit, $said] = $this->run_($this->judge('GuardarCaja')->files[0]->contents);

        self::assertNotSame(0, $exit, $said);
        self::assertStringContainsString('this judge does not judge anything yet — declare what GuardarCaja must DO', $said);
        self::assertMatchesRegularExpression('/Tests: 1, Assertions: \d+, Failures: 1\./', $said, 'one failure, and no error: what it holds is built');
    }

    /** The call in the comment is not prose: taken out of its comment, the scaffolded operation answers it. */
    public function testTheCallItShowsIsOneTheScaffoldedOperationAnswers(): void
    {
        $this->house('etiqueta:string,cantidad:int,activa:bool,precio:float,?nota:string');
        $judge = $this->judge('GuardarCaja')->files[0]->contents;
        self::assertStringContainsString("(new GuardarCaja(etiqueta: '…', cantidad: 1, activa: true, precio: 1.0))->run(\$this->cajas);", $judge);
        self::assertStringContainsString('optional: nota', $judge);

        [$exit, $said] = $this->run_($this->withItsCallMade($judge));

        self::assertSame(0, $exit, $said);
        self::assertStringContainsString('OK (1 test, 2 assertions)', $said);
    }

    /** An operation the plugin hands no repository gets none here either: the judge holds what run() takes, no more. */
    public function testAnOperationHandedNoRepositoryIsShownItsCallAndNothingElse(): void
    {
        $this->house(entities: []);

        $judge = $this->judge('GuardarCaja')->files[0]->contents;

        self::assertStringNotContainsString('InMemoryRepository', $judge);
        self::assertStringNotContainsString('setUp', $judge);
        self::assertStringContainsString("\$answer = (new GuardarCaja(etiqueta: '…'))->run();", $judge);
        [$exit, $said] = $this->run_($this->withItsCallMade($judge));
        self::assertSame(0, $exit, $said);
    }

    /** With several entities and none named, the scaffold handed run() nothing — and neither does its judge. */
    public function testWithSeveralEntitiesAndNoneNamedTheJudgeGuessesNoRepository(): void
    {
        $this->house(entities: ['Caja', 'Estante']);

        $judge = $this->judge('GuardarCaja')->files[0]->contents;

        self::assertStringNotContainsString('InMemoryRepository', $judge);
        self::assertStringContainsString('->run();', $judge);
    }

    /** Read from the entry of THIS operation: another operation of the plugin, over another entity, is listed first. */
    public function testTheNamedEntityIsTheOneTheJudgeHolds(): void
    {
        $this->write((new PluginGenerator())->generate(new GenerationContext($this->plugin, $this->plugin, ['flavor' => 'runtime'], $this->root)));
        foreach (['Caja', 'Estante'] as $entity) {
            $this->write((new EntityGenerator())->generate(new GenerationContext($this->plugin, $entity, ['flavor' => 'runtime', 'fields' => 'etiqueta:string'], $this->root)));
        }
        foreach (['ContarCajas' => 'Caja', 'GuardarCaja' => 'Estante'] as $operation => $entity) {
            $this->write((new OperationGenerator())->generate(new GenerationContext(
                $this->plugin,
                $operation,
                ['flavor' => 'runtime', 'operation' => 'bodega:' . strtolower($operation), 'fields' => 'etiqueta:string', 'entity' => $entity],
                $this->root,
            )));
        }

        $judge = $this->judge('GuardarCaja')->files[0]->contents;

        self::assertStringContainsString('$this->estantes = new InMemoryRepository(Estante::class);', $judge);
        self::assertStringNotContainsString('Caja::class', $judge);
    }

    /**
     * The state a resident left by hand: `run()` written against a repository while the entry that lists the
     * operation hands none. The judge holds what the house hands, so it holds nothing — and shows what is missing.
     */
    public function testARepositoryTheEntryDoesNotHandIsNotHeldByTheJudge(): void
    {
        $this->house(entities: []);
        $this->write((new EntityGenerator())->generate(new GenerationContext($this->plugin, 'Caja', ['flavor' => 'runtime', 'fields' => 'etiqueta:string'], $this->root)));
        $file = "{$this->root}/src/Plugins/{$this->plugin}/Operations/GuardarCaja.php";
        file_put_contents($file, str_replace('public function run(): array', 'public function run(\\Milpa\\Data\\RepositoryInterface $cajas): array', (string) file_get_contents($file), $replaced));
        self::assertSame(1, $replaced);

        $judge = $this->judge('GuardarCaja')->files[0]->contents;

        self::assertStringNotContainsString('InMemoryRepository', $judge);
        self::assertStringNotContainsString('$this->cajas', $judge);
        self::assertStringContainsString("->run(/* \\Milpa\\Data\\RepositoryInterface \$cajas */);", $judge);
        self::assertStringContainsString('It shows the call the house makes', (string) $this->judge('GuardarCaja')->guidance);
    }

    /**
     * An entry hands ONE repository: `run()` is handed by type. A second one in the signature is not held as if it
     * were the same — the judge shows it is missing, as the house would find it missing.
     */
    public function testASecondRepositoryInTheSignatureIsShownMissingAndNotHeld(): void
    {
        $this->house();
        $file = "{$this->root}/src/Plugins/{$this->plugin}/Operations/GuardarCaja.php";
        file_put_contents($file, str_replace('RepositoryInterface $cajas): array', 'RepositoryInterface $cajas, RepositoryInterface $estantes): array', (string) file_get_contents($file), $replaced));
        self::assertSame(1, $replaced);

        $judge = $this->judge('GuardarCaja')->files[0]->contents;

        self::assertStringContainsString("->run(\$this->cajas, /* RepositoryInterface \$estantes */);", $judge);
        self::assertStringContainsString('private RepositoryInterface $cajas;', $judge);
        self::assertStringNotContainsString('$this->estantes', $judge);
    }

    /** A class that lives beside the operations and is not one gets the bare judge. */
    public function testAClassBesideTheOperationsThatIsNotOneGetsTheBareJudge(): void
    {
        $this->house();
        file_put_contents(
            "{$this->root}/src/Plugins/{$this->plugin}/Operations/Etiquetador.php",
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\{$this->plugin}\\Operations;\n\nfinal class Etiquetador\n{\n    public function run(\\Milpa\\Data\\RepositoryInterface \$cajas): array\n    {\n        return [];\n    }\n}\n",
        );

        self::assertSame(self::bare($this->plugin, 'Etiquetador'), $this->judge('Etiquetador')->files[0]->contents);
    }

    public function testTheGuidanceSaysWhatTheJudgeHoldsAndWhatItRunsOver(): void
    {
        $this->house();

        $guidance = (string) $this->judge('GuardarCaja')->guidance;

        self::assertStringContainsString('This judge (class GuardarCajaTest) will run when GuardarCaja lands.', $guidance);
        self::assertStringContainsString('It already holds what GuardarCaja::run() works through — the repository of Caja, in memory', $guidance);
        self::assertStringContainsString('shows the call the house makes', $guidance);
        self::assertStringContainsString('If it calls another operation of this plugin, land that one\'s body first', $guidance);
    }

    /** A judge named with its suffix is the same judge. */
    public function testANameThatEndsInTestIsTheSameJudge(): void
    {
        $this->house();

        self::assertSame(
            $this->judge('GuardarCaja')->files[0]->contents,
            $this->judge('GuardarCajaTest')->files[0]->contents,
        );
    }

    /** Nothing changes for what is not an operation: its judge is the bare one, byte for byte. */
    public function testTheJudgeOfSomethingThatIsNotAnOperationIsWhatItWas(): void
    {
        $this->house();
        mkdir("{$this->root}/src/Plugins/{$this->plugin}/Services", 0o775, true);
        file_put_contents(
            "{$this->root}/src/Plugins/{$this->plugin}/Services/Etiquetas.php",
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\{$this->plugin}\\Services;\n\nfinal class Etiquetas\n{\n    public function run(): array\n    {\n        return [];\n    }\n}\n",
        );

        $result = $this->judge('Etiquetas');

        self::assertSame(self::bare($this->plugin, 'Etiquetas'), $result->files[0]->contents);
        self::assertSame('This judge (class EtiquetasTest) will run when Etiquetas lands. Fill it with `implement` declaring what Etiquetas must do.', $result->guidance);
    }

    /** Nor for a judge scaffolded before the class it judges exists. */
    public function testTheJudgeOfAClassThatDoesNotExistYetIsWhatItWas(): void
    {
        $this->house();

        $result = $this->judge('ListarCajas');

        self::assertSame(self::bare($this->plugin, 'ListarCajas'), $result->files[0]->contents);
        self::assertStringContainsString('No ListarCajas exists yet', (string) $result->guidance);
    }

    /**
     * A plugin with its entities and one operation over them, scaffolded by the real generators in the order a
     * resident scaffolds them.
     *
     * @param list<string>         $entities
     * @param array<string, mixed> $operation
     */
    private function house(string $fields = 'etiqueta:string', array $entities = ['Caja'], array $operation = []): void
    {
        $this->write((new PluginGenerator())->generate(new GenerationContext($this->plugin, $this->plugin, ['flavor' => 'runtime'], $this->root)));
        foreach ($entities as $entity) {
            $this->write((new EntityGenerator())->generate(new GenerationContext($this->plugin, $entity, ['flavor' => 'runtime', 'fields' => 'etiqueta:string'], $this->root)));
        }
        $this->write((new OperationGenerator())->generate(new GenerationContext(
            $this->plugin,
            'GuardarCaja',
            ['flavor' => 'runtime', 'operation' => 'bodega:guardar', 'fields' => $fields] + $operation,
            $this->root,
        )));
    }

    private function judge(string $name): GenerationResult
    {
        return (new TestGenerator())->generate(new GenerationContext($this->plugin, $name, [], $this->root));
    }

    private function write(GenerationResult $result): void
    {
        foreach ($result->files as $file) {
            $path = str_starts_with($file->path, '/') ? $file->path : $this->root . '/' . $file->path;
            @mkdir(\dirname($path), 0o775, true);
            file_put_contents($path, $file->contents);
        }
    }

    /** The scaffold with the call it shows taken out of its comment, and the scaffold's own answer asserted. */
    private function withItsCallMade(string $judge): string
    {
        self::assertSame(1, preg_match('/^\s*\/\/\s+(\$answer = .+;)$/m', $judge, $call), 'the scaffold shows a call');

        return (string) preg_replace(
            '/^\s*self::fail\(.*$/m',
            "        {$call[1]}\n        self::assertFalse(\$answer['ok']);\n        self::assertStringContainsString('is a scaffold', \$answer['error']);",
            $judge,
        );
    }

    /**
     * Runs a judge with a real phpunit, over the plugin as it is on disk.
     *
     * @return array{0: int, 1: string}
     */
    private function run_(string $judge): array
    {
        $file = "{$this->root}/tests/Plugins/{$this->plugin}/GuardarCajaTest.php";
        @mkdir(\dirname($file), 0o775, true);
        file_put_contents($file, $judge);
        $requires = "<?php\nrequire " . var_export(\dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ";\n";
        foreach (['Entities', 'Operations'] as $kind) {
            foreach (glob("{$this->root}/src/Plugins/{$this->plugin}/{$kind}/*.php") ?: [] as $source) {
                $requires .= 'require ' . var_export($source, true) . ";\n";
            }
        }
        file_put_contents($this->root . '/bootstrap.php', $requires);
        exec(
            escapeshellarg(\dirname(__DIR__, 3) . '/vendor/bin/phpunit') . ' --no-configuration --bootstrap '
            . escapeshellarg($this->root . '/bootstrap.php') . ' ' . escapeshellarg($file) . ' 2>&1',
            $lines,
            $exit,
        );

        return [$exit, implode("\n", $lines)];
    }

    /** The judge's scaffold as it has always been. */
    private static function bare(string $plugin, string $target): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\\Tests\\Plugins\\{$plugin};

use PHPUnit\\Framework\\TestCase;

/**
 * The behavioral judge of {@see \\App\\Plugins\\{$plugin}\\{$target}}.
 *
 * The landing gate runs this file whenever {$target} lands: red restores the original byte for
 * byte, green is named in the landing's verdict. Declare here what the class must DO — not what
 * it looks like; the gate already judges shape.
 */
final class {$target}Test extends TestCase
{
    public function testDeclareWhatItMustDo(): void
    {
        // Replace this with real behavior. It fails ON PURPOSE: a judge that judges nothing must
        // not green-light anything — until this is real, no body of {$target} lands past the gate.
        self::fail('this judge does not judge anything yet — declare what {$target} must DO');
    }
}

PHP;
    }
}
