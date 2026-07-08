<?php

declare(strict_types=1);

namespace Milpa\DevTools\Make;

/** Everything a generator needs: the target plugin, the artifact name, CLI options, and repo root. */
final class GenerationContext
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly string $plugin,
        public readonly string $name,
        public readonly array $options,
        public readonly string $root,
    ) {
    }

    /** Reads a CLI `--option` value; `null` when absent or not a string. */
    public function option(string $key): ?string
    {
        $value = $this->options[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
