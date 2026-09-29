<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Fixtures;

/**
 * The runtime entity methods on the wrong side of `static` (it cannot implement EntityInterface that way: PHP refuses the class) — {@see \Milpa\DevTools\Tests\Verify\EntityVerifierPitfallsTest}.
 */
final class RuntimeEntityWrongStatics
{
    public static function id(): int|string|null
    {
        return null;
    }

    public function toArray(): array
    {
        return [];
    }

    public function fromArray(array $data): static
    {
        return $this;
    }
}
