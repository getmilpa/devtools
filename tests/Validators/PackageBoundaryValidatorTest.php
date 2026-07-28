<?php

/**
 * This file is part of Milpa DevTools — the developer tooling of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Validators;

use Milpa\DevTools\Validators\PackageBoundaryResult;
use Milpa\DevTools\Validators\PackageBoundaryRule;
use Milpa\DevTools\Validators\PackageBoundaryValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A boundary that runs through a namespace instead of around it.
 *
 * The case that motivates all of this is the first test: two imports with the same prefix, the same
 * shape and opposite verdicts, because one class ships in the render-agnostic package and the other
 * in a surface. No substring separates them, so the rule has to ask where each one lives.
 */
#[CoversClass(PackageBoundaryValidator::class)]
#[CoversClass(PackageBoundaryRule::class)]
#[CoversClass(PackageBoundaryResult::class)]
final class PackageBoundaryValidatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-boundary-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/plugins/Billing', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root . '/plugins/Billing/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->root . '/plugins/Billing');
        @rmdir($this->root . '/plugins');
        @rmdir($this->root);
    }

    private function plugin(string $name, string $body): void
    {
        file_put_contents($this->root . "/plugins/Billing/{$name}.php", "<?php\n\n" . $body);
    }

    /**
     * Ownership as the family actually lays it out: one namespace, three packages.
     *
     * @param array<string, string> $map
     */
    private function validator(array $map = []): PackageBoundaryValidator
    {
        $default = [
            'Milpa\Live\Contracts\Component\ComponentDefinitionInterface' => '/app/vendor/milpa/live/src/Contracts/Component/ComponentDefinitionInterface.php',
            'Milpa\Live\Rendering\ComponentRendererRegistry' => '/app/vendor/milpa/live/src/Rendering/ComponentRendererRegistry.php',
            'Milpa\Live\Rendering\DashboardHtmlRenderer' => '/app/vendor/milpa/live-web/src/Rendering/DashboardHtmlRenderer.php',
            'Milpa\Live\Adapters\Alpine' => '/app/vendor/milpa/live-web/src/Adapters/Alpine.php',
            'Milpa\Live\Tui\StreamTerminal' => '/app/vendor/milpa/live-tui/src/Tui/StreamTerminal.php',
        ];
        $map += $default;

        return new PackageBoundaryValidator(static fn (string $class): ?string => $map[$class] ?? null);
    }

    private function rule(): PackageBoundaryRule
    {
        return new PackageBoundaryRule(
            label: 'a plugin declares components; it never speaks a surface',
            dir: 'plugins',
            forbiddenPackages: ['milpa/live-web', 'milpa/live-tui'],
        );
    }

    public function test_two_imports_with_the_same_prefix_get_opposite_verdicts(): void
    {
        // The reason this validator exists. Both lines read the same; one is the contract a plugin
        // is meant to use, the other binds it to the web. A text rule banning `Milpa\Live\Rendering`
        // would reject both, and a rule allowing it would accept both.
        $this->plugin('Allowed', "use Milpa\\Live\\Rendering\\ComponentRendererRegistry;\n");
        $this->plugin('Forbidden', "use Milpa\\Live\\Rendering\\DashboardHtmlRenderer;\n");

        $result = $this->validator()->validate([$this->rule()], $this->root)[0];

        self::assertCount(1, $result->violations);
        self::assertStringContainsString('DashboardHtmlRenderer', $result->violations[0]);
        self::assertStringContainsString('milpa/live-web', $result->violations[0]);
        self::assertStringNotContainsString('ComponentRendererRegistry', implode("\n", $result->violations));
    }

    public function test_a_plugin_using_only_contracts_is_clean(): void
    {
        $this->plugin('Definition', "use Milpa\\Live\\Contracts\\Component\\ComponentDefinitionInterface;\n");

        self::assertTrue($this->validator()->validate([$this->rule()], $this->root)[0]->ok());
    }

    public function test_every_forbidden_surface_counts_not_just_the_first(): void
    {
        // Adding a surface must not require anyone to remember to extend a list of class names —
        // that is the maintenance failure this design removes.
        $this->plugin('Web', "use Milpa\\Live\\Adapters\\Alpine;\n");
        $this->plugin('Terminal', "use Milpa\\Live\\Tui\\StreamTerminal;\n");

        $result = $this->validator()->validate([$this->rule()], $this->root)[0];

        self::assertCount(2, $result->violations);
    }

    public function test_an_aliased_import_does_not_escape(): void
    {
        // What defeats a text needle: the alias erases the name the rule was looking for. Ownership
        // does not care what the class was renamed to on the way in.
        $this->plugin('Sneaky', "use Milpa\\Live\\Rendering\\DashboardHtmlRenderer as Renderer;\n");

        $result = $this->validator()->validate([$this->rule()], $this->root)[0];

        self::assertCount(1, $result->violations);
    }

    public function test_a_class_it_cannot_locate_is_reported_rather_than_passed(): void
    {
        // The failure that would make this gate useless in silence: an uninstalled package or a
        // stale autoloader resolves nothing, and a validator that skipped what it could not see
        // would report a clean boundary having checked none of it.
        $this->plugin('Unknown', "use Some\\Package\\That\\Is\\Not\\Installed;\n");

        $result = $this->validator()->validate([$this->rule()], $this->root)[0];

        self::assertSame([], $result->violations);
        self::assertCount(1, $result->unresolved);
        self::assertFalse($result->ok(), 'A check that could not see must not report success.');
    }

    public function test_a_whitelisted_file_is_exempt(): void
    {
        $this->plugin('Bridge', "use Milpa\\Live\\Adapters\\Alpine;\n");

        $rule = new PackageBoundaryRule(
            label: 'plugins stay surface-agnostic',
            dir: 'plugins',
            forbiddenPackages: ['milpa/live-web'],
            whitelist: ['Billing/Bridge.php'],
        );

        self::assertTrue($this->validator()->validate([$rule], $this->root)[0]->ok());
    }

    public function test_a_whitelisted_directory_exempts_what_is_inside_it(): void
    {
        // The exemption that matters is structural, not per-file: a package that owns a surface has
        // an adapter directory for it, and enumerating the files inside would be a list that grows
        // silently permissive as files are added.
        $this->plugin('Screen', "use Milpa\\Live\\Tui\\StreamTerminal;\n");

        $rule = new PackageBoundaryRule(
            label: 'component code stays surface-agnostic; the panel owns its own shells',
            dir: 'plugins',
            forbiddenPackages: ['milpa/live-tui'],
            whitelist: ['Billing/'],
            watchedPrefixes: ['Milpa\\Live\\'],
        );

        self::assertTrue($this->validator()->validate([$rule], $this->root)[0]->ok());
    }

    public function test_a_prefix_outside_the_watch_is_not_this_rules_question(): void
    {
        // Symfony, Doctrine and the plugin's own classes are out of scope by construction. Before
        // this, they arrived as 970 unresolved entries on the real monorepo and buried eleven
        // genuine findings — a check nobody reads protects nothing.
        $this->plugin('Vendor', "use Symfony\\Component\\Console\\Command\\Command;\n");

        $rule = new PackageBoundaryRule(
            label: 'watched family only',
            dir: 'plugins',
            forbiddenPackages: ['milpa/live-web'],
            watchedPrefixes: ['Milpa\\Live\\'],
        );

        self::assertTrue($this->validator()->validate([$rule], $this->root)[0]->ok());
    }

    public function test_a_missing_directory_is_a_finding_not_a_pass(): void
    {
        // A rule pointed at a directory that was renamed would otherwise iterate nothing and
        // succeed — the loudest possible way to protect nothing.
        $rule = new PackageBoundaryRule('gone', 'does-not-exist', ['milpa/live-web']);

        $result = $this->validator()->validate([$rule], $this->root)[0];

        self::assertFalse($result->ok());
        self::assertStringContainsString('directory not found', $result->unresolved[0]);
    }

    public function test_the_default_resolver_locates_a_real_class(): void
    {
        // Every other test injects the seam, so without this the production path — reflection
        // against the installed tree — would ship unexercised. It uses a class this package
        // certainly has: its own rule.
        $this->plugin('Real', "use Milpa\\DevTools\\Validators\\PackageBoundaryRule;\n");

        $rule = new PackageBoundaryRule(
            label: 'resolved for real',
            dir: 'plugins',
            forbiddenPackages: ['some/package-we-do-not-use'],
            watchedPrefixes: ['Milpa\\DevTools\\'],
        );

        $result = (new PackageBoundaryValidator())->validate([$rule], $this->root)[0];

        self::assertTrue($result->ok(), 'A class that exists must resolve, or the gate is blind in production.');
    }

    public function test_the_default_resolver_reports_a_class_that_does_not_exist(): void
    {
        $this->plugin('Ghost', "use Milpa\\DevTools\\NoSuchThing;\n");

        $rule = new PackageBoundaryRule(
            label: 'unresolvable is visible',
            dir: 'plugins',
            forbiddenPackages: ['x/y'],
            watchedPrefixes: ['Milpa\\DevTools\\'],
        );

        $result = (new PackageBoundaryValidator())->validate([$rule], $this->root)[0];

        self::assertCount(1, $result->unresolved);
    }

    public function test_files_that_are_not_php_are_skipped(): void
    {
        file_put_contents($this->root . '/plugins/Billing/notes.md', "use Milpa\\Live\\Adapters\\Alpine;\n");

        self::assertTrue($this->validator()->validate([$this->rule()], $this->root)[0]->ok());
    }

    public function test_a_fixture_under_tests_may_reference_the_forbidden_side(): void
    {
        // The same exemption the namespace-based validator makes: a fixture exists precisely to
        // stand on the other side of the boundary.
        mkdir($this->root . '/plugins/Billing/Tests');
        file_put_contents(
            $this->root . '/plugins/Billing/Tests/Fixture.php',
            "<?php\n\nuse Milpa\\Live\\Adapters\\Alpine;\n",
        );

        $ok = $this->validator()->validate([$this->rule()], $this->root)[0]->ok();

        @unlink($this->root . '/plugins/Billing/Tests/Fixture.php');
        @rmdir($this->root . '/plugins/Billing/Tests');

        self::assertTrue($ok);
    }

    public function test_it_reports_which_file_crossed_the_line(): void
    {
        // A violation without a file name sends the reader searching; the point is to be actionable
        // the moment CI prints it.
        $this->plugin('Dashboard', "use Milpa\\Live\\Rendering\\DashboardHtmlRenderer;\n");

        $result = $this->validator()->validate([$this->rule()], $this->root)[0];

        self::assertStringContainsString('plugins/Billing/Dashboard.php', $result->violations[0]);
    }
}
