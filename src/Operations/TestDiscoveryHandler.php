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

namespace Milpa\DevTools\Operations;

use Milpa\DevTools\Support\RootResolver;

/**
 * Discovers PHPUnit test classes and their assertions statically, without booting or running a suite.
 *
 * PHPUnit's list mode still boots configuration and application code. This handler instead tokenizes
 * files under `tests/`, which makes discovery a read operation and keeps top-level fixture code inert.
 */
final class TestDiscoveryHandler
{
    public function __construct(private readonly RootResolver $roots = new RootResolver())
    {
    }

    /**
     * Lists test classes, optionally narrowed by their plugin, artifact, or behavioral criterion.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, tests: list<array{name: string, fqcn: string, plugin: string|null, artifact: string, path: string, criteria: list<string>}>, error?: string}
     */
    public function handleList(array $input): array
    {
        $plugin = $this->inputString($input, 'plugin');
        $artifact = $this->inputString($input, 'artifact');
        $criterion = $this->inputString($input, 'criterion');

        $matches = array_values(array_filter(
            $this->discover($this->roots->resolve()),
            function (array $test) use ($plugin, $artifact, $criterion): bool {
                if ($plugin !== '' && strcasecmp((string) $test['plugin'], $plugin) !== 0) {
                    return false;
                }
                if ($artifact !== '' && strcasecmp($test['artifact'], $artifact) !== 0) {
                    return false;
                }
                if ($criterion === '') {
                    return true;
                }

                $needle = strtolower($criterion);
                foreach ($test['methods'] as $method) {
                    $haystack = strtolower($method['name'] . ' ' . $method['criterion'] . ' ' . implode(' ', $method['assertions']));
                    if (str_contains($haystack, $needle)) {
                        return true;
                    }
                }

                return false;
            },
        ));

        if ($matches === []) {
            return ['ok' => false, 'tests' => [], 'error' => 'no tests matched the requested plugin, artifact, and criterion'];
        }

        $tests = [];
        foreach ($matches as $test) {
            $tests[] = [
                'name' => $test['name'],
                'fqcn' => $test['fqcn'],
                'plugin' => $test['plugin'],
                'artifact' => $test['artifact'],
                'path' => $test['path'],
                'criteria' => array_column($test['methods'], 'criterion'),
            ];
        }

        return ['ok' => true, 'tests' => $tests];
    }

    /**
     * Shows one test class with each test method's criterion and static assertion calls.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, test?: array{name: string, fqcn: string, plugin: string|null, artifact: string, path: string, methods: list<array{name: string, criterion: string, assertions: list<string>}>}, error?: string, matches?: list<string>}
     */
    public function handleShow(array $input): array
    {
        $name = $this->inputString($input, 'name');
        if ($name === '') {
            return ['ok' => false, 'error' => 'name the test class: test:show requires `name`'];
        }
        $plugin = $this->inputString($input, 'plugin');

        $matches = array_values(array_filter(
            $this->discover($this->roots->resolve()),
            static fn (array $test): bool => (strcasecmp($test['name'], $name) === 0 || strcasecmp($test['fqcn'], $name) === 0)
                && ($plugin === '' || strcasecmp((string) $test['plugin'], $plugin) === 0),
        ));

        if ($matches === []) {
            return ['ok' => false, 'error' => "no test «{$name}»" . ($plugin !== '' ? " in plugin «{$plugin}»" : '')];
        }
        if (\count($matches) > 1) {
            return [
                'ok' => false,
                'error' => "test «{$name}» is ambiguous — add `plugin` or use its FQCN",
                'matches' => array_column($matches, 'fqcn'),
            ];
        }

        return ['ok' => true, 'test' => $matches[0]];
    }

    /**
     * Reads a trimmed string input, treating every other JSON type as absent.
     *
     * @param array<string, mixed> $input
     */
    private function inputString(array $input, string $key): string
    {
        return \is_string($input[$key] ?? null) ? trim($input[$key]) : '';
    }

    /**
     * Finds test-bearing classes under the host's conventional `tests/` directory.
     *
     * @return list<array{name: string, fqcn: string, plugin: string|null, artifact: string, path: string, methods: list<array{name: string, criterion: string, assertions: list<string>}>}>
     */
    private function discover(string $root): array
    {
        $testsDir = $root . '/tests';
        if (!is_dir($testsDir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($testsDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        $tests = [];
        foreach ($files as $file) {
            array_push($tests, ...$this->inspectFile($file, $root));
        }
        usort($tests, static fn (array $left, array $right): int => $left['fqcn'] <=> $right['fqcn']);

        return $tests;
    }

    /**
     * Extracts named classes and their own PHPUnit test methods from one file's tokens.
     *
     * @return list<array{name: string, fqcn: string, plugin: string|null, artifact: string, path: string, methods: list<array{name: string, criterion: string, assertions: list<string>}>}>
     */
    private function inspectFile(string $file, string $root): array
    {
        $source = file_get_contents($file);
        if ($source === false) {
            return [];
        }

        $tokens = token_get_all($source);
        $namespace = '';
        $tests = [];
        $count = \count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (\is_array($token) && $token[0] === T_NAMESPACE) {
                $namespace = '';
                for (++$index; $index < $count; ++$index) {
                    $part = $tokens[$index];
                    if ($part === ';' || $part === '{') {
                        break;
                    }
                    if (\is_array($part) && \in_array($part[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $namespace .= $part[1];
                    }
                }
                continue;
            }

            if (!\is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }
            $nameIndex = $this->nextSignificant($tokens, $index + 1);
            $nameToken = $nameIndex !== null ? $tokens[$nameIndex] : null;
            if (!\is_array($nameToken) || $nameToken[0] !== T_STRING) {
                continue;
            }

            $open = $this->nextToken($tokens, $nameIndex + 1, '{');
            $close = $open !== null ? $this->matchingBrace($tokens, $open) : null;
            if ($open === null || $close === null) {
                continue;
            }

            $methods = $this->testMethods($tokens, $open, $close);
            if ($methods !== []) {
                $name = $nameToken[1];
                $path = $this->relativePath($file, $root);
                $tests[] = [
                    'name' => $name,
                    'fqcn' => $namespace !== '' ? $namespace . '\\' . $name : $name,
                    'plugin' => preg_match('#^tests/Plugins/([^/]+)/#', $path, $match) === 1 ? $match[1] : null,
                    'artifact' => str_ends_with($name, 'Test') ? substr($name, 0, -4) : $name,
                    'path' => $path,
                    'methods' => $methods,
                ];
            }
            $index = $close;
        }

        return $tests;
    }

    /**
     * Finds public `test*`, `@test`, and `#[Test]` methods at the class's own brace depth.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens
     *
     * @return list<array{name: string, criterion: string, assertions: list<string>}>
     */
    private function testMethods(array $tokens, int $open, int $close): array
    {
        $methods = [];
        $doc = null;
        $hasTestAttribute = false;
        $isPublic = true;

        for ($index = $open + 1; $index < $close; ++$index) {
            $token = $tokens[$index];
            if (\is_array($token) && $token[0] === T_DOC_COMMENT) {
                $doc = $token[1];
                continue;
            }
            if (\is_array($token) && $token[0] === T_ATTRIBUTE) {
                [$attributes, $attributeEnd] = $this->attribute($tokens, $index);
                foreach ($attributes as $attribute) {
                    $parts = explode('\\', ltrim($attribute, '\\'));
                    $hasTestAttribute = $hasTestAttribute || end($parts) === 'Test';
                }
                $index = $attributeEnd;
                continue;
            }
            if (\is_array($token) && \in_array($token[0], [T_PRIVATE, T_PROTECTED], true)) {
                $isPublic = false;
                continue;
            }
            if (\is_array($token) && $token[0] === T_PUBLIC) {
                $isPublic = true;
                continue;
            }
            if (!\is_array($token) || $token[0] !== T_FUNCTION) {
                if ($token === ';') {
                    $doc = null;
                    $hasTestAttribute = false;
                    $isPublic = true;
                }
                continue;
            }

            $nameIndex = $this->nextSignificant($tokens, $index + 1);
            $nameToken = $nameIndex !== null ? $tokens[$nameIndex] : null;
            if (!\is_array($nameToken) || $nameToken[0] !== T_STRING) {
                continue;
            }

            $methodOpen = $this->nextToken($tokens, $nameIndex + 1, '{', ';');
            $methodClose = $methodOpen !== null && $tokens[$methodOpen] === '{'
                ? $this->matchingBrace($tokens, $methodOpen)
                : $methodOpen;
            if ($methodOpen === null || $methodClose === null) {
                continue;
            }

            $name = $nameToken[1];
            $isTest = $isPublic && (
                str_starts_with(strtolower($name), 'test')
                || $hasTestAttribute
                || ($doc !== null && preg_match('/@test\b/i', $doc) === 1)
            );
            if ($isTest) {
                $methods[] = [
                    'name' => $name,
                    'criterion' => $this->criterion($name, $doc),
                    'assertions' => $tokens[$methodOpen] === '{'
                        ? $this->assertions($tokens, $methodOpen + 1, $methodClose)
                        : [],
                ];
            }

            $doc = null;
            $hasTestAttribute = false;
            $isPublic = true;
            $index = $methodClose;
        }

        return $methods;
    }

    /**
     * Collects the names in an attribute group, excluding names and strings inside its arguments.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens
     *
     * @return array{0: list<string>, 1: int}
     */
    private function attribute(array $tokens, int $start): array
    {
        $names = [];
        $bracketDepth = 0;
        $parenthesisDepth = 0;
        $expectsName = true;
        $count = \count($tokens);
        for ($index = $start; $index < $count; ++$index) {
            $token = $tokens[$index];
            $part = \is_array($token) ? $token[1] : $token;

            if ($expectsName && \is_array($token)
                && \in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                $names[] = $part;
                $expectsName = false;
            }

            $bracketDepth += substr_count($part, '[') - substr_count($part, ']');
            $parenthesisDepth += substr_count($part, '(') - substr_count($part, ')');
            if ($part === ',' && $bracketDepth === 1 && $parenthesisDepth === 0) {
                $expectsName = true;
            }
            if ($bracketDepth === 0) {
                return [$names, $index];
            }
        }

        return [$names, $count - 1];
    }

    /**
     * Lists PHPUnit assertion and expectation calls in source order, without evaluating arguments.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens
     *
     * @return list<string>
     */
    private function assertions(array $tokens, int $from, int $to): array
    {
        $assertions = [];
        for ($index = $from; $index < $to; ++$index) {
            $token = $tokens[$index];
            if (!\is_array($token) || $token[0] !== T_STRING) {
                continue;
            }
            if (preg_match('/^(?:assert|expect)/i', $token[1]) !== 1 && strcasecmp($token[1], 'fail') !== 0) {
                continue;
            }
            $before = $this->previousSignificant($tokens, $index - 1);
            $after = $this->nextSignificant($tokens, $index + 1);
            $beforeToken = $before !== null ? $tokens[$before] : null;
            if (!\is_array($beforeToken)
                || !\in_array($beforeToken[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
                || $after === null
                || $tokens[$after] !== '(') {
                continue;
            }
            if (!\in_array($token[1], $assertions, true)) {
                $assertions[] = $token[1];
            }
        }

        return $assertions;
    }

    /** Uses the method's summary when present, otherwise turns its identifier into readable words. */
    private function criterion(string $method, ?string $doc): string
    {
        if ($doc !== null) {
            foreach (preg_split('/\R/', $doc) ?: [] as $line) {
                $summary = trim($line, " \t/*");
                if ($summary !== '' && !str_starts_with($summary, '@')) {
                    return $summary;
                }
            }
        }

        $criterion = preg_replace('/^test_?/i', '', $method) ?? $method;
        $criterion = str_replace('_', ' ', $criterion);

        return preg_replace('/(?<!^)([A-Z])/', ' $1', $criterion) ?? $criterion;
    }

    /**
     * Finds the next token that is not whitespace or a comment.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens
     */
    private function nextSignificant(array $tokens, int $from): ?int
    {
        for ($index = $from, $count = \count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!\is_array($token) || !\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Finds the previous token that is not whitespace or a comment.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens
     */
    private function previousSignificant(array $tokens, int $from): ?int
    {
        for ($index = $from; $index >= 0; --$index) {
            $token = $tokens[$index];
            if (!\is_array($token) || !\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Finds the next requested punctuation token.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens
     */
    private function nextToken(array $tokens, int $from, string ...$wanted): ?int
    {
        for ($index = $from, $count = \count($tokens); $index < $count; ++$index) {
            if (\is_string($tokens[$index]) && \in_array($tokens[$index], $wanted, true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Finds the closing brace paired with an opening brace token.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens
     */
    private function matchingBrace(array $tokens, int $open): ?int
    {
        $depth = 0;
        for ($index = $open, $count = \count($tokens); $index < $count; ++$index) {
            if ($tokens[$index] === '{') {
                ++$depth;
            } elseif (\is_array($tokens[$index])
                && \in_array($tokens[$index][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                ++$depth;
            } elseif ($tokens[$index] === '}' && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    /** Returns a stable path relative to the host app root. */
    private function relativePath(string $path, string $root): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';

        return str_starts_with($path, $prefix) ? substr($path, \strlen($prefix)) : $path;
    }
}
