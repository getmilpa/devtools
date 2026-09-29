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

namespace Milpa\DevTools\Tests\Support;

use Milpa\DevTools\Support\ClassNameExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The FQCN a source file declares, read from the file — including the `final class` every generator here writes.
 */
final class ClassNameExtractorTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/milpa-devtools-cne-' . bin2hex(random_bytes(4)) . '.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /** @return iterable<string, array{0: string, 1: ?string}> */
    public static function sources(): iterable
    {
        yield 'a bare class' => ["<?php\nnamespace App\\Plugins\\Blog;\n\nclass Blog {}\n", 'App\\Plugins\\Blog\\Blog'];
        yield 'a final class, as make writes it' => ["<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\Blog\\Services;\n\nfinal class Posts\n{\n}\n", 'App\\Plugins\\Blog\\Services\\Posts'];
        yield 'final readonly' => ["<?php\nnamespace A\\B;\nfinal readonly class Value {}\n", 'A\\B\\Value'];
        yield 'abstract' => ["<?php\nnamespace A;\nabstract class Base {}\n", 'A\\Base'];
        yield 'no namespace' => ["<?php\nclass Loose {}\n", 'Loose'];
        yield 'an interface is not a class' => ["<?php\nnamespace A;\ninterface Port {}\n", null];
        yield 'the word class in a comment is not a declaration' => ["<?php\nnamespace A;\n// this class does nothing\nfunction f() {}\n", null];
    }

    #[DataProvider('sources')]
    public function testItReadsTheClassTheFileDeclares(string $source, ?string $expected): void
    {
        file_put_contents($this->file, $source);

        self::assertSame($expected, ClassNameExtractor::fromFile($this->file));
    }

    public function testAFileThatIsNotThereDeclaresNothing(): void
    {
        self::assertNull(ClassNameExtractor::fromFile($this->file));
    }
}
