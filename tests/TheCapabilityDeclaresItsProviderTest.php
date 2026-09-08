<?php

/**
 * This file is part of milpa/devtools — the development tools a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Tests;

use Milpa\Command\CommandProvider;
use Milpa\DevTools\Operations\DevToolsOperations;
use PHPUnit\Framework\TestCase;

/**
 * The capability names the provider that carries its operations, so `capabilities:enable milpa/devtools`
 * can declare it in the app's `config/operations.php` — and the skeleton no longer has to pre-list a
 * class it does not ship (a fresh app's phpstan reported it as not found; greenhouse evidence/0565).
 */
final class TheCapabilityDeclaresItsProviderTest extends TestCase
{
    public function testTheManifestNamesAProviderThatExistsAndProvides(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__) . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        $declared = $manifest['extra']['milpa']['capability']['operations'] ?? null;

        self::assertSame([DevToolsOperations::class], $declared, 'the capability declares exactly its provider');
        foreach ($declared as $class) {
            self::assertTrue(class_exists($class), $class);
            self::assertTrue(is_a($class, CommandProvider::class, true), $class . ' must be a CommandProvider for the runtime to list it');
        }
    }
}
