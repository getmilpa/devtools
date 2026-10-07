<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\PostconditionVerifier;
use Milpa\DevTools\Operations\DevToolsOperations;
use Milpa\DevTools\Operations\MakeHandler;
use Milpa\DevTools\Support\RootResolver;
use Milpa\Plugin\Contracts\BootWitnessInterface;
use PHPUnit\Framework\TestCase;

/**
 * `make what=operation`, ASKED THE WAY A CALLER ASKS IT (greenhouse decisions/0591).
 *
 * The generator's own test proves what it renders; this one proves the door: that `make` knows the kind, that the
 * arguments a caller types reach the generator, and that `ok` means the operation is REGISTERED — an operation
 * class no plugin lists is in no catalogue, and handing the registration back as prose is an incomplete run.
 */
final class MakeScaffoldsAnOperationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-make-operation-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o777, true);
        file_put_contents($this->root . '/composer.json', (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]]));
        file_put_contents($this->root . '/config/plugins.php', "<?php\nreturn [];\n");
        $this->root = (string) realpath($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testMakeKnowsTheKindAndItsContractSaysSo(): void
    {
        self::assertContains('operation', $this->handler()->kinds());

        $make = null;
        foreach ((new DevToolsOperations())->operations() as $operation) {
            if ($operation->name === 'make') {
                $make = $operation;
            }
        }
        self::assertNotNull($make);
        self::assertContains('operation', $make->inputSchema['properties']['what']['enum']);
        self::assertArrayHasKey('operation', $make->inputSchema['properties'], 'the name an operation declares is an argument of make');
        self::assertSame('boolean', $make->inputSchema['properties']['reads']['type']);
        self::assertStringContainsString('operation', $make->description);
    }

    public function testItScaffoldsRegistersAndReportsBoth(): void
    {
        $made = $this->handler()->handle([
            'what' => 'operation', 'plugin' => 'Prestamos', 'name' => 'PrestarHerramienta',
            'operation' => 'herramientas:prestar', 'description' => 'Prestar una herramienta disponible.', 'fields' => 'id:int',
        ]);

        self::assertTrue($made['ok'], (string) ($made['error'] ?? json_encode($made['postconditions'] ?? null)));
        self::assertSame(
            ['created', 'created'],
            array_column($made['files'], 'action'),
        );
        $operation = (string) file_get_contents($this->root . '/src/Plugins/Prestamos/Operations/PrestarHerramienta.php');
        self::assertStringContainsString("#[Operation(name: 'herramientas:prestar', description: 'Prestar una herramienta disponible.')]", $operation);
        self::assertStringContainsString("#[Needs(scopes: ['herramientas:write'])]", $operation);
        self::assertStringContainsString('public readonly int $id,', $operation);

        $checks = array_column($made['postconditions']['checks'], 'ok', 'name');
        self::assertTrue($checks[PostconditionVerifier::OPERATION_FILE]);
        self::assertTrue($checks[PostconditionVerifier::OPERATION_REGISTERED]);
        self::assertStringContainsString('herramientas_prestar', (string) $made['guidance']);
    }

    public function testAReadIsAskedForWithReads(): void
    {
        $made = $this->handler()->handle(['what' => 'operation', 'plugin' => 'Prestamos', 'name' => 'ListarHerramientas', 'operation' => 'herramientas:listar', 'reads' => true]);

        self::assertTrue($made['ok'], (string) ($made['error'] ?? ''));
        $operation = (string) file_get_contents($this->root . '/src/Plugins/Prestamos/Operations/ListarHerramientas.php');
        self::assertStringContainsString('#[Reads]', $operation);
        self::assertStringContainsString("#[Needs(scopes: ['herramientas:read'])]", $operation);
    }

    public function testAnOperationOverAnEntityOfItsPluginReceivesItsRepository(): void
    {
        $entity = $this->handler()->handle(['what' => 'entity', 'plugin' => 'Prestamos', 'name' => 'Herramienta', 'fields' => 'nombre:string', 'no_verify' => true]);
        self::assertTrue($entity['ok'], (string) ($entity['error'] ?? ''));

        $made = $this->handler()->handle([
            'what' => 'operation', 'plugin' => 'Prestamos', 'name' => 'PrestarHerramienta', 'operation' => 'herramientas:prestar', 'entity' => 'Herramienta',
        ]);

        self::assertTrue($made['ok'], (string) ($made['error'] ?? json_encode($made['postconditions'] ?? null)));
        self::assertSame(['created', 'merged'], array_column($made['files'], 'action'), 'the plugin of the entity is merged into, and stays its plugin');
        self::assertStringContainsString(
            'public function run(RepositoryInterface $herramientas): array',
            (string) file_get_contents($this->root . '/src/Plugins/Prestamos/Operations/PrestarHerramienta.php'),
        );
        $plugin = (string) file_get_contents($this->root . '/src/Plugins/Prestamos/Prestamos.php');
        self::assertStringContainsString("Herramienta::class . 'Repository') : \$this->container->get(\$type)", $plugin);
        self::assertStringContainsString('RepositoryFactory::fromConfig', $plugin);
    }

    public function testASecondOperationIsAMergeIntoThePluginNotAnOverwrite(): void
    {
        $this->handler()->handle(['what' => 'operation', 'plugin' => 'Prestamos', 'name' => 'PrestarHerramienta', 'operation' => 'herramientas:prestar']);

        $made = $this->handler()->handle(['what' => 'operation', 'plugin' => 'Prestamos', 'name' => 'DevolverHerramienta', 'operation' => 'herramientas:devolver']);

        self::assertTrue($made['ok'], (string) ($made['error'] ?? ''));
        self::assertSame(['created', 'merged'], array_column($made['files'], 'action'));
        $plugin = (string) file_get_contents($this->root . '/src/Plugins/Prestamos/Prestamos.php');
        self::assertStringContainsString('PrestarHerramienta::class', $plugin);
        self::assertStringContainsString('DevolverHerramienta::class', $plugin);
    }

    public function testAnOperationNoPluginListsIsAnIncompleteRunAndSaysWhatIsMissing(): void
    {
        mkdir($this->root . '/src/Plugins/Prestamos', 0o777, true);
        file_put_contents($this->root . '/src/Plugins/Prestamos/Prestamos.php', "<?php\nfinal class Prestamos {\n");

        $made = $this->handler()->handle(['what' => 'operation', 'plugin' => 'Prestamos', 'name' => 'PrestarHerramienta', 'operation' => 'herramientas:prestar']);

        self::assertFalse($made['ok'], 'registered as prose is not registered');
        self::assertTrue($made['incomplete'] ?? false);
        self::assertSame([PostconditionVerifier::OPERATION_REGISTERED], $made['postconditions']['missing']);
        self::assertFileExists($this->root . '/src/Plugins/Prestamos/Operations/PrestarHerramienta.php', 'what was written is valid and is kept');
        self::assertStringContainsString('could not be registered', (string) $made['guidance']);
    }

    public function testTheReportAsksTheDiskForEachOfTheTwo(): void
    {
        $context = new GenerationContext('Prestamos', 'PrestarHerramienta', [], $this->root);
        $verifier = new PostconditionVerifier();

        $nothing = $verifier->verify('operation', $context, Flavor::Runtime);
        self::assertSame([PostconditionVerifier::OPERATION_FILE, PostconditionVerifier::OPERATION_REGISTERED], $nothing->missing());

        $this->handler()->handle(['what' => 'operation', 'plugin' => 'Prestamos', 'name' => 'PrestarHerramienta', 'operation' => 'herramientas:prestar']);
        self::assertTrue($verifier->verify('operation', $context, Flavor::Runtime)->ok());

        unlink($this->root . '/src/Plugins/Prestamos/Operations/PrestarHerramienta.php');
        self::assertSame([PostconditionVerifier::OPERATION_FILE], $verifier->verify('operation', $context, Flavor::Runtime)->missing(), 'a plugin that lists a class nobody wrote');
    }

    public function testANameMakeCannotDeclareIsRefusedAndNothingIsWritten(): void
    {
        $made = $this->handler()->handle(['what' => 'operation', 'plugin' => 'Prestamos', 'name' => 'PrestarHerramienta', 'operation' => 'Prestar Herramienta']);

        self::assertFalse($made['ok']);
        self::assertStringContainsString('is not an operation name', (string) $made['error']);
        self::assertStringEndsWith('in lower case: domain:verb', (string) $made['error'], 'it says the form, and names no domain');
        self::assertDirectoryDoesNotExist($this->root . '/src/Plugins/Prestamos');
    }

    public function testADryRunPlansBothFilesAndWritesNeither(): void
    {
        $made = $this->handler()->handle(['what' => 'operation', 'plugin' => 'Prestamos', 'name' => 'PrestarHerramienta', 'dry_run' => true]);

        self::assertTrue($made['ok']);
        self::assertSame(['would-create', 'would-create'], array_column($made['files'], 'action'));
        self::assertDirectoryDoesNotExist($this->root . '/src/Plugins/Prestamos');
    }

    private function handler(): MakeHandler
    {
        return new MakeHandler(new RootResolver($this->root), static fn (): ?BootWitnessInterface => null);
    }
}
