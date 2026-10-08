<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use Milpa\Command\CommandProvider;
use Milpa\Command\Declaration\DeclaredOperation;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\GenerationResult;
use Milpa\DevTools\Make\Generators\EntityGenerator;
use Milpa\DevTools\Make\Generators\OperationGenerator;
use Milpa\DevTools\Make\Generators\PluginGenerator;
use Milpa\DevTools\Make\Markers;
use Milpa\DevTools\Make\PlannedFile;
use Milpa\Data\RepositoryInterface;
use Milpa\Interfaces\Di\DIContainerInterface;
use PHPUnit\Framework\TestCase;

/**
 * `make what=operation` LEAVES SOMETHING AN AGENT WORKS WITH (greenhouse decisions/0591).
 *
 * Nothing `make` scaffolded entered the agent's catalogue as something to work with: the one thing that showed up,
 * a `#[Tool]`, the house refused as unjudgeable (greenhouse decisions/0585 §9). A real resident decided on its own
 * to write four `#[Operation]` classes, and the house had no door for that: `implement` fills, it does not create
 * (greenhouse evidence/1127). This is the door.
 *
 * The judge here is the real one: every generated class is LOADED and derived with `DeclaredOperation::from()`,
 * the function the house derives an operation with. A scaffold that only looked right would not pass.
 */
final class OperationGeneratorTest extends TestCase
{
    private static int $n = 0;

    private string $root;
    private string $plugin;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-operation-' . uniqid();
        mkdir($this->root, 0o775, true);
        file_put_contents(
            $this->root . '/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], JSON_PRETTY_PRINT),
        );
        // One plugin name per test: the generated classes are loaded into this process.
        $this->plugin = 'Taller' . (++self::$n) . 'x' . getmypid();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testItLeavesADeclaredOperationTheHouseCanDerive(): void
    {
        $result = $this->make('PrestarHerramienta', ['operation' => 'herramientas:prestar', 'description' => 'Prestar una herramienta disponible.', 'fields' => 'id:int']);
        $this->write($result);

        $file = $this->fileNamed($result->files, 'PrestarHerramienta.php');
        self::assertStringEndsWith("/src/Plugins/{$this->plugin}/Operations/PrestarHerramienta.php", $file->path);
        self::assertSame(Flavor::Runtime, $result->flavor);

        $operation = DeclaredOperation::from($this->load($file));

        self::assertSame('herramientas:prestar', $operation->name);
        self::assertSame('Prestar una herramienta disponible.', $operation->description);
        self::assertTrue($operation->mutating, 'said nothing, it mutates: silence never lowers a control');
        self::assertSame(['herramientas:write'], $operation->scopes, 'the scope is the domain of its name, and what a person grants');
        self::assertSame(['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]], $operation->inputSchema);
    }

    public function testWhatItDoesAtWorstIsTheWriteProfileOfTheCourse(): void
    {
        $result = $this->make('AgregarHerramienta', ['operation' => 'herramientas:agregar']);
        $this->write($result);

        $effects = DeclaredOperation::from($this->load($this->fileNamed($result->files, 'AgregarHerramienta.php')))->effects;

        self::assertEquals(new EffectProfile(
            mutation: Mutation::Persistent,
            externality: Externality::None,
            reversibility: Reversibility::ManualRecovery,
            authority: Authority::WriteAsUser,
            subject: Subject::Data,
        ), $effects);
    }

    public function testAnUnfilledScaffoldAnswersThatItIsOneAndNeverOk(): void
    {
        $result = $this->make('DevolverHerramienta', ['operation' => 'herramientas:devolver', 'fields' => 'id:int']);
        $this->write($result);

        $answer = (DeclaredOperation::from($this->load($this->fileNamed($result->files, 'DevolverHerramienta.php')))->handler)(['id' => 7]);

        self::assertFalse($answer['ok'], 'a scaffold that answered ok would be a write that did not happen (greenhouse decisions/0587)');
        self::assertStringContainsString('DevolverHerramienta', $answer['error']);
        self::assertStringContainsString('scaffold', $answer['error']);
        self::assertStringContainsString('implement', $answer['error']);
    }

    public function testAReadSaysSoAndSpendsTheReadScope(): void
    {
        $result = $this->make('ListarHerramientas', ['operation' => 'herramientas:listar', 'reads' => true]);
        $this->write($result);

        $operation = DeclaredOperation::from($this->load($this->fileNamed($result->files, 'ListarHerramientas.php')));

        self::assertFalse($operation->mutating);
        self::assertSame(['herramientas:read'], $operation->scopes);
        self::assertEquals(EffectProfile::readOnly(), $operation->effects);
        self::assertStringNotContainsString('Mutates', $this->fileNamed($result->files, 'ListarHerramientas.php')->contents);
    }

    public function testWithoutANameItDerivesOneFromThePluginAndTheClassAndSaysIt(): void
    {
        $this->plugin = 'Prestamos' . self::$n . 'x' . getmypid();
        $result = $this->make('PrestarHerramienta');
        $this->write($result);

        $operation = DeclaredOperation::from($this->load($this->fileNamed($result->files, 'PrestarHerramienta.php')));
        $domain = strtolower($this->plugin);

        self::assertSame($domain . ':prestar-herramienta', $operation->name);
        self::assertSame([$domain . ':write'], $operation->scopes);
        self::assertStringContainsString("«{$domain}:prestar-herramienta»", (string) $result->guidance);
    }

    public function testADottedNameKeepsItsDomain(): void
    {
        $result = $this->make('PrestarHerramienta', ['operation' => 'herramientas.prestar']);
        $this->write($result);

        $operation = DeclaredOperation::from($this->load($this->fileNamed($result->files, 'PrestarHerramienta.php')));

        self::assertSame('herramientas.prestar', $operation->name);
        self::assertSame(['herramientas:write'], $operation->scopes);
    }

    public function testItsInputIsWhatFieldsDeclare(): void
    {
        $result = $this->make('RegistrarHerramienta', ['operation' => 'herramientas:registrar', 'fields' => 'nombre:string, cantidad:int, ?nota:text, urgente:bool, peso:float']);
        $this->write($result);

        $schema = DeclaredOperation::from($this->load($this->fileNamed($result->files, 'RegistrarHerramienta.php')))->inputSchema;

        self::assertSame(['nombre', 'cantidad', 'urgente', 'peso'], $schema['required'] ?? null, 'a nullable field is optional, and comes last');
        self::assertSame(['nombre', 'cantidad', 'urgente', 'peso', 'nota'], array_keys($schema['properties']));
        self::assertSame('string', $schema['properties']['nombre']['type']);
        self::assertSame('integer', $schema['properties']['cantidad']['type']);
        self::assertSame('boolean', $schema['properties']['urgente']['type']);
        self::assertSame('number', $schema['properties']['peso']['type']);
        self::assertSame('string', $schema['properties']['nota']['type'], 'a text is a string an operation receives');
    }

    public function testWhatRunWorksThroughIsWhatNeedsDeclares(): void
    {
        $result = $this->make('ContarHerramientas', ['operation' => 'herramientas:contar', 'reads' => true, 'needs' => '\\ArrayObject']);
        $this->write($result);
        $class = $this->load($this->fileNamed($result->files, 'ContarHerramientas.php'));

        $asked = [];
        $operation = DeclaredOperation::from($class, static function (string $type) use (&$asked): object {
            $asked[] = $type;

            return new \ArrayObject();
        });
        ($operation->handler)([]);

        self::assertSame(['ArrayObject'], $asked, 'run() receives its collaborators from whoever registers the operation');
    }

    public function testAnOperationOverAnEntityReceivesTheRepositoryOfThatEntity(): void
    {
        $this->write((new EntityGenerator())->generate(new GenerationContext($this->plugin, 'Herramienta', ['flavor' => 'runtime', 'fields' => 'nombre:string'], $this->root)));

        $result = $this->make('PrestarHerramienta', ['operation' => 'herramientas:prestar', 'entity' => 'Herramienta', 'fields' => 'id:int']);
        $this->write($result);
        $operation = $this->fileNamed($result->files, 'PrestarHerramienta.php');
        self::assertStringContainsString('@param RepositoryInterface<Herramienta> $herramientas', $operation->contents);
        self::assertStringContainsString('public function run(RepositoryInterface $herramientas): array', $operation->contents);

        $asked = [];
        $derived = DeclaredOperation::from($this->load($operation), function (string $type) use (&$asked): object {
            $asked[] = $type;

            return $this->createStub(RepositoryInterface::class);
        });
        ($derived->handler)(['id' => 1]);
        self::assertSame([RepositoryInterface::class], $asked, 'its state is where the entity keeps it: the declaration says which entity');

        $plugin = $this->fileNamed($result->files, $this->plugin . '.php')->contents;
        self::assertStringContainsString(
            "\\App\\Plugins\\{$this->plugin}\\Entities\\Herramienta::class . 'Repository'",
            $plugin,
            'and the plugin hands it the repository `make entity` registered, by the same key',
        );
        self::assertStringContainsString('the repository of Herramienta', (string) $result->guidance);
    }

    /**
     * WHERE ITS STATE LIVES IS NOT SOMETHING TO REMEMBER TO SAY (greenhouse evidence/1154). A real resident
     * scaffolded four operations of a plugin with one entity, named the entity in none, and wrote `run()` against
     * the repository: the house landed them green and the first call answered «RepositoryInterface is not
     * registered in the container». A plugin's only entity is the one its operations work over — the house says
     * the same of where their state lives (decisions/0588) — so the scaffold hands it without being asked.
     */
    public function testWithNoEntityNamedThePluginsOnlyEntityIsTheOneRunReceives(): void
    {
        $this->write((new EntityGenerator())->generate(new GenerationContext($this->plugin, 'Herramienta', ['flavor' => 'runtime', 'fields' => 'nombre:string'], $this->root)));

        $result = $this->make('RegistrarHerramienta', ['operation' => 'herramientas:registrar', 'fields' => 'nombre:string']);

        $operation = $this->fileNamed($result->files, 'RegistrarHerramienta.php');
        self::assertStringContainsString('public function run(RepositoryInterface $herramientas): array', $operation->contents);
        self::assertStringContainsString('@param RepositoryInterface<Herramienta> $herramientas', $operation->contents);
        self::assertStringContainsString(
            "\\App\\Plugins\\{$this->plugin}\\Entities\\Herramienta::class . 'Repository'",
            $this->fileNamed($result->files, $this->plugin . '.php')->contents,
            'and the entry that lists it hands that repository',
        );
        self::assertStringContainsString('Its run() receives the repository of Herramienta — the only entity of this plugin.', (string) $result->guidance);
    }

    /**
     * The entity's own scaffold said how to reach its repository with the container's id — and `run()` has no
     * container to ask. It says the way an operation has, too.
     */
    public function testTheEntitysScaffoldSaysHowAnOperationReachesItsRepository(): void
    {
        $plugin = (new \Milpa\DevTools\Make\Generators\PluginGenerator())->generate(new GenerationContext($this->plugin, $this->plugin, ['flavor' => 'runtime'], $this->root));
        $this->write($plugin);

        $result = (new EntityGenerator())->generate(new GenerationContext($this->plugin, 'Herramienta', ['flavor' => 'runtime', 'fields' => 'nombre:string'], $this->root));

        self::assertStringContainsString('Resolve the repository later via', (string) $result->guidance);
        self::assertStringContainsString('An operation of this plugin does not ask the container for it: its run() receives it', (string) $result->guidance);
    }

    /** A read works over the same store: it receives it too. */
    public function testAReadOfAPluginWithOneEntityReceivesItToo(): void
    {
        $this->write((new EntityGenerator())->generate(new GenerationContext($this->plugin, 'Herramienta', ['flavor' => 'runtime', 'fields' => 'nombre:string'], $this->root)));

        $result = $this->make('HerramientasDisponibles', ['operation' => 'herramientas:disponibles', 'reads' => '1']);

        self::assertStringContainsString('public function run(RepositoryInterface $herramientas): array', $this->fileNamed($result->files, 'HerramientasDisponibles.php')->contents);
    }

    /** What lives beside the entities and is not one — a value object, an enum — does not make them several. */
    public function testAClassBesideTheEntitiesThatIsNotOneIsNotCounted(): void
    {
        $this->write((new EntityGenerator())->generate(new GenerationContext($this->plugin, 'Herramienta', ['flavor' => 'runtime', 'fields' => 'nombre:string'], $this->root)));
        file_put_contents(
            "{$this->root}/src/Plugins/{$this->plugin}/Entities/Estado.php",
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\{$this->plugin}\\Entities;\n\nenum Estado: string\n{\n    case Disponible = 'disponible';\n}\n",
        );

        $result = $this->make('RegistrarHerramienta', ['operation' => 'herramientas:registrar']);

        self::assertStringContainsString('public function run(RepositoryInterface $herramientas): array', $this->fileNamed($result->files, 'RegistrarHerramienta.php')->contents);
        self::assertStringContainsString('the only entity of this plugin', (string) $result->guidance);
    }

    /** Which of several is a decision nobody made: none is guessed, and the scaffold says there is one to make. */
    public function testWithSeveralEntitiesNoneIsGuessedAndTheGuidanceNamesThem(): void
    {
        foreach (['Herramienta', 'Prestamo'] as $entity) {
            $this->write((new EntityGenerator())->generate(new GenerationContext($this->plugin, $entity, ['flavor' => 'runtime', 'fields' => 'nombre:string'], $this->root)));
        }

        $result = $this->make('RegistrarHerramienta', ['operation' => 'herramientas:registrar', 'fields' => 'nombre:string']);

        $operation = $this->fileNamed($result->files, 'RegistrarHerramienta.php')->contents;
        self::assertStringContainsString('public function run(): array', $operation);
        self::assertStringNotContainsString('RepositoryInterface', $operation);
        self::assertStringContainsString(
            'Its run() receives NO repository: this plugin has several entities (Herramienta, Prestamo) and none was named — if it stores or reads rows, scaffold it with entity=<Entity>.',
            (string) $result->guidance,
        );
    }

    /** A plugin with no entity has no store to hand: nothing is wired, as before, and nothing is said of one. */
    public function testWithNoEntityInThePluginNothingIsWired(): void
    {
        $result = $this->make('PrestarHerramienta', ['operation' => 'herramientas:prestar']);

        self::assertStringContainsString('public function run(): array', $this->fileNamed($result->files, 'PrestarHerramienta.php')->contents);
        self::assertStringNotContainsString('repository', (string) $result->guidance);
    }

    public function testThePluginResolvesThatRepositoryAndEverythingElseByItsClass(): void
    {
        $this->write((new EntityGenerator())->generate(new GenerationContext($this->plugin, 'Herramienta', ['flavor' => 'runtime', 'fields' => 'nombre:string'], $this->root)));
        $result = $this->make('PrestarHerramienta', ['operation' => 'herramientas:prestar', 'entity' => 'Herramienta', 'needs' => 'ArrayObject']);
        $this->write($result);
        $this->load($this->fileNamed($result->files, 'PrestarHerramienta.php'));
        require_once "{$this->root}/src/Plugins/{$this->plugin}/Entities/Herramienta.php";

        $asked = [];
        $container = $this->createStub(DIContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id) use (&$asked): object {
            $asked[] = $id;

            return str_ends_with($id, 'Repository') ? $this->createStub(RepositoryInterface::class) : new \ArrayObject();
        });
        $plugin = $this->load($this->fileNamed($result->files, $this->plugin . '.php'));
        // The plugin `make entity` scaffolded boots a repository from the app's config; only operations() is asked here.
        $instance = (new \ReflectionClass($plugin))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($plugin, 'container'))->setValue($instance, $container);
        self::assertInstanceOf(CommandProvider::class, $instance);

        ($instance->operations()[0]->handler)([]);

        self::assertSame(["App\\Plugins\\{$this->plugin}\\Entities\\Herramienta" . 'Repository', 'ArrayObject'], $asked);
    }

    public function testAnEntityThePluginDoesNotHaveIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('«Herramienta» is not an entity of plugin');

        $this->make('PrestarHerramienta', ['operation' => 'herramientas:prestar', 'entity' => 'Herramienta']);
    }

    public function testANameThatIsNotAnOperationNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('«Prestar Herramienta»');

        $this->make('PrestarHerramienta', ['operation' => 'Prestar Herramienta']);
    }

    public function testAFieldThatIsNotAnInputIsRefusedAndNothingIsGuessed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('«datos:json»');

        $this->make('PrestarHerramienta', ['operation' => 'herramientas:prestar', 'fields' => 'id:int, datos:json']);
    }

    public function testWithNoPluginYetItScaffoldsOneThatProvidesTheOperation(): void
    {
        $result = $this->make('PrestarHerramienta', ['operation' => 'herramientas:prestar']);
        $this->write($result);
        $this->load($this->fileNamed($result->files, 'PrestarHerramienta.php'));
        $plugin = $this->fileNamed($result->files, $this->plugin . '.php');

        self::assertFalse($plugin->merge);
        self::assertStringContainsString('// {' . Markers::OPERATIONS . '}', $plugin->contents);
        self::assertSame(['herramientas:prestar'], $this->provided($this->load($plugin)));
    }

    public function testASecondOperationJoinsTheFirstAtTheAnchor(): void
    {
        $this->write($this->make('PrestarHerramienta', ['operation' => 'herramientas:prestar']));

        $result = $this->make('DevolverHerramienta', ['operation' => 'herramientas:devolver']);
        $plugin = $this->fileNamed($result->files, $this->plugin . '.php');
        self::assertTrue($plugin->merge, 'an insertion at its anchor, never an overwrite');
        $this->write($result);

        foreach (['PrestarHerramienta', 'DevolverHerramienta'] as $class) {
            require_once "{$this->root}/src/Plugins/{$this->plugin}/Operations/{$class}.php";
        }
        self::assertSame(['herramientas:prestar', 'herramientas:devolver'], $this->provided($this->load($plugin)));
    }

    public function testThePluginMakeScaffoldedBecomesAProviderWithoutAnyoneEditingIt(): void
    {
        $this->write((new PluginGenerator())->generate(new GenerationContext($this->plugin, $this->plugin, ['flavor' => 'runtime'], $this->root)));
        $before = (string) file_get_contents("{$this->root}/src/Plugins/{$this->plugin}/{$this->plugin}.php");
        self::assertStringNotContainsString('operations()', $before, 'the control: the plugin `make plugin` leaves provides no operation');

        $result = $this->make('PrestarHerramienta', ['operation' => 'herramientas:prestar']);
        $plugin = $this->fileNamed($result->files, $this->plugin . '.php');
        self::assertTrue($plugin->merge);
        $this->write($result);
        $this->load($this->fileNamed($result->files, 'PrestarHerramienta.php'));

        $class = $this->load($plugin);
        self::assertContains(CommandProvider::class, class_implements($class) ?: []);
        self::assertSame(['herramientas:prestar'], $this->provided($class));
        self::assertStringContainsString('// {' . Markers::OPERATIONS . '}', $plugin->contents, 'and it carries the anchor for the next one');
    }

    public function testThePluginOfAnEntityBecomesAProviderToo(): void
    {
        $this->write((new EntityGenerator())->generate(new GenerationContext($this->plugin, 'Herramienta', ['flavor' => 'runtime', 'fields' => 'nombre:string'], $this->root)));

        $result = $this->make('PrestarHerramienta', ['operation' => 'herramientas:prestar']);
        $plugin = $this->fileNamed($result->files, $this->plugin . '.php');
        self::assertTrue($plugin->merge);

        self::assertStringContainsString('RepositoryFactory::fromConfig', $plugin->contents, 'what the plugin already wired is still there');
        self::assertStringContainsString('implements PluginInterface, \\Milpa\\Command\\CommandProvider', $plugin->contents);
        $this->assertPhpLints($plugin->contents);
    }

    public function testAnOperationsListSomeoneWroteByHandReceivesTheEntry(): void
    {
        $this->write($this->make('PrestarHerramienta', ['operation' => 'herramientas:prestar']));
        $path = "{$this->root}/src/Plugins/{$this->plugin}/{$this->plugin}.php";
        file_put_contents($path, str_replace('            // {' . Markers::OPERATIONS . "}\n", '', (string) file_get_contents($path)));

        $result = $this->make('DevolverHerramienta', ['operation' => 'herramientas:devolver']);
        $plugin = $this->fileNamed($result->files, $this->plugin . '.php');
        self::assertTrue($plugin->merge);
        $this->write($result);

        foreach (['PrestarHerramienta', 'DevolverHerramienta'] as $class) {
            require_once "{$this->root}/src/Plugins/{$this->plugin}/Operations/{$class}.php";
        }
        self::assertSame(['herramientas:prestar', 'herramientas:devolver'], $this->provided($this->load($plugin)));
    }

    public function testScaffoldingTheSameOperationTwiceRegistersItOnce(): void
    {
        $this->write($this->make('PrestarHerramienta', ['operation' => 'herramientas:prestar']));

        $again = $this->make('PrestarHerramienta', ['operation' => 'herramientas:prestar']);

        self::assertCount(1, $again->files, 'the class is planned again — the write guard decides — and the plugin is left as it is');
        self::assertStringContainsString('Already registered', (string) $again->guidance);
    }

    public function testAClassWhoseNameEndsAnotherIsNotTakenForIt(): void
    {
        $this->write($this->make('NoPrestarHerramienta', ['operation' => 'herramientas:no-prestar']));

        $result = $this->make('PrestarHerramienta', ['operation' => 'herramientas:prestar']);

        self::assertCount(2, $result->files, 'the plugin lists NoPrestarHerramienta, which is another class');
        self::assertStringContainsString('\\PrestarHerramienta::class', $this->fileNamed($result->files, $this->plugin . '.php')->contents);
    }

    public function testADescriptionIsCarriedAsItWasWritten(): void
    {
        $said = "Presta la herramienta que 'está' libre */ y nada más \\ ya.";
        $result = $this->make('PrestarHerramienta', ['operation' => 'herramientas:prestar', 'description' => $said]);
        $this->write($result);

        $operation = DeclaredOperation::from($this->load($this->fileNamed($result->files, 'PrestarHerramienta.php')));

        self::assertSame($said, $operation->description, 'a quote, a backslash and a comment end are text, not PHP');
    }

    public function testAPluginItCannotReadIsLeftUntouchedAndTheReasonIsNamed(): void
    {
        mkdir("{$this->root}/src/Plugins/{$this->plugin}", 0o775, true);
        file_put_contents("{$this->root}/src/Plugins/{$this->plugin}/{$this->plugin}.php", "<?php\nfinal class {$this->plugin} {\n");

        $result = $this->make('PrestarHerramienta', ['operation' => 'herramientas:prestar']);

        self::assertCount(1, $result->files, 'the operation class only');
        self::assertStringContainsString('could not be registered', (string) $result->guidance);
        self::assertStringContainsString('DeclaredOperation::from', (string) $result->guidance);
    }

    public function testTheResultSaysWhatItLeftAndTheStepThatFollows(): void
    {
        $result = $this->make('PrestarHerramienta', ['operation' => 'herramientas:prestar']);

        $guidance = (string) $result->guidance;
        self::assertStringContainsString('«herramientas:prestar»', $guidance);
        self::assertStringContainsString('herramientas_prestar', $guidance, 'the name an agent calls it by');
        self::assertStringContainsString('«herramientas:write»', $guidance);
        self::assertStringContainsString('ok: false', $guidance);
        self::assertStringContainsString("implement plugin={$this->plugin} class=PrestarHerramienta", $guidance);
    }

    public function testTheLegacyHostHasNoOperationToScaffold(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('make:operation');

        (new OperationGenerator())->generate(new GenerationContext($this->plugin, 'PrestarHerramienta', ['flavor' => 'legacy'], $this->root));
    }

    /** @param array<string, mixed> $options */
    private function make(string $name, array $options = []): GenerationResult
    {
        return (new OperationGenerator())->generate(new GenerationContext($this->plugin, $name, ['flavor' => 'runtime'] + $options, $this->root));
    }

    private function write(GenerationResult $result): void
    {
        foreach ($result->files as $file) {
            @mkdir(\dirname($file->path), 0o775, true);
            file_put_contents($file->path, $file->contents);
        }
    }

    /**
     * Load a planned file's class into this process, and return its name.
     *
     * @return class-string
     */
    private function load(PlannedFile $file): string
    {
        $this->assertPhpLints($file->contents);
        // The plugin is loaded from a copy under another class name each time: one file is rewritten across a test.
        $source = $file->contents;
        preg_match('/^namespace ([^;]+);/m', $source, $namespace);
        preg_match('/^final class (\w+)/m', $source, $class);
        if (($class[1] ?? '') === $this->plugin) {
            $alias = $this->plugin . 'V' . (++self::$n);
            $source = (string) preg_replace('/^final class ' . preg_quote($this->plugin, '/') . '\b/m', 'final class ' . $alias, $source);
            $copy = $this->root . '/' . $alias . '.php';
            file_put_contents($copy, $source);
            require_once $copy;

            /** @var class-string */
            return $namespace[1] . '\\' . $alias;
        }
        require_once $file->path;

        /** @var class-string */
        return $namespace[1] . '\\' . $class[1];
    }

    /**
     * The names of the operations a loaded plugin class provides.
     *
     * @param class-string $plugin
     *
     * @return list<string>
     */
    private function provided(string $plugin): array
    {
        $instance = new $plugin($this->createStub(DIContainerInterface::class));
        self::assertInstanceOf(CommandProvider::class, $instance);

        return array_map(static fn (Operation $operation): string => $operation->name, $instance->operations());
    }

    /** @param list<PlannedFile> $files */
    private function fileNamed(array $files, string $basename): PlannedFile
    {
        foreach ($files as $file) {
            if (basename($file->path) === $basename) {
                return $file;
            }
        }
        self::fail("no planned file named {$basename}");
    }

    private function assertPhpLints(string $code): void
    {
        $tmp = $this->root . '/lint-' . uniqid() . '.php';
        file_put_contents($tmp, $code);
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $exitCode);
        unlink($tmp);

        self::assertSame(0, $exitCode, "php -l failed:\n" . implode("\n", $output));
    }
}
