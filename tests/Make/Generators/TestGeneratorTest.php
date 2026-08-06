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

namespace Milpa\DevTools\Tests\Make\Generators;

use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\Generators\TestGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The judge scaffold: where it lands, what namespace its location dictates, and — the decision that
 * matters — that it FAILS on purpose until somebody declares real behavior. A scaffold that passed
 * vacuously would green-light every body the moment the file exists: a judge that judges nothing,
 * wearing a verdict.
 */
final class TestGeneratorTest extends TestCase
{
    public function testTheScaffoldLandsWhereTheGateWillLookForIt(): void
    {
        $r = (new TestGenerator())->generate(new GenerationContext('Demo', 'GreeterService', [], '/tmp/x'));

        self::assertCount(1, $r->files);
        self::assertSame('tests/Plugins/Demo/GreeterServiceTest.php', $r->files[0]->path);
        self::assertStringContainsString('namespace App\\Tests\\Plugins\\Demo;', $r->files[0]->contents);
        self::assertStringContainsString('final class GreeterServiceTest extends TestCase', $r->files[0]->contents);
        self::assertStringContainsString('declare(strict_types=1)', $r->files[0]->contents);
    }

    /** The scaffold is RED by construction — a vacuous judge must not green-light anything. */
    public function testTheScaffoldFailsOnPurposeUntilTheJudgeIsReal(): void
    {
        $r = (new TestGenerator())->generate(new GenerationContext('Demo', 'GreeterService', [], '/tmp/x'));

        self::assertStringContainsString('self::fail(', $r->files[0]->contents);
        self::assertStringNotContainsString('markTestIncomplete', $r->files[0]->contents);
        self::assertStringContainsString('implement', (string) $r->guidance);
    }
}
