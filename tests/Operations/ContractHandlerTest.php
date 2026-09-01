<?php

/**
 * This file is part of Milpa DevTools — the generate-verify-inspect developer loop of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Operations\ContractHandler;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

final class ContractHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-contract-' . uniqid();
        mkdir($this->root, 0o775, true);
        file_put_contents(
            $this->root . '/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], JSON_PRETTY_PRINT),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testReadsAnEnumsCasesWithoutProvokingAnError(): void
    {
        // Unique class name so require_once never collides across the suite's single process.
        $enum = 'Prio' . substr(md5(uniqid()), 0, 8);
        $this->writeArtifact('Tareas', 'Enums', $enum, "enum {$enum}: string\n{\n    case baja = 'baja';\n    case alta = 'alta';\n}\n");

        $result = (new ContractHandler(new RootResolver($this->root)))->handle(['name' => $enum, 'plugin' => 'Tareas']);

        $this->assertTrue($result['ok'], 'the contract is read, not an error');
        $this->assertSame('enum', $result['artifact']['kind']);
        $this->assertSame('string', $result['artifact']['backing']);
        $cases = array_column($result['artifact']['cases'], 'value', 'name');
        $this->assertSame(['baja' => 'baja', 'alta' => 'alta'], $cases);
    }

    public function testReadsAClassConstructorSignature(): void
    {
        $class = 'Ent' . substr(md5(uniqid()), 0, 8);
        $this->writeArtifact('Tareas', 'Entities', $class, "final class {$class}\n{\n    public function __construct(\n        public string \$titulo,\n        public ?int \$listaId = null,\n    ) {\n    }\n}\n");

        $result = (new ContractHandler(new RootResolver($this->root)))->handle(['name' => $class, 'plugin' => 'Tareas']);

        $this->assertTrue($result['ok']);
        $this->assertSame('class', $result['artifact']['kind']);
        $params = $result['artifact']['constructor'];
        $this->assertSame('titulo', $params[0]['name']);
        $this->assertSame('string', $params[0]['type']);
        $this->assertFalse($params[0]['optional']);
        $this->assertSame('listaId', $params[1]['name']);
        $this->assertTrue($params[1]['nullable']);
        $this->assertTrue($params[1]['optional']);
    }

    public function testAMissingArtifactIsOkFalseNotAnException(): void
    {
        $result = (new ContractHandler(new RootResolver($this->root)))->handle(['name' => 'DoesNotExist', 'plugin' => 'Tareas']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('DoesNotExist', (string) $result['error']);
    }

    public function testResolvesAVendorClassByItsFqcn(): void
    {
        $result = (new ContractHandler(new RootResolver($this->root)))
            ->handle(['name' => \PHPUnit\Framework\TestCase::class]);

        $this->assertTrue($result['ok'], 'an installed class exists for the agent, not only for execution');
        $this->assertSame('class', $result['artifact']['kind']);
        $this->assertSame(\PHPUnit\Framework\TestCase::class, $result['artifact']['fqcn']);
        $this->assertNotSame([], $result['artifact']['methods']);
    }

    public function testResolvesAVendorEnumByItsFqcn(): void
    {
        // A leading backslash is how an agent often writes a FQCN; it resolves to the same artifact.
        $result = (new ContractHandler(new RootResolver($this->root)))
            ->handle(['name' => '\\Milpa\\Command\\Effect\\Mutation']);

        $this->assertTrue($result['ok']);
        $this->assertSame('enum', $result['artifact']['kind']);
        $this->assertSame('Milpa\\Command\\Effect\\Mutation', $result['artifact']['fqcn']);
        $this->assertNotSame([], $result['artifact']['cases']);
    }

    public function testAnUnloadableFqcnIsOkFalseNotAnException(): void
    {
        $result = (new ContractHandler(new RootResolver($this->root)))
            ->handle(['name' => 'Definitely\\Not\\Loadable']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Definitely\\Not\\Loadable', (string) $result['error']);
        $this->assertStringContainsString('contract:search', (string) $result['error'], 'the refusal points at the finder');
    }

    public function testMemberConstructorNarrowsToTheConstructor(): void
    {
        $class = $this->writeServiceFixture();

        $result = (new ContractHandler(new RootResolver($this->root)))
            ->handle(['name' => $class, 'plugin' => 'Tareas', 'member' => 'constructor']);

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('methods', $result['artifact'], 'a narrow question gets a small answer');
        $this->assertSame('db', $result['artifact']['constructor'][0]['name']);
        $this->assertSame('string', $result['artifact']['constructor'][0]['type']);
    }

    public function testMemberConstructorOnAClassWithoutOneIsNullNotAnError(): void
    {
        $class = 'Bare' . substr(md5(uniqid()), 0, 8);
        $this->writeArtifact('Tareas', 'Services', $class, "final class {$class}\n{\n}\n");

        $result = (new ContractHandler(new RootResolver($this->root)))
            ->handle(['name' => $class, 'plugin' => 'Tareas', 'member' => 'constructor']);

        $this->assertTrue($result['ok'], 'having no constructor IS the contract, not a failure');
        $this->assertNull($result['artifact']['constructor']);
    }

    public function testMemberMethodsNarrowsToCompactSignatures(): void
    {
        $class = $this->writeServiceFixture();

        $result = (new ContractHandler(new RootResolver($this->root)))
            ->handle(['name' => $class, 'plugin' => 'Tareas', 'member' => 'methods']);

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('constructor', $result['artifact']);
        $this->assertContains('save(string $titulo): void', $result['artifact']['methods']);
        $this->assertContains('static make(): self', $result['artifact']['methods']);
        foreach ($result['artifact']['methods'] as $signature) {
            $this->assertStringNotContainsString('hidden', $signature, 'private methods are not part of the contract');
        }
    }

    public function testMemberNamesOneMethodAndGetsOnlyThat(): void
    {
        $class = $this->writeServiceFixture();

        $result = (new ContractHandler(new RootResolver($this->root)))
            ->handle(['name' => $class, 'plugin' => 'Tareas', 'member' => 'save']);

        $this->assertTrue($result['ok']);
        $this->assertSame('save', $result['artifact']['method']['name']);
        $this->assertSame('void', $result['artifact']['method']['returns']);
        $this->assertFalse($result['artifact']['method']['static']);
        $this->assertArrayNotHasKey('methods', $result['artifact']);
    }

    public function testAMissingOrPrivateMemberIsOkFalseNotAnException(): void
    {
        $class = $this->writeServiceFixture();
        $handler = new ContractHandler(new RootResolver($this->root));

        $missing = $handler->handle(['name' => $class, 'plugin' => 'Tareas', 'member' => 'doesNotExist']);
        $this->assertFalse($missing['ok']);
        $this->assertStringContainsString('doesNotExist', (string) $missing['error']);

        $private = $handler->handle(['name' => $class, 'plugin' => 'Tareas', 'member' => 'hidden']);
        $this->assertFalse($private['ok'], 'a private method is not part of the contract either');
    }

    public function testMemberConstructorOnAnEnumIsAnswered(): void
    {
        $enum = 'Pri' . substr(md5(uniqid()), 0, 8);
        $this->writeArtifact('Tareas', 'Enums', $enum, "enum {$enum}: string\n{\n    case baja = 'baja';\n}\n");

        $result = (new ContractHandler(new RootResolver($this->root)))
            ->handle(['name' => $enum, 'plugin' => 'Tareas', 'member' => 'constructor']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('enum', (string) $result['error']);
    }

    public function testMemberNarrowsAVendorFqcnToo(): void
    {
        $result = (new ContractHandler(new RootResolver($this->root)))
            ->handle(['name' => \PHPUnit\Framework\TestCase::class, 'member' => 'assertTrue']);

        $this->assertTrue($result['ok']);
        $this->assertSame('assertTrue', $result['artifact']['method']['name']);
        $this->assertTrue($result['artifact']['method']['static']);
    }

    /** Writes one service class with a constructor, two public methods, and a private one. */
    private function writeServiceFixture(): string
    {
        $class = 'Svc' . substr(md5(uniqid()), 0, 8);
        $this->writeArtifact(
            'Tareas',
            'Services',
            $class,
            "final class {$class}\n{\n    public function __construct(private string \$db)\n    {\n    }\n\n"
            . "    public function save(string \$titulo): void\n    {\n    }\n\n"
            . "    public static function make(): self\n    {\n        return new self('x');\n    }\n\n"
            . "    private function hidden(): void\n    {\n    }\n}\n",
        );

        return $class;
    }

    private function writeArtifact(string $plugin, string $subdir, string $class, string $body): void
    {
        $dir = $this->root . '/src/Plugins/' . $plugin . '/' . $subdir;
        mkdir($dir, 0o775, true);
        file_put_contents(
            $dir . '/' . $class . '.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\{$plugin}\\{$subdir};\n\n{$body}",
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
