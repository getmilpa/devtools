<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\DevTools\Operations;

/** Apply exact replacements to supplied bytes; this grants no authority and writes no files. */
final class EditPairs
{
    /**
     * Transform only the supplied bytes, refusing missing or ambiguous matches.
     *
     * @param array<array-key, mixed> $edits
     *
     * @return array{ok: true, content: string, edits_applied: int}|array{ok: false, error: string}
     */
    public static function apply(string $content, array $edits, string $sourceLabel = 'CURRENT file'): array
    {
        if ($edits === []) {
            return ['ok' => false, 'error' => 'nothing to edit: `edits` is a list of {find, replace} pairs'];
        }
        foreach (array_values($edits) as $i => $edit) {
            $find = is_array($edit) && is_string($edit['find'] ?? null) ? $edit['find'] : '';
            $replace = is_array($edit) && is_string($edit['replace'] ?? null) ? $edit['replace'] : '';
            if ($find === '') {
                return ['ok' => false, 'error' => 'edit #' . ($i + 1) . ' has an empty `find`'];
            }
            $times = substr_count($content, $find);
            // WHAT THE NEXT CALL IS WRITTEN FROM (greenhouse evidence/1162). The text a pair is judged against is the
            // one the pairs before it left, and is named as that.
            $label = $i === 0 ? $sourceLabel : $sourceLabel . ', with edit' . ($i === 1 ? ' #1' : 's #1–#' . $i) . ' of this call applied,';
            $where = $i === 0 ? "the {$sourceLabel}" : 'the text the earlier edits of this call left';
            if ($times === 0) {
                return ['ok' => false, 'error' => 'edit #' . ($i + 1)
                    . " matches nothing — this `find` does not appear in the file:\n{$find}\n"
                    . self::whereItStops($content, $find, $where)
                    . "The {$label} is:\n---\n{$content}\n---\nBuild your pairs against that text exactly."];
            }
            if ($times > 1) {
                return ['ok' => false, 'error' => 'edit #' . ($i + 1)
                    . " is ambiguous: `find` appears {$times} times and must appear exactly once — widen it with surrounding lines"
                    . self::whereItSits($content, $find, $where)];
            }
            $content = str_replace($find, $replace, $content);
        }
        return ['ok' => true, 'content' => $content, 'edits_applied' => count($edits)];
    }

    /** How many places of an ambiguous text are shown. */
    private const PLACES = 8;

    /** How much of a `find` has to be in the text before saying where it stops: a letter or two is in every text. */
    private const ENOUGH = 6;

    /**
     * Where a `find` that is not in the text parts ways with it, and the text's own lines there.
     *
     * The whole text was already handed back, and a builder was left to compare the two by eye. The longest start of
     * the `find` that IS in the text says where they part: the line it reaches, how the text reads there, how the
     * `find` reads there. And the text's own lines over that stretch are handed over as the `find` that matches —
     * only when they do, and only once: a text that sits in several places would be the next refusal.
     */
    private static function whereItStops(string $content, string $find, string $where): string
    {
        // The longest prefix of `find` the text holds: a shorter prefix of a prefix it holds is held too.
        $low = 0;
        $high = \strlen($find);
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            str_contains($content, substr($find, 0, $middle)) ? $low = $middle : $high = $middle - 1;
        }
        $held = substr($find, 0, $low);
        // Too little of it is there to say where it stops: a letter or two is in every text.
        $first = trim(explode("\n", ltrim($find, "\n"))[0]);
        if (\strlen(trim($held)) < min(\strlen($first), self::ENOUGH)) {
            return "Nothing of its first line is in {$where}.\n";
        }
        $at = (int) strpos($content, $held);
        $places = substr_count($content, $held);
        $lines = explode("\n", $content);
        $from = substr_count($content, "\n", 0, $at);                 // the line the match starts on, from 0
        $stops = substr_count($content, "\n", 0, $at + $low);         // the line it stops on
        $findLines = explode("\n", $find);
        $findStops = substr_count($held, "\n");
        $said = "It matches {$where} up to line " . ($stops + 1) . ' and stops there'
            . ($places > 1 ? " (the first of {$places} places it matches that far)" : '') . '. There the file reads:' . "\n"
            . $lines[$stops] . "\nand your `find` reads:\n" . $findLines[$findStops] . "\n";

        // The text's own lines over that stretch — or, when only spacing parts the two, over the stretch that says
        // the same words.
        $squash = static fn (string $text): string => trim((string) preg_replace('/\s+/', ' ', $text));
        $wanted = $squash($find);
        $own = null;
        $onlySpacing = false;
        for ($to = $from; $to < \count($lines); ++$to) {
            $candidate = $squash(implode("\n", \array_slice($lines, $from, $to - $from + 1)));
            if ($candidate === $wanted) {
                $own = implode("\n", \array_slice($lines, $from, $to - $from + 1));
                $onlySpacing = true;
                break;
            }
            // A stretch that already says another word never becomes the `find` by growing: stop there. It changes
            // nothing of the answer — it is what keeps its cost that of the stretch, not of the file.
            if (!str_starts_with($wanted, $candidate)) {
                break;
            }
        }
        $own ??= implode("\n", \array_slice($lines, $from, \count($findLines)));
        // Those lines hold the start that is in the text only once, so they are in it only once too.
        if ($places > 1) {
            return $said;
        }

        return $said . ($onlySpacing ? 'Only its spacing differs from the file. ' : '')
            . "The file's own text over those lines — as a `find` it matches exactly once:\n{$own}\n";
    }

    /** The lines an ambiguous `find` sits on, so the next one can be widened with what tells them apart. */
    private static function whereItSits(string $content, string $find, string $where): string
    {
        $lines = explode("\n", $content);
        $numbers = [];
        $line = 1;
        $counted = 0;
        for ($at = strpos($content, $find); $at !== false; $at = strpos($content, $find, $at + \strlen($find))) {
            $line += substr_count($content, "\n", $counted, $at - $counted);   // counted from the place before it
            $counted = $at;
            $numbers[$line] = true;                                              // a line it is on twice is one line
        }
        $numbers = array_keys($numbers);
        $shown = \array_slice($numbers, 0, self::PLACES);
        $more = \count($numbers) - \count($shown);
        $width = \strlen((string) max($shown));
        $list = match (true) {
            $more > 0 => 'lines ' . implode(', ', $shown) . " and {$more} more",
            \count($shown) > 1 => 'lines ' . implode(', ', \array_slice($shown, 0, -1)) . ' and ' . end($shown),
            default => "line {$shown[0]}, more than once,",
        };
        $rows = array_map(static fn (int $n): string => '   ' . str_pad((string) $n, $width, ' ', STR_PAD_LEFT) . ' │ ' . $lines[$n - 1], $shown);

        return ". It is on {$list} of {$where}:\n" . implode("\n", $rows);
    }
}
