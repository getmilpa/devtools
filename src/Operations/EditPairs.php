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
            if ($times === 0) {
                return ['ok' => false, 'error' => 'edit #' . ($i + 1)
                    . " matches nothing — this `find` does not appear in the file:\n{$find}\n"
                    . "The {$sourceLabel} is:\n---\n{$content}\n---\nBuild your pairs against that text exactly."];
            }
            if ($times > 1) {
                return ['ok' => false, 'error' => 'edit #' . ($i + 1)
                    . " is ambiguous: `find` appears {$times} times and must appear exactly once — widen it with surrounding lines"];
            }
            $content = str_replace($find, $replace, $content);
        }
        return ['ok' => true, 'content' => $content, 'edits_applied' => count($edits)];
    }
}
