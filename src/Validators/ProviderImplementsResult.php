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

namespace Milpa\DevTools\Validators;

/** The outcome of a {@see ProviderImplementsValidator} run. */
final class ProviderImplementsResult
{
    /** @param list<string> $violations */
    public function __construct(
        public readonly int $checked,
        public readonly array $violations,
    ) {
    }

    /** True when every checked provider actually autoloads and implements its declared interface. */
    public function ok(): bool
    {
        return $this->violations === [];
    }
}
