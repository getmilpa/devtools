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
