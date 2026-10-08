<?php

/**
 * This file is part of Milpa DevTools — the developer toolbox of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Operations\EditPairs;
use Milpa\DevTools\Operations\ImplementHandler;
use Milpa\DevTools\Operations\SyntaxFinding;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/**
 * A LANDING REFUSAL CARRIES THE TEXT THE NEXT CALL NEEDS (greenhouse evidence/1162).
 *
 * Three refusals are about the text itself, and each told a builder that something was wrong without the words to
 * put it right. A body with a syntax error got one line — a line number, and it is the line where the parser gave
 * up, which is rarely the line of the mistake — beside a hint to repair the proposal with exact find and replace
 * pairs: exact pairs over a text the answer did not show. An edit whose `find` was not in the file got the whole
 * file back and nothing about where the two part ways. An edit whose `find` was there several times got a count.
 *
 * So each says what the next call is written from: the builder's own lines where the parser stopped; the line where
 * an edit stops matching, how the file reads there, and the file's own text over those lines; the lines an
 * ambiguous text sits on.
 */
final class ALandingRefusalCarriesWhatTheNextCallNeedsTest extends TestCase
{
    private const FILE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Plugins\Demo\Services;

        use App\Plugins\Demo\Rules;

        final class GreeterService
        {
            public function greet(string $name): string
            {
                $clean = trim($name);

                return 'hola ' . $clean;
            }

            public function part(string $name): string
            {
                return 'adiós ' . trim($name);
            }
        }

        PHP;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-refusal-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src/Plugins/Demo/Services', 0o775, true);
        file_put_contents($this->file(), "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\Demo\\Services;\n\nfinal class GreeterService\n{\n    // Fill me.\n}\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    // ── a body with a syntax error ───────────────────────────────────────────────────────────────

    public function testASyntaxRefusalShowsTheBuildersOwnLinesWhereTheParserStopped(): void
    {
        $proposal = str_replace('$clean = trim($name);', '$clean = trim($name)', self::FILE);

        $r = $this->implement($proposal);

        self::assertFalse($r['ok']);
        self::assertSame('syntax', $r['diagnostic']['phase']);
        self::assertStringStartsWith('refused: syntax error in the proposal for src/Plugins/Demo/Services/GreeterService.php on line 15: ', $r['error']);
        // The parser stopped two lines after the mistake: both are in what is shown, and the one it names is marked.
        self::assertStringContainsString("   13 │         \$clean = trim(\$name)\n", $r['error']);
        self::assertStringContainsString(" ▸ 15 │         return 'hola ' . \$clean;\n", $r['error']);
        self::assertStringContainsString('The parser stopped at line 15: what it could not read is on that line or just before it.', $r['error']);
    }

    /** What is shown is copied into a `find`: after the gutter, every line is the proposal's, byte for byte. */
    public function testTheLinesShownAreTheProposalsByteForByte(): void
    {
        $proposal = str_replace("        \$clean = trim(\$name);\n", "\t\$clean = trim(\$name)  \n", self::FILE);

        $r = $this->implement($proposal);

        $lines = explode("\n", $proposal);
        self::assertSame(1, preg_match_all('/^ [ ▸]\s*(\d+) │ (.*)$/mu', $r['error'], $shown, PREG_SET_ORDER) > 0 ? 1 : 0, $r['error']);
        self::assertGreaterThanOrEqual(4, \count($shown));
        foreach ($shown as [, $number, $text]) {
            self::assertSame($lines[(int) $number - 1], $text, "line {$number}");
        }
    }

    public function testAnUnclosedBracketShowsWhereItWasOpenedToo(): void
    {
        $proposal = str_replace("        return 'adiós ' . trim(\$name);\n    }\n}\n", "        return 'adiós ' . trim(\$name);\n}\n", self::FILE) . "\n\n\n\n\n\n// the end\n";

        $r = $this->implement($proposal);

        self::assertFalse($r['ok']);
        self::assertMatchesRegularExpression("/Unclosed '\\{' on line (\\d+)/", $r['error']);
        self::assertStringContainsString('The bracket it names was opened on line 10.', $r['error']);
        self::assertStringContainsString(" ◂ 10 │ {\n   …\n", $r['error'], 'far from where the parser stopped, it is shown apart');
        self::assertMatchesRegularExpression('/^ ▸ 29 │ $/mu', $r['error']);
    }

    /** Opened close by, the bracket's line is among the lines shown: it is marked there, and nothing is set apart. */
    public function testABracketOpenedCloseByIsMarkedAmongTheLinesShown(): void
    {
        $proposal = rtrim(self::FILE) . "\n\nfunction more(): void\n{\n    return;\n";

        $r = $this->implement($proposal);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('The bracket it names was opened on line 25.', $r['error']);
        self::assertMatchesRegularExpression('/^ ◂ 25 │ \{$/mu', $r['error']);
        self::assertMatchesRegularExpression('/^ ▸ 27 │ $/mu', $r['error']);
        self::assertStringNotContainsString("   …\n", $r['error']);
    }

    public function testASyntaxErrorOnTheLastLineShowsTheEndOfTheProposal(): void
    {
        $proposal = rtrim(self::FILE) . "\n\nfunction (";

        $r = $this->implement($proposal);

        self::assertFalse($r['ok']);
        self::assertStringEndsWith(" ▸ 24 │ function (", $r['error'], 'the last line of the proposal is the last line shown');
        self::assertStringContainsString("   21 │     }\n", $r['error']);
    }

    public function testAVeryLongLineIsCutAndSaysSo(): void
    {
        $proposal = str_replace('$clean = trim($name);', '$clean = trim($name) . \'' . str_repeat('x', 600) . '\'', self::FILE);

        $r = $this->implement($proposal);

        self::assertFalse($r['ok']);
        self::assertStringContainsString(str_repeat('x', 100) . ' … ⟨the line goes on: 6', $r['error']);
        self::assertLessThan(1600, \strlen($r['error']));
    }

    /** What nothing else reads stays as it was: the first sentence, and the finding the diagnostic carries. */
    public function testTheFindingItselfIsWhatItWas(): void
    {
        $proposal = str_replace('$clean = trim($name);', '$clean = trim($name)', self::FILE);

        $r = $this->implement($proposal);

        self::assertSame(SyntaxFinding::inspect($proposal), $r['diagnostic']['result']);
        self::assertSame(['parser', 'message', 'line', 'fingerprint'], array_keys($r['diagnostic']['result']));
        self::assertStringContainsString('; destination preserved, proposal never installed', explode("\n", $r['error'])[0]);
    }

    // ── a body the parser reads and the compile check refuses ────────────────────────────────────

    /**
     * The parser is not the only door. A body it reads can still fail to compile — a void function that returns a
     * value, a method declared twice — and the check that says so has a sentence of its own. A resident met it on
     * a house that carried the rest of this: 300 characters, a line number, no text.
     */
    public function testABodyThatDoesNotCompileIsShownItsOwnLinesToo(): void
    {
        $proposal = str_replace('part(string $name): string', 'part(string $name): void', self::FILE);

        $r = $this->implement($proposal);

        self::assertFalse($r['ok']);
        self::assertStringStartsWith("refused: syntax or compilation check failed (exit 255):\n", $r['error']);
        // What the check printed, in the engine's own words — which change with its version («A void function…»,
        // «A void method…»): only what both say is asked for.
        self::assertStringContainsString(' must not return a value in ', $r['error']);
        self::assertStringEndsWith(
            "\nThe check names line 20. Your proposal reads there — after «│» every line is yours, byte for byte:\n"
            . "   17 │ \n"
            . "   18 │     public function part(string \$name): void\n"
            . "   19 │     {\n"
            . " ▸ 20 │         return 'adiós ' . trim(\$name);\n"
            . '   21 │     }',
            $r['error'],
        );
    }

    /** The line it names is the mistake's own, whatever the mistake: here the second declaration of a method. */
    public function testTheLineTheCompileCheckNamesIsTheOneMarked(): void
    {
        $r = $this->implement(str_replace('function part(', 'function greet(', self::FILE));

        self::assertFalse($r['ok']);
        self::assertStringContainsString('Cannot redeclare ', $r['error']);
        self::assertStringContainsString('The check names line 18.', $r['error']);
        self::assertMatchesRegularExpression('/^ ▸ 18 │     public function greet\(string \$name\): string$/mu', $r['error']);
    }

    /** What the check printed is still there whole, under the name of the file it was meant for. */
    public function testWhatTheCompileCheckPrintedIsStillThere(): void
    {
        $r = $this->implement(str_replace('part(string $name): string', 'part(string $name): void', self::FILE));

        self::assertStringContainsString(' in ' . $this->file() . ' on line 20', $r['error']);
        self::assertStringContainsString('Errors parsing ' . $this->file(), $r['error']);
        self::assertStringNotContainsString('milpa-implement-', $r['error']);
        self::assertArrayNotHasKey('diagnostic', $r);
    }

    /** The line is read where the check closes its sentence with it; a check that names none adds nothing. */
    public function testTheLineIsReadFromWhatTheCheckPrinted(): void
    {
        self::assertSame(41, SyntaxFinding::lineNamedIn("PHP Fatal error:  A void function must not return a value in /app/src/A.php on line 41\nErrors parsing /app/src/A.php"));
        // A folder can be named anything: the line is the one that closes the sentence, not the first that looks like one.
        self::assertSame(7, SyntaxFinding::lineNamedIn("Fatal error: Cannot redeclare A::b() in /app/notes on line 3/A.php on line 7\nErrors parsing /app/notes on line 3/A.php"));
        self::assertNull(SyntaxFinding::lineNamedIn('Errors parsing /app/src/A.php'));
        self::assertNull(SyntaxFinding::lineNamedIn("sh: 1: php: not found\n"));
    }

    // ── an edit whose text is not in the file ────────────────────────────────────────────────────

    public function testAnEditWhoseTextIsNotThereIsToldWhereItStopsMatching(): void
    {
        $find = "    public function greet(string \$name): string\n    {\n        return 'hola ' . \$name;";

        $r = EditPairs::apply(self::FILE, [['find' => $find, 'replace' => 'x']]);

        self::assertFalse($r['ok']);
        self::assertStringStartsWith("edit #1 matches nothing — this `find` does not appear in the file:\n{$find}\n", $r['error']);
        self::assertStringContainsString("It matches the CURRENT file up to line 13 and stops there. There the file reads:\n        \$clean = trim(\$name);\nand your `find` reads:\n        return 'hola ' . \$name;\n", $r['error']);
    }

    /** The file's own text over those lines is handed over as the `find` that matches — and it does. */
    public function testTheFilesOwnLinesAreGivenAndTheyMatch(): void
    {
        $find = "    public function greet(string \$name): string\n    {\n        return 'hola ' . \$name;";

        $r = EditPairs::apply(self::FILE, [['find' => $find, 'replace' => 'x']]);

        self::assertSame(1, preg_match('/as a `find` it matches exactly once:\n(.*?)\nThe CURRENT file is:/s', $r['error'], $given), $r['error']);
        self::assertSame("    public function greet(string \$name): string\n    {\n        \$clean = trim(\$name);", $given[1]);
        self::assertTrue(EditPairs::apply(self::FILE, [['find' => $given[1], 'replace' => 'x']])['ok']);
    }

    /** The commonest miss: the words are right and the spacing is not — a blank line, an indentation. */
    public function testWhenOnlyItsSpacingDiffersItSaysSoAndGivesTheExactText(): void
    {
        $find = "    \$clean = trim(\$name);\n    return 'hola ' . \$clean;";

        $r = EditPairs::apply(self::FILE, [['find' => $find, 'replace' => 'x']]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('Only its spacing differs from the file', $r['error']);
        self::assertSame(1, preg_match('/as a `find` it matches exactly once:\n(.*?)\nThe CURRENT file is:/s', $r['error'], $given), $r['error']);
        self::assertSame("        \$clean = trim(\$name);\n\n        return 'hola ' . \$clean;", $given[1]);
    }

    public function testWhenNothingOfItIsThereItSaysSo(): void
    {
        $r = EditPairs::apply(self::FILE, [['find' => "    private function shout(): string\n    {", 'replace' => 'x']]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('Nothing of its first line is in the CURRENT file.', $r['error']);
        self::assertStringNotContainsString('stops there', $r['error']);
        self::assertStringNotContainsString('matches exactly once', $r['error']);
    }

    /** A text over several places is not handed back as a `find`: it would be the next refusal. */
    public function testLinesThatAreInTheFileMoreThanOnceAreNotOfferedAsAFind(): void
    {
        $r = EditPairs::apply(self::FILE, [['find' => "    {\n        zzz();", 'replace' => 'x']]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('It matches the CURRENT file up to line 13 and stops there (the first of 2 places it matches that far).', $r['error']);
        self::assertStringNotContainsString('matches exactly once', $r['error']);
    }

    /** A second pair is judged against the text the first one left — and the answer says that is the text it shows. */
    public function testALaterPairIsShownTheTextTheEarlierOnesLeft(): void
    {
        $r = EditPairs::apply(self::FILE, [['find' => "'hola '", 'replace' => "'buenas '"], ['find' => "return 'hola ' . \$clean;", 'replace' => 'x']]);

        self::assertFalse($r['ok']);
        self::assertStringStartsWith('edit #2 matches nothing', $r['error']);
        self::assertStringContainsString("The CURRENT file, with edit #1 of this call applied, is:\n---\n", $r['error']);
        self::assertStringContainsString("return 'buenas ' . \$clean;", $r['error']);
        self::assertStringContainsString('It matches the text the earlier edits of this call left up to line 15 and stops there', $r['error']);
        self::assertStringContainsString("There the file reads:\n        return 'buenas ' . \$clean;\nand your `find` reads:\nreturn 'hola ' . \$clean;\n", $r['error']);
    }

    public function testAThirdPairSaysWhichOnesCameBeforeIt(): void
    {
        $r = EditPairs::apply(self::FILE, [['find' => "'hola '", 'replace' => "'buenas '"], ['find' => "'adiós '", 'replace' => "'chao '"], ['find' => 'absent', 'replace' => 'x']]);

        self::assertStringStartsWith('edit #3 matches nothing', $r['error']);
        self::assertStringContainsString("The CURRENT file, with edits #1–#2 of this call applied, is:\n---\n", $r['error']);
        self::assertStringContainsString('Nothing of its first line is in the text the earlier edits of this call left.', $r['error']);
    }

    /**
     * What the answer costs is the cost of the stretch it speaks of, not of the file: the search for the stretch
     * that says the same words ends at the first line that says another. Without that it reads the rest of the
     * file once per line — measured, 22 seconds on 8,000 lines.
     */
    public function testALongFileIsAnsweredAsSoonAsAShortOne(): void
    {
        $lines = ['<?php', 'final class Long', '{', '    public function first(): int', '    {', '        return 1;', '    }'];
        for ($i = 0; $i < 12000; ++$i) {
            $lines[] = "    private int \$field{$i} = {$i}; // a line of ordinary length, as a class has them";
        }
        $content = implode("\n", $lines) . "\n}\n";

        $started = hrtime(true);
        $r = EditPairs::apply($content, [['find' => "    public function first(): int\n    {\n        return 2;", 'replace' => 'x']]);
        $seconds = (hrtime(true) - $started) / 1e9;

        self::assertStringContainsString("as a `find` it matches exactly once:\n    public function first(): int\n    {\n        return 1;\n", $r['error']);
        self::assertLessThan(2.0, $seconds, 'a refused edit on a long file took ' . round($seconds, 1) . ' s to answer');
    }

    /** The same for a text that is on every line of a long file: each place is counted from the one before it. */
    public function testATextOnEveryLineOfALongFileIsCountedOnce(): void
    {
        $lines = [];
        for ($i = 0; $i < 80000; ++$i) {
            $lines[] = "    private int \$field{$i} = {$i}; // a line of ordinary length, as a class has them";
        }

        $started = hrtime(true);
        $r = EditPairs::apply(implode("\n", $lines) . "\n", [['find' => ';', 'replace' => 'x']]);
        $seconds = (hrtime(true) - $started) / 1e9;

        self::assertStringContainsString('It is on lines 1, 2, 3, 4, 5, 6, 7, 8 and 79992 more of the CURRENT file:', $r['error']);
        self::assertLessThan(2.0, $seconds, 'an ambiguous edit on a long file took ' . round($seconds, 1) . ' s to answer');
    }

    /** Nothing is taken away: the whole text still travels, under the name it was given. */
    public function testTheWholeTextStillTravels(): void
    {
        $r = EditPairs::apply(self::FILE, [['find' => 'absent', 'replace' => 'x']], 'RECORDED proposal');

        self::assertStringContainsString("The RECORDED proposal is:\n---\n" . self::FILE . "\n---\nBuild your pairs against that text exactly.", $r['error']);
        self::assertStringContainsString('Nothing of its first line is in the RECORDED proposal.', $r['error']);
    }

    // ── an edit whose text is there more than once ───────────────────────────────────────────────

    public function testAnAmbiguousEditIsToldTheLinesItsTextSitsOn(): void
    {
        $r = EditPairs::apply(self::FILE, [['find' => 'trim($name)', 'replace' => 'x']]);

        self::assertFalse($r['ok']);
        self::assertStringStartsWith('edit #1 is ambiguous: `find` appears 2 times and must appear exactly once — widen it with surrounding lines', $r['error']);
        self::assertStringContainsString("It is on lines 13 and 20 of the CURRENT file:\n   13 │         \$clean = trim(\$name);\n   20 │         return 'adiós ' . trim(\$name);", $r['error']);
    }

    public function testALineItSitsOnTwiceIsOneLine(): void
    {
        $r = EditPairs::apply(self::FILE, [['find' => 'string', 'replace' => 'x']]);

        self::assertStringContainsString('appears 4 times', $r['error']);
        self::assertStringContainsString("It is on lines 11 and 18 of the CURRENT file:\n   11 │     public function greet(string \$name): string\n   18 │     public function part(string \$name): string", $r['error']);

        $one = EditPairs::apply("a x x b\n", [['find' => 'x', 'replace' => 'y']]);
        self::assertStringContainsString("It is on line 1, more than once, of the CURRENT file:\n   1 │ a x x b", $one['error']);
    }

    public function testManyPlacesAreCounted(): void
    {
        $r = EditPairs::apply(str_repeat("same\n", 40), [['find' => 'same', 'replace' => 'x']]);

        self::assertStringContainsString('appears 40 times', $r['error']);
        self::assertStringContainsString('It is on lines 1, 2, 3, 4, 5, 6, 7, 8 and 32 more of the CURRENT file:', $r['error']);
        self::assertSame(8, preg_match_all('/^\s+\d+ │ same$/mu', $r['error']));
    }

    /** Through the door a builder uses, the same answer arrives. */
    public function testTheEditDoorAnswersWithIt(): void
    {
        file_put_contents($this->file(), self::FILE);

        $r = (new ImplementHandler(new RootResolver($this->root)))->edit(['plugin' => 'Demo', 'class' => 'GreeterService',
            'edits' => [['find' => "        return 'hola ' . \$name;", 'replace' => 'x']]]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString("It matches the CURRENT file up to line 15 and stops there. There the file reads:\n        return 'hola ' . \$clean;\n", $r['error']);
        self::assertSame(self::FILE, (string) file_get_contents($this->file()), 'and the file is as it was');
    }

    private function file(): string
    {
        return $this->root . '/src/Plugins/Demo/Services/GreeterService.php';
    }

    /** @return array<string, mixed> */
    private function implement(string $content): array
    {
        return (new ImplementHandler(new RootResolver($this->root)))->handle(['plugin' => 'Demo', 'class' => 'GreeterService', 'content' => $content]);
    }
}
