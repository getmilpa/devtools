<?php

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
