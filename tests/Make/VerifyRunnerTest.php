<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Make\VerifyRunner;
use Milpa\DevTools\Tests\Fixtures\BadController;
use Milpa\DevTools\Tests\Fixtures\GoodRuntimeController;

final class VerifyRunnerTest extends TestCase
{
    /** No `$flavor` argument preserves the pre-F1 behavior: the fixed, legacy-flavored verifier. */
    public function testDefaultRunWithNoFlavorUsesTheLegacyControllerVerifier(): void
    {
        $outcome = (new VerifyRunner())->run('controller', BadController::class, '/fake/host');

        $this->assertFalse($outcome['ok']);
        $this->assertStringContainsString('does not extend BaseController', $outcome['output']);
    }

    public function testFlavorArgumentSelectsTheRuntimeControllerVerifier(): void
    {
        $outcome = (new VerifyRunner())->run('controller', GoodRuntimeController::class, '/fake/host', Flavor::Runtime);

        $this->assertTrue($outcome['ok'], $outcome['output']);
    }

    /** The same runtime-shaped class must FAIL when verified without the runtime flavor (legacy default). */
    public function testRuntimeControllerFailsTheDefaultLegacyVerifier(): void
    {
        $outcome = (new VerifyRunner())->run('controller', GoodRuntimeController::class, '/fake/host');

        $this->assertFalse($outcome['ok']);
    }

    public function testUnknownKindReportsAnError(): void
    {
        $outcome = (new VerifyRunner())->run('bogus', BadController::class, '/fake/host');

        $this->assertFalse($outcome['ok']);
        $this->assertStringContainsString("no verifier for kind 'bogus'", $outcome['output']);
    }
}
