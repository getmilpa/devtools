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

use Milpa\DevTools\Operations\PackageArtifactsHandler;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/**
 * The package inventory: what an installed package declares, read from its own autoload roots
 * without executing anything of it.
 */
final class PackageArtifactsHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-pkg-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o775, true);
        file_put_contents($this->root . '/composer.json', '{}');

        $this->writeFile(
            'vendor/acme/lib/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']]]),
        );
        $this->writeFile(
            'vendor/acme/lib/src/InvoiceGateway.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Lib;\n\n"
            . "file_put_contents(__DIR__ . '/../../../executed-marker', 'loaded');\n\n"
            . "final class InvoiceGateway\n{\n}\n",
        );
        $this->writeFile(
            'vendor/acme/lib/src/Contracts/PaymentGatewayInterface.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Lib\\Contracts;\n\ninterface PaymentGatewayInterface\n{\n}\n",
        );
        $this->writeFile(
            'vendor/acme/lib/src/Kind.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Lib;\n\nenum Kind: string\n{\n    case Card = 'card';\n}\n",
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    /** Every declaration under the package's PSR-4 roots is listed — classes, interfaces, enums. */
    public function testListsWhatThePackageAutoloadRootsDeclare(): void
    {
        $result = $this->handler()->handle(['package' => 'acme/lib']);

        self::assertTrue($result['ok']);
        self::assertSame(
            [
                ['fqcn' => 'Acme\\Lib\\Contracts\\PaymentGatewayInterface', 'kind' => 'interface', 'source' => 'vendor', 'package' => 'acme/lib'],
                ['fqcn' => 'Acme\\Lib\\InvoiceGateway', 'kind' => 'class', 'source' => 'vendor', 'package' => 'acme/lib'],
                ['fqcn' => 'Acme\\Lib\\Kind', 'kind' => 'enum', 'source' => 'vendor', 'package' => 'acme/lib'],
            ],
            $result['artifacts'],
        );
        self::assertFileDoesNotExist($this->root . '/executed-marker', 'listing a package must never execute its files');
    }

    /** A prefix may declare a LIST of directories; all of them are enumerated. */
    public function testMultiDirectoryPsr4RootsAreAllEnumerated(): void
    {
        $this->writeFile(
            'vendor/acme/multi/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => ['Acme\\Multi\\' => ['src/', 'lib/']]]]),
        );
        $this->writeFile(
            'vendor/acme/multi/src/One.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Multi;\n\nfinal class One\n{\n}\n",
        );
        $this->writeFile(
            'vendor/acme/multi/lib/Two.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Multi;\n\nfinal class Two\n{\n}\n",
        );

        $result = $this->handler()->handle(['package' => 'acme/multi']);

        self::assertTrue($result['ok']);
        self::assertSame(['Acme\\Multi\\One', 'Acme\\Multi\\Two'], array_column($result['artifacts'], 'fqcn'));
    }

    /** Without `package` there is nothing to list — answered, not thrown. */
    public function testAMissingPackageInputIsAnswered(): void
    {
        $result = $this->handler()->handle([]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('package', (string) $result['error']);
    }

    /** A package that is not installed is `ok:false` with a reason, never an exception. */
    public function testAnUninstalledPackageIsOkFalse(): void
    {
        $result = $this->handler()->handle(['package' => 'acme/nope']);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('acme/nope', (string) $result['error']);
    }

    /** A package that is a path, not a name, is refused before touching the filesystem. */
    public function testAPathShapedPackageIsRefused(): void
    {
        foreach (['../../etc', 'acme/..', 'acme/lib/extra'] as $bad) {
            $result = $this->handler()->handle(['package' => $bad]);

            self::assertFalse($result['ok'], "«{$bad}» must be refused");
            self::assertStringContainsString('vendor/name', (string) $result['error']);
        }
    }

    /** A classmap-only package cannot be enumerated this way, and the answer says why. */
    public function testAPackageWithoutPsr4RootsIsAnswered(): void
    {
        $this->writeFile(
            'vendor/acme/classmap-only/composer.json',
            (string) json_encode(['autoload' => ['classmap' => ['src/']]]),
        );

        $result = $this->handler()->handle(['package' => 'acme/classmap-only']);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('PSR-4', (string) $result['error']);
    }

    /** Roots that hold no type declarations are an answer too — not an empty success. */
    public function testAPackageWhoseRootsDeclareNothingIsAnswered(): void
    {
        $this->writeFile(
            'vendor/acme/functions/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => ['Acme\\Fn\\' => 'src/']]]),
        );
        $this->writeFile(
            'vendor/acme/functions/src/helpers.php',
            "<?php\n\ndeclare(strict_types=1);\n\nfunction acme_helper(): void\n{\n}\n",
        );

        $result = $this->handler()->handle(['package' => 'acme/functions']);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('declare no', (string) $result['error']);
    }

    private function handler(): PackageArtifactsHandler
    {
        return new PackageArtifactsHandler(new RootResolver($this->root));
    }

    private function writeFile(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        if (! is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o775, true);
        }
        file_put_contents($path, $content);
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
