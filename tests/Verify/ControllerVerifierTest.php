<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Verify;

use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Tests\Fixtures\BadController;
use Milpa\DevTools\Tests\Fixtures\GoodController;
use Milpa\DevTools\Verify\ControllerVerifier;

final class ControllerVerifierTest extends TestCase
{
    public function testConventionalControllerVerifiesClean(): void
    {
        $result = (new ControllerVerifier())->verify(GoodController::class);

        $this->assertTrue($result->ok(), implode("\n", $result->errors));
        $this->assertSame([], $result->warnings);
    }

    public function testFlagsEachRealViolationOnABadController(): void
    {
        $result = (new ControllerVerifier())->verify(BadController::class);

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
}
