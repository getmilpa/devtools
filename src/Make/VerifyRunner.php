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

namespace Milpa\DevTools\Make;

use Milpa\DevTools\Verify\ControllerVerifier;
use Milpa\DevTools\Verify\EntityVerifier;
use Milpa\DevTools\Verify\VerifierInterface;

/**
 * Runs the paired {@see VerifierInterface} on a generated class FQCN, closing the
 * generate → verify loop so a produced file that does not satisfy the framework's runtime
 * conventions fails the `coa:make` run.
 *
 * Before the topology fix (OLA 3 / E4) this shelled out to `php scripts/verify-<kind>.php` — a
 * subprocess per verify, and one that only worked because the script happened to sit at a fixed
 * path relative to the monorepo root. Verifiers now run IN-PROCESS (same PHP request that just
 * wrote the file), so a freshly generated class is checked via the very autoloader that will load
 * it in production — no subprocess, no path assumption. `$root` is kept in the signature for
 * call-site/API stability; the in-process verifiers reflect the already-autoloaded class and do
 * not need it.
 */
final class VerifyRunner
{
    /** @var array<string, VerifierInterface> */
    private array $verifiers;

    /**
     * @param array<string, VerifierInterface>|null $verifiers override for testing; defaults to the
     *                                                         built-in controller/entity verifiers
     */
    public function __construct(?array $verifiers = null)
    {
        $this->verifiers = $verifiers ?? [
            'controller' => new ControllerVerifier(),
            'entity' => new EntityVerifier(),
        ];
    }

    /**
     * Runs the `$kind` verifier ('controller'|'entity') against `$fqcn` and returns its outcome.
     *
     * @param Flavor|null $flavor the {@see Flavor} to verify a `'controller'` or `'entity'` against
     *                            (both {@see ControllerVerifier} and {@see EntityVerifier} pick their
     *                            convention this same way as of F3) — typically
     *                            {@see GenerationResult::$flavor} from the same `generate()` call
     *                            that produced `$fqcn`; `null` (the default) preserves this method's
     *                            pre-F1 behavior of using the constructor's fixed verifiers (both
     *                            default to {@see Flavor::Legacy})
     *
     * @return array{ok: bool, output: string}
     */
    public function run(string $kind, string $fqcn, string $root, ?Flavor $flavor = null): array
    {
        $verifier = $flavor !== null
            ? $this->verifierFor($kind, $flavor)
            : ($this->verifiers[$kind] ?? null);

        if ($verifier === null) {
            return ['ok' => false, 'output' => "no verifier for kind '{$kind}'"];
        }

        $result = $verifier->verify($fqcn);

        return ['ok' => $result->ok(), 'output' => $result->render()];
    }

    /** Builds a fresh, `$flavor`-selected verifier for `$kind`; falls back to the cached default when `$kind` is unknown. */
    private function verifierFor(string $kind, Flavor $flavor): ?VerifierInterface
    {
        return match ($kind) {
            'controller' => new ControllerVerifier($flavor),
            'entity' => new EntityVerifier($flavor),
            default => $this->verifiers[$kind] ?? null,
        };
    }
}
