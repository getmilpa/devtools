<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Operations\SourcePageHandler;
use Milpa\DevTools\Support\RootResolver;
use Milpa\ToolRuntime\Contracts\ResultBudget;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pages reconstruct source exactly while rejecting stale identity and escaped roots. */
final class SourcePageHandlerTest extends TestCase
{
    private string $root;
    private SourcePageHandler $handler;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-page-' . bin2hex(random_bytes(6));
        mkdir($this->root);
        file_put_contents($this->root . '/sample.txt', str_repeat("ñ🌽/\\\"\r\nnext\tline\n", 400));
        $this->handler = new SourcePageHandler(new RootResolver($this->root));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public static function budgets(): iterable
    {
        yield 'default window' => [8000, 999999];
        yield 'small window' => [6144, 999999];
        yield 'producer tightens' => [8000, 900];
    }

    #[DataProvider('budgets')]
    public function testMultilineUnicodeAndEscapesReconstructExactly(int $transport, int $requested): void
    {
        $budget = ResultBudget::json($transport);
        $context = new ToolContext(resultBudget: $budget);
        $input = ['path' => 'sample.txt', 'max_chars' => $requested];
        $expected = file_get_contents($this->root . '/sample.txt');
        $received = '';
        $pages = 0;
        do {
            $page = $this->handler->handle($input, authority: $context);
            self::assertTrue($page['ok']);
            self::assertTrue($budget->tightenedTo($requested)->fits($page));
            self::assertSame($page, json_decode($budget->encode($page), true, 512, JSON_THROW_ON_ERROR));
            self::assertSame('sample.txt', $page['path']);
            self::assertSame(hash('sha256', $expected), $page['sha256']);
            self::assertSame(strlen($received), $page['offset']);
            self::assertSame(strlen($expected), $page['total_bytes']);
            self::assertTrue(mb_check_encoding($page['content'], 'UTF-8'));
            $received .= $page['content'];
            self::assertSame(strlen($received), $page['next_offset']);
            self::assertNotSame('', $page['content']);
            $input['cursor'] = $page['next_cursor'];
            self::assertLessThan(100, ++$pages);
        } while ($page['next_cursor'] !== null);
        self::assertGreaterThan(1, $pages);
        self::assertSame($expected, $received);
    }

    public function testExplicitBudgetWorksWithoutTransportAndEmptyFileCompletes(): void
    {
        file_put_contents($this->root . '/empty.txt', '');
        $page = $this->handler->handle(['path' => $this->root . '/empty.txt', 'max_chars' => 256]);
        self::assertTrue($page['ok']);
        self::assertSame('empty.txt', $page['path']);
        self::assertSame('', $page['content']);
        self::assertSame(0, $page['next_offset']);
        self::assertNull($page['next_cursor']);
        self::assertTrue(ResultBudget::json(256)->fits($page));
    }

    public function testMissingBudgetAndInsufficientMetadataSpaceAreExplicitFailures(): void
    {
        self::assertFalse($this->handler->handle(['path' => 'sample.txt'])['ok']);
        foreach ([0, 255, '8000', null] as $limit) {
            self::assertFalse($this->handler->handle(['path' => 'sample.txt', 'max_chars' => $limit])['ok']);
        }
        $small = $this->handler->handle(['path' => 'sample.txt', 'max_chars' => 256]);
        self::assertFalse($small['ok']);
        self::assertStringContainsString('metadata', $small['error']);
    }

    public function testContentChangeAndCrossFileCursorAreRejected(): void
    {
        $input = ['path' => 'sample.txt', 'max_chars' => 800];
        $first = $this->handler->handle($input);
        $input['cursor'] = $first['next_cursor'];
        file_put_contents($this->root . '/other.txt', file_get_contents($this->root . '/sample.txt'));
        self::assertFalse($this->handler->handle(array_replace($input, ['path' => 'other.txt']))['ok']);
        file_put_contents($this->root . '/sample.txt', 'changed');
        self::assertFalse($this->handler->handle($input)['ok']);
        self::assertSame('changed', $this->handler->handle(['path' => 'sample.txt', 'max_chars' => 800])['content']);
    }

    public static function invalidCursors(): iterable
    {
        foreach ([null, 1, [], '', '*', 'a', str_repeat('a', 16385), 'bnVsbA'] as $i => $value) {
            yield 'malformed ' . $i => [$value, null];
        }
        foreach ([-1, 999999, 1] as $offset) {
            yield 'invalid byte offset ' . $offset => [null, ['offset' => $offset]];
        }
        yield 'wrong version' => [null, ['v' => 2]];
        yield 'extra property' => [null, ['extra' => true]];
        yield 'string offset' => [null, ['offset' => '1']];
        yield 'wrong hash' => [null, ['sha256' => 'wrong']];
    }

    #[DataProvider('invalidCursors')]
    public function testMalformedOrInvalidCursorCannotAdvance(mixed $token, ?array $changes): void
    {
        if ($changes !== null) {
            $cursor = array_replace(['v' => 1, 'path' => 'sample.txt', 'sha256' => hash_file('sha256', $this->root . '/sample.txt'), 'offset' => 0], $changes);
            $token = rtrim(strtr(base64_encode(json_encode($cursor, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        }
        self::assertFalse($this->handler->handle(['path' => 'sample.txt', 'max_chars' => 800, 'cursor' => $token])['ok']);
    }

    public function testMissingPathsTraversalAndExternalSymlinksAreRejected(): void
    {
        $outside = $this->root . '-outside';
        file_put_contents($outside, 'external');
        symlink($outside, $this->root . '/linked');
        try {
            foreach (['missing', '', '.', $outside, '../' . basename($outside), 'linked'] as $path) {
                self::assertFalse($this->handler->handle(['path' => $path, 'max_chars' => 800])['ok'], $path);
            }
        } finally {
            unlink($outside);
        }
    }

    public function testInvalidUtf8IsRejectedBeforeSplitting(): void
    {
        file_put_contents($this->root . '/sample.txt', "\xFF");
        self::assertFalse($this->handler->handle(['path' => 'sample.txt', 'max_chars' => 800])['ok']);
    }
}
