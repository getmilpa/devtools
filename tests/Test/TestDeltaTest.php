<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Test;

use Milpa\DevTools\Test\TestDelta;
use PHPUnit\Framework\TestCase;

/**
 * The diff turns "it already failed" into a checkable statement: it names which failures are new,
 * which are resolved, and which were already there.
 */
final class TestDeltaTest extends TestCase
{
    public function testItSplitsFailuresIntoNewResolvedAndUnchanged(): void
    {
        $baseline = [
            'A::already' => 'failed',   // was red, still red -> unchanged
            'A::fixed' => 'errored',    // was red, now green -> resolved
            'A::green' => 'passed',
        ];
        $current = [
            'A::already' => 'failed',
            'A::fixed' => 'passed',
            'A::green' => 'passed',
            'A::regressed' => 'failed',  // green/absent before, red now -> new
        ];

        $d = (new TestDelta())->compare($baseline, $current);

        self::assertSame(['A::regressed'], $d['new_failures']);
        self::assertSame(['A::fixed'], $d['resolved_failures']);
        self::assertSame(['A::already'], $d['unchanged_failures']);
        self::assertTrue($d['regressed']);
        self::assertSame(2, $d['baseline_failures']);
        self::assertSame(2, $d['current_failures']);
    }

    public function testATestFailingNowButAbsentFromBaselineIsNew(): void
    {
        $d = (new TestDelta())->compare([], ['A::fresh' => 'failed']);

        self::assertSame(['A::fresh'], $d['new_failures']);
        self::assertTrue($d['regressed']);
    }

    public function testNoNewFailuresMeansNoRegression(): void
    {
        $d = (new TestDelta())->compare(['A::x' => 'failed'], ['A::x' => 'passed', 'A::y' => 'passed']);

        self::assertSame([], $d['new_failures']);
        self::assertSame(['A::x'], $d['resolved_failures']);
        self::assertFalse($d['regressed']);
    }

    public function testSkippedIsNotAFailure(): void
    {
        $d = (new TestDelta())->compare([], ['A::s' => 'skipped']);

        self::assertSame([], $d['new_failures']);
        self::assertSame(0, $d['current_failures']);
        self::assertFalse($d['regressed']);
    }
}
