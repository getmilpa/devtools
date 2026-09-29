<?php

/**
 * This file is part of Milpa DevTools — the coa generate-verify-inspect toolset.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Test;

use Milpa\DevTools\Test\HouseWrites;
use PHPUnit\Framework\TestCase;

/**
 * The witness beside `test`'s ephemeral declaration (greenhouse decisions/0523): what a run wrote into the house, by content.
 */
final class HouseWritesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-housewrites-' . bin2hex(random_bytes(4));
        foreach (['config', 'vendor/pkg', 'var/agent-runs', 'var/trials/w1/copy', 'var/boot-candidates/b1', 'node_modules/x', '.git'] as $dir) {
            mkdir($this->root . '/' . $dir, 0777, true);
        }
        file_put_contents($this->root . '/config/app.php', '<?php return [];');
        file_put_contents($this->root . '/vendor/pkg/a.php', 'a');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testASameBytesRewriteIsNotAWrite(): void
    {
        $w = new HouseWrites();
        $before = $w->digest($this->root);
        file_put_contents($this->root . '/config/app.php', '<?php return [];');

        self::assertSame([], $w->between($before, $w->digest($this->root)));
    }

    public function testChangedAddedAndRemovedPathsAreNamed(): void
    {
        $w = new HouseWrites();
        file_put_contents($this->root . '/gone.txt', 'x');
        $before = $w->digest($this->root);
        file_put_contents($this->root . '/config/app.php', '<?php return ["greeting" => "hi"];');
        mkdir($this->root . '/var/data', 0777, true);
        file_put_contents($this->root . '/var/data/posts.sqlite', 'rows');
        unlink($this->root . '/gone.txt');

        self::assertSame(['config/app.php', 'gone.txt', 'var/data/posts.sqlite'], $w->between($before, $w->digest($this->root)));
    }

    public function testWhatIsNotTheHousesOwnTreeIsLeftOut(): void
    {
        $w = new HouseWrites();
        $before = $w->digest($this->root);
        file_put_contents($this->root . '/vendor/pkg/a.php', 'changed');
        file_put_contents($this->root . '/node_modules/x/y.js', 'y');
        file_put_contents($this->root . '/.git/HEAD', 'ref');
        file_put_contents($this->root . '/var/agent-runs/lease', 'l');
        file_put_contents($this->root . '/var/agent-sessions.jsonl', '{}');
        file_put_contents($this->root . '/var/agent-sessions.jsonl.1', '{}');
        file_put_contents($this->root . '/var/trials/w1/copy/Blog.php', '<?php');
        file_put_contents($this->root . '/var/boot-candidates/b1/app.php', '<?php');

        self::assertSame([], $w->between($before, $w->digest($this->root)));
        self::assertArrayNotHasKey('vendor/pkg/a.php', (array) $before);
    }

    public function testAHouseThatCannotBeReadIsUnknownNotClean(): void
    {
        $w = new HouseWrites();

        self::assertNull($w->digest($this->root . '/missing'));
        self::assertNull($w->between(null, []));
        self::assertNull($w->between([], null));
    }

    public function testAnUnreadableFileMakesTheDigestUnknown(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root reads every file');
        }
        file_put_contents($this->root . '/secret', 's');
        chmod($this->root . '/secret', 0);

        self::assertNull((new HouseWrites())->digest($this->root));
        chmod($this->root . '/secret', 0644);
    }
}
