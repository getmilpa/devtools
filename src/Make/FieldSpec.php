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
 * A single field parsed from the `--fields` DSL: its PHP type, Doctrine column type, nullability,
 * and any modifiers (length / precision+scale) or relation-enum target. Consumed by the generators
 * to emit properties, columns and accessors.
 */
final class FieldSpec
{
    /**
     * @param 'scalar'|'enum'|'belongsTo' $kind
     * @param array<string, int>          $modifiers
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly string $phpType,
        public readonly string $columnType,
        public readonly bool $nullable = false,
        public readonly array $modifiers = [],
        public readonly ?string $target = null,
    ) {
    }
}
