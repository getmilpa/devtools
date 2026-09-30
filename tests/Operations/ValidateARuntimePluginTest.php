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

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Operations\MakeHandler;
use Milpa\DevTools\Operations\ValidateHandler;
use Milpa\DevTools\Support\RootResolver;
use Milpa\Plugin\Contracts\BootWitnessInterface;
use PHPUnit\Framework\TestCase;

/**
 * `validate` on a runtime-convention plugin — the one `create-project` makes, whose truth is its `#[PluginMetadata]`.
 *
 * The plugins are real classes on disk under a namespace of their own, loaded through that house's composer.json the
 * way the handler finds them; the good one is the one `make plugin` writes.
 */
final class ValidateARuntimePluginTest extends TestCase
{
    private string $root;

    private string $namespace;

    protected function setUp(): void
    {
        $id = bin2hex(random_bytes(4));
        $this->namespace = 'LabValidate' . $id;
        $this->root = sys_get_temp_dir() . '/milpa-devtools-validate-' . $id;
        mkdir($this->root . '/src/Plugins', 0o777, true);
        file_put_contents($this->root . '/composer.json', (string) json_encode(['autoload' => ['psr-4' => [$this->namespace . '\\' => 'src/']]]));
        $root = $this->root;
        $namespace = $this->namespace;
        spl_autoload_register(static function (string $class) use ($root, $namespace): void {
            $file = $root . '/src/' . str_replace('\\', '/', substr($class, \strlen($namespace) + 1)) . '.php';
            if (str_starts_with($class, $namespace . '\\') && is_file($file)) {
                require $file;
            }
        });
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    private function plugin(string $name, string $body): void
    {
        mkdir($this->root . '/src/Plugins/' . $name, 0o777, true);
        file_put_contents($this->root . "/src/Plugins/{$name}/{$name}.php", "<?php\nnamespace {$this->namespace}\\Plugins\\{$name};\n{$body}\n");
    }

    /** @return array<string, mixed> */
    private function validate(string $name): array
    {
        return (new ValidateHandler(new RootResolver($this->root)))->handle(['target' => $name]);
    }

    public function testThePluginMakeWritesIsValid(): void
    {
        $made = (new MakeHandler(new RootResolver($this->root), static fn (): ?BootWitnessInterface => null))
            ->handle(['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog', 'no_verify' => true]);
        self::assertTrue($made['ok'], (string) ($made['error'] ?? ''));

        $result = $this->validate('Blog');

        self::assertTrue($result['ok'], json_encode($result) ?: '');
        self::assertSame('runtime', $result['convention']);
        self::assertSame(['attribute' => ['ok' => true, 'findings' => []]], $result['checks']);
    }

    public function testWhatTheAttributeGetsWrongIsNamed(): void
    {
        $this->plugin('Loose', '#[\Milpa\Attributes\PluginMetadata(version: "one", author: "t", site: "https://example.com", name: " ", type: "Service")]
final class Loose {}');

        $result = $this->validate('Loose');

        self::assertFalse($result['ok']);
        self::assertSame([
            'the attribute declares no `name`',
            '`version` is not semver: «one»',
            "«{$this->namespace}\\Plugins\\Loose\\Loose» does not implement PluginInterface",
        ], $result['checks']['attribute']['findings']);
    }

    public function testAClassWithoutTheAttributeCannotBeBooted(): void
    {
        $this->plugin('Bare', 'final class Bare {}');

        $result = $this->validate('Bare');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('declares no `#[PluginMetadata]`', (string) $result['error']);
    }

    public function testAFileWhoseClassDoesNotLoadSaysSo(): void
    {
        $this->plugin('Misnamed', 'final class SomethingElse {}');

        $result = $this->validate('Misnamed');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('exists on disk and cannot be loaded', (string) $result['error']);
    }

    public function testAPluginThatIsNowhereIsAnswerNotAnException(): void
    {
        $result = $this->validate('Ghost');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('plugin «Ghost» not found', (string) $result['error']);
    }

    public function testNoTargetIsAskedFor(): void
    {
        self::assertStringContainsString('missing `target`', (string) $this->validate('')['error']);
    }
}
