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

use Milpa\DevTools\Operations\SourceReadHandler;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/**
 * The read that makes `edit` usable: a raw file slice with honest accounting, confined to the root.
 */
final class SourceReadHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-read-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src', 0o775, true);

        $lines = '';
        for ($number = 1; $number <= 10; ++$number) {
            $lines .= "line {$number}\n";
        }
        file_put_contents($this->root . '/src/Sample.php', $lines);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    /** A slice comes back verbatim, with `from`, count, total, and an explicit truncation flag. */
    public function testReadsASliceWithHonestLineAccounting(): void
    {
        $result = $this->handler()->handle(['path' => 'src/Sample.php', 'from' => 3, 'lines' => 4]);

        self::assertTrue($result['ok']);
        self::assertSame('src/Sample.php', $result['path']);
        self::assertSame(3, $result['from']);
        self::assertSame(4, $result['lines']);
        self::assertSame(10, $result['total_lines']);
        self::assertSame("line 3\nline 4\nline 5\nline 6\n", $result['content']);
        self::assertTrue($result['truncated'], 'lines 7..10 were left out and the answer says so');
    }

    /** By default the read starts at the top, and a whole file is declared un-truncated. */
    public function testDefaultsReadFromTheTopAndSayWhenNothingWasCut(): void
    {
        $result = $this->handler()->handle(['path' => 'src/Sample.php']);

        self::assertTrue($result['ok']);
        self::assertSame(1, $result['from']);
        self::assertSame(10, $result['lines']);
        self::assertFalse($result['truncated']);
        self::assertStringStartsWith("line 1\n", (string) $result['content']);
    }

    /** An absolute path is accepted when — and only when — it stays inside the root. */
    public function testAnAbsolutePathInsideTheRootWorks(): void
    {
        $result = $this->handler()->handle(['path' => $this->root . '/src/Sample.php']);

        self::assertTrue($result['ok']);
        self::assertSame('src/Sample.php', $result['path']);
    }

    /** A path that resolves outside the root is refused before anything is opened. */
    public function testATraversalOutsideTheRootIsRefused(): void
    {
        $outside = $this->root . '-outside.txt';
        file_put_contents($outside, 'secret');

        try {
            $relative = $this->handler()->handle(['path' => '../' . basename($outside)]);
            self::assertFalse($relative['ok'], 'a .. traversal must not escape the root');

            $absolute = $this->handler()->handle(['path' => $outside]);
            self::assertFalse($absolute['ok'], 'an absolute path outside the root must be refused');
        } finally {
            unlink($outside);
        }
    }

    /** Without `path` there is nothing to read — answered, not thrown. */
    public function testAMissingPathInputIsAnswered(): void
    {
        $result = $this->handler()->handle([]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('path', (string) $result['error']);
    }

    /** A file that is not there is `ok:false` with a reason, never an exception. */
    public function testAMissingFileIsOkFalseNotAnException(): void
    {
        $result = $this->handler()->handle(['path' => 'src/Nope.php']);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('src/Nope.php', (string) $result['error']);
    }

    /** A directory is not a file to read. */
    public function testADirectoryIsNotAFileToRead(): void
    {
        $result = $this->handler()->handle(['path' => 'src']);

        self::assertFalse($result['ok']);
    }

    /** Asking past the end is answered with the file's real size, so the next ask can be right. */
    public function testFromPastTheEndIsAnsweredWithTheFileSize(): void
    {
        $result = $this->handler()->handle(['path' => 'src/Sample.php', 'from' => 99]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('10 lines', (string) $result['error']);
    }

    /** The per-call budget is clamped to the ceiling — a bigger ask is a second `from`, not a dump. */
    public function testTheLineBudgetIsClamped(): void
    {
        $big = '';
        for ($number = 1; $number <= 450; ++$number) {
            $big .= "row {$number}\n";
        }
        file_put_contents($this->root . '/src/Big.php', $big);

        $result = $this->handler()->handle(['path' => 'src/Big.php', 'lines' => 9999]);

        self::assertTrue($result['ok']);
        self::assertSame(400, $result['lines']);
        self::assertSame(450, $result['total_lines']);
        self::assertTrue($result['truncated']);

        $floor = $this->handler()->handle(['path' => 'src/Big.php', 'lines' => 0]);
        self::assertTrue($floor['ok']);
        self::assertSame(1, $floor['lines'], 'the floor is one line, not zero');
    }

    /** An empty file reads as its honest content: nothing. */
    public function testAnEmptyFileReadsAsEmpty(): void
    {
        file_put_contents($this->root . '/src/Empty.php', '');

        $result = $this->handler()->handle(['path' => 'src/Empty.php']);

        self::assertTrue($result['ok']);
        self::assertSame('', $result['content']);
        self::assertSame(0, $result['total_lines']);
        self::assertSame(0, $result['lines']);
        self::assertFalse($result['truncated']);
    }

    private function handler(): SourceReadHandler
    {
        return new SourceReadHandler(new RootResolver($this->root));
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
