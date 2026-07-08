<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Make\StubRenderer;

final class StubRendererTest extends TestCase
{
    private string $stub;

    protected function setUp(): void
    {
        $this->stub = sys_get_temp_dir() . '/milpa-stub-' . uniqid() . '.stub';
        file_put_contents($this->stub, "class {{class}} extends {{base}} {}\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->stub);
    }

    public function testReplacesPlaceholders(): void
    {
        $out = (new StubRenderer())->render($this->stub, ['class' => 'Post', 'base' => 'BaseController']);

        $this->assertSame("class Post extends BaseController {}\n", $out);
    }

    public function testUnreplacedPlaceholderThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unreplaced placeholder {{base}}');
        (new StubRenderer())->render($this->stub, ['class' => 'Post']);
    }
}
