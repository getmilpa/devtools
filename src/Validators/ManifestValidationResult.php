<?php

declare(strict_types=1);

namespace Milpa\DevTools\Validators;

/** The outcome of a {@see PluginManifestValidator} run against one manifest. */
final class ManifestValidationResult
{
    /** @param list<string> $errors */
    public function __construct(
        public readonly string $path,
        public readonly array $errors,
    ) {
    }

    /** True when the manifest has no validation errors. */
    public function ok(): bool
    {
        return $this->errors === [];
    }
}
