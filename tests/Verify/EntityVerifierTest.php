<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Verify;

use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Tests\Fixtures\BadEntity;
use Milpa\DevTools\Tests\Fixtures\GoodEntity;
use Milpa\DevTools\Verify\EntityVerifier;

final class EntityVerifierTest extends TestCase
{
    public function testConventionalEntityVerifiesClean(): void
    {
        $result = (new EntityVerifier())->verify(GoodEntity::class);

        $this->assertTrue($result->ok(), implode("\n", $result->errors));
        $this->assertSame([], $result->warnings);
    }

    public function testFlagsEachRealViolationOnABadEntity(): void
    {
        $result = (new EntityVerifier())->verify(BadEntity::class);

        $this->assertFalse($result->ok());
        $errors = implode("\n", $result->errors);
        $this->assertStringContainsString('Missing #[ORM\\Entity] attribute', $errors);
        $this->assertStringContainsString('Property $id is public', $errors);
        $this->assertStringContainsString("PHP type 'string' does not allow null", $errors);
    }

    public function testUnknownClassIsReportedAsAnError(): void
    {
        $result = (new EntityVerifier())->verify('Milpa\\DevTools\\Tests\\Fixtures\\DoesNotExist');

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('class not found', $result->errors[0]);
    }

    /**
     * F1: `doctrine/orm` moved from `require` to `suggest` (see composer.json) — verifying an entity
     * without it must report a clear, actionable error (not throw, matching this class's existing
     * "class not found" non-throwing shape) instead of crashing inside attribute reflection.
     */
    public function testReturnsAClearErrorWhenDoctrineOrmIsNotInstalled(): void
    {
        $result = (new EntityVerifier(doctrineAvailable: false))->verify(GoodEntity::class);

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('composer require doctrine/orm', implode("\n", $result->errors));
    }
}
