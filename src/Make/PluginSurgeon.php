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

namespace Milpa\DevTools\Make;

/**
 * Deterministic, token-based STRUCTURAL insertion into an existing plugin file that carries no
 * `// {marker}` anchor — the closure of the last guidance-prose fallback. When a generator knows the
 * exact registration a postcondition requires, handing it back as text for someone else to paste is
 * an incomplete run, not a deliverable: a measured cattle run had `make entity` create the file,
 * verify it, then return `ok:false` because the repository registration had landed as prose — and
 * the fix was handed to a language model as instructions. {@see MarkerInserter} stays the first
 * choice (an anchor is cheaper and survives any file shape); this class is the second, for files
 * with no anchor but a locatable structure.
 *
 * This is still NOT free-form AST rewriting. Every operation locates ONE unambiguous structural
 * anchor — a method's closing brace, a `return [` list's closing bracket, the class declaration —
 * via `token_get_all()`, whose tokenizer atomizes comments and strings so brace counting cannot be
 * fooled by a `}` inside either, and splices at it, byte-preserving everything else. Anything it
 * cannot locate it REFUSES with a named reason (see {@see diagnose()}): failing closed into
 * guidance is the only remaining prose path, and the postcondition report names the file and the
 * reason.
 */
final class PluginSurgeon
{
    /**
     * Why `$contents` cannot be structurally edited — `null` when it can (parseable PHP with one
     * locatable class body). The non-null string is the reason a generator's fallback guidance and
     * the postcondition report must NAME, e.g. `PHP parse error: …`, `no class declaration found`.
     */
    public function diagnose(string $contents): ?string
    {
        try {
            $tokens = $this->tokens($contents);
        } catch (\ParseError $e) {
            return 'PHP parse error: ' . $e->getMessage();
        }

        if ($this->classKeywordIndex($tokens) === null) {
            return 'no class declaration found';
        }
        if ($this->classBody($tokens) === null) {
            return 'unbalanced braces after the class declaration';
        }

        return null;
    }

    /** Whether the class in `$contents` declares a concrete (braced) method named `$method`. */
    public function hasMethod(string $contents, string $method): bool
    {
        try {
            $tokens = $this->tokens($contents);
        } catch (\ParseError) {
            return false;
        }
        $body = $this->classBody($tokens);

        return $body !== null && $this->methodSpan($tokens, $body, $method) !== null;
    }

    /**
     * Splices `$snippet` at the END of `$method`'s body — immediately before its closing brace,
     * each line re-indented one level deeper than that brace's own line, so the insertion reads as
     * hand-written code wherever the file put the method.
     *
     * @throws \RuntimeException When the file is unparseable, has no class, or has no `$method`.
     */
    public function insertIntoMethod(string $contents, string $method, string $snippet): string
    {
        $tokens = $this->tokensOrThrow($contents);
        $body = $this->classBodyOrThrow($tokens);
        $span = $this->methodSpan($tokens, $body, $method);
        if ($span === null) {
            throw new \RuntimeException("method {$method}() not found");
        }

        return $this->spliceBeforeOffset($contents, $span['close'], $snippet);
    }

    /**
     * Splices `$snippet` (one array entry per line, each ending in `,`) at the END of the literal
     * `return [ … ]` list inside `$method` — before its closing `]`, adding the missing trailing
     * comma to the previous entry when the hand-written list lacks one.
     *
     * @throws \RuntimeException When the method is missing, or returns anything other than a
     *                           literal `[…]` array — the fail-closed case the caller reports.
     */
    public function insertIntoReturnArray(string $contents, string $method, string $snippet): string
    {
        $tokens = $this->tokensOrThrow($contents);
        $body = $this->classBodyOrThrow($tokens);
        $span = $this->methodSpan($tokens, $body, $method);
        if ($span === null) {
            throw new \RuntimeException("method {$method}() not found");
        }

        $returnIndex = null;
        for ($i = $span['openIndex'] + 1; $i < $span['closeIndex']; $i++) {
            if ($tokens[$i][0] === T_RETURN) {
                $returnIndex = $i;
                break;
            }
        }
        if ($returnIndex === null) {
            throw new \RuntimeException("{$method}() has no return statement to insert into");
        }

        $openIndex = $this->nextMeaningful($tokens, $returnIndex, $span['closeIndex']);
        if ($openIndex === null || $tokens[$openIndex][0] !== '[') {
            throw new \RuntimeException("{$method}() does not return a literal array");
        }

        $closeIndex = null;
        $depth = 0;
        for ($i = $openIndex; $i <= $span['closeIndex']; $i++) {
            $id = $tokens[$i][0];
            if ($id === '[' || $id === T_ATTRIBUTE) {
                $depth++;
            } elseif ($id === ']') {
                $depth--;
                if ($depth === 0) {
                    $closeIndex = $i;
                    break;
                }
            }
        }
        if ($closeIndex === null) {
            throw new \RuntimeException("{$method}()'s return array has unbalanced brackets");
        }

        // A hand-written list whose last entry has no trailing comma would turn the splice into a
        // syntax error — add the comma first, then account for the one-byte shift.
        $closeOffset = $tokens[$closeIndex][2];
        $lastIndex = $this->lastMeaningful($tokens, $openIndex, $closeIndex);
        if ($lastIndex !== null && $tokens[$lastIndex][0] !== ',') {
            $commaAt = $tokens[$lastIndex][2] + \strlen($tokens[$lastIndex][1]);
            $contents = substr($contents, 0, $commaAt) . ',' . substr($contents, $commaAt);
            $closeOffset++;
        }

        return $this->spliceBeforeOffset($contents, $closeOffset, $snippet);
    }

    /**
     * Appends a complete `$methodSource` (as {@see wrapMethod()} renders it) at the END of the class
     * body — before the class's closing brace, indented one level in, separated by a blank line from
     * the previous member.
     *
     * @throws \RuntimeException When the file is unparseable or carries no locatable class body.
     */
    public function appendMethod(string $contents, string $methodSource): string
    {
        $tokens = $this->tokensOrThrow($contents);
        $body = $this->classBodyOrThrow($tokens);

        $closeOffset = $tokens[$body['close']][2];
        $lineStart = strrpos(substr($contents, 0, $closeOffset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $prefix = substr($contents, $lineStart, $closeOffset - $lineStart);
        preg_match('/^[ \t]*/', $prefix, $m);
        $indent = $m[0];

        $block = $this->indentBlock($methodSource, $indent . '    ');

        // No blank separator when the class body is empty (its opening brace is the previous line).
        $before = substr($contents, 0, $lineStart);
        $separator = str_ends_with(rtrim($before, " \t"), "{\n") ? '' : "\n";

        return $before . $separator . $block . "\n" . substr($contents, $lineStart);
    }

    /**
     * Adds `implements \$interfaceFqcn` to the class declaration (or appends it to an existing
     * `implements` list) so an appended method is actually reached by the kernel — a `routes()` on a
     * class that never declares `RouteProviderInterface` would be dead code wearing a wiring.
     * Idempotent: when the declaration already mentions the interface (short name or FQCN), the
     * contents come back unchanged.
     *
     * @throws \RuntimeException When the file is unparseable or carries no class declaration.
     */
    public function ensureImplements(string $contents, string $interfaceFqcn): string
    {
        $tokens = $this->tokensOrThrow($contents);
        $classIndex = $this->classKeywordIndex($tokens);
        if ($classIndex === null) {
            throw new \RuntimeException('no class declaration found');
        }
        $body = $this->classBodyOrThrow($tokens);

        $spanStart = $tokens[$classIndex][2];
        $spanEnd = $tokens[$body['open']][2];
        $span = substr($contents, $spanStart, $spanEnd - $spanStart);

        $fqcn = ltrim($interfaceFqcn, '\\');
        $slash = strrpos($fqcn, '\\');
        $short = $slash === false ? $fqcn : substr($fqcn, $slash + 1);
        if (str_contains($span, $short)) {
            return $contents;
        }

        $hasImplements = false;
        for ($i = $classIndex; $i < $body['open']; $i++) {
            if ($tokens[$i][0] === T_IMPLEMENTS) {
                $hasImplements = true;
                break;
            }
        }

        $lastIndex = $this->lastMeaningful($tokens, $classIndex, $body['open']);
        if ($lastIndex === null) {
            throw new \RuntimeException('malformed class declaration');
        }
        $insertAt = $tokens[$lastIndex][2] + \strlen($tokens[$lastIndex][1]);
        $addition = ($hasImplements ? ', ' : ' implements ') . '\\' . $fqcn;

        return substr($contents, 0, $insertAt) . $addition . substr($contents, $insertAt);
    }

    /**
     * Renders a complete method source — `$signature` plus a braced `$body` indented one level — in
     * the shape {@see appendMethod()} expects (no outer indentation; that is applied at splice time).
     */
    public function wrapMethod(string $signature, string $body): string
    {
        return $signature . "\n{\n" . $this->indentBlock($body, '    ') . "\n}";
    }

    /**
     * Tokenizes `$contents` into `[id-or-char, text, byte-offset]` triples.
     *
     * @return list<array{0: int|string, 1: string, 2: int}>
     *
     * @throws \ParseError When `$contents` is not lexable as PHP.
     */
    private function tokens(string $contents): array
    {
        $raw = token_get_all($contents, TOKEN_PARSE);
        $out = [];
        $offset = 0;
        foreach ($raw as $token) {
            [$id, $text] = \is_array($token) ? [$token[0], $token[1]] : [$token, $token];
            $out[] = [$id, $text, $offset];
            $offset += \strlen($text);
        }

        return $out;
    }

    /**
     * {@see tokens()}, with the lexer failure translated into the {@see \RuntimeException} shape the
     * public operations throw.
     *
     * @return list<array{0: int|string, 1: string, 2: int}>
     */
    private function tokensOrThrow(string $contents): array
    {
        try {
            return $this->tokens($contents);
        } catch (\ParseError $e) {
            throw new \RuntimeException('PHP parse error: ' . $e->getMessage());
        }
    }

    /**
     * The index of the `class` keyword that opens the file's class declaration — skipping the
     * `::class` constant and anonymous `new class` expressions, which reuse the same token.
     *
     * @param list<array{0: int|string, 1: string, 2: int}> $tokens
     */
    private function classKeywordIndex(array $tokens): ?int
    {
        foreach ($tokens as $i => $token) {
            if ($token[0] !== T_CLASS) {
                continue;
            }
            $previous = $this->lastMeaningful($tokens, -1, $i);
            if ($previous !== null && \in_array($tokens[$previous][0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * The token indexes of the class body's opening and closing braces, or `null` when they cannot
     * be matched. Interpolation braces (`T_CURLY_OPEN`, `T_DOLLAR_OPEN_CURLY_BRACES`) close with a
     * raw `}` token, so they count toward depth like any block.
     *
     * @param list<array{0: int|string, 1: string, 2: int}> $tokens
     *
     * @return array{open: int, close: int}|null
     */
    private function classBody(array $tokens): ?array
    {
        $classIndex = $this->classKeywordIndex($tokens);
        if ($classIndex === null) {
            return null;
        }

        $open = null;
        $count = \count($tokens);
        for ($i = $classIndex + 1; $i < $count; $i++) {
            if ($tokens[$i][0] === '{') {
                $open = $i;
                break;
            }
        }
        if ($open === null) {
            return null;
        }

        $depth = 0;
        for ($i = $open; $i < $count; $i++) {
            $id = $tokens[$i][0];
            if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($id === '}') {
                $depth--;
                if ($depth === 0) {
                    return ['open' => $open, 'close' => $i];
                }
            }
        }

        return null;
    }

    /**
     * {@see classBody()}, throwing the named reason instead of returning `null`.
     *
     * @param list<array{0: int|string, 1: string, 2: int}> $tokens
     *
     * @return array{open: int, close: int}
     */
    private function classBodyOrThrow(array $tokens): array
    {
        if ($this->classKeywordIndex($tokens) === null) {
            throw new \RuntimeException('no class declaration found');
        }
        $body = $this->classBody($tokens);
        if ($body === null) {
            throw new \RuntimeException('unbalanced braces after the class declaration');
        }

        return $body;
    }

    /**
     * The body braces of the class-level method `$method` — both as token indexes and byte offsets —
     * or `null` when the class declares no concrete method by that name. Methods of nested closures
     * sit at depth > 0 inside the class body and are never matched.
     *
     * @param list<array{0: int|string, 1: string, 2: int}> $tokens
     * @param array{open: int, close: int}                  $body
     *
     * @return array{openIndex: int, closeIndex: int, open: int, close: int}|null
     */
    private function methodSpan(array $tokens, array $body, string $method): ?array
    {
        $depth = 0;
        for ($i = $body['open'] + 1; $i < $body['close']; $i++) {
            $id = $tokens[$i][0];
            if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                continue;
            }
            if ($id === '}') {
                $depth--;
                continue;
            }
            if ($depth !== 0 || $id !== T_FUNCTION) {
                continue;
            }

            $nameIndex = $this->nextMeaningful($tokens, $i, $body['close']);
            if ($nameIndex === null || $tokens[$nameIndex][0] !== T_STRING || strcasecmp($tokens[$nameIndex][1], $method) !== 0) {
                continue;
            }

            $open = null;
            for ($j = $nameIndex + 1; $j < $body['close']; $j++) {
                if ($tokens[$j][0] === ';') {
                    continue 2;
                }
                if ($tokens[$j][0] === '{') {
                    $open = $j;
                    break;
                }
            }
            if ($open === null) {
                return null;
            }

            $innerDepth = 0;
            $count = \count($tokens);
            for ($k = $open; $k < $count; $k++) {
                $id = $tokens[$k][0];
                if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $innerDepth++;
                } elseif ($id === '}') {
                    $innerDepth--;
                    if ($innerDepth === 0) {
                        return [
                            'openIndex' => $open,
                            'closeIndex' => $k,
                            'open' => $tokens[$open][2],
                            'close' => $tokens[$k][2],
                        ];
                    }
                }
            }

            return null;
        }

        return null;
    }

    /**
     * The index of the first non-whitespace, non-comment token after `$after` (exclusive), up to
     * `$before` (exclusive).
     *
     * @param list<array{0: int|string, 1: string, 2: int}> $tokens
     */
    private function nextMeaningful(array $tokens, int $after, int $before): ?int
    {
        for ($i = $after + 1; $i < $before; $i++) {
            if (!\in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The index of the last non-whitespace, non-comment token between `$after` and `$before`
     * (both exclusive).
     *
     * @param list<array{0: int|string, 1: string, 2: int}> $tokens
     */
    private function lastMeaningful(array $tokens, int $after, int $before): ?int
    {
        for ($i = $before - 1; $i > $after; $i--) {
            if (!\in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Splices `$snippet` immediately before the delimiter at `$closeOffset`, re-indented one level
     * deeper than the delimiter's line. A delimiter sharing its line with code (a one-line method
     * body, a single-line `return [];`) is broken onto its own line first, so the result reads as
     * conventionally formatted code either way.
     */
    private function spliceBeforeOffset(string $contents, int $closeOffset, string $snippet): string
    {
        $lineStart = strrpos(substr($contents, 0, $closeOffset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $prefix = substr($contents, $lineStart, $closeOffset - $lineStart);
        preg_match('/^[ \t]*/', $prefix, $m);
        $indent = $m[0];

        $block = $this->indentBlock($snippet, $indent . '    ');

        if (trim($prefix) === '') {
            return substr($contents, 0, $lineStart) . $block . "\n" . substr($contents, $lineStart);
        }

        return substr($contents, 0, $closeOffset) . "\n" . $block . "\n" . $indent . substr($contents, $closeOffset);
    }

    /** Indents every non-empty line of `$text` with `$indent`; trailing newlines are trimmed first. */
    private function indentBlock(string $text, string $indent): string
    {
        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : $indent . $line,
            explode("\n", rtrim($text, "\n")),
        ));
    }
}
