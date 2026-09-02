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
 * Write the body of a class that `make` already scaffolded — through a gate, not around it.
 *
 * ── WHY THE UNIT IS A CLASS AND NOT A FILE PATH ──────────────────────────────────────────────────
 *
 * A generic file write is the ungovernable extreme: its target is a path nobody's request names,
 * the intent contract has nothing to compare, and its blast radius is the filesystem. A CLASS in a
 * plugin this app scaffolded is the governable unit: the target is a name the human's request can
 * carry (the intent contract applies, ADR-0044), and the path is DERIVED — searching only inside
 * `src/Plugins/<plugin>/` — so escaping the tree is impossible by construction, not by discipline.
 *
 * ── LANDING IS A POSTCONDITION, NOT A HOPE ───────────────────────────────────────────────────────
 *
 * Measured on three real sessions (long-session-three-arms.tsv): everything the catalogue cannot
 * express, the agent fakes with structure that LOOKS like the task. Raising the ceiling without a
 * gate would trade unfilled shells for broken files. So the content verifies BEFORE it lands — syntax
 * (`php -l` on a temp copy), strict types, the class it claims, the namespace its location
 * dictates — and on any failure the original survives byte for byte and the diagnostic travels
 * back, which is what a model corrects from.
 *
 * ── STATIC CONFORMANCE, MEASURED BEFORE IT WAS ADDED ─────────────────────────────────────────────
 *
 * The first real run (implement-first-landing.tsv) landed clean four times and still fataled three:
 * invented collaborators and a narrowed interface signature — linkage defects `php -l` cannot see.
 * Replayed against PHPStan level 0 with the app's autoloader, BOTH classes of failure were named
 * surgically in ~1s and the good landing passed in 0.8s. So when the app ships an analyzer, the
 * gate uses it: the candidate is analyzed IN PLACE — context needs the app's autoloader — and on
 * any finding the original is restored byte for byte. Level 0 on purpose: this gate rules on
 * linkage, not style; the app's own level belongs to its suite.
 *
 * ── THE BEHAVIORAL JUDGE: THE CLASS'S OWN TEST, INSIDE THE GATE ──────────────────────────────────
 *
 * Measured on a real session: a service landed conformant — syntax, interface, namespace clean —
 * while its solicitar() SIMULATED persistence in a comment. Linkage had a judge and the app suite
 * had one; behavior had none. The judge is never an LLM reading code (the substitute certificate
 * P-0001 took seven generations to kill) and never the agent grading itself (the prior that faked
 * the behavior would grade its fake): it is the test that already declares what the class must DO
 * — `tests/Plugins/<plugin>/<Class>Test.php` — executed as one more landing postcondition, red
 * restoring byte for byte. Without a test the landing still lands — Q-P19-R measured that
 * OBLIGATING a criterion made everything worse — but the result SAYS the behavior went unjudged: a
 * silent gap reads as covered, and that is how a simulated persistence ships wearing green.
 *
 * Running the class's test EXECUTES the written code, in a subprocess — the same risk class as the
 * `test` operation, accepted for the same reason and said here. Fuller isolation is ADR-0045's.
 *
 * ── THE CAP: THE OLD CONTRACT WAS BREAKING ITS OWN CALLER ────────────────────────────────────────
 *
 * Measured twice on the greenhouse fixture series (runs 10-11): a model writing a WHOLE PHP file
 * inline as one JSON string argument broke its own tool-call JSON with high per-attempt probability
 * at size — «Failed to parse tool call arguments as JSON … missing closing quote» at column 5,389
 * and at column 15,248 — and a gateway-side retry did not absorb the double flake. The root is the
 * contract: it INVITED unbounded inline bodies. So the fix is architecture, not a nudge: content
 * over MAX_INLINE_CHARS is refused before any write, and the refusal teaches the piece-wise door —
 * `mode=start` writes the file header and first section, `mode=append` each next section (verbatim:
 * the caller owns the bytes), `mode=finish` verifies and judges the assembled file through the SAME
 * gate a single-shot passes. A partial file is not valid PHP, so start and append verify NOTHING
 * and their results say so plainly — no green is claimable until finish.
 */
final class ImplementHandler
{
    /**
     * The inline-content ceiling, in characters.
     *
     * Calibrated under both measured breaks: the tool-call JSON snapped at column 5,389 on one run
     * and at column 15,248 on another (greenhouse fixture series, runs 10-11). Anything over this
     * is refused toward the parts door, whose sections each stay under it.
     */
    public const MAX_INLINE_CHARS = 4000;

    /** The modes of the parts door; absent means single-shot, today's behavior byte for byte. */
    private const MODES = ['start', 'append', 'finish'];

    /**
     * @param string|null $analyzer       the static-analysis command, or `null` to derive it from
     *                                    the app (`vendor/bin/phpstan`, when present). Tests
     *                                    inject a seam here — a gate only verifiable against a
     *                                    running binary is a gate nobody verifies in practice
     * @param string|null $behaviorRunner the command that runs one test file, or `null` to derive
     *                                    it (`vendor/bin/phpunit`, when present) — same seam, same
     *                                    reason
     */
    public function __construct(
        private readonly RootResolver $roots = new RootResolver(),
        private readonly ?string $analyzer = null,
        private readonly ?string $behaviorRunner = null,
    ) {
    }

    /**
     * Land the complete body of one scaffolded class — or refuse with the diagnostic, original intact.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function handle(array $input): array
    {
        $plugin = \is_string($input['plugin'] ?? null) ? trim($input['plugin']) : '';
        $class = \is_string($input['class'] ?? null) ? trim($input['class']) : '';
        $content = \is_string($input['content'] ?? null) ? $input['content'] : '';
        $mode = $input['mode'] ?? null;

        // A bare PHP identifier or nothing: anything path-shaped is refused before touching disk.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class) !== 1) {
            return ['ok' => false, 'error' => "«{$class}» is not a class name — one bare identifier, no paths"];
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $plugin) !== 1) {
            return ['ok' => false, 'error' => "«{$plugin}» is not a plugin directory name"];
        }
        if ($mode !== null && !\in_array($mode, self::MODES, true)) {
            return [
                'ok' => false,
                'error' => 'unknown mode — omit it to land the complete file in one call, or land in parts: '
                    . 'mode=start, then mode=append per section, then mode=finish',
            ];
        }

        // `content` is a per-mode obligation, not a schema-wide one: finish assembles what start and
        // append already landed, so a section riding on it would be silently half-landed — refused.
        if ($mode === 'finish' && $content !== '') {
            return [
                'ok' => false,
                'error' => 'mode=finish takes no `content` — sections travel with mode=append; '
                    . 'finish verifies and judges what the parts assembled',
            ];
        }
        if ($mode !== 'finish' && $content === '') {
            return [
                'ok' => false,
                'error' => $mode === null
                    ? 'nothing to land: `content` is the complete PHP file'
                    : "nothing to land: mode={$mode} carries one section of the file in `content`",
            ];
        }

        // ── The cap: the measured killer is refused BEFORE any write, teaching the door ──────────
        if ($mode !== 'finish' && \strlen($content) > self::MAX_INLINE_CHARS) {
            if ($mode === null) {
                return [
                    'ok' => false,
                    'error' => 'refused: `content` is ' . \strlen($content) . ' chars, over MAX_INLINE_CHARS ('
                        . self::MAX_INLINE_CHARS . ') — a body that size breaks the caller\'s own tool-call JSON. '
                        . 'Write it in parts: mode=start with the file header and first section, mode=append for '
                        . 'each next section (each under the cap), mode=finish to verify and judge.',
                ];
            }

            return [
                'ok' => false,
                'error' => 'refused: this section is ' . \strlen($content) . ' chars, over MAX_INLINE_CHARS ('
                    . self::MAX_INLINE_CHARS . ') — split it into smaller sections, each under the cap',
            ];
        }

        $root = rtrim($this->roots->resolve(), '/');
        $tree = $root . '/src/Plugins/' . $plugin;
        if (!is_dir($tree)) {
            return ['ok' => false, 'error' => "this app has no plugin «{$plugin}» under src/Plugins"];
        }

        // The path is DERIVED, never received: the class is searched inside the plugin's own trees
        // only — its sources, and its tests. A judge is a class too, and the TDD flow depends on it
        // landing through the SAME gate before the body it will judge.
        $file = $this->fileFor($tree, $class)
            ?? (is_dir($root . '/tests/Plugins/' . $plugin) ? $this->fileFor($root . '/tests/Plugins/' . $plugin, $class) : null);
        if ($file === null) {
            if ($mode === 'append' || $mode === 'finish') {
                return [
                    'ok' => false,
                    'error' => "nothing to {$mode}: no scaffold declares class «{$class}» in plugin «{$plugin}» — "
                        . 'scaffold it with `make`, then land the first section with mode=start',
                ];
            }

            return [
                'ok' => false,
                'error' => "no scaffold declares class «{$class}» in plugin «{$plugin}» — filling is not creating; scaffold it first with `make`",
            ];
        }

        // ── The partial writes: a partial file is not valid PHP, so NOTHING verifies here ────────
        //
        // The results carry no `verified` key and no postcondition a caller could quote as green —
        // the work-protocol doctrine: a claimable green from a partial would be a lie wearing keys.
        if ($mode === 'start') {
            if (file_put_contents($file, $content) === false) {
                return ['ok' => false, 'error' => "could not write {$file}"];
            }

            return [
                'ok' => true,
                'file' => substr($file, \strlen($root) + 1),
                'partial' => 'started — nothing verified, nothing judged: a partial file is not valid PHP. '
                    . 'Send each next section with mode=append (each under ' . self::MAX_INLINE_CHARS
                    . ' chars), then mode=finish to verify and judge.',
            ];
        }
        if ($mode === 'append') {
            if (file_put_contents($file, $content, \FILE_APPEND) === false) {
                return ['ok' => false, 'error' => "could not append to {$file}"];
            }

            return [
                'ok' => true,
                'file' => substr($file, \strlen($root) + 1),
                'partial' => 'appended verbatim — nothing verified, nothing judged. More sections go through '
                    . 'mode=append; mode=finish verifies and judges the assembled file.',
            ];
        }

        // finish: the assembled file IS the content, and from here the pipeline is single-shot's —
        // same checks, same judge, same result shape. Two doors, one landing gate.
        if ($mode === 'finish') {
            $content = (string) file_get_contents($file);
        }

        // ── The landing gate: everything verifies on a copy, or nothing lands ────────────────────
        if (!str_contains($content, 'declare(strict_types=1)')) {
            return ['ok' => false, 'error' => 'refused: every PHP file in this house declares strict_types=1'];
        }

        if (preg_match('/\b(?:class|interface|trait|enum)\s+' . preg_quote($class, '/') . '\b/', $content) !== 1) {
            return ['ok' => false, 'error' => "refused: the content does not declare «{$class}» — a landing that renames its class leaves a file that lies"];
        }

        // The namespace is a FACT of the file's location — the system owns it, the author does not
        // guess it. If the content declares a different namespace (or none), the system RESOLVES it to
        // the location-dictated one instead of refusing: the class name is the author's intent (kept and
        // checked above); the namespace is derived, so a file that would «land unloadable» is made
        // loadable, not rejected. An agent should never re-guess what the system already knows.
        $expected = $this->namespaceFor($root, $file);
        if (preg_match('/^namespace\s+[^;]+;/m', $content) === 1) {
            $content = (string) preg_replace('/^namespace\s+[^;]+;/m', "namespace {$expected};", $content, 1);
        } else {
            // No namespace at all — inject it after the opening tag and its declare(), if present.
            $content = (string) preg_replace(
                '/\A(<\?php\b[^\n]*\n(?:\s*declare\s*\([^)]*\)\s*;\s*\n)?)/',
                "$1\nnamespace {$expected};\n",
                $content,
                1
            );
        }
        if (preg_match('/^namespace\s+' . preg_quote($expected, '/') . '\s*;/m', $content) !== 1) {
            return ['ok' => false, 'error' => "could not resolve this file's namespace to `{$expected}` — is the opening `<?php` well-formed?"];
        }

        $staged = tempnam(sys_get_temp_dir(), 'milpa-implement-');
        if ($staged === false || file_put_contents($staged, $content) === false) {
            return ['ok' => false, 'error' => 'could not stage the content for verification'];
        }

        try {
            exec('php -l ' . escapeshellarg($staged) . ' 2>&1', $lines, $code);
            if ($code !== 0) {
                // The diagnostic travels whole — it is what the model corrects from. The temp path
                // inside it would only mislead, so it is renamed to the file it was meant for.
                $detail = str_replace($staged, $file, implode("\n", $lines));

                return ['ok' => false, 'error' => "refused: the content does not parse — syntax check said:\n{$detail}"];
            }
        } finally {
            @unlink($staged);
        }

        // ── Static conformance, when the app ships an analyzer ───────────────────────────────────
        //
        // The candidate is analyzed IN PLACE: linkage — does the interface exist, do the
        // signatures match — is only visible with the app's autoloader, which a staged temp file
        // does not have. The original is held in memory and restored byte for byte on any finding,
        // so the transactional guarantee moves from «never touched» to «atomically restored».
        $previous = (string) file_get_contents($file);
        if (file_put_contents($file, $content) === false) {
            return ['ok' => false, 'error' => "verified clean but could not write {$file}"];
        }

        $analyzer = $this->analyzer ?? $this->defaultAnalyzer($root);
        if ($analyzer !== null) {
            exec($analyzer . ' ' . escapeshellarg($file) . ' 2>&1', $findings, $verdict);
            if ($verdict !== 0) {
                file_put_contents($file, $previous);
                // Only the findings travel — `path:line:message`, the raw format's shape. The
                // analyzer's banners and tips would bury the one line the model corrects from.
                $lines = array_values(array_filter(
                    $findings,
                    static fn (string $l): bool => preg_match('/:\d+:/', $l) === 1,
                ));
                if ($lines === []) {
                    $lines = array_slice(array_values(array_filter(
                        $findings,
                        static fn (string $l): bool => trim($l) !== '',
                    )), -6);
                }
                $detail = implode("\n", array_slice($lines, 0, 12));

                return ['ok' => false, 'error' => "refused: the content does not conform — static analysis said:\n{$detail}"];
            }
        }

        // ── The behavioral judge: the class's own test, when one declares what it must DO ────────
        //
        // Except when the landed class IS a judge: running a test against the shell it exists to
        // fail would make TDD's red unlandable — the judge lands first, and runs when its subject
        // lands. It never judges itself.
        if (str_starts_with($file, $root . '/tests/')) {
            return [
                'ok' => true,
                'file' => substr($file, \strlen($root) + 1),
                'class' => $expected . '\\' . $class,
                'verified' => 'syntax, strict_types, class, namespace'
                    . ($analyzer !== null ? ' and static conformance' : ' — static analysis unavailable in this app')
                    . '; this class IS a judge — it runs when its subject lands',
            ];
        }

        $verdictNote = '; behavior unjudged — no test declares what this class must do';
        $testFile = $this->testFor($root, $plugin, $class);
        if ($testFile !== null) {
            $runner = $this->behaviorRunner ?? $this->defaultBehaviorRunner($root);
            if ($runner === null) {
                $verdictNote = '; behavior unjudged — a test exists but this app ships no phpunit';
            } else {
                exec($runner . ' ' . escapeshellarg($testFile) . ' 2>&1', $verdictLines, $verdictCode);
                if ($verdictCode !== 0) {
                    file_put_contents($file, $previous);
                    $tail = implode("\n", \array_slice(array_values(array_filter(
                        $verdictLines,
                        static fn (string $l): bool => trim($l) !== '',
                    )), -10));

                    return [
                        'ok' => false,
                        'error' => 'refused: the class\'s own test judges this behavior red — '
                            . basename($testFile, '.php') . " said:\n{$tail}",
                    ];
                }
                $verdictNote = ', behavior (' . basename($testFile, '.php') . ' green)';
            }
        }

        return [
            'ok' => true,
            'file' => substr($file, \strlen($root) + 1),
            'class' => $expected . '\\' . $class,
            'verified' => 'syntax, strict_types, class, namespace'
                . ($analyzer !== null ? ' and static conformance' : ' — static analysis unavailable in this app')
                . $verdictNote,
        ];
    }

    /** The class's behavioral test under `tests/Plugins/<plugin>/`, or `null` when none declares it. */
    private function testFor(string $root, string $plugin, string $class): ?string
    {
        $tree = $root . '/tests/Plugins/' . $plugin;
        if (!is_dir($tree)) {
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tree, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->getFilename() === $class . 'Test.php') {
                return $entry->getPathname();
            }
        }

        return null;
    }

    /** The runner this app ships, or `null` — and `null` is SAID in the result, never silent. */
    private function defaultBehaviorRunner(string $root): ?string
    {
        $phpunit = $root . '/vendor/bin/phpunit';

        return is_file($phpunit) ? 'timeout 120 ' . escapeshellarg($phpunit) : null;
    }

    /**
     * The analyzer this app ships, or `null` — and `null` is SAID in the result, never silent.
     *
     * Level 0 and a 60s ceiling on purpose: this gate rules on linkage (unknown classes, interface
     * conformance — both measured catches), not on style, and a hung analyzer must not hang the
     * agent's turn.
     */
    private function defaultAnalyzer(string $root): ?string
    {
        $phpstan = $root . '/vendor/bin/phpstan';
        $autoload = $root . '/vendor/autoload.php';
        if (!is_file($phpstan) || !is_file($autoload)) {
            return null;
        }

        return 'timeout 60 ' . escapeshellarg($phpstan)
            . ' analyse --level=0 --no-progress --error-format=raw --autoload-file=' . escapeshellarg($autoload);
    }

    /** The one file inside the plugin's tree whose basename is the class — or null. */
    private function fileFor(string $tree, string $class): ?string
    {
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

    /** The namespace this file's location dictates: `src/` maps to `App\`, `tests/` to `App\Tests\`. */
    private function namespaceFor(string $root, string $file): string
    {
        if (str_starts_with($file, $root . '/tests/')) {
            $relative = substr(\dirname($file), \strlen($root . '/tests/'));

            return 'App\\Tests\\' . str_replace('/', '\\', $relative);
        }

        $relative = substr(\dirname($file), \strlen($root . '/src/'));

        return 'App\\' . str_replace('/', '\\', $relative);
    }
}
