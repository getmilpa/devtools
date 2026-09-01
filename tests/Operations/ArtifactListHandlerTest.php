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

use Milpa\DevTools\Operations\ArtifactListHandler;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/**
 * The read-only artifact inventory: declarations are listed without loading or returning their bodies.
 */
final class ArtifactListHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-artifact-list-' . bin2hex(random_bytes(4));
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

    /** Lists classes, interfaces, and enums across plugins without loading their files. */
    public function testListsArtifactDeclarationsWithoutExecutingTheirFiles(): void
    {
        $marker = $this->root . '/artifact-loaded';
        $this->writeArtifact(
            'Billing',
            'Entities',
            'Invoice',
            "file_put_contents(" . var_export($marker, true) . ", 'loaded');\n\nfinal class Invoice {}\n",
        );
        $this->writeArtifact('Billing', 'Contracts', 'InvoiceRepository', "interface InvoiceRepository {}\n");
        $this->writeArtifact('Shipping', 'Enums', 'DeliveryState', "enum DeliveryState: string { case Pending = 'pending'; }\n");

        $result = $this->handler()->handle([]);

        self::assertTrue($result['ok']);
        self::assertFileDoesNotExist($marker, 'listing declarations must not execute a file body');

        $artifacts = [];
        foreach ($result['artifacts'] as $artifact) {
            $artifacts[$artifact['fqcn']] = $artifact;
            self::assertArrayNotHasKey('source', $artifact);
            self::assertArrayNotHasKey('content', $artifact);
        }

        self::assertSame('interface', $artifacts['App\\Plugins\\Billing\\Contracts\\InvoiceRepository']['kind']);
        self::assertSame('class', $artifacts['App\\Plugins\\Billing\\Entities\\Invoice']['kind']);
        self::assertSame('enum', $artifacts['App\\Plugins\\Shipping\\Enums\\DeliveryState']['kind']);
        self::assertSame('src/Plugins/Billing/Entities/Invoice.php', $artifacts['App\\Plugins\\Billing\\Entities\\Invoice']['path']);
    }

    /** A plugin filter narrows the inventory without changing its response shape. */
    public function testFiltersArtifactsByPlugin(): void
    {
        $this->writeArtifact('Billing', 'Entities', 'Invoice', "final class Invoice {}\n");
        $this->writeArtifact('Shipping', 'Entities', 'Parcel', "final class Parcel {}\n");

        $result = $this->handler()->handle(['plugin' => 'Billing']);

        self::assertTrue($result['ok']);
        self::assertCount(1, $result['artifacts']);
        self::assertSame('Billing', $result['artifacts'][0]['plugin']);
        self::assertSame('Invoice', $result['artifacts'][0]['name']);
    }

    /** The inventory covers the legacy plugin tree produced by the package's legacy generators. */
    public function testListsArtifactsFromTheLegacyPluginConvention(): void
    {
        $dir = $this->root . '/plugins/LegacyBilling/Entities';
        mkdir($dir, 0o775, true);
        file_put_contents(
            $dir . '/LegacyInvoice.php',
            "<?php\n\nnamespace Milpa\\Plugins\\LegacyBilling\\Entities;\n\nfinal class LegacyInvoice {}\n",
        );

        $result = $this->handler()->handle(['plugin' => 'LegacyBilling']);

        self::assertTrue($result['ok']);
        self::assertSame('class', $result['artifacts'][0]['kind']);
        self::assertSame('Milpa\\Plugins\\LegacyBilling\\Entities\\LegacyInvoice', $result['artifacts'][0]['fqcn']);
        self::assertSame('plugins/LegacyBilling/Entities/LegacyInvoice.php', $result['artifacts'][0]['path']);
    }

    /** A missing or invalid plugin is an `ok:false` observation, never an exception. */
    public function testMissingAndInvalidPluginsAreResults(): void
    {
        $missing = $this->handler()->handle(['plugin' => 'Missing']);
        $invalid = $this->handler()->handle(['plugin' => '../Billing']);

        self::assertFalse($missing['ok']);
        self::assertSame([], $missing['artifacts']);
        self::assertStringContainsString('Missing', (string) $missing['error']);
        self::assertFalse($invalid['ok']);
        self::assertStringContainsString('identifier', (string) $invalid['error']);
    }

    /** An empty inventory is not reported as a successful discovery. */
    public function testAnEmptyInventoryIsNotFound(): void
    {
        mkdir($this->root . '/src/Plugins/EmptyPlugin', 0o775, true);

        $emptyPlugin = $this->handler()->handle(['plugin' => 'EmptyPlugin']);
        $emptyGlobal = $this->handler()->handle([]);

        self::assertFalse($emptyPlugin['ok']);
        self::assertSame([], $emptyPlugin['artifacts']);
        self::assertFalse($emptyGlobal['ok']);
        self::assertSame([], $emptyGlobal['artifacts']);
    }

    private function handler(): ArtifactListHandler
    {
        return new ArtifactListHandler(new RootResolver($this->root));
    }

    private function writeArtifact(string $plugin, string $subdir, string $name, string $body): void
    {
        $dir = $this->root . '/src/Plugins/' . $plugin . '/' . $subdir;
        mkdir($dir, 0o775, true);
        file_put_contents(
            $dir . '/' . $name . '.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\{$plugin}\\{$subdir};\n\n{$body}",
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
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
