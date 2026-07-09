<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Milpa\Data\FileRepository;
use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\Generators\EntityGenerator;
use Milpa\DevTools\Make\PlannedFile;

/**
 * Covers the RUNTIME flavor of {@see EntityGenerator}: the plain `Milpa\Data\EntityInterface` stub
 * it renders, and — the load-bearing part (F3) — that generating an entity wires an actually usable
 * `Milpa\Data\FileRepository` instead of leaving an orphan class with nothing to persist it. Unlike
 * {@see EntityGeneratorTest} (legacy, which only ever composes `$root` into a path STRING),
 * repository-wiring genuinely inspects the filesystem under `$root` to decide "does a plugin already
 * exist here", so these tests use a REAL temp directory — mirroring
 * {@see ControllerGeneratorRuntimeTest} exactly.
 */
final class EntityGeneratorRuntimeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-entity-runtime-' . uniqid();
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

    public function testGeneratesAValidPlainEntityImplementingEntityInterface(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'Article',
            options: ['flavor' => 'runtime', 'fields' => 'title:string:120, ?note:text, published:bool'],
            root: $this->root,
        );

        $result = (new EntityGenerator())->generate($ctx);
        $entity = $this->fileNamed($result->files, 'Article.php');

        $this->assertStringEndsWith('/src/Plugins/BlogPlugin/Entities/Article.php', $entity->path);

        $code = $entity->contents;
        $this->assertStringContainsString('namespace App\\Plugins\\BlogPlugin\\Entities;', $code);
        $this->assertStringContainsString('use Milpa\\Data\\EntityInterface;', $code);
        $this->assertStringContainsString('final readonly class Article implements EntityInterface', $code);
        $this->assertStringContainsString('public int|string|null $id,', $code);
        $this->assertStringContainsString('public string $title,', $code);
        $this->assertStringContainsString('public ?string $note,', $code);
        $this->assertStringContainsString('public bool $published,', $code);
        $this->assertStringContainsString('public function id(): int|string|null', $code);
        $this->assertStringContainsString('public function toArray(): array', $code);
        $this->assertStringContainsString('public static function fromArray(array $row): static', $code);
        $this->assertStringNotContainsString('ORM\\', $code);
        $this->assertStringNotContainsString('Doctrine', $code);
        $this->assertStringNotContainsString('UuidGenerator', $code);

        $this->assertPhpLints($code);

        $this->assertSame(Flavor::Runtime, $result->flavor);
        $this->assertSame('entity', $result->verifyKind);
        $this->assertSame('App\\Plugins\\BlogPlugin\\Entities\\Article', $result->verifyTarget);
    }

    public function testNoExistingPluginGeneratesABootingRepositoryPluginToo(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'Article',
            options: ['flavor' => 'runtime', 'fields' => 'title:string'],
            root: $this->root,
        );

        $result = (new EntityGenerator())->generate($ctx);

        $this->assertCount(2, $result->files, 'expected entity + plugin when no plugin exists yet');
        $plugin = $this->fileNamed($result->files, 'BlogPlugin.php');

        $this->assertStringEndsWith('/src/Plugins/BlogPlugin/BlogPlugin.php', $plugin->path);

        $code = $plugin->contents;
        $this->assertStringContainsString('namespace App\\Plugins\\BlogPlugin;', $code);
        $this->assertStringContainsString('use App\\Plugins\\BlogPlugin\\Entities\\Article;', $code);
        $this->assertStringContainsString('use Milpa\\Data\\FileRepository;', $code);
        $this->assertStringContainsString('use Milpa\\Runtime\\Support\\RootResolver;', $code);
        $this->assertStringContainsString('implements PluginInterface', $code);
        $this->assertStringNotContainsString('RouteProviderInterface', $code);
        $this->assertStringContainsString("Article::class . 'Repository'", $code);
        $this->assertStringContainsString("new FileRepository((new RootResolver())->resolve() . '/var/articles.json', Article::class)", $code);

        $this->assertPhpLints($code);

        $this->assertNotNull($result->guidance);
        $this->assertStringContainsString('config/plugins.php', (string) $result->guidance);
        $this->assertStringContainsString('App\\Plugins\\BlogPlugin\\BlogPlugin::class', (string) $result->guidance);
    }

    public function testExistingPluginIsNotEditedAndGetsARegistrationSnippetInGuidanceInstead(): void
    {
        $pluginDir = $this->root . '/src/Plugins/BlogPlugin';
        mkdir($pluginDir, 0o775, true);
        $existing = "<?php\n// hand-written plugin — must not be touched\n";
        file_put_contents($pluginDir . '/BlogPlugin.php', $existing);

        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'Article',
            options: ['flavor' => 'runtime', 'fields' => 'title:string'],
            root: $this->root,
        );

        $result = (new EntityGenerator())->generate($ctx);

        $this->assertCount(1, $result->files, 'the existing plugin file must not be (re)written');
        $this->assertSame('Article.php', basename($result->files[0]->path));
        $this->assertSame($existing, file_get_contents($pluginDir . '/BlogPlugin.php'), 'existing plugin file must be untouched on disk');

        $this->assertNotNull($result->guidance);
        $guidance = (string) $result->guidance;
        $this->assertStringContainsString('already exists', $guidance);
        $this->assertStringContainsString('registerService(', $guidance);
        $this->assertStringContainsString("Article::class . 'Repository'", $guidance);
        $this->assertStringContainsString('use App\\Plugins\\BlogPlugin\\Entities\\Article;', $guidance);
    }

    public function testDefaultTableIsDerivedFromTheEntityName(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'Article',
            options: ['flavor' => 'runtime'],
            root: $this->root,
        );

        $result = (new EntityGenerator())->generate($ctx);
        $plugin = $this->fileNamed($result->files, 'BlogPlugin.php');

        $this->assertStringContainsString("/var/articles.json'", $plugin->contents);
    }

    public function testRejectsReservedIdFieldName(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'Article',
            options: ['flavor' => 'runtime', 'fields' => 'id:int'],
            root: $this->root,
        );

        $this->expectException(\InvalidArgumentException::class);
        (new EntityGenerator())->generate($ctx);
    }

    /**
     * `milpa/data` has no relation concept (see the F1 report's Fricciones) — a `belongsTo` field
     * cannot be expressed by a flat `toArray()`/`fromArray()` round trip the way it can as a Doctrine
     * `#[ORM\ManyToOne]`, so the runtime path rejects it with an actionable message instead of
     * emitting code that doesn't compile or silently dropping the relation.
     */
    public function testRejectsBelongsToFieldsWithAnActionableMessage(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'Article',
            options: ['flavor' => 'runtime', 'fields' => 'author:belongsTo:Author'],
            root: $this->root,
        );

        try {
            (new EntityGenerator())->generate($ctx);
            $this->fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('belongsTo', $e->getMessage());
            $this->assertStringContainsString('--flavor=legacy', $e->getMessage());
        }
    }

    /** `uuid` is NOT reserved for the runtime stub — only legacy's Doctrine stub has a built-in `$uuid`. */
    public function testUuidIsNotAReservedFieldNameForRuntime(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'Article',
            options: ['flavor' => 'runtime', 'fields' => 'uuid:string'],
            root: $this->root,
        );

        $result = (new EntityGenerator())->generate($ctx);

        $this->assertStringContainsString('public string $uuid,', $result->files[0]->contents);
    }

    public function testEnumAndDateTimeFieldsRenderConvertingToArrayAndFromArray(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'Article',
            options: ['flavor' => 'runtime', 'fields' => 'status:enum:ArticleStatus, ?publishedAt:datetime'],
            root: $this->root,
        );

        $result = (new EntityGenerator())->generate($ctx);
        $code = $result->files[0]->contents;

        $this->assertStringContainsString('use App\\Plugins\\BlogPlugin\\Enums\\ArticleStatus;', $code);
        $this->assertStringContainsString('public ArticleStatus $status,', $code);
        $this->assertStringContainsString("'status' => \$this->status->value,", $code);
        $this->assertStringContainsString("ArticleStatus::from(\$row['status']),", $code);

        $this->assertStringContainsString('use DateTime;', $code);
        $this->assertStringContainsString('public ?DateTime $publishedAt,', $code);
        $this->assertStringContainsString("'publishedAt' => \$this->publishedAt?->format(DATE_ATOM),", $code);
        $this->assertStringContainsString(
            "\$row['publishedAt'] !== null ? new DateTime(\$row['publishedAt']) : null,",
            $code,
        );

        $this->assertPhpLints($code);
    }

    /**
     * The ENTITY path (runtime flavor) must never touch `doctrine/orm` — run in a fresh process so
     * "never loaded" is a real, order-independent claim, mirroring
     * {@see ControllerGeneratorRuntimeTest::testControllerGenerationNeverLoadsDoctrine()}.
     */
    #[RunInSeparateProcess]
    public function testEntityGenerationNeverLoadsDoctrine(): void
    {
        $this->assertFalse(
            class_exists('Doctrine\\ORM\\Mapping\\Entity', false),
            'precondition: Doctrine must not already be loaded in this fresh process',
        );

        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'Article',
            options: ['flavor' => 'runtime', 'fields' => 'title:string'],
            root: $this->root,
        );
        (new EntityGenerator())->generate($ctx);

        $this->assertFalse(
            class_exists('Doctrine\\ORM\\Mapping\\Entity', false),
            'EntityGenerator runtime path must never trigger autoloading of a Doctrine class',
        );
    }

    /**
     * The load-bearing proof (F3 GOAL): the generated entity class actually round-trips
     * (construct -> toArray() -> fromArray()) AND actually persists through a real
     * `Milpa\Data\FileRepository` — save() assigns an id, and a FRESH repository instance pointed at
     * the same file rereads exactly what was saved. This is what "make:entity generates something
     * that persists" means in practice, not just a shape assertion on the generated source text.
     *
     * Run in a separate process: this test `require`s the generated file, which declares a real,
     * fixed-FQCN class (`App\Plugins\BlogPlugin\Entities\Article`) — isolating it avoids any
     * "class already declared" risk if the suite ever re-invokes this method in the same process.
     */
    #[RunInSeparateProcess]
    public function testGeneratedEntityRoundTripsAndPersistsThroughAFileRepository(): void
    {
        $ctx = new GenerationContext(
            plugin: 'BlogPlugin',
            name: 'Article',
            options: ['flavor' => 'runtime', 'fields' => 'title:string, views:int, published:bool, tags:json'],
            root: $this->root,
        );

        $result = (new EntityGenerator())->generate($ctx);
        $entityFile = $this->fileNamed($result->files, 'Article.php');

        mkdir(\dirname($entityFile->path), 0o775, true);
        file_put_contents($entityFile->path, $entityFile->contents);
        require $entityFile->path;

        $fqcn = $result->verifyTarget;
        $this->assertTrue(class_exists($fqcn, false));

        // construct -> toArray() -> fromArray() round trip, no repository involved yet.
        $article = new $fqcn(id: null, title: 'Hello', views: 0, published: false, tags: ['a', 'b']);
        $row = $article->toArray();
        $this->assertSame(['id' => null, 'title' => 'Hello', 'views' => 0, 'published' => false, 'tags' => ['a', 'b']], $row);

        $rehydrated = $fqcn::fromArray($row);
        $this->assertSame($row, $rehydrated->toArray());
        $this->assertNull($rehydrated->id());

        // save() through a real FileRepository assigns an id and persists to disk.
        $dataFile = $this->root . '/var/articles.json';
        $repo = new FileRepository($dataFile, $fqcn);
        $id = $repo->save(new $fqcn(id: null, title: 'Persisted', views: 3, published: true, tags: []));

        $this->assertFileExists($dataFile);

        $found = $repo->find($id);
        $this->assertInstanceOf($fqcn, $found);
        $this->assertSame($id, $found->id());
        $this->assertSame('Persisted', $found->title);

        // A FRESH repository instance over the same file rereads exactly what was persisted —
        // durability, not an in-memory artifact of the same $repo object.
        $reread = (new FileRepository($dataFile, $fqcn))->find($id);
        $this->assertInstanceOf($fqcn, $reread);
        $this->assertSame('Persisted', $reread->title);
        $this->assertSame(3, $reread->views);
        $this->assertTrue($reread->published);
    }

    /** @param list<PlannedFile> $files */
    private function fileNamed(array $files, string $basename): PlannedFile
    {
        foreach ($files as $file) {
            if (basename($file->path) === $basename) {
                return $file;
            }
        }

        $this->fail("no planned file named {$basename} among: " . implode(', ', array_map(
            static fn (PlannedFile $f): string => basename($f->path),
            $files,
        )));
    }

    private function assertPhpLints(string $code): void
    {
        $tmp = $this->root . '/lint-' . uniqid() . '.php';
        file_put_contents($tmp, $code);
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $exitCode);
        unlink($tmp);

        $this->assertSame(0, $exitCode, "php -l failed:\n" . implode("\n", $output));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
