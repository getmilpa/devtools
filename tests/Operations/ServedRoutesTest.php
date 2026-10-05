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

use Milpa\DevTools\Operations\MakeHandler;
use Milpa\DevTools\Operations\ServedRoutes;
use Milpa\DevTools\Support\RootResolver;
use Milpa\Plugin\Contracts\BootWitnessInterface;
use PHPUnit\Framework\TestCase;

/**
 * The house is asked what it serves (greenhouse decisions/0567 §3, slice BV-2): by booting it at its root, the
 * way the construction judge does — never by reading a file of a package this one does not know.
 */
final class ServedRoutesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-served-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/vendor', 0o775, true);
        mkdir($this->root . '/config', 0o775, true);
        mkdir($this->root . '/src/Plugins/Doors', 0o775, true);
        file_put_contents($this->root . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}');
        file_put_contents($this->root . '/vendor/autoload.php', "<?php\n\$loader = require "
            . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
            . "\$loader->addPsr4('App\\\\', __DIR__ . '/../src/', true);\n\nreturn \$loader;\n");
        file_put_contents($this->root . '/config/app.php', "<?php\n\nreturn [];\n");
        file_put_contents($this->root . '/config/boot.php', "<?php\n\nreturn ['container' => new \\Milpa\\Container\\DIContainer(), "
            . "'plugins' => [\\App\\Plugins\\Doors\\Doors::class]];\n");
        file_put_contents($this->root . '/config/plugins.php', "<?php return [App\\Plugins\\Doors\\Doors::class];\n");
        // A plugin that serves what the app runtime's live door would: a mounted screen, a POST, and a route of its own.
        file_put_contents($this->root . '/src/Plugins/Doors/Doors.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Plugins\Doors;

            use Milpa\Attributes\PluginMetadata;
            use Milpa\Http\HttpMethod;
            use Milpa\Http\Routing\HandlerReference;
            use Milpa\Http\Routing\Route;
            use Milpa\Interfaces\Di\DIContainerInterface;
            use Milpa\Interfaces\Plugin\PluginInterface;
            use Milpa\Runtime\Http\RouteProviderInterface;

            #[PluginMetadata(version: '0.1.0', author: 'Test', site: 'https://example.com', name: 'Doors', type: 'Web')]
            final class Doors implements PluginInterface, RouteProviderInterface
            {
                public function __construct(private readonly DIContainerInterface $container)
                {
                }

                public function boot(): void
                {
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

                public function routes(): array
                {
                    return [
                        new Route('/blog', HttpMethod::GET, 'live.screen.blog', [], new HandlerReference(self::class, 'boot')),
                        new Route('/inbox', HttpMethod::POST, 'live.screen.inbox', [], new HandlerReference(self::class, 'boot')),
                        new Route('/api', [HttpMethod::GET, HttpMethod::POST], 'api_index', [], new HandlerReference(self::class, 'boot')),
                    ];
                }
            }
            PHP);
        $this->root = (string) realpath($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testTheBootedHouseSaysItsRouteTable(): void
    {
        self::assertSame([
            ['method' => 'GET', 'path' => '/blog', 'name' => 'live.screen.blog'],
            ['method' => 'POST', 'path' => '/inbox', 'name' => 'live.screen.inbox'],
            ['method' => 'GET|POST', 'path' => '/api', 'name' => 'api_index'],
        ], (new ServedRoutes())->table($this->root));
    }

    public function testAMountedScreenIsARouteTheHouseNamedForIt(): void
    {
        self::assertSame(['/blog' => 'blog'], (new ServedRoutes())->mountedScreens($this->root), 'by the name the runtime gives the mount, and only where it answers GET');
    }

    public function testAHouseThatCannotBeAskedMountsNothing(): void
    {
        unlink($this->root . '/vendor/autoload.php');
        self::assertSame([], (new ServedRoutes())->table($this->root), 'no vendor/: nothing boots');

        file_put_contents($this->root . '/vendor/autoload.php', "<?php\nthrow new \\RuntimeException('broken');\n");
        self::assertSame([], (new ServedRoutes())->mountedScreens($this->root), 'a house that does not boot answers nothing — never a guess');
    }

    public function testMakeAsksTheBootedHouseBeforeScaffoldingARoute(): void
    {
        $make = new MakeHandler(new RootResolver($this->root), static fn (): ?BootWitnessInterface => null);

        $refused = $make->handle(['what' => 'controller', 'plugin' => 'Doors', 'name' => 'BlogController', 'route' => '/blog', 'returns' => 'data', 'no_verify' => true]);
        self::assertFalse($refused['ok']);
        self::assertStringContainsString('GET /blog is where the screen «blog» is mounted', (string) $refused['error']);
        self::assertFileDoesNotExist($this->root . '/src/Plugins/Doors/Controllers/BlogController.php');

        $free = $make->handle(['what' => 'controller', 'plugin' => 'Doors', 'name' => 'InboxController', 'route' => '/inbox', 'returns' => 'data', 'no_verify' => true]);
        self::assertTrue($free['ok'], 'a route no screen answers GET at is free: ' . (json_encode($free) ?: ''));
    }
}
