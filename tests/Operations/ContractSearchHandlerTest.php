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

use Milpa\DevTools\Operations\ContractSearchHandler;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/**
 * The name finder: app plugins AND installed vendor code answer «what is this called», by reading
 * names — never by executing candidate files.
 */
final class ContractSearchHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-search-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o775, true);
        file_put_contents(
            $this->root . '/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], JSON_PRETTY_PRINT),
        );

        // The app's own plugin artifact.
        $this->writeFile(
            'src/Plugins/Billing/Entities/Invoice.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\Billing\\Entities;\n\nfinal class Invoice\n{\n}\n",
        );

        // A PSR-4 vendor package — one class with a TOP-LEVEL SIDE EFFECT that must never run,
        // and one interface deeper in the tree.
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
            'vendor/composer/autoload_psr4.php',
            "<?php\n\nreturn array(\n    'Acme\\\\Lib\\\\' => array(" . var_export($this->root . '/vendor/acme/lib/src', true) . "),\n);\n",
        );

        // A classmap-only vendor package (e.g. how PHPUnit autoloads) — no PSR-4 entry at all.
        $this->writeFile(
            'vendor/acme/mapped/StaticInvoice.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Mapped;\n\nenum StaticInvoice\n{\n    case One;\n}\n",
        );
        $this->writeFile(
            'vendor/composer/autoload_classmap.php',
            "<?php\n\nreturn array(\n    'Acme\\\\Mapped\\\\StaticInvoice' => " . var_export($this->root . '/vendor/acme/mapped/StaticInvoice.php', true) . ",\n);\n",
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    /** App plugins, PSR-4 vendor code, and classmap-only vendor code all answer — without running. */
    public function testFindsNamesAcrossAppAndVendorWithoutExecutingThem(): void
    {
        $result = $this->handler()->handle(['q' => 'invoice']);

        self::assertTrue($result['ok']);
        self::assertFalse($result['truncated']);
        self::assertSame(
            [
                ['fqcn' => 'Acme\\Lib\\InvoiceGateway', 'kind' => 'class', 'source' => 'vendor', 'package' => 'acme/lib'],
                ['fqcn' => 'Acme\\Mapped\\StaticInvoice', 'kind' => 'enum', 'source' => 'vendor', 'package' => 'acme/mapped'],
                ['fqcn' => 'App\\Plugins\\Billing\\Entities\\Invoice', 'kind' => 'class', 'source' => 'app'],
            ],
            $result['matches'],
        );
        self::assertFileDoesNotExist($this->root . '/executed-marker', 'searching a name must never execute a candidate file');
    }

    /** `package` narrows the answer to one vendor package — the app tree included stays out. */
    public function testPackageNarrowsTheSearchToOneVendorPackage(): void
    {
        $result = $this->handler()->handle(['q' => 'invoice', 'package' => 'acme/lib']);

        self::assertTrue($result['ok']);
        self::assertSame(['Acme\\Lib\\InvoiceGateway'], array_column($result['matches'], 'fqcn'));
    }

    /** A trailing backslash asks for a whole namespace, matched against the FQCN. */
    public function testATrailingBackslashMatchesWholeNamespaces(): void
    {
        $result = $this->handler()->handle(['q' => 'Acme\\Lib\\']);

        self::assertTrue($result['ok']);
        self::assertSame(
            ['Acme\\Lib\\Contracts\\PaymentGatewayInterface', 'Acme\\Lib\\InvoiceGateway'],
            array_column($result['matches'], 'fqcn'),
        );
    }

    /** Without `q` there is nothing to search — answered, not thrown. */
    public function testAMissingQueryIsAnswered(): void
    {
        $result = $this->handler()->handle([]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('q', (string) $result['error']);
    }

    /** No match is `ok:false` with a reason, never an exception. */
    public function testNoMatchIsOkFalseNotAnException(): void
    {
        $result = $this->handler()->handle(['q' => 'zzz-nothing-here']);

        self::assertFalse($result['ok']);
        self::assertSame([], $result['matches']);
        self::assertStringContainsString('zzz-nothing-here', (string) $result['error']);
    }

    /** A package that is a path, not a name, is refused before touching the filesystem. */
    public function testAPathShapedPackageIsRefused(): void
    {
        $result = $this->handler()->handle(['q' => 'invoice', 'package' => 'acme/..']);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('vendor/name', (string) $result['error']);
    }

    /** An app with no vendor autoload maps at all still searches its own plugins. */
    public function testAnAppWithoutVendorAutoloadStillSearchesItsPlugins(): void
    {
        $bare = sys_get_temp_dir() . '/milpa-devtools-search-bare-' . bin2hex(random_bytes(4));
        mkdir($bare . '/src/Plugins/Solo/Services', 0o775, true);
        file_put_contents(
            $bare . '/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]]),
        );
        file_put_contents(
            $bare . '/src/Plugins/Solo/Services/Thing.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\Solo\\Services;\n\nfinal class Thing\n{\n}\n",
        );

        try {
            $result = (new ContractSearchHandler(new RootResolver($bare)))->handle(['q' => 'thing']);

            self::assertTrue($result['ok']);
            self::assertSame(
                [['fqcn' => 'App\\Plugins\\Solo\\Services\\Thing', 'kind' => 'class', 'source' => 'app']],
                $result['matches'],
            );
        } finally {
            $this->removeDirectory($bare);
        }
    }

    /** The answer is capped at 25 and SAYS it was cut — a silent truncation would lie by omission. */
    public function testTheAnswerIsCappedAndSaysSo(): void
    {
        for ($index = 0; $index < 30; ++$index) {
            $name = sprintf('Cap%02d', $index);
            $this->writeFile(
                "vendor/acme/lib/src/Caps/{$name}.php",
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Lib\\Caps;\n\nfinal class {$name}\n{\n}\n",
            );
        }

        $result = $this->handler()->handle(['q' => 'cap']);

        self::assertTrue($result['ok']);
        self::assertCount(25, $result['matches']);
        self::assertTrue($result['truncated']);
    }

    private function handler(): ContractSearchHandler
    {
        return new ContractSearchHandler(new RootResolver($this->root));
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
