<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Operations\ImplementHandler;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Changes to staging must neither silently overwrite stale work nor certify executable code. */
final class StagingAmendmentTest extends TestCase
{
    private string $root;
    private string $file;
    private string $staging;
    private ImplementHandler $handler;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-amend-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src/Plugins/Demo/Services', 0o775, true);
        $this->file = $this->root . '/src/Plugins/Demo/Services/Demo.php';
        $this->staging = $this->file . ImplementHandler::STAGING_SUFFIX;
        file_put_contents($this->file, '<?php // active scaffold');
        file_put_contents($this->staging, 'first second second');
        $this->handler = new ImplementHandler(new RootResolver($this->root));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return array<string, mixed> */
    private function arguments(): array
    {
        return ['plugin' => 'Demo', 'class' => 'Demo', 'mode' => 'amend',
            'expected_sha256' => hash('sha256', 'first second second'),
            'edits' => [['find' => 'first', 'replace' => 'fixed']]];
    }

    /** Ordered replacements work on large, even invalid, assemblies without claiming a judgment. */
    public function testAmendmentIsPartialAndBoundToTheCurrentStagingBytes(): void
    {
        $body = str_repeat('padding ', 2048) . 'unique';
        file_put_contents($this->staging, $body);
        chmod($this->staging, 0o640);
        $args = $this->arguments();
        $args['expected_sha256'] = hash('sha256', $body);
        $args['edits'] = [['find' => 'unique', 'replace' => 'new text'], ['find' => 'new text', 'replace' => 'final']];
        $result = $this->handler->handle($args);
        $expected = str_replace('unique', 'final', $body);

        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertSame($expected, file_get_contents($this->staging));
        self::assertSame('<?php // active scaffold', file_get_contents($this->file));
        self::assertSame(hash('sha256', $body), $result['before_sha256']);
        self::assertSame(hash('sha256', $expected), $result['sha256']);
        self::assertSame(2, $result['edits_applied']);
        self::assertSame('src/Plugins/Demo/Services/Demo.php.milpa-part', $result['staging']);
        self::assertSame(0o640, fileperms($this->staging) & 0o777);
        self::assertArrayNotHasKey('verified', $result);
        self::assertArrayNotHasKey('diagnostic', $result);
        self::assertStringContainsString('nothing verified', $result['partial']);
        self::assertFalse($this->handler->handle($args)['ok'], 'Reusing the old hash must refuse.');
        self::assertSame($expected, file_get_contents($this->staging));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusals(): iterable
    {
        yield 'missing hash' => [['expected_sha256' => null], 'expected_sha256'];
        yield 'malformed hash' => [['expected_sha256' => 'not-a-digest'], 'expected_sha256'];
        yield 'stale hash' => [['expected_sha256' => str_repeat('0', 64)], 'hash mismatch'];
        yield 'no pairs' => [['edits' => []], 'nonempty list'];
        yield 'not a list' => [['edits' => ['find' => 'first', 'replace' => 'x']], 'nonempty list'];
        yield 'bad pair' => [['edits' => [1]], 'string'];
        yield 'empty find' => [['edits' => [['find' => '', 'replace' => 'x']]], 'nonempty string'];
        yield 'missing replacement' => [['edits' => [['find' => 'first']]], 'string'];
        yield 'invalid replacement' => [['edits' => [['find' => 'first', 'replace' => 1]]], 'string'];
        yield 'missing match' => [['edits' => [['find' => 'absent', 'replace' => 'x']]], 'matches nothing'];
        yield 'ambiguous match' => [['edits' => [['find' => 'second', 'replace' => 'x']]], 'ambiguous'];
        yield 'second pair fails' => [['edits' => [['find' => 'first', 'replace' => 'fixed'],
            ['find' => 'absent', 'replace' => 'x']]], 'matches nothing'];
        yield 'content mixed in' => [['content' => 'ignored?'], 'no `content`'];
        yield 'empty content mixed in' => [['content' => ''], 'no `content`'];
        yield 'oversized multibyte edit' => [['edits' => [['find' => 'first', 'replace' => str_repeat('é', 4094)]]], '8192'];
        yield 'path-shaped plugin' => [['plugin' => '../Demo'], 'plugin directory'];
        yield 'path-shaped class' => [['class' => '../Demo'], 'class name'];
        yield 'no plugin' => [['plugin' => 'Absent'], 'no plugin'];
        yield 'no scaffold' => [['class' => 'Absent'], 'no scaffold'];
    }

    /** A refusal must preserve both artifacts, including when an earlier pair would succeed. */
    #[DataProvider('refusals')]
    public function testRefusedAmendmentsLeaveBothFilesIntact(array $overrides, string $error): void
    {
        $result = $this->handler->handle(array_replace($this->arguments(), $overrides));
        self::assertFalse($result['ok']);
        self::assertStringContainsString($error, $result['error']);
        self::assertSame('first second second', file_get_contents($this->staging));
        self::assertSame('<?php // active scaffold', file_get_contents($this->file));
        self::assertSame([], glob(dirname($this->file) . '/.milpa-amend-*'));
    }

    /** The byte limit includes every find and replace, and empty replacement means deletion. */
    public function testExactByteBoundaryAndDeletion(): void
    {
        $args = $this->arguments();
        $args['edits'] = [['find' => 'first', 'replace' => str_repeat('é', 4093) . 'x']];
        self::assertSame(8192, strlen($args['edits'][0]['find'] . $args['edits'][0]['replace']));
        $result = $this->handler->handle($args);
        self::assertTrue($result['ok'], $result['error'] ?? '');
        $args['expected_sha256'] = $result['sha256'];
        $args['edits'] = [['find' => $args['edits'][0]['replace'], 'replace' => '']];
        self::assertTrue($this->handler->handle($args)['ok']);
        self::assertSame(' second second', file_get_contents($this->staging));
    }

    /** No amendment payload can be silently ignored by another mode. */
    public function testAmendmentArgumentsRequireAmendMode(): void
    {
        foreach ([null, 'start', 'append', 'finish'] as $mode) {
            foreach (['edits' => [], 'expected_sha256' => ''] as $name => $value) {
                $result = $this->handler->handle(['plugin' => 'Demo', 'class' => 'Demo', 'mode' => $mode,
                    'content' => '<?php', $name => $value]);
                self::assertFalse($result['ok']);
                self::assertStringContainsString('require mode=amend', $result['error']);
            }
        }
        self::assertSame('first second second', file_get_contents($this->staging));
    }

    /** Start and append expose the exact current staging hash the next amendment must use. */
    public function testPartialProducersExposeTheCurrentDigest(): void
    {
        foreach (['start', 'append'] as $mode) {
            $result = $this->handler->handle(['plugin' => 'Demo', 'class' => 'Demo', 'mode' => $mode, 'content' => 'part']);
            self::assertTrue($result['ok']);
            self::assertSame(hash_file('sha256', $this->staging), $result['sha256']);
            self::assertSame('src/Plugins/Demo/Services/Demo.php.milpa-part', $result['staging']);
        }
    }

    /** Missing staging cannot be created implicitly. */
    public function testMissingStaging(): void
    {
        unlink($this->staging);
        $result = $this->handler->handle($this->arguments());
        self::assertFalse($result['ok']);
        self::assertStringContainsString('mode=start', $result['error']);
        self::assertFileDoesNotExist($this->staging);
    }

    /** Both staging links and scaffold redirection refuse, including links inside the app. */
    public function testSymlinksAndHardlinksCannotRedirectAnAmendment(): void
    {
        $target = $this->root . '/retained';
        file_put_contents($target, 'first second second');
        foreach (['symlink', 'link'] as $link) {
            unlink($this->staging);
            $link($target, $this->staging);
            self::assertFalse($this->handler->handle($this->arguments())['ok']);
            self::assertSame('first second second', file_get_contents($target));
        }
        unlink($this->staging);
        file_put_contents($this->staging, 'first second second');
        unlink($this->file);
        symlink($target, $this->file);
        self::assertFalse($this->handler->handle($this->arguments())['ok']);
        self::assertSame('first second second', file_get_contents($target));
        unlink($this->file);
        file_put_contents($this->file, '<?php // active scaffold');
        rename($this->root . '/src/Plugins/Demo', $this->root . '/Redirected');
        symlink($this->root . '/Redirected', $this->root . '/src/Plugins/Demo');
        self::assertFalse($this->handler->handle($this->arguments())['ok']);
        self::assertSame('first second second', file_get_contents($this->staging));
    }

    /** An in-flight amendment refuses instead of waiting indefinitely or overwriting its peer. */
    public function testBusyStagingRefusesWithoutWriting(): void
    {
        $handle = fopen($this->staging, 'rb');
        flock($handle, LOCK_EX);
        try {
            $result = $this->handler->handle($this->arguments());
            self::assertFalse($result['ok']);
            self::assertStringContainsString('busy', $result['error']);
            self::assertSame('first second second', file_get_contents($this->staging));
        } finally {
            fclose($handle);
        }
    }
}
