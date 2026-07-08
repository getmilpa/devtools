<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Fixtures;

/** Real, autoloadable interface + implementation pair for {@see ProviderImplementsValidatorTest}. */
interface SampleContractInterface
{
    public function ping(): string;
}

final class SampleContractImplementation implements SampleContractInterface
{
    public function ping(): string
    {
        return 'pong';
    }
}

final class SampleContractNonImplementation
{
}
