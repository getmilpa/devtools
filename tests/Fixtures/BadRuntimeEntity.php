<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Fixtures;

/**
 * Deliberately does NOT implement {@see \Milpa\Data\EntityInterface} and is missing
 * `toArray()`/`fromArray()` entirely — {@see \Milpa\DevTools\Tests\Verify\EntityVerifierTest}
 * asserts {@see \Milpa\DevTools\Verify\EntityVerifier} catches each violation under
 * {@see \Milpa\DevTools\Make\Flavor::Runtime}.
 */
final class BadRuntimeEntity
{
    public function id(): int|string|null
    {
        return null;
    }
}
