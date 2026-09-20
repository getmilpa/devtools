<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Operations\EditPairs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EditPairsTest extends TestCase
{
    public function testOrderedRepairsPreserveEveryOtherByte(): void
    {
        $body = "<?php\n// 🌽\nreturn 'old';\n";
        $result = EditPairs::apply($body, [
            ['find' => "'old'", 'replace' => "'intermediate'"],
            ['find' => "'intermediate'", 'replace' => "'repaired'"],
        ], 'RECORDED proposal');
        self::assertSame(['ok' => true, 'content' => "<?php\n// 🌽\nreturn 'repaired';\n", 'edits_applied' => 2], $result);
        self::assertSame("<?php\n// 🌽\nreturn 'old';\n", $body);
    }

    public function testMissingMatchNamesTheSelectedSourceWithoutInventingAHostWrite(): void
    {
        $result = EditPairs::apply('retained source', [['find' => 'absent', 'replace' => 'new']], 'RECORDED proposal');
        self::assertFalse($result['ok']);
        self::assertStringContainsString("The RECORDED proposal is:\n---\nretained source", $result['error']);
        self::assertArrayNotHasKey('content', $result);
    }

    #[DataProvider('invalidPairs')]
    public function testInvalidPairsNeverReturnCandidateBytes(array $edits, string $error): void
    {
        $result = EditPairs::apply('same same', $edits);
        self::assertFalse($result['ok']);
        self::assertStringContainsString($error, $result['error']);
        self::assertArrayNotHasKey('content', $result);
    }

    public static function invalidPairs(): iterable
    {
        yield 'empty list' => [[], 'nothing to edit'];
        yield 'empty find' => [[['find' => '', 'replace' => 'x']], 'empty `find`'];
        yield 'invalid entry' => [['not a pair'], 'empty `find`'];
        yield 'ambiguous' => [[['find' => 'same', 'replace' => 'x']], 'appears 2 times'];
        yield 'late missing match' => [[['find' => 'same same', 'replace' => 'new'], ['find' => 'absent', 'replace' => 'x']], 'edit #2 matches nothing'];
    }

    public function testEmptyReplacementDeletesOnlyTheSelectedBytes(): void
    {
        self::assertSame(
            ['ok' => true, 'content' => 'prefixsuffix', 'edits_applied' => 1],
            EditPairs::apply('prefixmiddlesuffix', [['find' => 'middle', 'replace' => '']])
        );
    }
}
