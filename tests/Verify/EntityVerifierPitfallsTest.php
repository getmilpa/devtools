<?php

/**
 * This file is part of Milpa DevTools — the generate-verify-inspect developer loop of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Verify;

use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Tests\Fixtures\LegacyEntityPitfalls;
use Milpa\DevTools\Tests\Fixtures\RuntimeEntityWrongStatics;
use Milpa\DevTools\Verify\EntityVerifier;
use PHPUnit\Framework\TestCase;

/**
 * The mistakes an entity carries that `php -l` lets through and hydration does not, each one named by the verifier.
 */
final class EntityVerifierPitfallsTest extends TestCase
{
    public function testEveryLegacyPitfallIsNamed(): void
    {
        $result = (new EntityVerifier())->verify(LegacyEntityPitfalls::class);

        self::assertSame([
            'Missing declare(strict_types=1) at top of file',
            "Property \$subtitle: is nullable but missing default '= null' — causes 'must not be accessed before initialization'",
            'Property $subtitle: Typed property ' . LegacyEntityPitfalls::class . '::$subtitle must not be accessed before initialization',
        ], $result->errors);
        self::assertSame([
            "Missing #[ORM\\Table(name: '...')] attribute — Doctrine will auto-generate a table name",
            "Property \$title: #[ORM\\Column] missing 'type:' — Doctrine will infer from PHP type but being explicit is safer",
            "Property \$comments: collection relation should be initialized to '= []' or via Doctrine ArrayCollection in constructor",
            "Entity has 'updatedAt' property but no method with #[ORM\\PreUpdate] — updatedAt will never be automatically set",
            'Entity has lifecycle callback methods but is missing #[ORM\\HasLifecycleCallbacks] on the class',
        ], $result->warnings);
    }

    public function testARuntimeEntityWithItsStaticsBackwardsIsNamed(): void
    {
        $result = (new EntityVerifier(Flavor::Runtime))->verify(RuntimeEntityWrongStatics::class);

        self::assertContains('fromArray() must be a static method', $result->errors);
        self::assertContains('id() must not be static', $result->errors);
    }

    public function testAnUnknownRuntimeClassIsReportedNotThrown(): void
    {
        $result = (new EntityVerifier(Flavor::Runtime))->verify('Milpa\\DevTools\\Tests\\Fixtures\\NoSuchEntity');

        self::assertStringContainsString('class not found', $result->errors[0]);
    }
}
