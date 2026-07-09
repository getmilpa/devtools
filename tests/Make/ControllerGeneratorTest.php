<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\Generators\ControllerGenerator;

final class ControllerGeneratorTest extends TestCase
{
    // Generators only ever compose $root into a returned path STRING (see PlannedFile) — they never
    // read/write it — so a synthetic value is honest here (no real "host app" exists in this suite).
    // Every context below pins 'flavor' => 'legacy' explicitly: this class exercises the LEGACY
    // convention specifically, and since F1 auto-detects the flavor from the (real) filesystem under
    // $root when no override is given, a nonexistent synthetic root would otherwise be genuinely
    // ambiguous and default to Flavor::Runtime (see ConventionDetectorTest) — not what these tests
    // are about.
    private string $root = '/fake/host';

    public function testGeneratesControllerWithRoutedMethods(): void
    {
        $ctx = new GenerationContext(
            plugin: 'MarketingPlugin',
            name: 'PostController',
            options: ['route' => '/posts', 'methods' => 'index,store', 'flavor' => 'legacy'],
            root: $this->root,
        );

        $result = (new ControllerGenerator())->generate($ctx);
        $code = $result->files[0]->contents;

        $this->assertStringEndsWith('/plugins/MarketingPlugin/Controllers/PostController.php', $result->files[0]->path);
        $this->assertStringContainsString('namespace Milpa\\Plugins\\MarketingPlugin\\Controllers;', $code);
        $this->assertStringContainsString('class PostController extends BaseController', $code);
        $this->assertStringContainsString('parent::__construct($container);', $code);
        $this->assertStringContainsString("#[Route(path: '/posts', method: 'GET', name: 'posts_index')]", $code);
        $this->assertStringContainsString('public function index(Request $request, array $params = []): HttpResponse', $code);
        $this->assertStringContainsString("#[Route(path: '/posts', method: 'POST', name: 'posts_store')]", $code);
        $this->assertStringContainsString('return $this->json(', $code);

        $this->assertSame('controller', $result->verifyKind);
        $this->assertSame('Milpa\\Plugins\\MarketingPlugin\\Controllers\\PostController', $result->verifyTarget);
    }

    /**
     * Regression test for F1 (PHPStan L6 `missingType.iterableValue` on `array $params` with no
     * docblock) and F2 (route collision: every method used to get `#[Route(path: {base}, ...)]`
     * with no member-path suffix, so `index,show` produced two GET routes on the same path).
     */
    public function testMemberMethodsGetUniquePathsAndParamsDocblock(): void
    {
        $ctx = new GenerationContext(
            plugin: 'MarketingPlugin',
            name: 'ArticleController',
            options: ['route' => '/articles', 'methods' => 'index,show,store,update,destroy', 'flavor' => 'legacy'],
            root: $this->root,
        );

        $result = (new ControllerGenerator())->generate($ctx);
        $code = $result->files[0]->contents;

        // F1: every routed method carries the framework's `@param array<string, string> $params` docblock.
        $this->assertSame(
            5,
            substr_count($code, '@param array<string, string> $params'),
            'expected one @param docblock per generated method',
        );

        // F2: index/store share the collection path with different verbs; show/update/destroy share
        // a distinct member path (with {id}) with different verbs — no two methods collide on
        // (path, verb).
        $this->assertStringContainsString("#[Route(path: '/articles', method: 'GET', name: 'articles_index')]", $code);
        $this->assertStringContainsString("#[Route(path: '/articles', method: 'POST', name: 'articles_store')]", $code);
        $this->assertStringContainsString("#[Route(path: '/articles/{id}', method: 'GET', name: 'articles_show')]", $code);
        $this->assertStringContainsString("#[Route(path: '/articles/{id}', method: 'PUT', name: 'articles_update')]", $code);
        $this->assertStringContainsString("#[Route(path: '/articles/{id}', method: 'DELETE', name: 'articles_destroy')]", $code);
    }
}
