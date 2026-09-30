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

use Milpa\DevTools\Operations\ConstructionProbe;
use Milpa\DevTools\Operations\ImplementHandler;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/**
 * `implement` asks the container for a routed controller, the way serving the route does.
 *
 * Measured on Rod's first live run (greenhouse evidence/1071): `implement BlogController` landed GREEN a
 * controller whose constructor asked for `DIContainerInterface`, which nothing registers; the promotion
 * took `GET /blog` from 200 to 500 with `Cannot resolve parameter $container`. Syntax, strict types,
 * class, namespace and PHPStan all passed — none of them asks what the house does with a controller:
 * `$container->get()` at request time. So the judge is that act, executed in a booted copy of the house,
 * never a reading of the constructor (greenhouse decisions/0541).
 *
 * The fixture is a real `milpa/runtime` house: its `vendor/autoload.php` chains this package's own
 * autoloader, `config/boot.php` builds a real `DIContainer`, and the Blog plugin routes `GET /blog`.
 */
final class ImplementBuildsThroughTheContainerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-construction-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/vendor', 0o775, true);
        mkdir($this->root . '/config', 0o775, true);
        mkdir($this->root . '/src/Plugins/Blog/Controllers', 0o775, true);
        mkdir($this->root . '/src/Plugins/Blog/Services', 0o775, true);

        $package = \dirname(__DIR__, 2);
        file_put_contents($this->root . '/vendor/autoload.php', "<?php\n\$loader = require "
            . var_export($package . '/vendor/autoload.php', true) . ";\n"
            . "\$loader->addPsr4('App\\\\', __DIR__ . '/../src/', true);\n\nreturn \$loader;\n");
        file_put_contents($this->root . '/config/app.php', "<?php\n\nreturn ['app' => ['debug' => false]];\n");
        file_put_contents($this->root . '/config/boot.php', "<?php\n\nreturn ['container' => new \\Milpa\\Container\\DIContainer(), "
            . "'plugins' => [\\App\\Plugins\\Blog\\Blog::class]];\n");
        file_put_contents($this->root . '/src/Plugins/Blog/Services/PostsInterface.php', "<?php\n\ndeclare(strict_types=1);\n\n"
            . "namespace App\\Plugins\\Blog\\Services;\n\ninterface PostsInterface\n{\n    /** @return list<string> */\n"
            . "    public function titles(): array;\n}\n");
        file_put_contents($this->root . '/src/Plugins/Blog/Services/Posts.php', "<?php\n\ndeclare(strict_types=1);\n\n"
            . "namespace App\\Plugins\\Blog\\Services;\n\nfinal class Posts implements PostsInterface\n{\n"
            . "    public function titles(): array\n    {\n        return ['The milpa that learned to write'];\n    }\n}\n");
        $this->plugin('');
        file_put_contents($this->controllerFile(), $this->scaffold());
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** The Blog plugin, routing `GET /blog` to BlogController, with `$boot` as the body of its boot(). */
    private function plugin(string $boot): void
    {
        file_put_contents($this->root . '/src/Plugins/Blog/Blog.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Plugins\\Blog;

            use App\\Plugins\\Blog\\Controllers\\BlogController;
            use Milpa\\Attributes\\PluginMetadata;
            use Milpa\\Http\\HttpMethod;
            use Milpa\\Http\\Routing\\HandlerReference;
            use Milpa\\Http\\Routing\\Route;
            use Milpa\\Interfaces\\Di\\DIContainerInterface;
            use Milpa\\Interfaces\\Plugin\\PluginInterface;
            use Milpa\\Runtime\\Http\\RouteProviderInterface;

            #[PluginMetadata(version: '0.1.0', author: 'Test', site: 'https://example.com', name: 'Blog', type: 'Web')]
            final class Blog implements PluginInterface, RouteProviderInterface
            {
                public function __construct(private readonly DIContainerInterface \$container)
                {
                }

                public function boot(): void
                {
                    {$boot}
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

                /** @return list<Route> */
                public function routes(): array
                {
                    return [new Route(path: '/blog', methods: HttpMethod::GET, name: 'blog_index', handler: new HandlerReference(BlogController::class, 'index'))];
                }
            }

            PHP);
    }

    private function controllerFile(): string
    {
        return $this->root . '/src/Plugins/Blog/Controllers/BlogController.php';
    }

    /** The controller as `make controller` leaves it: dependency-free, so the container builds it. */
    private function scaffold(): string
    {
        return $this->controller('', '');
    }

    /** A BlogController whose constructor takes `$parameters`, with `$uses` imported. */
    private function controller(string $uses, string $parameters): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\Blog\\Controllers;\n\n{$uses}"
            . "use Nyholm\\Psr7\\Response;\nuse Psr\\Http\\Message\\ResponseInterface;\nuse Psr\\Http\\Message\\ServerRequestInterface;\n\n"
            . "final class BlogController\n{\n"
            . ($parameters === '' ? '' : "    public function __construct({$parameters})\n    {\n    }\n\n")
            . "    public function index(ServerRequestInterface \$request): ResponseInterface\n    {\n"
            . "        return new Response(200, [], 'blog');\n    }\n}\n";
    }

    /** @return array<string, mixed> */
    private function implement(string $content, string $class = 'BlogController'): array
    {
        return (new ImplementHandler(new RootResolver($this->root)))
            ->handle(['plugin' => 'Blog', 'class' => $class, 'content' => $content]);
    }

    /**
     * THE 1071 CONTROLLER: it asks for the container, nothing registers the container, and the landing is
     * refused naming the route, the parameter, its type, and the rule with the exact line that fixes it —
     * the original survives byte for byte, as with every other judge.
     */
    public function testAControllerThatAsksForTheContainerIsRefusedWithTheRegistrationRule(): void
    {
        $before = (string) file_get_contents($this->controllerFile());

        $r = $this->implement($this->controller(
            "use Milpa\\Interfaces\\Di\\DIContainerInterface;\n",
            'private readonly DIContainerInterface $container',
        ));

        self::assertFalse($r['ok'], 'a controller the house cannot build landed');
        $error = (string) $r['error'];
        self::assertStringContainsString('GET /blog', $error);
        self::assertStringContainsString('$container', $error);
        self::assertStringContainsString('Milpa\\Interfaces\\Di\\DIContainerInterface', $error);
        self::assertStringContainsString('boot()', $error);
        self::assertStringContainsString('registerService(BlogController::class, new BlogController(', $error);
        self::assertStringContainsString('new BlogController(/* what it would pull from the container */)', $error);
        self::assertStringContainsString('not the container', $error);
        self::assertSame($before, (string) file_get_contents($this->controllerFile()), 'the refused body stayed on disk');
        self::assertSame('container', $r['diagnostic']['phase'] ?? null);
        self::assertTrue($r['diagnostic']['rolled_back'] ?? false);
        self::assertTrue($r['diagnostic']['stable_subject'] ?? false, 'the judged body was the submitted one');
        self::assertSame([['parameter' => '$container', 'type' => 'Milpa\\Interfaces\\Di\\DIContainerInterface']], $r['diagnostic']['result']['unresolvable'] ?? null);
    }

    /** Any interface nobody registered is the same defect — the rule is not about the container. */
    public function testAnUnregisteredInterfaceIsRefusedTheSameWay(): void
    {
        $r = $this->implement($this->controller(
            "use App\\Plugins\\Blog\\Services\\PostsInterface;\n",
            'private readonly PostsInterface $posts',
        ));

        self::assertFalse($r['ok']);
        self::assertStringContainsString('$posts', (string) $r['error']);
        self::assertStringContainsString('App\\Plugins\\Blog\\Services\\PostsInterface', (string) $r['error']);
        self::assertStringContainsString('new BlogController(/* PostsInterface $posts */)', (string) $r['error']);
    }

    /** The positive control of the refusal: the SAME controller, registered in boot(), lands and says it was built. */
    public function testTheSameControllerRegisteredInBootLands(): void
    {
        $this->plugin('$this->container->registerService(BlogController::class, new BlogController(new \\App\\Plugins\\Blog\\Services\\Posts()));');

        $r = $this->implement($this->controller(
            "use App\\Plugins\\Blog\\Services\\PostsInterface;\n",
            'private readonly PostsInterface $posts',
        ));

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertStringContainsString('construction (GET /blog builds it through the container)', (string) $r['verified']);
        self::assertStringContainsString('PostsInterface $posts', (string) file_get_contents($this->controllerFile()));
    }

    /** A concrete class the container can build needs no registration: autowiring is not the defect. */
    public function testAConcreteDependencyIsAutowired(): void
    {
        $r = $this->implement($this->controller(
            "use App\\Plugins\\Blog\\Services\\Posts;\n",
            'private readonly Posts $posts',
        ));

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertStringContainsString('construction (GET /blog', (string) $r['verified']);
    }

    /** A constructor that throws is refused with its own message — the request would have died on it too. */
    public function testAConstructorThatThrowsIsRefusedWithItsMessage(): void
    {
        $content = str_replace(
            "final class BlogController\n{\n",
            "final class BlogController\n{\n    public function __construct()\n    {\n        throw new \\RuntimeException('no posts table yet');\n    }\n\n",
            $this->scaffold(),
        );

        $r = $this->implement($content);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no posts table yet', (string) $r['error']);
        self::assertSame([], $r['diagnostic']['result']['unresolvable'] ?? null);
    }

    /**
     * A class no route names is never built: the house would not build it either, and a judge that
     * constructs what nothing constructs invents the use. It lands, claiming no construction.
     */
    public function testAClassNoRouteNamesIsNotBuilt(): void
    {
        file_put_contents($this->root . '/src/Plugins/Blog/Services/Feed.php', "<?php\n\ndeclare(strict_types=1);\n\n"
            . "namespace App\\Plugins\\Blog\\Services;\n\nfinal class Feed\n{\n}\n");

        $r = $this->implement("<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\Blog\\Services;\n\n"
            . "use Milpa\\Interfaces\\Di\\DIContainerInterface;\n\nfinal class Feed\n{\n"
            . "    public function __construct(private readonly DIContainerInterface \$container)\n    {\n    }\n}\n", 'Feed');

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertStringNotContainsString('construction', (string) $r['verified']);
    }

    /**
     * A house that does not boot is not this judge's to refuse — the boot witness refuses it at the
     * promotion (0512/0515). The landing goes through and SAYS construction went unjudged, and why.
     */
    public function testAHouseThatDoesNotBootLeavesConstructionUnjudgedAndSaysSo(): void
    {
        file_put_contents($this->root . '/config/boot.php', "<?php\n\nthrow new \\RuntimeException('the store is not configured');\n");

        $r = $this->implement($this->controller(
            "use Milpa\\Interfaces\\Di\\DIContainerInterface;\n",
            'private readonly DIContainerInterface $container',
        ));

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertStringContainsString('construction unjudged', (string) $r['verified']);
        self::assertStringContainsString('the store is not configured', (string) $r['verified']);
        self::assertStringNotContainsString($this->root, (string) $r['verified'], 'an absolute path reached the answer');
    }

    /** An app with no autoloader cannot be booted; the answer says so rather than claiming a build. */
    public function testWithoutAnAutoloaderConstructionIsUnjudged(): void
    {
        unlink($this->root . '/vendor/autoload.php');

        $r = $this->implement($this->controller(
            "use Milpa\\Interfaces\\Di\\DIContainerInterface;\n",
            'private readonly DIContainerInterface $container',
        ));

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertStringContainsString('construction unjudged — this app has no vendor/autoload.php', (string) $r['verified']);
    }

    /** A child that answers nothing (here, a PHP binary that cannot start) leaves construction unjudged, and says so. */
    public function testAProbeThatAnswersNothingIsUnjudgedNotBuilt(): void
    {
        $r = (new ImplementHandler(new RootResolver($this->root), construction: new ConstructionProbe('false')))
            ->handle(['plugin' => 'Blog', 'class' => 'BlogController', 'content' => $this->scaffold()]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertStringContainsString('construction unjudged — the house gave no answer when booted (exit 1)', (string) $r['verified']);
    }

    /** `edit` lands through the same gate, so it inherits the judge: an edit that adds the container is refused. */
    public function testAnEditThatAsksForTheContainerIsRefusedToo(): void
    {
        $r = (new ImplementHandler(new RootResolver($this->root)))->edit([
            'plugin' => 'Blog',
            'class' => 'BlogController',
            'edits' => [[
                'find' => "final class BlogController\n{\n",
                'replace' => "final class BlogController\n{\n    public function __construct(private readonly \\Milpa\\Interfaces\\Di\\DIContainerInterface \$container)\n    {\n    }\n\n",
            ]],
        ]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('$container', (string) $r['error']);
        self::assertSame($this->scaffold(), (string) file_get_contents($this->controllerFile()));
    }
}
