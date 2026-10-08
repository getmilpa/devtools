<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\DevTools\Operations;

/** A native parser finding, without executing the proposal or interpreting process stderr. */
final class SyntaxFinding
{
    /** Location attributes the finding; only the parser and message identify its information.
     * Null is not a compilation verdict: php -l still judges bodies without a ParseError.
     *
     * @return array{parser: string, message: string, line: int, fingerprint: string}|null
     */
    public static function inspect(string $content): ?array
    {
        try {
            $tokens = token_get_all($content, TOKEN_PARSE);
            unset($tokens);
        } catch (\ParseError $error) {
            $parser = 'php-token-parse/v1';
            $message = $error->getMessage();
            // PHP embeds the opening delimiter's line in this specific mismatch message.
            // Retain the full diagnostic, but do not mistake that location for new information.
            $information = preg_replace("/^(Unclosed '(?:\\(|\\{|\\[)') on line [1-9][0-9]*( does not match '(?:\\)|\\}|\\])')$/D", '$1$2', $message);
            return [
                'parser' => $parser,
                'message' => $message,
                'line' => $error->getLine(),
                'fingerprint' => hash('sha256', json_encode([$parser, $information], JSON_THROW_ON_ERROR)),
            ];
        } catch (\CompileError) {
            // A different engine failure must still pass the existing compilation gate.
        }
        return null;
    }

    /** How much of one line is shown: a generated line can be thousands of characters long. */
    private const SHOWN = 240;

    /**
     * The builder's own lines where the parser stopped, to close the refusal with (greenhouse evidence/1162).
     *
     * A line number alone sends a builder back to a text it no longer has in front of it — and the house, beside
     * the refusal, tells it to repair that text with exact find and replace pairs. The line the parser names is
     * where it gave up, which is rarely the line of the mistake: a missing semicolon is reported two lines down. So
     * the lines before it travel too, and so does the line a bracket was opened on when the parser names one. After
     * the gutter each line is the proposal's, byte for byte: it is what a `find` is copied from.
     *
     * @param array{message: string, line: int} $finding
     */
    public static function shown(string $content, array $finding): string
    {
        $opened = preg_match("/^Unclosed '.' on line ([1-9][0-9]*)/", $finding['message'], $named) === 1 ? (int) $named[1] : null;

        return self::lines($content, $finding['line'], $opened, "The parser stopped at line {$finding['line']}: what it could not read is on that line or just before it.");
    }

    /**
     * The same lines for the check that runs after the parser, to close its refusal with.
     *
     * A body the parser reads can still fail to compile — a method declared twice, a void function that returns a
     * value — and that check answered with what it printed: a line number inside a sentence, and no text. The line
     * it names is the line of the mistake itself.
     */
    public static function shownAt(string $content, int $line): string
    {
        return self::lines($content, $line, null, "The check names line {$line}.");
    }

    /** The line a compile check names in what it printed — it closes its sentence with `on line N` — or null. */
    public static function lineNamedIn(string $printed): ?int
    {
        return preg_match('/ on line ([1-9][0-9]*)$/m', $printed, $named) === 1 ? (int) $named[1] : null;
    }

    private static function lines(string $content, int $stopped, ?int $opened, string $said): string
    {
        $lines = explode("\n", $content);
        $width = \strlen((string) \count($lines));
        $row = static function (int $number, string $mark) use ($lines, $width): string {
            $text = $lines[$number - 1];
            if (\strlen($text) > self::SHOWN) {
                $text = substr($text, 0, self::SHOWN) . ' … ⟨the line goes on: ' . \strlen($text) . ' bytes⟩';
            }

            return ' ' . $mark . ' ' . str_pad((string) $number, $width, ' ', STR_PAD_LEFT) . ' │ ' . $text . "\n";
        };

        $block = '';
        if ($opened !== null && $opened < $stopped - 3 && $opened <= \count($lines)) {
            $said .= " The bracket it names was opened on line {$opened}.";
            $block .= $row($opened, '◂') . "   …\n";
        } elseif ($opened !== null) {
            $said .= " The bracket it names was opened on line {$opened}.";
        }
        for ($number = max(1, $stopped - 3); $number <= min(\count($lines), $stopped + 1); ++$number) {
            $block .= $row($number, $number === $stopped ? '▸' : ($number === $opened ? '◂' : ' '));
        }

        return "\n" . $said . " Your proposal reads there — after «│» every line is yours, byte for byte:\n" . rtrim($block, "\n");
    }
}
