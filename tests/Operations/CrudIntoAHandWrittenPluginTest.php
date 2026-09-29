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
use Milpa\DevTools\Support\RootResolver;
use Milpa\Plugin\Contracts\BootWitnessInterface;
use PHPUnit\Framework\TestCase;

/**
 * `make crud` into a plugin a person wrote — no `// {coa:*}` markers — is wired structurally, and a plugin an older
 * scaffold wrote (its five routes there, its writes ungated) is told what is missing instead of «nothing to add»
 * (greenhouse decisions/0459). The merged file is checked by `php -l`, not by reading it.
 */
final class CrudIntoAHandWrittenPluginTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-crud-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o777, true);
        mkdir($this->root . '/src/Plugins/Blog', 0o777, true);
        file_put_contents($this->root . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}');
        file_put_contents($this->root . '/config/plugins.php', "<?php return [];\n");
        $this->root = (string) realpath($this->root);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    private function plugin(string $members): string
    {
        $file = $this->root . '/src/Plugins/Blog/Blog.php';
        file_put_contents($file, "<?php\ndeclare(strict_types=1);\nnamespace App\\Plugins\\Blog;\nfinal class Blog implements \\Milpa\\Interfaces\\Plugin\\PluginInterface\n{\n"
            . "    public function __construct(private readonly \\Milpa\\Interfaces\\Di\\DIContainerInterface \$container) {}\n"
            . $members
            . "    public function install(): void {}\n    public function uninstall(): void {}\n    public function enable(): void {}\n    public function disable(): void {}\n}\n");

        return $file;
    }

    /** @return array<string, mixed> */
    private function crud(): array
    {
        return (new MakeHandler(new RootResolver($this->root), static fn (): ?BootWitnessInterface => null))
            ->handle(['what' => 'crud', 'plugin' => 'Blog', 'name' => 'Post', 'fields' => 'title:string', 'no_verify' => true]);
    }

    private static function lints(string $file): bool
    {
        exec(escapeshellarg(\PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);

        return $code === 0;
    }

    public function testAPluginWithoutMarkersIsWiredStructurally(): void
    {
        $file = $this->plugin("    public function boot(): void\n    {\n        \$this->ready = true;\n    }\n");

        $result = $this->crud();

        self::assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        self::assertStringContainsString('boot(), structurally; routes(), structurally', (string) $result['guidance']);
        $merged = (string) file_get_contents($file);
        self::assertTrue(self::lints($file), 'the merged plugin still parses');
        self::assertStringContainsString("\$this->ready = true;\n        \$storage", $merged, 'what boot() did stays first');
        self::assertStringContainsString('implements \\Milpa\\Interfaces\\Plugin\\PluginInterface, \\Milpa\\Runtime\\Http\\RouteProviderInterface', $merged);
        self::assertSame(3, substr_count($merged, 'middleware: [\\App\\Plugins\\Blog\\Http\\PostWritesGate::class]'), 'the three writes gated');
    }

    public function testAPluginWithoutBootGetsOne(): void
    {
        $file = $this->plugin('');

        $result = $this->crud();

        self::assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        self::assertTrue(self::lints($file));
        self::assertStringContainsString('public function boot(): void', (string) file_get_contents($file));
    }

    public function testAnOlderScaffoldsUngatedWritesAreNamedNotCalledWired(): void
    {
        $routes = '';
        foreach (['index', 'show', 'create', 'update', 'delete'] as $verb) {
            $routes .= "            'posts_{$verb}',\n";
        }
        $this->plugin("    public function boot(): void {}\n    /** @return list<string> */\n    public function routes(): array\n    {\n        return [\n{$routes}        ];\n    }\n");

        $result = $this->crud();

        self::assertStringContainsString(
            'Its write routes (posts_create, posts_update, posts_delete) declare no middleware',
            (string) $result['guidance'],
        );
    }
}
