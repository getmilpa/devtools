<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Test;

use Milpa\DevTools\Test\JUnitParser;
use PHPUnit\Framework\TestCase;

/**
 * The parser reads per-test identity and outcome from a JUnit report — the data a delta needs and
 * that the human summary cannot give.
 */
final class JUnitParserTest extends TestCase
{
    private function xml(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <testsuites>
          <testsuite name="Suite" tests="4">
            <testsuite name="App\Tests\BarTest">
              <testcase name="testPasses" class="App\Tests\BarTest" classname="App\Tests\BarTest" time="0.01"/>
              <testcase name="testFails" class="App\Tests\BarTest" classname="App\Tests\BarTest" time="0.01">
                <failure type="PHPUnit\Framework\ExpectationFailedException">nope</failure>
              </testcase>
              <testcase name="testErrors" class="App\Tests\BarTest" classname="App\Tests\BarTest" time="0.01">
                <error type="Error">boom</error>
              </testcase>
              <testcase name="testSkipped" class="App\Tests\BarTest" classname="App\Tests\BarTest" time="0.0">
                <skipped/>
              </testcase>
            </testsuite>
          </testsuite>
        </testsuites>
        XML;
    }

    public function testItReadsEveryOutcomeKeyedByClassAndMethod(): void
    {
        $results = (new JUnitParser())->parse($this->xml());

        self::assertSame('passed', $results['App\Tests\BarTest::testPasses']);
        self::assertSame('failed', $results['App\Tests\BarTest::testFails']);
        self::assertSame('errored', $results['App\Tests\BarTest::testErrors']);
        self::assertSame('skipped', $results['App\Tests\BarTest::testSkipped']);
        self::assertCount(4, $results);
    }

    public function testErrorOutranksFailureWhenBothArePresent(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0"?>
        <testsuites><testsuite name="S">
          <testcase name="testBoth" class="X" classname="X">
            <failure>f</failure><error>e</error>
          </testcase>
        </testsuite></testsuites>
        XML;

        self::assertSame('errored', (new JUnitParser())->parse($xml)['X::testBoth']);
    }

    public function testItFallsBackToClassnameWhenClassAttributeIsAbsent(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0"?>
        <testsuites><testsuite name="S">
          <testcase name="testOnly" classname="Only\Classname"/>
        </testsuite></testsuites>
        XML;

        self::assertArrayHasKey('Only\Classname::testOnly', (new JUnitParser())->parse($xml));
    }

    public function testEmptyDocumentIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new JUnitParser())->parse('   ');
    }

    public function testMalformedXmlIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new JUnitParser())->parse('<testsuites><testcase name="a"></testsuites>');
    }
}
