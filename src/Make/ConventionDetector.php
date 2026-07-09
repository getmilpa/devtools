<?php

declare(strict_types=1);

namespace Milpa\DevTools\Make;

use Milpa\DevTools\Support\ComposerAutoload;

/**
 * Decides which controller convention (see {@see Flavor}) `coa:make controller` should target for a
 * given app root, so the same command "just works" whether it runs inside an existing legacy Milpa
 * host (this monorepo's own `src/app/Providers/BaseController` convention) or a fresh `milpa/runtime`
 * + skeleton app — without the caller having to know which.
 *
 * Detection order (first hit wins):
 *
 *   1. `$override` — an explicit `--flavor=runtime|legacy` always wins outright, no filesystem
 *      inspection at all.
 *   2. **Legacy**, if EITHER is true:
 *      - `$root/milpa.json` exists (a self-declaring app-level manifest); or
 *      - `Milpa\app\Providers\BaseController` resolves to a real file under `$root`'s own
 *        `composer.json` `autoload.psr-4` map. Deliberately a pure FILESYSTEM check, not
 *        `class_exists()`: this detector must give the same answer whether or not the host has been
 *        autoloaded yet (e.g. a `--dry-run` before `composer install`), and `class_exists()` would
 *        also give a false positive inside this very package's own test process, where
 *        `tests/Fixtures/HostStubs.php` defines that exact FQCN unconditionally for
 *        {@see \Milpa\DevTools\Verify\ControllerVerifier}'s own fixtures.
 *   3. **Runtime**, otherwise. This covers every explicit runtime signal (`config/plugins.php`
 *      present, `milpa/runtime` required in `composer.json`, an `App\`-style PSR-4 root with no
 *      `Milpa\app`) AND the genuinely ambiguous case (a brand new root with none of the above):
 *      runtime is the framework's forward default, so the mere absence of an affirmative legacy
 *      signal is enough — nothing past step 2 can still produce `Legacy`.
 */
final class ConventionDetector
{
    private const LEGACY_BASE_CONTROLLER = 'Milpa\\app\\Providers\\BaseController';

    /** Detects the {@see Flavor} for the app rooted at `$root`; `$override` short-circuits to that flavor. */
    public function detect(string $root, ?string $override = null): Flavor
    {
        if ($override !== null) {
            return Flavor::fromOption($override);
        }

        return $this->isLegacy($root) ? Flavor::Legacy : Flavor::Runtime;
    }

    private function isLegacy(string $root): bool
    {
        if (is_file($root . '/milpa.json')) {
            return true;
        }

        return $this->resolvesUnderPsr4($root, self::LEGACY_BASE_CONTROLLER);
    }

    /** Whether `$fqcn` resolves to a real file under `$root`'s own `composer.json` PSR-4 map. */
    private function resolvesUnderPsr4(string $root, string $fqcn): bool
    {
        foreach (ComposerAutoload::psr4($root) as $prefix => $dir) {
            if (!str_starts_with($fqcn, $prefix)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($fqcn, strlen($prefix))) . '.php';

            if (is_file($root . '/' . $dir . '/' . $relative)) {
                return true;
            }
        }

        return false;
    }
}
