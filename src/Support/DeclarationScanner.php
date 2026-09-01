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

namespace Milpa\DevTools\Support;

/**
 * Reads the class, interface, and enum declarations out of one PHP file by tokenizing it — never by
 * requiring it — so asking «what does this file declare» cannot run top-level code or static
 * initializers. Shared by every read-only introspection operation that inventories source it must
 * not execute: {@see \Milpa\DevTools\Operations\ArtifactListHandler},
 * {@see \Milpa\DevTools\Operations\ContractSearchHandler}, and
 * {@see \Milpa\DevTools\Operations\PackageArtifactsHandler}.
 */
final class DeclarationScanner
{
    /**
     * The named type declarations in the file: each one's short name, FQCN (from the file's own
     * `namespace` statement), and kind (`class`, `interface`, or `enum`). A file that cannot be read
     * scans as declaring nothing.
     *
     * @return list<array{name: string, fqcn: string, kind: string}>
     */
    public static function scan(string $file): array
    {
        $source = file_get_contents($file);
        if ($source === false) {
            return [];
        }

        $tokens = token_get_all($source);
        $namespace = '';
        $declarations = [];
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

            if (!\is_array($token) || !\in_array($token[0], [T_CLASS, T_INTERFACE, T_ENUM], true)) {
                continue;
            }

            $nameIndex = self::nextSignificant($tokens, $index + 1);
            $nameToken = $nameIndex !== null ? $tokens[$nameIndex] : null;
            if (!\is_array($nameToken) || $nameToken[0] !== T_STRING) {
                continue;
            }

            $name = $nameToken[1];
            $declarations[] = [
                'name' => $name,
                'fqcn' => $namespace !== '' ? $namespace . '\\' . $name : $name,
                'kind' => match ($token[0]) {
                    T_INTERFACE => 'interface',
                    T_ENUM => 'enum',
                    default => 'class',
                },
            ];
        }

        return $declarations;
    }

    /**
     * Finds the next token that is not whitespace or a comment.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens
     */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        for ($index = $from, $count = \count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!\is_array($token) || !\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
    }
}
