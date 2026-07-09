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

    /**
     * Reads a boolean-ish CLI `--flag` (e.g. `--force`, used to re-run a
     * {@see \Milpa\DevTools\Make\MarkerInserter} insertion that already landed once): a native
     * `bool` is returned as-is, a string is truthy unless it is `''`, `'0'`, or `'false'`
     * (case-insensitive) — mirrors {@see \Milpa\DevTools\Make\Generators\ServiceGenerator}'s
     * pre-existing `--interface` parsing exactly. Absent or any other type reads as `false`.
     */
    public function flag(string $key): bool
    {
        $value = $this->options[$key] ?? false;

        if (\is_bool($value)) {
            return $value;
        }
        if (\is_string($value)) {
            return !\in_array(strtolower($value), ['', '0', 'false'], true);
        }

        return (bool) $value;
    }
}
