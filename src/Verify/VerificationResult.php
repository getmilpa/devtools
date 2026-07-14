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

/**
 * The outcome of running a {@see ControllerVerifier} or {@see EntityVerifier} against one class:
 * a short subject label, the hard failures (`errors`), and the non-fatal advisories (`warnings`).
 * `ok` is true whenever there are no errors — warnings alone never fail a verify run, matching the
 * original `scripts/verify-*.php` exit-code contract (exit 0 on warnings-only).
 */
final class VerificationResult
{
    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $subject,
        public readonly array $errors,
        public readonly array $warnings = [],
    ) {
    }

    /** True when there are no {@see self::$errors} — warnings alone never fail a verify run. */
    public function ok(): bool
    {
        return $this->errors === [];
    }

    /** Human-readable one-banner-plus-bullets report, in the spirit of the original CLI scripts. */
    public function render(): string
    {
        if ($this->ok() && $this->warnings === []) {
            return "PASS: {$this->subject} — 0 errors";
        }

        $lines = [];
        if ($this->ok()) {
            $lines[] = "WARN: {$this->subject} — 0 errors, " . count($this->warnings) . ' warning(s)';
        } else {
            $lines[] = "FAIL: {$this->subject} — " . count($this->errors) . ' error(s)';
        }
        foreach ($this->errors as $error) {
            $lines[] = "   x {$error}";
        }
        foreach ($this->warnings as $warning) {
            $lines[] = "   ~ {$warning}";
        }

        return implode("\n", $lines);
    }
}
