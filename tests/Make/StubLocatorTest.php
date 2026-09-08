<?php

/**
 * This file is part of milpa/devtools — the development tools a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\Generators\PluginGenerator;
use Milpa\DevTools\Make\StubLocator;
use PHPUnit\Framework\TestCase;

/**
 * A stub under the app's `stubs/` wins for that one name; every other keeps coming from the package;
 * a name in neither place is refused by name (greenhouse decisions/0216, point 6).
 */
final class StubLocatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-stubs-' . uniqid();
        mkdir($this->root . '/stubs', 0o775, true);
        file_put_contents(
            $this->root . '/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], JSON_PRETTY_PRINT),
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testUnboundItReadsThePackageAndRefusesWhatThePackageDoesNotShip(): void
    {
        $locator = new StubLocator();

        $this->assertNull($locator->app());
        $this->assertStringEndsWith('/src/Make/stubs/plugin.standalone.runtime.php.stub', $locator->path('plugin.standalone.runtime.php.stub'));
        $this->assertContains('plugin.standalone.runtime.php.stub', $locator->names());
        $this->assertFalse($locator->isOverridden('plugin.standalone.runtime.php.stub'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no stub named «nope.stub»');
        $locator->path('nope.stub');
    }

    public function testBoundToAnAppItsCopyWinsForThatOneNameOnly(): void
    {
        file_put_contents($this->root . '/stubs/plugin.standalone.runtime.php.stub', 'MINE');
        $bound = (new StubLocator())->at($this->root);

        $this->assertSame($this->root . '/stubs', $bound->app());
        $this->assertSame($this->root . '/stubs/plugin.standalone.runtime.php.stub', $bound->path('plugin.standalone.runtime.php.stub'));
        $this->assertTrue($bound->isOverridden('plugin.standalone.runtime.php.stub'));
        // THE REST STILL COMES FROM THE PACKAGE: overriding one stub is not adopting all of them.
        $this->assertStringEndsWith('/src/Make/stubs/entity.runtime.php.stub', $bound->path('entity.runtime.php.stub'));
        $this->assertFalse($bound->isOverridden('entity.runtime.php.stub'));
        // And `at()` did not touch the unbound one.
        $this->assertNull((new StubLocator())->app());
    }

    public function testBoundTheRefusalNamesBothPlaces(): void
    {
        $bound = (new StubLocator())->at($this->root);

        $this->expectExceptionMessage('nor in ' . $this->root . '/stubs');
        $bound->path('nope.stub');
    }

    public function testMakeWritesWhatTheAppsCopySays(): void
    {
        $context = new GenerationContext(plugin: 'Board', name: 'Board', options: ['flavor' => 'runtime'], root: $this->root);

        // THE CONTROL, FIRST: without a copy in the app, the package's stub is what gets written.
        $shipped = (new PluginGenerator())->generate($context)->files[0]->contents;
        $this->assertStringContainsString('namespace App\\Plugins\\Board;', $shipped);
        $this->assertStringNotContainsString('OVERRIDDEN BY THE APP', $shipped);

        // The app publishes its own copy of that one stub: from now on make writes the copy.
        $package = (new StubLocator())->path('plugin.standalone.runtime.php.stub');
        file_put_contents(
            $this->root . '/stubs/plugin.standalone.runtime.php.stub',
            "<?php\n// OVERRIDDEN BY THE APP\n" . substr((string) file_get_contents($package), \strlen("<?php\n")),
        );
        $overridden = (new PluginGenerator())->generate($context)->files[0]->contents;
        $this->assertStringContainsString('OVERRIDDEN BY THE APP', $overridden);
        $this->assertStringContainsString('namespace App\\Plugins\\Board;', $overridden, 'the variables are still rendered into the copy');
    }
}
