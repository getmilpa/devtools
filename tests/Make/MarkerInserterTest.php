<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Make\MarkerInserter;

/**
 * Covers {@see MarkerInserter} directly (F1's insertion primitive), independent of any generator:
 * marker detection, indentation-matched splicing that preserves the marker line for a later
 * insertion, the idempotent-safe dedup check, and `$force` re-insertion.
 */
final class MarkerInserterTest extends TestCase
{
    private const PLUGIN = <<<'PHP'
        <?php

        final class BoardPlugin
        {
            public function boot(): void
            {
                // {coa:services}
            }
        }
        PHP;

    public function testHasMarkerIsTrueWhenThePluginCarriesTheAnchorLine(): void
    {
        $this->assertTrue((new MarkerInserter())->hasMarker(self::PLUGIN, 'coa:services'));
    }

    public function testHasMarkerIsFalseForAnUnmarkedFile(): void
    {
        $this->assertFalse((new MarkerInserter())->hasMarker("<?php\n// hand-written, no markers\n", 'coa:services'));
    }

    public function testHasMarkerDoesNotFalsePositiveOnAPartialTextualMatch(): void
    {
        $this->assertFalse((new MarkerInserter())->hasMarker(
            "<?php\n// this mentions {coa:services} mid-sentence, not as its own line\n",
            'coa:services',
        ));
    }

    public function testInsertBeforeSplicesTheSnippetAboveTheMarkerIndentedToMatchIt(): void
    {
        $out = (new MarkerInserter())->insertBefore(
            self::PLUGIN,
            'coa:services',
            "\$this->container->registerService(\n    Foo::class,\n    new Foo(),\n);",
        );

        $this->assertStringContainsString(
            "        \$this->container->registerService(\n            Foo::class,\n            new Foo(),\n        );\n        // {coa:services}",
            $out,
        );
    }

    public function testInsertBeforePreservesTheMarkerLineForALaterInsertion(): void
    {
        $out = (new MarkerInserter())->insertBefore(self::PLUGIN, 'coa:services', "\$x = 1;");

        $this->assertSame(1, substr_count($out, '// {coa:services}'));
        $this->assertTrue((new MarkerInserter())->hasMarker($out, 'coa:services'));
    }

    public function testInsertBeforeThrowsWhenTheMarkerIsAbsent(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('coa:services');
        (new MarkerInserter())->insertBefore("<?php\n// no markers here\n", 'coa:services', "\$x = 1;");
    }

    /** F1's idempotency requirement: inserting the SAME registration twice must not duplicate it. */
    public function testInsertBeforeIsIdempotentForTheSameSnippet(): void
    {
        $inserter = new MarkerInserter();
        $snippet = "\$this->container->registerService(\n    Foo::class,\n    new Foo(),\n);";

        $once = $inserter->insertBefore(self::PLUGIN, 'coa:services', $snippet);
        $twice = $inserter->insertBefore($once, 'coa:services', $snippet);

        $this->assertSame($once, $twice);
        $this->assertSame(1, substr_count($twice, 'new Foo()'));
    }

    /** F1's escape hatch: `$force` re-inserts even when the snippet is already present. */
    public function testForceReinsertsEvenWhenAlreadyPresent(): void
    {
        $inserter = new MarkerInserter();
        $snippet = "\$this->container->registerService(\n    Foo::class,\n    new Foo(),\n);";

        $once = $inserter->insertBefore(self::PLUGIN, 'coa:services', $snippet);
        $forced = $inserter->insertBefore($once, 'coa:services', $snippet, true);

        $this->assertNotSame($once, $forced);
        $this->assertSame(2, substr_count($forced, 'new Foo()'));
    }

    /** A DIFFERENT snippet targeting the same marker is never treated as a duplicate. */
    public function testDifferentSnippetsAtTheSameMarkerBothLand(): void
    {
        $inserter = new MarkerInserter();

        $out = $inserter->insertBefore(self::PLUGIN, 'coa:services', "\$this->container->registerService(Foo::class, new Foo());");
        $out = $inserter->insertBefore($out, 'coa:services', "\$this->container->registerService(Bar::class, new Bar());");

        $this->assertStringContainsString('new Foo()', $out);
        $this->assertStringContainsString('new Bar()', $out);
        $this->assertSame(1, substr_count($out, '// {coa:services}'));
    }

    public function testInsertingAtARoutesMarkerIndentsToTheArrayItemLevel(): void
    {
        $plugin = <<<'PHP'
            <?php

            final class BlogPlugin
            {
                public function routes(): array
                {
                    return [
                        // {coa:routes}
                    ];
                }
            }
            PHP;

        $out = (new MarkerInserter())->insertBefore(
            $plugin,
            'coa:routes',
            "new Route(\n    path: '/posts',\n),",
        );

        $this->assertStringContainsString(
            "            new Route(\n                path: '/posts',\n            ),\n            // {coa:routes}",
            $out,
        );
    }
}
