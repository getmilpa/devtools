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

use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\GenerationResult;
use Milpa\DevTools\Make\Generators\EntityGenerator;
use Milpa\DevTools\Make\Generators\OperationGenerator;
use Milpa\DevTools\Make\Generators\PluginGenerator;
use Milpa\DevTools\Operations\ImplementHandler;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/**
 * A REFUSAL NAMES ITS CAUSE WHEN THE HOUSE KNOWS IT (greenhouse evidence/1156).
 *
 * A real resident wrote one judge for the whole cycle of its plugin — register, lend, take back — and named it
 * after one operation. That operation's body was refused three times in a row as «judged red by its own test»,
 * and the run ended with it still a scaffold. The body was never the reason: the judge called the plugin's other
 * operations, which in that copy of the house were still scaffolds, and a scaffold answers `ok: false`. The house
 * wrote those scaffolds and could read that they were unfilled; the refusal showed ten lines of phpunit.
 *
 * Everything here is scaffolded by the real generators and judged by a real phpunit.
 */
final class ARedJudgeNamesTheScaffoldsItRunsOverTest extends TestCase
{
    private static int $n = 0;

    private string $root;
    private string $plugin;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-scaffolds-' . uniqid();
        mkdir($this->root, 0o775, true);
        file_put_contents(
            $this->root . '/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], JSON_PRETTY_PRINT),
        );
        $this->plugin = 'Bodega' . (++self::$n) . 'y' . getmypid();
        $this->write((new PluginGenerator())->generate($this->context($this->plugin)));
        $this->write((new EntityGenerator())->generate($this->context('Caja', ['fields' => 'etiqueta:string'])));
        $this->scaffold('GuardarCaja', ['operation' => 'bodega:guardar', 'fields' => 'etiqueta:string']);
        $this->scaffold('ListarCajas', ['operation' => 'bodega:listar', 'reads' => true]);
        $this->judge("\$guardada = (new GuardarCaja(etiqueta: 'Tornillos'))->run(\$this->cajas);\n        self::assertTrue(\$guardada['ok']);");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testARedJudgeNamesTheScaffoldItRunsOverAndTheOrderThatGetsPastIt(): void
    {
        $before = (string) file_get_contents($this->operation('ListarCajas'));

        $r = $this->handler()->handle(['plugin' => $this->plugin, 'class' => 'ListarCajas', 'content' => $this->listar()]);

        self::assertFalse($r['ok']);
        self::assertSame('behavior', $r['diagnostic']['phase']);
        self::assertSame(['GuardarCaja'], $r['diagnostic']['scaffolds']);
        self::assertStringContainsString('ListarCajasTest names GuardarCaja, and in this copy of the house it is still a scaffold', $r['error']);
        self::assertStringContainsString('its run() answers «not implemented»', $r['error']);
        self::assertStringContainsString("1. implement plugin={$this->plugin} class=GuardarCaja, and promote it.", $r['error']);
        self::assertStringContainsString("2. Then send this same implement plugin={$this->plugin} class=ListarCajas again.", $r['error']);
        self::assertSame($before, (string) file_get_contents($this->operation('ListarCajas')), 'and the scaffold is back, byte for byte');
    }

    /** What phpunit said still travels: the note is added to it, it does not replace it. */
    public function testWhatTheJudgeSaidStillTravels(): void
    {
        $r = $this->handler()->handle(['plugin' => $this->plugin, 'class' => 'ListarCajas', 'content' => $this->listar()]);

        self::assertStringContainsString("refused: the class's own test judges this behavior red — ListarCajasTest said:\n", $r['error']);
        self::assertStringContainsString('Failed asserting that false is true.', $r['error']);
        self::assertGreaterThan(strpos($r['error'], 'Failed asserting'), strpos($r['error'], 'ListarCajasTest names GuardarCaja'), 'the cause closes the refusal');
    }

    public function testFollowedInThatOrderTheSameBodyLands(): void
    {
        file_put_contents($this->operation('GuardarCaja'), $this->guardar());

        $r = $this->handler()->handle(['plugin' => $this->plugin, 'class' => 'ListarCajas', 'content' => $this->listar()]);

        self::assertTrue($r['ok'], json_encode($r, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertStringContainsString('behavior (ListarCajasTest green)', $r['verified']);
    }

    /** A judge red over no scaffold is red for what the body does: nothing is said of scaffolds. */
    public function testARedJudgeOverNoScaffoldSaysNothingOfScaffolds(): void
    {
        file_put_contents($this->operation('GuardarCaja'), $this->guardar());

        $r = $this->handler()->handle(['plugin' => $this->plugin, 'class' => 'ListarCajas',
            'content' => str_replace('$cajas->all()', '[]', $this->listar())]);

        self::assertFalse($r['ok']);
        self::assertSame('behavior', $r['diagnostic']['phase']);
        self::assertSame([], $r['diagnostic']['scaffolds']);
        self::assertStringNotContainsString('ListarCajasTest names', $r['error']);
        self::assertStringNotContainsString('in this order', $r['error']);
        self::assertStringEndsNotWith("\n", $r['error']);
    }

    /** A scaffold the judge does not name is nobody's obstacle here. */
    public function testAScaffoldTheJudgeDoesNotNameIsNotBlamed(): void
    {
        $this->scaffold('BorrarCaja', ['operation' => 'bodega:borrar', 'fields' => 'id:int']);

        $r = $this->handler()->handle(['plugin' => $this->plugin, 'class' => 'ListarCajas', 'content' => $this->listar()]);

        self::assertSame(['GuardarCaja'], $r['diagnostic']['scaffolds']);
        self::assertStringNotContainsString('BorrarCaja', $r['error']);
    }

    /** With several, each is named, and the order fills them one at a time. */
    public function testSeveralScaffoldsAreNamedEachWithItsCall(): void
    {
        $this->scaffold('BorrarCaja', ['operation' => 'bodega:borrar', 'fields' => 'id:int']);
        $this->judge("\$guardada = (new GuardarCaja(etiqueta: 'Tornillos'))->run(\$this->cajas);\n        self::assertTrue(\$guardada['ok']);\n"
            . "        self::assertTrue((new \\App\\Plugins\\{$this->plugin}\\Operations\\BorrarCaja(id: 99))->run(\$this->cajas)['ok']);");

        $r = $this->handler()->handle(['plugin' => $this->plugin, 'class' => 'ListarCajas', 'content' => $this->listar()]);

        self::assertSame(['BorrarCaja', 'GuardarCaja'], $r['diagnostic']['scaffolds']);
        self::assertStringContainsString('ListarCajasTest names BorrarCaja, GuardarCaja, and in this copy of the house they are still scaffolds', $r['error']);
        self::assertStringContainsString("1. implement plugin={$this->plugin} class=BorrarCaja, and promote it; then the same for GuardarCaja.", $r['error']);
    }

    /** A judge reaches an operation by the name it declares too. */
    public function testAScaffoldNamedByTheOperationItDeclaresIsNamedToo(): void
    {
        $this->judge("self::assertSame([], \$this->cajas->all(), 'nothing until bodega:guardar has run');\n        self::fail('bodega:guardar is not callable here yet');");

        $r = $this->handler()->handle(['plugin' => $this->plugin, 'class' => 'ListarCajas', 'content' => $this->listar()]);

        self::assertSame(['GuardarCaja'], $r['diagnostic']['scaffolds']);
    }

    /** A name that only contains a scaffold's name is another name. */
    public function testANameThatOnlyContainsAScaffoldsNameIsNotIt(): void
    {
        $this->judge("self::fail('MiGuardarCaja, GuardarCajaGrande y GuardarCajas no son de este plugin');");

        $r = $this->handler()->handle(['plugin' => $this->plugin, 'class' => 'ListarCajas', 'content' => $this->listar()]);

        self::assertFalse($r['ok']);
        self::assertSame([], $r['diagnostic']['scaffolds']);
    }

    /** The class being landed is the proposal: it is never its own obstacle, whatever its text still says. */
    public function testTheClassBeingLandedIsNeverNamedAsItsOwnObstacle(): void
    {
        file_put_contents($this->operation('GuardarCaja'), $this->guardar());

        $r = $this->handler()->handle(['plugin' => $this->plugin, 'class' => 'ListarCajas', 'content' => str_replace(
            "return ['ok' => true, 'cajas' => \\count(\$cajas->all())];",
            "return ['ok' => false, 'error' => 'not implemented: ListarCajas::run() is a scaffold — fill it with implement'];",
            $this->listar(),
        )]);

        self::assertFalse($r['ok']);
        self::assertSame([], $r['diagnostic']['scaffolds']);
    }

    /** The house reads which operations are unfilled from the sentence its own scaffold answers with. */
    public function testTheScaffoldStillCarriesTheSentenceTheHouseReadsItBy(): void
    {
        $dir = "{$this->root}/src/Plugins/{$this->plugin}";

        self::assertSame(['GuardarCaja', 'ListarCajas'], OperationGenerator::unfilledIn($dir));
        self::assertStringContainsString('GuardarCaja' . OperationGenerator::UNFILLED, (string) file_get_contents($this->operation('GuardarCaja')));

        // Filled — and a filled body that quotes ANOTHER class's sentence is still filled.
        file_put_contents($this->operation('GuardarCaja'), str_replace(
            "return ['ok' => true];",
            "// ListarCajas::run() is a scaffold — fill it with implement\n        return ['ok' => true];",
            $this->guardar(),
        ));

        self::assertSame(['ListarCajas'], OperationGenerator::unfilledIn($dir));
        self::assertSame([], OperationGenerator::unfilledIn($dir . '/nowhere'));
    }

    /** @param array<string, mixed> $options */
    private function context(string $name, array $options = []): GenerationContext
    {
        return new GenerationContext($this->plugin, $name, ['flavor' => 'runtime'] + $options, $this->root);
    }

    /** @param array<string, mixed> $options */
    private function scaffold(string $name, array $options): void
    {
        $this->write((new OperationGenerator())->generate($this->context($name, $options)));
    }

    private function write(GenerationResult $result): void
    {
        foreach ($result->files as $file) {
            $path = str_starts_with($file->path, '/') ? $file->path : $this->root . '/' . $file->path;
            @mkdir(\dirname($path), 0o775, true);
            file_put_contents($path, $file->contents);
        }
    }

    private function operation(string $class): string
    {
        return "{$this->root}/src/Plugins/{$this->plugin}/Operations/{$class}.php";
    }

    /** The judge of ListarCajas: it stores a row first — through another operation — and then lists. */
    private function judge(string $arrange): void
    {
        $uses = preg_match('/(?<![A-Za-z0-9_\\\\])GuardarCaja\(/', $arrange) === 1 ? "use App\\Plugins\\{$this->plugin}\\Operations\\GuardarCaja;\n" : '';
        @mkdir("{$this->root}/tests/Plugins/{$this->plugin}", 0o775, true);
        file_put_contents("{$this->root}/tests/Plugins/{$this->plugin}/ListarCajasTest.php", <<<PHP
<?php

declare(strict_types=1);

namespace App\\Tests\\Plugins\\{$this->plugin};

use App\\Plugins\\{$this->plugin}\\Entities\\Caja;
{$uses}use App\\Plugins\\{$this->plugin}\\Operations\\ListarCajas;
use Milpa\\Data\\InMemoryRepository;
use Milpa\\Data\\RepositoryInterface;
use PHPUnit\\Framework\\TestCase;

final class ListarCajasTest extends TestCase
{
    private RepositoryInterface \$cajas;

    protected function setUp(): void
    {
        \$this->cajas = new InMemoryRepository(Caja::class);
    }

    public function testItCountsWhatWasStored(): void
    {
        {$arrange}
        self::assertSame(1, (new ListarCajas())->run(\$this->cajas)['cajas']);
    }
}

PHP);
    }

    /** A handler whose judge is a real phpunit over the plugin as it is on disk when the judge runs. */
    private function handler(): ImplementHandler
    {
        $requires = "<?php\nrequire " . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
            . 'foreach ([\'Entities\', \'Operations\'] as $kind) { foreach (glob(' . var_export("{$this->root}/src/Plugins/{$this->plugin}/", true)
            . " . \$kind . '/*.php') ?: [] as \$source) { require \$source; } }\n";
        file_put_contents($this->root . '/bootstrap.php', $requires);
        $runner = $this->root . '/judge.sh';
        file_put_contents($runner, "#!/bin/sh\n" . escapeshellarg(\dirname(__DIR__, 2) . '/vendor/bin/phpunit')
            . ' --no-configuration --bootstrap ' . escapeshellarg($this->root . '/bootstrap.php') . " \"\$1\" 2>&1\n");
        chmod($runner, 0o755);

        return new ImplementHandler(new RootResolver($this->root), behaviorRunner: $runner);
    }

    /** The body of GuardarCaja, on its scaffold. */
    private function guardar(): string
    {
        return str_replace(
            "return ['ok' => false, 'error' => 'not implemented: GuardarCaja::run() is a scaffold — fill it with implement'];",
            "\$cajas->save(new \\App\\Plugins\\{$this->plugin}\\Entities\\Caja(null, \$this->etiqueta));\n\n        return ['ok' => true];",
            (string) file_get_contents($this->operation('GuardarCaja')),
        );
    }

    /** The body of ListarCajas, on its scaffold: a right one. */
    private function listar(): string
    {
        return str_replace(
            "return ['ok' => false, 'error' => 'not implemented: ListarCajas::run() is a scaffold — fill it with implement'];",
            "return ['ok' => true, 'cajas' => \\count(\$cajas->all())];",
            (string) file_get_contents($this->operation('ListarCajas')),
        );
    }
}
