<?php

/**
 * This file is part of Milpa DevTools — the developer toolbox of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Operations;

use Milpa\DevTools\Support\RootResolver;

/**
 * Edit a scaffolded class by exact find→replace pairs — the shape a measurement asked for.
 *
 * ── WHY PAIRS, AND WHY EXACTLY-ONCE ──────────────────────────────────────────────────────────────
 *
 * The first real session against the conformance gate (implement-first-landing.tsv, corrida 2)
 * showed WHERE the model's priors leak: re-generating the whole file. Six landings were refused and
 * the sixth regressed to the first one's defect — every full re-generation is a fresh chance to
 * reintroduce everything the diagnostics had already killed. A find→replace pair that must match
 * EXACTLY ONCE has no such surface: it touches the line it names, or it refuses. Ambiguity is
 * refused too — applying a pair that matches twice would edit a place the request never named.
 *
 * Not unified diffs, deliberately: line numbers and context hunks are where models miss; exact
 * substrings are mechanically verifiable and are the edit shape code models know best.
 *
 * ── ONE LANDING AUTHORITY ────────────────────────────────────────────────────────────────────────
 *
 * This class produces the candidate content and DELEGATES the entire landing gate — syntax, strict
 * types, class, namespace, static conformance, restore-on-failure — to {@see ImplementHandler}. A
 * second gate here would be a second translation of «what may land», and two of those diverge on
 * the case nobody tested.
 */
final class EditHandler
{
    private readonly ImplementHandler $lander;

    public function __construct(
        private readonly RootResolver $roots = new RootResolver(),
        ?ImplementHandler $lander = null,
    ) {
        // The lander shares THIS handler's root by default — two resolvers pointing at different
        // trees would apply the pairs in one app and land the result in another.
        $this->lander = $lander ?? new ImplementHandler($this->roots);
    }

    /**
     * Apply exactly-once find→replace pairs to one scaffolded class, then land through implement's gate.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function handle(array $input): array
    {
        $plugin = \is_string($input['plugin'] ?? null) ? trim($input['plugin']) : '';
        $class = \is_string($input['class'] ?? null) ? trim($input['class']) : '';
        $edits = \is_array($input['edits'] ?? null) ? $input['edits'] : [];

        if ($edits === []) {
            return ['ok' => false, 'error' => 'nothing to edit: `edits` is a list of {find, replace} pairs'];
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class) !== 1 || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $plugin) !== 1) {
            return ['ok' => false, 'error' => 'plugin and class are bare identifiers, no paths'];
        }

        $root = rtrim($this->roots->resolve(), '/');
        $file = $this->currentFile($root, $plugin, $class);
        if ($file === null) {
            return [
                'ok' => false,
                'error' => "no scaffold declares class «{$class}» in plugin «{$plugin}» — editing is not creating; scaffold it first with `make`",
            ];
        }

        // ── Apply every pair on the CURRENT content, refusing on absence or ambiguity ───────────
        $content = (string) file_get_contents($file);
        foreach ($edits as $i => $edit) {
            $find = \is_string($edit['find'] ?? null) ? $edit['find'] : '';
            $replace = \is_string($edit['replace'] ?? null) ? $edit['replace'] : '';
            if ($find === '') {
                return ['ok' => false, 'error' => 'edit #' . ($i + 1) . ' has an empty `find`'];
            }

            $times = substr_count($content, $find);
            if ($times === 0) {
                // The CURRENT file travels with the refusal. Measured on the first live run: both
                // rejected pairs were built against the file the model IMAGINED — one tried to find
                // the whole header of a file that was never landed. A find can only be exact
                // against ground truth, and the catalogue has no other way to read it.
                return [
                    'ok' => false,
                    'error' => 'edit #' . ($i + 1) . " matches nothing — this `find` does not appear in the file:\n{$find}\n"
                        . "The CURRENT file is:\n---\n{$content}\n---\nBuild your pairs against that text exactly.",
                ];
            }
            if ($times > 1) {
                return ['ok' => false, 'error' => 'edit #' . ($i + 1) . " is ambiguous: `find` appears {$times} times and must appear exactly once — widen it with surrounding lines"];
            }

            $content = str_replace($find, $replace, $content);
        }

        // ── One landing authority: the whole gate is implement's, delegated ──────────────────────
        $landed = $this->lander->handle(['plugin' => $plugin, 'class' => $class, 'content' => $content]);
        if (($landed['ok'] ?? false) !== true) {
            return $landed;
        }

        return [...$landed, 'edits_applied' => \count($edits)];
    }

    /** The one file inside the plugin's tree whose basename is the class — or null. */
    private function currentFile(string $root, string $plugin, string $class): ?string
    {
        $tree = $root . '/src/Plugins/' . $plugin;
        if (!is_dir($tree)) {
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tree, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->getFilename() === $class . '.php') {
                return $entry->getPathname();
            }
        }

        return null;
    }
}
