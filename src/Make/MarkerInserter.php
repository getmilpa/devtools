<?php

declare(strict_types=1);

namespace Milpa\DevTools\Make;

/**
 * Deterministic, anchor-based text insertion for AUTO-WIRING a generator's registration snippet
 * into an EXISTING plugin file that already carries the matching `// {marker}` comment anchor (see
 * {@see Markers}) — never a rewrite, never AST surgery: a plain line-splice immediately before the
 * marker line, re-indented to the marker line's own indentation, with the marker line itself always
 * preserved so a later `coa:make` run can insert at it again (F1: "insert at a known anchor, not a
 * rewrite").
 *
 * Idempotent-safe by construction: {@see insertBefore()} skips the splice (returns `$contents`
 * unchanged) when the given snippet, trimmed, already appears verbatim in the file — closing the F1
 * friction ("~57 lines hand-merged") without risking silent duplicate wiring on a re-run of the same
 * `coa:make` command. Pass `$force` to re-insert anyway (mirrors {@see WriteGuard}'s own `--force`
 * semantics).
 */
final class MarkerInserter
{
    /** Whether `$contents` carries a `// {$marker}` anchor line (see {@see Markers}). */
    public function hasMarker(string $contents, string $marker): bool
    {
        return $this->markerLineIndex(explode("\n", $contents), $marker) !== null;
    }

    /**
     * Inserts `$snippet` immediately before the `// {$marker}` anchor line inside `$contents`,
     * indenting every line of `$snippet` to match the marker line's own leading whitespace — the
     * marker line is never consumed, so a later call targeting the same marker splices in above the
     * previous insertion(s), oldest first.
     *
     * @throws \RuntimeException When `$contents` carries no `// {$marker}` anchor — check
     *                           {@see hasMarker()} before calling this.
     */
    public function insertBefore(string $contents, string $marker, string $snippet, bool $force = false): string
    {
        $lines = explode("\n", $contents);
        $index = $this->markerLineIndex($lines, $marker);
        if ($index === null) {
            throw new \RuntimeException('marker // {' . $marker . '} not found — call hasMarker() first');
        }

        $indent = $this->indentOf($lines[$index]);
        $inserted = array_map(
            static fn (string $line): string => $line === '' ? '' : $indent . $line,
            explode("\n", rtrim($snippet, "\n")),
        );
        $insertedBlock = implode("\n", $inserted);

        // Idempotent-safe: the dedup check compares against the SAME indented form this method
        // would itself splice in, not the caller's raw (unindented) $snippet — a naive comparison
        // against the raw snippet never matches once it has been indented once already.
        if (!$force && trim($insertedBlock) !== '' && str_contains($contents, $insertedBlock)) {
            return $contents;
        }

        array_splice($lines, $index, 0, $inserted);

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $lines
     */
    private function markerLineIndex(array $lines, string $marker): ?int
    {
        $target = '// {' . $marker . '}';
        foreach ($lines as $i => $line) {
            if (trim($line) === $target) {
                return $i;
            }
        }

        return null;
    }

    /** The leading whitespace of `$line` — everything {@see insertBefore()} indents an inserted line by. */
    private function indentOf(string $line): string
    {
        return preg_replace('/^(\s*).*$/', '$1', $line) ?? '';
    }
}
