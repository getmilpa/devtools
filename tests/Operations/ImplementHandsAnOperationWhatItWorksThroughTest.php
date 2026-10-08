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

use Milpa\Command\Declaration\DeclaredOperation;
use Milpa\DevTools\Operations\ConstructionProbe;
use Milpa\DevTools\Operations\ImplementHandler;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/**
 * What an operation's `run()` works through, the house hands it — judged when the code lands, not at its first call
 * (greenhouse evidence/1154; the rule of decisions/0541, for what the catalogue offers).
 *
 * A real resident scaffolded an operation naming no entity and wrote its `run()` against
 * `Milpa\Data\RepositoryInterface`. Every judge passed — syntax, namespace, static conformance — and the first call
 * answered «Service "Milpa\Data\RepositoryInterface" is not registered in the container». The house resolves what
 * `run()` takes through the entry that lists the operation in its plugin, and that entry resolved by class; a
 * repository has no class to be found by. The house's own act, in a booted copy, is the judge here too.
 */
final class ImplementHandsAnOperationWhatItWorksThroughTest extends TestCase
{
    private const BY_CLASS = 'fn (string $type): object => $this->container->get($type)';
    private const BY_ENTITY = "fn (string \$type): object => \$type === \\Milpa\\Data\\RepositoryInterface::class ? \$this->container->get(\\App\\Plugins\\Bodega\\Entities\\Caja::class . 'Repository') : \$this->container->get(\$type)";

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-handed-' . bin2hex(random_bytes(4));
        foreach (['vendor', 'config', 'src/Plugins/Bodega/Entities', 'src/Plugins/Bodega/Operations', 'src/Plugins/Bodega/Services'] as $dir) {
            mkdir($this->root . '/' . $dir, 0o775, true);
        }
        $package = \dirname(__DIR__, 2);
        file_put_contents($this->root . '/vendor/autoload.php', "<?php\n\$loader = require "
            . var_export($package . '/vendor/autoload.php', true) . ";\n"
            . "\$loader->addPsr4('App\\\\', __DIR__ . '/../src/', true);\n\nreturn \$loader;\n");
        file_put_contents($this->root . '/config/app.php', "<?php\n\nreturn ['app' => ['debug' => false]];\n");
        file_put_contents($this->root . '/config/boot.php', "<?php\n\nreturn ['container' => new \\Milpa\\Container\\DIContainer(), "
            . "'plugins' => [\\App\\Plugins\\Bodega\\Bodega::class]];\n");
        file_put_contents($this->root . '/src/Plugins/Bodega/Entities/Caja.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Plugins\Bodega\Entities;

            use Milpa\Data\EntityInterface;

            final readonly class Caja implements EntityInterface
            {
                public function __construct(public int|string|null $id, public string $etiqueta)
                {
                }

                public function id(): int|string|null
                {
                    return $this->id;
                }

                public function toArray(): array
                {
                    return ['id' => $this->id, 'etiqueta' => $this->etiqueta];
                }

                public static function fromArray(array $row): static
                {
                    return new self($row['id'] ?? null, $row['etiqueta']);
                }
            }
            PHP);
        file_put_contents($this->root . '/src/Plugins/Bodega/Services/Etiquetas.php', "<?php\n\ndeclare(strict_types=1);\n\n"
            . "namespace App\\Plugins\\Bodega\\Services;\n\ninterface Etiquetas\n{\n    public function limpia(string \$etiqueta): string;\n}\n");
        $this->plugin(self::BY_CLASS);
        file_put_contents($this->operationFile(), $this->operation('', '', "return ['ok' => false, 'error' => 'not implemented'];"));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * THE RUN OF EVIDENCE/1154: scaffolded with no entity, written against the repository. It is refused before it
     * lands, saying what `run()` asked for, what the house answered, why, and the edit that makes its entry hand it
     * — with the order in which the two land. The scaffold survives byte for byte.
     */
    public function testARunThatAsksForARepositoryItsEntryCannotHandIsRefusedWithTheWay(): void
    {
        $before = (string) file_get_contents($this->operationFile());

        $r = $this->implement($this->saves());

        self::assertFalse($r['ok'], 'an operation the house cannot run landed');
        $error = (string) $r['error'];
        self::assertStringContainsString('the house cannot hand «GuardarCaja» what its run() works through', $error);
        self::assertStringContainsString('«bodega:guardar»', $error);
        self::assertStringContainsString('Milpa\\Data\\RepositoryInterface', $error);
        self::assertStringContainsString('is not registered in the container', $error, 'what the house itself answered');
        self::assertStringContainsString('A repository has no class to be found by: it is reached by its entity', $error);
        self::assertStringContainsString('This plugin\'s entities: Caja', $error);
        // The way, exact and in order: the entry first, then this body again.
        self::assertStringContainsString('edit plugin=Bodega class=Bodega', $error);
        $entry = '\\Milpa\\Command\\Declaration\\DeclaredOperation::from(\\App\\Plugins\\Bodega\\Operations\\GuardarCaja::class, ';
        self::assertStringContainsString('find: ' . $entry . self::BY_CLASS . '),', $error, 'the whole entry: in a plugin with four operations the resolver alone is in four places');
        self::assertStringContainsString('replace: ' . $entry . self::BY_ENTITY . '),', $error);
        self::assertMatchesRegularExpression('/1\..*edit plugin=Bodega.*2\..*implement plugin=Bodega class=GuardarCaja/s', $error);
        self::assertSame($before, (string) file_get_contents($this->operationFile()), 'the refused body stayed on disk');
        self::assertSame('collaborators', $r['diagnostic']['phase'] ?? null);
        self::assertTrue($r['diagnostic']['rolled_back'] ?? false);
        self::assertSame('bodega:guardar', $r['diagnostic']['result']['operation'] ?? null);
        self::assertSame('Milpa\\Data\\RepositoryInterface', $r['diagnostic']['result']['unhanded'][0]['type'] ?? null);
    }

    public function testTheSameBodyLandsOnceItsEntryNamesTheEntityAndItSavesAndReads(): void
    {
        $this->plugin(self::BY_ENTITY);

        $r = $this->implement($this->saves());

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertStringContainsString('run() is handed what it works through', (string) $r['verified']);
        // And it is not only resolvable: called the way the house calls it, it stores a row and reads it back.
        exec(\PHP_BINARY . ' -r ' . escapeshellarg(
            'require ' . var_export($this->root . '/vendor/autoload.php', true) . '; $c = new \Milpa\Container\DIContainer();'
            . ' $p = new \App\Plugins\Bodega\Bodega($c); $p->boot(); $op = $p->operations()[0];'
            . ' ($op->handler)(["etiqueta" => "Tornillos"]); echo json_encode(($op->handler)(["etiqueta" => "Clavos"]));',
        ) . ' 2>&1', $out, $exit);
        self::assertSame(0, $exit, implode("\n", $out));
        self::assertSame(['ok' => true, 'cajas' => 2], json_decode(implode("\n", $out), true));
    }

    /** With several entities the house names them and guesses none: the edit is written for the one that is said. */
    public function testWithSeveralEntitiesItNamesThemAndWritesNoEditForAGuess(): void
    {
        copy($this->root . '/src/Plugins/Bodega/Entities/Caja.php', $this->root . '/src/Plugins/Bodega/Entities/Estante.php');
        file_put_contents($this->root . '/src/Plugins/Bodega/Entities/Estante.php', str_replace('class Caja', 'class Estante', (string) file_get_contents($this->root . '/src/Plugins/Bodega/Entities/Estante.php')));

        $error = (string) $this->implement($this->saves())['error'];

        self::assertStringContainsString('This plugin\'s entities: Caja, Estante', $error);
        self::assertStringContainsString('<Entity>::class', $error, 'the edit leaves the entity to be named');
        self::assertStringNotContainsString('Entities\\Caja::class . \'Repository\'', $error);
    }

    /** Anything else nobody registered is the same defect, and its fix is the one a controller's has. */
    public function testAServiceNobodyRegisteredIsRefusedNamingItsType(): void
    {
        $r = $this->implement($this->operation("use App\\Plugins\\Bodega\\Services\\Etiquetas;\n", 'Etiquetas $etiquetas', "return ['ok' => true, 'etiqueta' => \$etiquetas->limpia(\$this->etiqueta)];"));

        self::assertFalse($r['ok']);
        $error = (string) $r['error'];
        self::assertStringContainsString('App\\Plugins\\Bodega\\Services\\Etiquetas', $error);
        self::assertStringContainsString('registerService(\\App\\Plugins\\Bodega\\Services\\Etiquetas::class', $error);
        self::assertStringContainsString('boot()', $error);
        self::assertStringNotContainsString('reached by its entity', $error);
    }

    public function testARunThatAsksForNothingLandsAndIsSaidHanded(): void
    {
        $r = $this->implement($this->operation('', '', "return ['ok' => true];"));

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertStringContainsString('run() is handed what it works through', (string) $r['verified']);
    }

    /**
     * A SILENT GAP READS AS COVERED. An operation whose plugin the house does not boot yet — scaffolded, and not
     * registered — is in no catalogue, so nothing can be asked for it: it lands, and the result says that what its
     * run() works through went unjudged, and why.
     */
    public function testAnOperationTheBootedHouseDoesNotOfferLandsAndSaysItWentUnjudged(): void
    {
        file_put_contents($this->root . '/config/boot.php', "<?php\n\nreturn ['container' => new \\Milpa\\Container\\DIContainer(), 'plugins' => []];\n");

        $r = $this->implement($this->saves());

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertStringContainsString('what run() works through unjudged — no operation the booted house offers is this class', (string) $r['verified']);
        self::assertStringNotContainsString('is handed what it works through', (string) $r['verified']);
    }

    /** A class no operation of the house is: nothing is asked of it here, and nothing is said. */
    public function testAClassThatIsNoOperationIsNotAsked(): void
    {
        file_put_contents($this->root . '/src/Plugins/Bodega/Services/Limpia.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\Bodega\\Services;\n\nfinal class Limpia\n{\n}\n");

        $r = (new ImplementHandler(new RootResolver($this->root)))->handle(['plugin' => 'Bodega', 'class' => 'Limpia', 'content' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\Bodega\\Services;\n\nfinal class Limpia\n{\n    public function limpia(string \$e): string\n    {\n        return trim(\$e);\n    }\n}\n"]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertStringNotContainsString('run()', (string) $r['verified']);
    }

    /** The probe alone, as the gate reads it. */
    public function testTheProbeSaysWhichOperationAndWhatItCouldNotBeHanded(): void
    {
        file_put_contents($this->operationFile(), $this->saves());

        $asked = (new ConstructionProbe())->probe($this->root, 'App\\Plugins\\Bodega\\Operations\\GuardarCaja');

        self::assertSame('bodega:guardar', $asked['operation']['name'] ?? null);
        self::assertFalse($asked['operation']['handed'] ?? null);
        self::assertSame('Milpa\\Data\\RepositoryInterface', $asked['operation']['unhanded'][0]['type'] ?? null);
        self::assertStringContainsString('is not registered in the container', (string) ($asked['operation']['unhanded'][0]['error'] ?? ''));
        self::assertSame([], $asked['routes'] ?? null, 'no route names it: construction is not asked');
    }

    /**
     * WHAT THE PROBE READS OF `milpa/command`, PINNED. The resolver an entry hands lives only inside the handler
     * `DeclaredOperation::from()` returns; the probe reaches it there. If that package changes how it closes over
     * it, this goes red here — and not as a judge that silently stopped judging.
     */
    public function testTheHandlerOfADeclaredOperationStillCarriesWhatTheProbeReads(): void
    {
        file_put_contents($this->operationFile(), $this->saves());
        require_once $this->root . '/vendor/autoload.php';
        $class = 'App\\Plugins\\Bodega\\Operations\\GuardarCaja';
        $resolve = static fn (string $type): object => new \stdClass();

        $closed = (new \ReflectionFunction(DeclaredOperation::from($class, $resolve)->handler))->getStaticVariables();

        self::assertSame($class, $closed['class'] ?? null);
        self::assertSame(['Milpa\\Data\\RepositoryInterface'], $closed['collaborators'] ?? null);
        self::assertSame($resolve, $closed['resolve'] ?? null);
    }

    private function operationFile(): string
    {
        return $this->root . '/src/Plugins/Bodega/Operations/GuardarCaja.php';
    }

    /** The body a resident wrote: it saves a row through the repository and counts what is there. */
    private function saves(): string
    {
        return $this->operation(
            "use App\\Plugins\\Bodega\\Entities\\Caja;\nuse Milpa\\Data\\RepositoryInterface;\n",
            'RepositoryInterface $repository',
            "\$repository->save(new Caja(null, \$this->etiqueta));\n\n        return ['ok' => true, 'cajas' => \\count(\$repository->all())];",
        );
    }

    /** `bodega:guardar`, with `$uses` imported, `run()` taking `$parameters` and doing `$body`. */
    private function operation(string $uses, string $parameters, string $body): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\Bodega\\Operations;\n\n{$uses}"
            . "use Milpa\\Command\\Declaration\\Mutates;\nuse Milpa\\Command\\Declaration\\Needs;\nuse Milpa\\Command\\Declaration\\Operation;\n"
            . "use Milpa\\Command\\Effect\\Authority;\nuse Milpa\\Command\\Effect\\Externality;\nuse Milpa\\Command\\Effect\\Mutation;\n"
            . "use Milpa\\Command\\Effect\\Reversibility;\nuse Milpa\\Command\\Effect\\Subject;\n\n"
            . "#[Operation(name: 'bodega:guardar', description: 'Guarda una caja.')]\n"
            . "#[Mutates(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::WriteAsUser, subject: Subject::Data)]\n"
            . "#[Needs(scopes: ['bodega:write'])]\nfinal class GuardarCaja\n{\n"
            . "    public function __construct(public readonly string \$etiqueta)\n    {\n    }\n\n"
            . "    /** @return array<string, mixed> */\n    public function run({$parameters}): array\n    {\n        {$body}\n    }\n}\n";
    }

    /** The Bodega plugin: it registers the repository of Caja, and lists the operation with `$resolver`. */
    private function plugin(string $resolver): void
    {
        file_put_contents($this->root . '/src/Plugins/Bodega/Bodega.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Plugins\\Bodega;

            use Milpa\\Attributes\\PluginMetadata;
            use Milpa\\Interfaces\\Di\\DIContainerInterface;
            use Milpa\\Interfaces\\Plugin\\PluginInterface;

            #[PluginMetadata(version: '0.1.0', author: 'Test', site: 'https://example.com', name: 'Bodega', type: 'Service')]
            final class Bodega implements PluginInterface, \\Milpa\\Command\\CommandProvider
            {
                public function __construct(private readonly DIContainerInterface \$container)
                {
                }

                public function boot(): void
                {
                    \$this->container->registerService(
                        \\App\\Plugins\\Bodega\\Entities\\Caja::class . 'Repository',
                        new \\Milpa\\Data\\InMemoryRepository(\\App\\Plugins\\Bodega\\Entities\\Caja::class),
                    );
                }

                public function install(): void
                {
                }

                public function uninstall(): void
                {
                }

                public function enable(): void
                {
                }

                public function disable(): void
                {
                }

                /** @return list<\\Milpa\\Command\\Operation> */
                public function operations(): array
                {
                    return [
                        \\Milpa\\Command\\Declaration\\DeclaredOperation::from(\\App\\Plugins\\Bodega\\Operations\\GuardarCaja::class, {$resolver}),
                        // {coa:operations}
                    ];
                }
            }
            PHP);
    }

    /** @return array<string, mixed> */
    private function implement(string $content): array
    {
        return (new ImplementHandler(new RootResolver($this->root)))->handle(['plugin' => 'Bodega', 'class' => 'GuardarCaja', 'content' => $content]);
    }
}
