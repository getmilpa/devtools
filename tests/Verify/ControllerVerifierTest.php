<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Verify;

use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Tests\Fixtures\BadController;
use Milpa\DevTools\Tests\Fixtures\BadRuntimeController;
use Milpa\DevTools\Tests\Fixtures\BadRuntimeControllerWithWrongTypes;
use Milpa\DevTools\Tests\Fixtures\GoodController;
use Milpa\DevTools\Tests\Fixtures\GoodRuntimeController;
use Milpa\DevTools\Verify\ControllerVerifier;

final class ControllerVerifierTest extends TestCase
{
    public function testConventionalControllerVerifiesClean(): void
    {
        $result = (new ControllerVerifier())->verify(GoodController::class);

        $this->assertTrue($result->ok(), implode("\n", $result->errors));
        $this->assertSame([], $result->warnings);
    }

    /** The zero-arg constructor default is {@see Flavor::Legacy} — unchanged since before F1. */
    public function testDefaultConstructorVerifiesLegacyConvention(): void
    {
        $result = (new ControllerVerifier())->verify(BadController::class);

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('does not extend BaseController', implode("\n", $result->errors));
    }

    public function testFlagsEachRealViolationOnABadController(): void
    {
        $result = (new ControllerVerifier(Flavor::Legacy))->verify(BadController::class);

        $this->assertFalse($result->ok());
        $errors = implode("\n", $result->errors);
        $this->assertStringContainsString('does not extend BaseController', $errors);
        $this->assertStringContainsString('does not call parent::__construct', $errors);
        $this->assertStringContainsString("HTTP method 'get' must be uppercase", $errors);
        $this->assertStringContainsString("missing second parameter 'array \$params = []'", $errors);
    }

    public function testUnknownClassIsReportedAsAnError(): void
    {
        $result = (new ControllerVerifier())->verify('Milpa\\DevTools\\Tests\\Fixtures\\DoesNotExist');

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('class not found', $result->errors[0]);
    }

    public function testConventionalRuntimeControllerVerifiesClean(): void
    {
        $result = (new ControllerVerifier(Flavor::Runtime))->verify(GoodRuntimeController::class);

        $this->assertTrue($result->ok(), implode("\n", $result->errors));
        $this->assertSame([], $result->warnings);
    }

    public function testRuntimeVerifierFlagsAMissingIndexMethod(): void
    {
        $result = (new ControllerVerifier(Flavor::Runtime))->verify(BadRuntimeController::class);

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('Missing public method index(', implode("\n", $result->errors));
    }

    public function testRuntimeVerifierFlagsWrongTypesAndWarnsOnAStrayRouteAttribute(): void
    {
        $result = (new ControllerVerifier(Flavor::Runtime))->verify(BadRuntimeControllerWithWrongTypes::class);

        $this->assertFalse($result->ok());
        $errors = implode("\n", $result->errors);
        $this->assertStringContainsString('first parameter must be typed Psr\\Http\\Message\\ServerRequestInterface', $errors);
        $this->assertStringContainsString('return type must implement Psr\\Http\\Message\\ResponseInterface', $errors);
        $this->assertStringContainsString('#[Route] attribute, which is ignored', implode("\n", $result->warnings));
    }

    /** A legacy-shaped controller run through the RUNTIME verifier must be rejected, not silently accepted. */
    public function testRuntimeVerifierRejectsALegacyBaseControllerSubclass(): void
    {
        $result = (new ControllerVerifier(Flavor::Runtime))->verify(GoodController::class);

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('Runtime controllers must be plain classes', implode("\n", $result->errors));
    }
}
