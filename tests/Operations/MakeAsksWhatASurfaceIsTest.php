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

use Milpa\DevTools\Operations\DevToolsOperations;
use Milpa\DevTools\Operations\MakeHandler;
use Milpa\DevTools\Support\RootResolver;
use Milpa\Plugin\Contracts\BootWitnessInterface;
use PHPUnit\Framework\TestCase;

/**
 * `make` ASKS WHAT A SURFACE IS, AND HANDS OVER THE GOVERNED WAY TO SERVE IT (greenhouse decisions/0567 §2–§3,
 * slice BV-2).
 *
 * Measured (greenhouse evidence/1101): the last thing a resident read before choosing «a controller that
 * concatenates HTML» was the contract of `make`, whose `what` had no word for a page. The cheap path — scaffold
 * a controller — never asked what the route returns.
 *
 * `make` cannot declare a screen: the screen store lives in the app runtime, which this package does not know.
 * So it scaffolds what is its own, says what the surface is in fields, and answers with the exact next call —
 * `screen:declare … route=…` — the way a trial answers with `to_apply`.
 */
final class MakeAsksWhatASurfaceIsTest extends TestCase
{
    private string $root;

    /** @var array<string, string> route → screen, what the house says is mounted */
    private array $mounted = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-make-surface-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o777, true);
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents($this->root . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}');
        file_put_contents($this->root . '/config/plugins.php', "<?php return [App\\Plugins\\Blog\\Blog::class];\n");
        $this->root = (string) realpath($this->root);
        self::assertTrue($this->make(['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog'])['ok']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAPageScaffoldsItsPublicEntityAndAnswersWithTheDeclarationToRun(): void
    {
        $made = $this->make([
            'what' => 'page', 'plugin' => 'Blog', 'name' => 'blog', 'route' => '/blog',
            'entity' => 'Post', 'fields' => 'title:string, body:text, published:bool', 'public_when' => 'published',
        ]);

        self::assertTrue($made['ok'], json_encode($made) ?: '');
        $entity = (string) file_get_contents($this->root . '/src/Plugins/Blog/Entities/Post.php');
        self::assertStringContainsString("public const PUBLIC_WHEN = 'published';", $entity);
        self::assertContains($this->root . '/src/Plugins/Blog/Entities/Post.php', array_column($made['files'], 'path'));
        self::assertFileDoesNotExist($this->root . '/src/Plugins/Blog/Controllers', 'a page is not a controller: nothing is written for one');

        self::assertSame([
            'kind' => 'visual',
            'route' => 'GET /blog',
            'preferred_authoring' => 'screen',
            'screen' => 'blog',
            'declared' => false,
        ], $made['surface']);
        self::assertSame([
            'operation' => 'screen:declare',
            'arguments' => [
                'name' => 'blog',
                'type' => 'content',
                'route' => '/blog',
                'props' => ['heading' => 'Blog', 'roles' => ['title' => 'title', 'body' => 'body']],
                'source' => ['entity' => 'Blog/Post', 'columns' => ['title', 'body']],
            ],
        ], $made['next'], 'the bool that decides visibility is not a column of the page');
        self::assertStringContainsString('NOT served yet', $made['guidance'], 'the house reads «guidance» as the real next step');
        self::assertStringContainsString('screen:declare', $made['guidance']);
        self::assertStringContainsString('Once what this call scaffolded has landed', $made['guidance'], 'in a trial the entity is promoted first: a screen cannot bind to an entity the house does not have yet');
    }

    public function testTheColumnsAPageIsGivenAreTheOnesItShowsInThatOrder(): void
    {
        $made = $this->make([
            'what' => 'page', 'plugin' => 'Blog', 'name' => 'blog', 'route' => '/blog', 'columns' => 'summary, title',
            'entity' => 'Post', 'fields' => 'title:string, body:text, summary:string, published:bool', 'public_when' => 'published',
        ]);

        self::assertTrue($made['ok'], json_encode($made) ?: '');
        self::assertSame(['summary', 'title'], $made['next']['arguments']['source']['columns'], 'not every field: the ones named, as named');
        self::assertSame(['title' => 'summary', 'body' => 'title'], $made['next']['arguments']['props']['roles']);
    }

    public function testAPageOverAnEntityTheHouseAlreadyHasWritesNothing(): void
    {
        $this->make(['what' => 'entity', 'plugin' => 'Blog', 'name' => 'Post', 'fields' => 'title:string, body:text, author:string, published:bool', 'public_when' => 'published']);
        $before = $this->tree();

        $made = $this->make(['what' => 'page', 'plugin' => 'Blog', 'name' => 'blog', 'route' => 'blog/', 'entity' => 'Post', 'columns' => 'title, body, author']);

        self::assertTrue($made['ok'], json_encode($made) ?: '');
        self::assertSame([], $made['files']);
        self::assertSame($before, $this->tree(), 'nothing is written');
        self::assertSame('GET /blog', $made['surface']['route'], 'the route is written as HTTP writes it');
        self::assertSame('/blog', $made['next']['arguments']['route']);
        self::assertSame(['title' => 'title', 'body' => 'body', 'meta' => ['author']], $made['next']['arguments']['props']['roles'], 'the first column is the title, the second the body, the rest are named');
        self::assertSame(['title', 'body', 'author'], $made['next']['arguments']['source']['columns']);
    }

    public function testAPageOfOneColumnIsATable(): void
    {
        $this->make(['what' => 'entity', 'plugin' => 'Blog', 'name' => 'Post', 'fields' => 'title:string, published:bool', 'public_when' => 'published']);

        $made = $this->make(['what' => 'page', 'plugin' => 'Blog', 'name' => 'titles', 'route' => '/titles', 'entity' => 'Post', 'columns' => 'title']);

        self::assertTrue($made['ok'], json_encode($made) ?: '');
        self::assertSame(['name' => 'titles', 'type' => 'data-table', 'route' => '/titles', 'source' => ['entity' => 'Blog/Post', 'columns' => ['title']]], $made['next']['arguments'], 'a readable entry needs a title AND a body: one column is a list');
    }

    public function testAPlannedPageAnswersTheSameNextAndWritesNothing(): void
    {
        $before = $this->tree();

        $made = $this->make([
            'what' => 'page', 'plugin' => 'Blog', 'name' => 'blog', 'route' => '/blog', 'dry_run' => true,
            'entity' => 'Post', 'fields' => 'title:string, body:text, published:bool', 'public_when' => 'published',
        ]);

        self::assertTrue($made['ok'], json_encode($made) ?: '');
        self::assertSame($before, $this->tree());
        self::assertSame('would-create', $made['files'][0]['action']);
        self::assertSame('screen:declare', $made['next']['operation']);
    }

    public function testWhatAPageCannotBeBuiltFromIsRefusedByNameAndWritesNothing(): void
    {
        $this->make(['what' => 'entity', 'plugin' => 'Blog', 'name' => 'Note', 'fields' => 'text:string, body:text']);
        $before = $this->tree();
        $page = ['what' => 'page', 'plugin' => 'Blog', 'name' => 'blog', 'route' => '/blog', 'entity' => 'Post'];

        foreach ([
            'needs a route' => array_diff_key($page, ['route' => 0]) + ['fields' => 'title:string, body:text, published:bool', 'public_when' => 'published'],
            'not a URL' => ['route' => 'https://x/blog'] + $page + ['columns' => 'title'],
            'no parameters' => ['route' => '/blog/{id}'] + $page,
            'needs «entity»' => array_diff_key($page, ['entity' => 0]),
            'lowercase' => ['name' => 'Blog_Page'] + $page,
            'has no entity «Post»' => $page + ['columns' => 'title, body'],
            'declares no PUBLIC_WHEN' => ['entity' => 'Note', 'columns' => 'text, body'] + $page,
            'needs «columns»' => ['entity' => 'Note'] + $page,
            'needs «public_when»' => $page + ['fields' => 'title:string, body:text, published:bool'],
            '«draft» is not a bool field' => $page + ['fields' => 'title:string, body:text, published:bool', 'public_when' => 'draft'],
            '«title» is not a bool field' => $page + ['fields' => 'title:string, body:text, published:bool', 'public_when' => 'title'],
            'write it with letters' => ['route' => '/the blog'] + $page + ['columns' => 'title'],
            'no field to show besides its bools' => $page + ['fields' => 'published:bool, pinned:bool', 'public_when' => 'published'],
            'unknown field type' => $page + ['fields' => 'title:nonsense, published:bool', 'public_when' => 'published'],
                        '«summary» is not a field' => $page + ['fields' => 'title:string, body:text, published:bool', 'public_when' => 'published', 'columns' => 'title, summary'],
        ] as $why => $input) {
            $refused = $this->make($input);
            self::assertFalse($refused['ok'], $why);
            self::assertStringContainsString($why, (string) $refused['error'], $why);
            self::assertArrayNotHasKey('next', $refused, $why);
            self::assertSame($before, $this->tree(), "«{$why}» wrote something");
        }
    }

    public function testAPageDoesNotOverwriteAnEntityThatIsAlreadyThere(): void
    {
        $this->make(['what' => 'entity', 'plugin' => 'Blog', 'name' => 'Post', 'fields' => 'title:string, body:text']);
        $before = $this->tree();

        $refused = $this->make(['what' => 'page', 'plugin' => 'Blog', 'name' => 'blog', 'route' => '/blog', 'entity' => 'Post', 'fields' => 'title:string, body:text, published:bool', 'public_when' => 'published']);

        self::assertFalse($refused['ok'], 'the entity\'s own refusal is the page\'s');
        self::assertArrayNotHasKey('next', $refused, 'and no declaration is handed over for an entity that did not land');
        self::assertSame($before, $this->tree());

        $forced = $this->make(['what' => 'page', 'plugin' => 'Blog', 'name' => 'blog', 'route' => '/blog', 'entity' => 'Post', 'fields' => 'title:string, body:text, published:bool', 'public_when' => 'published', 'force' => true]);
        self::assertTrue($forced['ok'], json_encode($forced) ?: '');
        self::assertStringContainsString('PUBLIC_WHEN', (string) file_get_contents($this->root . '/src/Plugins/Blog/Entities/Post.php'));
    }

    public function testAControllerThatServesAGetMustSayWhatItReturns(): void
    {
        $before = $this->tree();

        $asked = $this->make(['what' => 'controller', 'plugin' => 'Blog', 'name' => 'PostController', 'route' => 'blog']);

        self::assertFalse($asked['ok']);
        self::assertSame($before, $this->tree(), 'nothing is scaffolded until the surface is said');
        self::assertSame(['kind' => 'undeclared', 'route' => 'GET /blog', 'ask' => 'returns', 'options' => ['page', 'data']], $asked['surface']);
        self::assertStringContainsString('returns=page', (string) $asked['error']);
        self::assertStringContainsString('returns=data', (string) $asked['error']);

        $bogus = $this->make(['what' => 'controller', 'plugin' => 'Blog', 'name' => 'PostController', 'route' => 'blog', 'returns' => 'html']);
        self::assertFalse($bogus['ok']);
        self::assertStringContainsString('«returns» is page or data', (string) $bogus['error']);
        self::assertSame($before, $this->tree());
    }

    public function testAControllerThatReturnsDataIsScaffoldedAsBefore(): void
    {
        $made = $this->make(['what' => 'controller', 'plugin' => 'Blog', 'name' => 'PostController', 'route' => '/api/posts', 'returns' => 'data']);

        self::assertTrue($made['ok'], json_encode($made) ?: '');
        self::assertFileExists($this->root . '/src/Plugins/Blog/Controllers/PostController.php');
        self::assertSame(['kind' => 'data', 'route' => 'GET /api/posts'], $made['surface']);
        self::assertArrayNotHasKey('next', $made);
    }

    public function testAControllerThatReturnsAPageIsNotAControllerItIsThePage(): void
    {
        $this->make(['what' => 'entity', 'plugin' => 'Blog', 'name' => 'Post', 'fields' => 'title:string, body:text, published:bool', 'public_when' => 'published']);

        $made = $this->make(['what' => 'controller', 'plugin' => 'Blog', 'name' => 'BlogController', 'route' => '/blog', 'returns' => 'page', 'entity' => 'Post', 'columns' => 'title, body']);

        self::assertTrue($made['ok'], json_encode($made) ?: '');
        self::assertFileDoesNotExist($this->root . '/src/Plugins/Blog/Controllers/BlogController.php', 'no controller is written for a page');
        self::assertSame('visual', $made['surface']['kind']);
        self::assertSame('blog', $made['next']['arguments']['name'], 'the screen is named after the controller it would have been');
        self::assertSame('/blog', $made['next']['arguments']['route']);

        $bare = $this->make(['what' => 'controller', 'plugin' => 'Blog', 'name' => 'BlogController', 'route' => '/blog', 'returns' => 'page']);
        self::assertFalse($bare['ok']);
        self::assertStringContainsString('needs «entity»', (string) $bare['error'], 'what a page needs is asked the same way');
    }

    public function testALegacyControllerIsNotAsked(): void
    {
        mkdir($this->root . '/plugins/Old', 0o777, true);

        $made = $this->make(['what' => 'controller', 'plugin' => 'Old', 'name' => 'ThingController', 'flavor' => 'legacy', 'no_verify' => true]);

        self::assertTrue($made['ok'], json_encode($made) ?: '');
        self::assertArrayNotHasKey('surface', $made, 'the legacy convention routes by method; this question is the runtime house\'s');
    }

    public function testNothingIsScaffoldedOnARouteWhereAScreenIsMounted(): void
    {
        $this->mounted = ['/blog' => 'blog'];
        $before = $this->tree();

        foreach ([
            ['what' => 'controller', 'plugin' => 'Blog', 'name' => 'PostController', 'route' => 'Blog/', 'returns' => 'data'],
            ['what' => 'crud', 'plugin' => 'Blog', 'name' => 'Post', 'route' => '/blog', 'fields' => 'title:string'],
            ['what' => 'crud', 'plugin' => 'Blog', 'name' => 'Blog', 'table' => 'blog', 'fields' => 'title:string'],
            ['what' => 'page', 'plugin' => 'Blog', 'name' => 'news', 'route' => '/blog', 'entity' => 'Post', 'fields' => 'title:string, body:text, published:bool', 'public_when' => 'published'],
        ] as $input) {
            $refused = $this->make($input);
            self::assertFalse($refused['ok'], json_encode($input) ?: '');
            self::assertStringContainsString('GET /blog is where the screen «blog» is mounted', (string) $refused['error']);
            self::assertSame($before, $this->tree());
        }

        $own = $this->make(['what' => 'page', 'plugin' => 'Blog', 'name' => 'blog', 'route' => '/blog', 'entity' => 'Post', 'fields' => 'title:string, body:text, published:bool', 'public_when' => 'published']);
        self::assertTrue($own['ok'], 'the screen that is mounted there may be made again: ' . (json_encode($own) ?: ''));
        self::assertTrue($own['surface']['declared'], 'and the answer says it is already declared');

        $elsewhere = $this->make(['what' => 'controller', 'plugin' => 'Blog', 'name' => 'FeedController', 'route' => '/feed', 'returns' => 'data']);
        self::assertTrue($elsewhere['ok'], json_encode($elsewhere) ?: '');
    }

    public function testACrudIsServedAtTheRouteItIsGiven(): void
    {
        $made = $this->make(['what' => 'crud', 'plugin' => 'Shop', 'name' => 'Item', 'route' => 'catalogue/', 'fields' => 'title:string']);

        self::assertTrue($made['ok'], json_encode($made) ?: '');
        $plugin = (string) file_get_contents($this->root . '/src/Plugins/Shop/Shop.php');
        self::assertSame(2, substr_count($plugin, "path: '/catalogue',"), 'index and create');
        self::assertSame(3, substr_count($plugin, "path: '/catalogue/{id}',"), 'show, update and delete');
        self::assertStringNotContainsString("'/items", $plugin, 'the table no longer decides the route when one is given');
        self::assertStringContainsString("name: 'items_index'", $plugin, 'route names stay the table\'s: they are what the postconditions and the wiring look for');
        self::assertStringContainsString('/var/items.json', $plugin, 'and the table still names the store');

        $merged = $this->make(['what' => 'crud', 'plugin' => 'Blog', 'name' => 'Post', 'route' => '/journal', 'fields' => 'title:string']);
        self::assertTrue($merged['ok'], json_encode($merged) ?: '');
        $blog = (string) file_get_contents($this->root . '/src/Plugins/Blog/Blog.php');
        self::assertStringContainsString("path: '/journal'", $blog, 'into a plugin that already exists, too');
        self::assertStringNotContainsString("'/posts", $blog);

        $default = $this->make(['what' => 'crud', 'plugin' => 'Wiki', 'name' => 'Entry', 'fields' => 'title:string']);
        self::assertTrue($default['ok']);
        self::assertStringContainsString("path: '/entrys'", (string) file_get_contents($this->root . '/src/Plugins/Wiki/Wiki.php'), 'without a route, as before');

        $bad = $this->make(['what' => 'crud', 'plugin' => 'Bad', 'name' => 'Thing', 'route' => '/things/{id}', 'fields' => 'title:string']);
        self::assertFalse($bad['ok']);
        self::assertStringContainsString('no parameters', (string) $bad['error']);
    }

    public function testTheContractOfMakeSaysAllOfIt(): void
    {
        $make = null;
        foreach ((new DevToolsOperations())->operations() as $operation) {
            if ($operation->name === 'make') {
                $make = $operation;
            }
        }
        self::assertNotNull($make);
        $properties = $make->inputSchema['properties'];

        self::assertContains('page', $properties['what']['enum']);
        self::assertStringContainsString('page', $make->description);
        self::assertSame(['page', 'data'], $properties['returns']['enum']);
        self::assertStringContainsString('controller', $properties['returns']['description']);
        self::assertArrayHasKey('entity', $properties);
        self::assertArrayHasKey('columns', $properties);
        self::assertStringContainsString('page', $properties['route']['description']);
        self::assertStringContainsString('entity, crud', $properties['public_when']['description'], 'make entity always honoured public_when; its contract said only crud and resource');
        self::assertStringContainsString('screen', $properties['public_when']['description']);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function make(array $input): array
    {
        return (new MakeHandler(
            new RootResolver($this->root),
            static fn (): ?BootWitnessInterface => null,
            fn (string $root): array => $this->mounted,
        ))->handle($input + ['no_verify' => true]);
    }

    /** @return array<string, string> every file of the house, by content */
    private function tree(): array
    {
        $tree = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $tree[substr($file->getPathname(), \strlen($this->root))] = hash_file('sha256', $file->getPathname()) ?: '';
        }
        ksort($tree);

        return $tree;
    }
}
