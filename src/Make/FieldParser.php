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
 * Parses the `--fields` DSL (`name:type[:mods]`, comma-separated; leading `?` = nullable) into a
 * list of {@see FieldSpec}. Supports scalars (+ length / precision,scale mods), `enum:<Enum>`, and
 * `<name>:belongsTo:<Target>` (ManyToOne). Throws on any unrecognised token.
 */
final class FieldParser
{
    /** @var array<string, array{0: string, 1: string}> DSL type => [php type, doctrine column] */
    private const SCALARS = [
        'string' => ['string', 'string'],
        'text' => ['string', 'text'],
        'int' => ['int', 'integer'],
        'bigint' => ['int', 'bigint'],
        'bool' => ['bool', 'boolean'],
        'float' => ['float', 'float'],
        'decimal' => ['string', 'decimal'],
        'datetime' => ['\DateTime', 'datetime'],
        'date' => ['\DateTime', 'date'],
        'json' => ['array', 'json'],
    ];

    /**
     * Parses the full `--fields` DSL string into a list of {@see FieldSpec}.
     *
     * @return list<FieldSpec>
     */
    public function parse(string $dsl): array
    {
        $dsl = trim($dsl);
        if ($dsl === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $this->splitTopLevel($dsl)) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            $out[] = $this->parseField($token);
        }

        return $out;
    }

    private function parseField(string $token): FieldSpec
    {
        $nullable = false;
        if (str_starts_with($token, '?')) {
            $nullable = true;
            $token = substr($token, 1);
        }

        $parts = explode(':', $token);
        $name = trim($parts[0]);
        $type = trim($parts[1] ?? '');

        if ($name === '' || $type === '') {
            throw new \InvalidArgumentException("malformed field '{$token}' (expected name:type)");
        }

        if ($type === 'enum') {
            $target = trim($parts[2] ?? '');
            if ($target === '') {
                throw new \InvalidArgumentException("enum field '{$name}' needs an enum name (name:enum:MyEnum)");
            }

            return new FieldSpec($name, 'enum', $target, 'string', $nullable, [], $target);
        }

        if ($type === 'belongsTo') {
            $target = trim($parts[2] ?? '');
            if ($target === '') {
                throw new \InvalidArgumentException("relation field '{$name}' needs a target (name:belongsTo:Target)");
            }

            return new FieldSpec($name, 'belongsTo', $target, '', $nullable, [], $target);
        }

        if (!isset(self::SCALARS[$type])) {
            throw new \InvalidArgumentException("unknown field type '{$type}' for field '{$name}'");
        }

        [$phpType, $columnType] = self::SCALARS[$type];
        $modifiers = $this->modifiers($type, $parts, $name);

        return new FieldSpec($name, 'scalar', $phpType, $columnType, $nullable, $modifiers);
    }

    /**
     * The decimal `precision,scale` modifier contains a comma; protect it before the top-level split
     * by temporarily encoding it. (Only decimal uses an inner comma in v1.)
     */
    private function splitTopLevel(string $dsl): string
    {
        return (string) preg_replace_callback(
            '/decimal:(\d+),(\d+)/',
            static fn (array $m): string => "decimal:{$m[1]}|{$m[2]}",
            $dsl,
        );
    }

    /**
     * @param list<string> $parts
     *
     * @return array<string, int>
     */
    private function modifiers(string $type, array $parts, string $name): array
    {
        $mod = trim($parts[2] ?? '');
        if ($mod === '') {
            return [];
        }

        if ($type === 'decimal') {
            if (preg_match('/^(\d+)\|(\d+)$/', $mod, $m) !== 1) {
                throw new \InvalidArgumentException("invalid decimal modifier '{$mod}' on '{$name}' (expected precision,scale)");
            }

            return ['precision' => (int) $m[1], 'scale' => (int) $m[2]];
        }

        if ($type === 'string') {
            if (!ctype_digit($mod)) {
                throw new \InvalidArgumentException("invalid length modifier '{$mod}' on '{$name}'");
            }

            return ['length' => (int) $mod];
        }

        throw new \InvalidArgumentException("type '{$type}' takes no modifier (got '{$mod}' on '{$name}')");
    }
}
