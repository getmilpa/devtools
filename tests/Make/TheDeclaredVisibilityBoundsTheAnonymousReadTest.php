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

namespace Milpa\DevTools\Tests\Make;

use Milpa\Data\EntityInterface;
use Milpa\Data\PagesResults;
use Milpa\Data\RepositoryInterface;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\Generators\CrudGenerator;
use Milpa\DevTools\Make\PlannedFile;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\Route;
use Milpa\Http\Routing\RouteResult;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A DECLARED visibility bounds what a stranger reads, and the generated controller is EXECUTED to
 * prove it.
 *
 * Measured first (greenhouse `decisions/0460`): the blog recipe declares `published:bool`, and a
 * served app handed an anonymous reader the unpublished draft in the index AND at
 * `GET /posts/2` with a 200. The flag was decoration, and a flag nobody obeys looks exactly like
 * one that is obeyed.
 *
 * The full matrix is here on purpose. Bounding the index while the detail route still answers is
 * the same leak with less noise, and a gate that refuses EVERYBODY would pass any one-sided test.
 *
 * @guards the declared visibility on BOTH read actions, and its absence when nothing was declared
 *
 * @fires  on every `make:crud` / `make:resource` run
 *
 * @refuses a --public-when that names a missing field or a non-boolean one
 *
 * @subject-in milpa/devtools
 */
#[CoversClass(CrudGenerator::class)]
final class TheDeclaredVisibilityBoundsTheAnonymousReadTest extends TestCase
{
    private string $root = '';

    /**
     * The app namespace this run generates into — UNIQUE per test.
     *
     * PHP cannot unload a class and these tests load two different shapes of the same controller,
     * so a shared namespace would silently exercise whichever loaded first. Varying it through
     * `composer.json` rather than rewriting the generated source is the point: the files are then
     * loaded EXACTLY as written, and a missing import fails here instead of in someone's app.
     */
    private string $appNamespace = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-vis-' . bin2hex(random_bytes(6));
        $this->appNamespace = 'VisApp' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents(
            $this->root . '/composer.json',
            (string) json_encode(
                ['autoload' => ['psr-4' => [$this->appNamespace . '\\' => 'src/']]],
                JSON_PRETTY_PRINT,
            ),
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testAStrangerSeesOnlyWhatIsPublicAndARecognisedCallerSeesEverything(): void
    {
        $controller = $this->loadController(publicWhen: 'published');

        // ── A stranger ──────────────────────────────────────────────────────────────────────────
        $listed = $this->decode($controller->index(new ServerRequest('GET', '/posts')));
        self::assertCount(1, $listed['items'], 'the draft is not in a stranger index');
        self::assertSame('Published', $listed['items'][0]['title']);

        self::assertSame(404, $controller->show($this->forId('2'))->getStatusCode(), 'the draft is NOT FOUND for a stranger');
        self::assertSame(200, $controller->show($this->forId('1'))->getStatusCode(), 'and the published one still answers');

        // ── A recognised caller: THE CONTROL ────────────────────────────────────────────────────
        // Without it, a controller that answered nothing to everyone would read as fixed.
        $all = $this->decode($controller->index($this->recognised(new ServerRequest('GET', '/posts'))));
        self::assertCount(2, $all['items'], 'a recognised caller sees the draft too');
        self::assertSame(200, $controller->show($this->recognised($this->forId('2')))->getStatusCode());
    }

    public function testWithNothingDeclaredEveryRowStaysPublicAndTheSeamSaysSo(): void
    {
        // The other control: this slice must not silently close the reads of every existing
        // scaffold. Nothing declared, nothing withheld — and the generated comment says how to
        // declare one instead of leaving a seam that looks like a boundary.
        $controller = $this->loadController(publicWhen: null);

        $listed = $this->decode($controller->index(new ServerRequest('GET', '/posts')));
        self::assertCount(2, $listed['items'], 'no declaration withholds nothing');
        self::assertSame(200, $controller->show($this->forId('2'))->getStatusCode());
    }

    public function testAVisibilityThatNamesAMissingFieldIsRefusedBeforeAnythingIsWritten(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/--public-when=published names a field this artifact does not declare.*title:string, done:bool/s');

        (new CrudGenerator())->generate($this->context('title:string, done:bool', 'published'));
    }

    public function testAVisibilityThatNamesANonBooleanFieldIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/names a string field.*yes or no/s');

        (new CrudGenerator())->generate($this->context('title:string, done:bool', 'title'));
    }

    private function context(string $fields, ?string $publicWhen): GenerationContext
    {
        $options = ['flavor' => 'runtime', 'fields' => $fields];
        if ($publicWhen !== null) {
            $options['public-when'] = $publicWhen;
        }

        return new GenerationContext(plugin: 'BoardPlugin', name: 'Post', options: $options, root: $this->root);
    }

    /**
     * Generates, then loads every planned PHP file VERBATIM and returns the live controller.
     *
     * Nothing is rewritten. An earlier version of this helper patched the namespaces and added the
     * `use` the controller needed — and the generator was not emitting that import, so every read
     * answered 500 in a real app while this test stayed green. A harness that repairs the artifact
     * certifies its own repair.
     */
    private function loadController(?string $publicWhen): object
    {
        $result = (new CrudGenerator())->generate(
            $this->context('title:string, body:text, published:bool', $publicWhen),
        );

        $planned = [];
        foreach ($result->files as $file) {
            \assert($file instanceof PlannedFile);
            $planned[basename($file->path)] = $file;
        }
        foreach (['Post.php', 'PostCaller.php', 'PostController.php'] as $expected) {
            self::assertArrayHasKey($expected, $planned, "the generator plans {$expected}");
        }

        // Written to their real paths and required in dependency order, as the autoloader would.
        foreach (['Post.php', 'PostCaller.php', 'PostController.php'] as $name) {
            $file = $planned[$name];
            \assert($file instanceof PlannedFile);
            if (! is_dir(\dirname($file->path))) {
                mkdir(\dirname($file->path), 0o777, true);
            }
            file_put_contents($file->path, $file->contents);
            require $file->path;
        }

        $entity = $this->appNamespace . '\\Plugins\\BoardPlugin\\Entities\\Post';
        $fqcn = $this->appNamespace . '\\Plugins\\BoardPlugin\\Controllers\\PostController';
        self::assertTrue(class_exists($fqcn), 'the generated controller is loadable PHP');

        return new $fqcn($this->repositoryWith([
            1 => ['id' => 1, 'title' => 'Published', 'body' => 'public', 'published' => true],
            2 => ['id' => 2, 'title' => 'Draft', 'body' => 'not yet', 'published' => false],
        ], $entity));
    }

    /**
     * A repository that pages and applies criteria with `query()`'s own semantics.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param class-string                     $entityClass
     */
    private function repositoryWith(array $rows, string $entityClass): RepositoryInterface
    {
        return new class ($rows, $entityClass) implements RepositoryInterface, PagesResults {
            /** @param array<int, array<string, mixed>> $rows */
            public function __construct(private readonly array $rows, private readonly string $entityClass)
            {
            }

            public function find(int|string $id): ?EntityInterface
            {
                $row = $this->rows[(int) $id] ?? null;

                return $row === null ? null : ($this->entityClass)::fromArray($row);
            }

            public function save(EntityInterface $entity): int|string
            {
                return 1;
            }

            public function delete(int|string $id): void
            {
            }

            public function all(): array
            {
                return array_values(array_map(fn (array $row): EntityInterface => ($this->entityClass)::fromArray($row), $this->rows));
            }

            public function nextId(): int|string
            {
                return 3;
            }

            public function query(array $criteria): array
            {
                $matched = [];
                foreach ($this->rows as $row) {
                    foreach ($criteria as $field => $value) {
                        if (($row[$field] ?? null) !== $value) {
                            continue 2;
                        }
                    }
                    $matched[] = ($this->entityClass)::fromArray($row);
                }

                return $matched;
            }

            public function page(array $criteria, int $limit, int $offset = 0): array
            {
                return \array_slice($this->query($criteria), $offset, $limit);
            }
        };
    }

    private function forId(string $id): ServerRequestInterface
    {
        return (new ServerRequest('GET', '/posts/' . $id))->withAttribute(
            RouteResult::ATTRIBUTE,
            RouteResult::matched(new Route(path: '/posts/{id}', methods: HttpMethod::GET), ['id' => $id]),
        );
    }

    private function recognised(ServerRequestInterface $request): ServerRequestInterface
    {
        $actor = new \stdClass();
        $actor->id = 'rod';
        $auth = new \stdClass();
        $auth->actor = $actor;

        return $request->withAttribute('milpa.auth', $auth);
    }

    /** @return array<string, mixed> */
    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
