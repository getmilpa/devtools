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

namespace Milpa\DevTools\Verify;

/** A convention verifier: reflects a live, autoloaded class and reports how it deviates. */
interface VerifierInterface
{
    /**
     * Reflects the already-autoloaded `$fqcn` and reports how it deviates from the convention.
     *
     * @param class-string $fqcn
     */
    public function verify(string $fqcn): VerificationResult;
}
