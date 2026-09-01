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

namespace Milpa\DevTools\Operations;

use Milpa\DevTools\Support\RootResolver;

/**
 * ONE shape for finding (greenhouse decisions/0183, primitive #4): `discover` fans out to the
 * package's EXISTING finders and answers uniform rows.
 *
 * ── WHY A FAN-OUT AND NEVER A SCAN OF ITS OWN ───────────────────────────────────────────────────
 *
 * Each finder here answers in its own dialect — `artifact:list` says `artifacts[].fqcn`,
 * `contract:search` says `matches[].fqcn`, `test:list` says `tests[].fqcn` — and a session was
 * measured learning every one of those dialects before doing any work. This operation exists to
 * collapse the dialects, NOT to add a fifth way of looking: every row is DERIVED from a real
 * finder's in-process answer, so a name `discover` reports is by construction a name the finder
 * it cites would also report. A parallel scan would be the duplicate-authority defect the family
 * keeps paying to remove.
 *
 * ── THE ROW SHAPE ───────────────────────────────────────────────────────────────────────────────
 *
 * `{kind, identity, path?, detail: {operation, arguments}}` — `identity` is the finder's own
 * identity for the thing (its FQCN), `path` travels only when the finder answered one, and
 * `detail` names the EXACT declared operation call that yields the full answer:
 * `artifact:contract` for an artifact, `contract:search`'s own entry for a contract, `test:show`
 * for a test, `package:artifacts` for a package's declaration. The pointer is executable as
 * given — no caller assembles arguments from prose.
 *
 * ── FINDING NOTHING IS AN ANSWER ────────────────────────────────────────────────────────────────
 *
 * An empty `found` comes back `ok:true` with the queried kinds named: «nothing declares this
 * name» is information the caller can act on. What fails closed is the unjudgeable: an unknown
 * kind is refused naming the valid set, and a missing query is refused asking for one.
 */
final class DiscoverHandler
{
    /** The kinds `discover` can search — each one backed by exactly one existing finder. */
    public const KINDS = ['artifact', 'contract', 'test', 'package'];

    private readonly ArtifactListHandler $artifacts;

    private readonly ContractSearchHandler $contracts;

    private readonly TestDiscoveryHandler $tests;

    private readonly PackageArtifactsHandler $packages;

    /** All four finders share the one root the caller resolves — the same seam every handler has. */
    public function __construct(RootResolver $roots = new RootResolver())
    {
        $this->artifacts = new ArtifactListHandler($roots);
        $this->contracts = new ContractSearchHandler($roots);
        $this->tests = new TestDiscoveryHandler($roots);
        $this->packages = new PackageArtifactsHandler($roots);
    }

    /**
     * Answers uniform rows for everything the existing finders know under one query.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, query?: string, kinds?: list<string>, total?: int, found?: list<array<string, mixed>>, error?: string}
     */
    public function handle(array $input): array
    {
        $query = \is_string($input['query'] ?? null) ? trim($input['query']) : '';
        if ($query === '') {
            return ['ok' => false, 'found' => [], 'error' => 'name a query: discover needs `query`'];
        }

        $kinds = $this->kindsAskedFor($input['kinds'] ?? null);
        if (\is_string($kinds)) {
            return ['ok' => false, 'found' => [], 'error' => $kinds];
        }

        $found = [];
        foreach ($kinds as $kind) {
            $rows = match ($kind) {
                'artifact' => $this->artifactRows($query),
                'contract' => $this->contractRows($query),
                'test' => $this->testRows($query),
                'package' => $this->packageRows($query),
                // Unreachable: `kindsAskedFor()` admits only self::KINDS. It throws rather than
                // answering `[]` so a kind added to the list without its fan-out cannot pass as
                // «nothing found».
                default => throw new \LogicException("kind «{$kind}» has no fan-out"),
            };
            $found = [...$found, ...$rows];
        }

        return [
            'ok' => true,
            'query' => $query,
            // The kinds are ALWAYS named, and matter most when `found` is empty: «nothing, and
            // this is where it was looked for» is an answer; a bare `[]` is a shrug.
            'kinds' => $kinds,
            'total' => \count($found),
            'found' => $found,
        ];
    }

    /**
     * The validated kinds to fan out to, or the refusal to answer with.
     *
     * @return list<string>|string
     */
    private function kindsAskedFor(mixed $declared): array|string
    {
        if ($declared === null) {
            return self::KINDS;
        }

        $valid = implode(', ', self::KINDS);
        if (!\is_array($declared) || $declared === []) {
            // An empty subset asks for nothing, and searching everything instead would be a
            // silent reinterpretation — same doctrine as an unknown kind: refused, in words.
            return "name at least one kind, or omit `kinds` to search them all — valid kinds: {$valid}";
        }

        $kinds = [];
        foreach ($declared as $kind) {
            if (!\is_string($kind) || !\in_array($kind, self::KINDS, true)) {
                $shown = \is_string($kind) ? $kind : get_debug_type($kind);

                return "unknown kind «{$shown}» — valid kinds: {$valid}";
            }
            if (!\in_array($kind, $kinds, true)) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }

    /**
     * The app artifacts matching the query — derived from `artifact:list`'s own answer.
     *
     * @return list<array<string, mixed>>
     */
    private function artifactRows(string $query): array
    {
        $answer = $this->artifacts->handle([]);
        if ($answer['ok'] !== true) {
            // The finder's «no declarations» is discover's empty: nothing found IS the answer.
            return [];
        }

        $rows = [];
        foreach ($answer['artifacts'] as $artifact) {
            if (!$this->matches($query, $artifact['name'], $artifact['fqcn'])) {
                continue;
            }
            $rows[] = [
                'kind' => 'artifact',
                'identity' => $artifact['fqcn'],
                'path' => $artifact['path'],
                'detail' => [
                    'operation' => 'artifact:contract',
                    'arguments' => ['name' => $artifact['name'], 'plugin' => $artifact['plugin']],
                ],
            ];
        }

        return $rows;
    }

    /**
     * The declared type names matching the query, app and vendor — `contract:search`'s own answer.
     *
     * @return list<array<string, mixed>>
     */
    private function contractRows(string $query): array
    {
        $answer = $this->contracts->handle(['q' => $query]);
        if ($answer['ok'] !== true) {
            return [];
        }

        $rows = [];
        foreach ($answer['matches'] as $match) {
            $rows[] = [
                'kind' => 'contract',
                'identity' => $match['fqcn'],
                // The full answer is the finder's own entry, re-asked by its exact FQCN.
                'detail' => [
                    'operation' => 'contract:search',
                    'arguments' => ['q' => $match['fqcn']],
                ],
            ];
        }

        return $rows;
    }

    /**
     * The test classes matching the query — derived from `test:list`'s own answer.
     *
     * @return list<array<string, mixed>>
     */
    private function testRows(string $query): array
    {
        $answer = $this->tests->handleList([]);
        if ($answer['ok'] !== true) {
            return [];
        }

        $rows = [];
        foreach ($answer['tests'] as $test) {
            if (!$this->matches($query, $test['name'], $test['fqcn'])) {
                continue;
            }
            $rows[] = [
                'kind' => 'test',
                'identity' => $test['fqcn'],
                'path' => $test['path'],
                'detail' => [
                    'operation' => 'test:show',
                    'arguments' => ['name' => $test['fqcn']],
                ],
            ];
        }

        return $rows;
    }

    /**
     * What an installed package declares, when the query names one — `package:artifacts`' answer.
     *
     * A query that is not shaped «vendor/name» cannot name a package, so this kind answers empty
     * for it instead of letting the finder's shape refusal fail a search the other kinds can
     * still answer.
     *
     * @return list<array<string, mixed>>
     */
    private function packageRows(string $query): array
    {
        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$#', $query) !== 1) {
            return [];
        }

        $answer = $this->packages->handle(['package' => $query]);
        if ($answer['ok'] !== true) {
            return [];
        }

        $rows = [];
        foreach ($answer['artifacts'] as $artifact) {
            $rows[] = [
                'kind' => 'package',
                'identity' => $artifact['fqcn'],
                'detail' => [
                    'operation' => 'package:artifacts',
                    'arguments' => ['package' => $artifact['package']],
                ],
            ];
        }

        return $rows;
    }

    /**
     * The one match rule for the kinds whose finder takes no query: case-insensitive fragment
     * against the short name or the FQCN — the same containment `contract:search` applies.
     */
    private function matches(string $query, string $name, string $fqcn): bool
    {
        $needle = strtolower(trim($query, '\\'));

        return str_contains(strtolower($name), $needle) || str_contains(strtolower($fqcn), $needle);
    }
}
