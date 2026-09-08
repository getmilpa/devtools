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

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Make\StubLocator;
use Milpa\DevTools\Operations\StubsPublishHandler;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/**
 * `stubs:publish` copies the package's stubs into the app's `stubs/`, keeps what is already there, and
 * refuses a name the package does not ship.
 */
final class StubsPublishHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-publish-' . uniqid();
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function handler(): StubsPublishHandler
    {
        return new StubsPublishHandler(new RootResolver($this->root));
    }

    public function testItPublishesEveryStubThePackageShipsAndKeepsThemOnASecondRun(): void
    {
        $shipped = (new StubLocator())->names();
        $this->assertNotSame([], $shipped);

        $first = $this->handler()->handle([]);
        $this->assertTrue($first['ok']);
        $this->assertSame($this->root . '/stubs', $first['dir']);
        $this->assertSame($shipped, $first['published']);
        $this->assertSame([], $first['kept']);
        foreach ($shipped as $name) {
            $this->assertFileEquals((new StubLocator())->path($name), $this->root . '/stubs/' . $name);
        }

        // The app edits one copy; publishing again must not undo the edit.
        file_put_contents($this->root . '/stubs/' . $shipped[0], 'EDITED');
        $second = $this->handler()->handle([]);
        $this->assertTrue($second['ok']);
        $this->assertSame([], $second['published']);
        $this->assertSame($shipped, $second['kept']);
        $this->assertStringEqualsFile($this->root . '/stubs/' . $shipped[0], 'EDITED');
        $this->assertStringContainsString('already published', $second['hint']);

        // POSITIVE CONTROL: `force` is the one way to start over from the package.
        $third = $this->handler()->handle(['force' => true, 'only' => $shipped[0]]);
        $this->assertSame([$shipped[0]], $third['published']);
        $this->assertFileEquals((new StubLocator())->path($shipped[0]), $this->root . '/stubs/' . $shipped[0]);
    }

    public function testOnlyNarrowsToNamedStubsAndRefusesOnesThePackageDoesNotShip(): void
    {
        $answer = $this->handler()->handle(['only' => 'entity.runtime.php.stub, plugin.standalone.runtime.php.stub']);
        $this->assertTrue($answer['ok']);
        $this->assertSame(['entity.runtime.php.stub', 'plugin.standalone.runtime.php.stub'], $answer['published']);
        $this->assertCount(2, glob($this->root . '/stubs/*.stub') ?: []);

        $refused = $this->handler()->handle(['only' => 'nope.stub']);
        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString('no stub named «nope.stub»', $refused['error']);
        $this->assertStringContainsString('the package ships:', $refused['error']);
        $this->assertCount(2, glob($this->root . '/stubs/*.stub') ?: [], 'a refusal writes nothing');
    }
}
